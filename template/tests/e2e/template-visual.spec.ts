import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

// Phase 5.1 Visual Regression — 変換前 Static Template と変換後 Agent CMS Site の視覚比較。
// Manifest / Plan 駆動: source の場所も比較ページも .agentcms/manifest/ から導出する
// （import 元テンプレートが変わっても spec の書き換えは不要）。
//
// 閾値: 既定 2%（ADR-0010 §7）。ページごとの例外は `.agentcms/visual-thresholds.json`
// に**理由つきで**宣言する（無ければ既定 2% だけ。書式は同ディレクトリの
// `visual-thresholds.example.json`）。kit の 4 ページぶんの例外もそこに在る。

const HERE = dirname(fileURLToPath(import.meta.url));

/**
 * manifest の在り処は**上へ歩いて探す**。
 *
 * kit ではこの spec が `template/tests/e2e/` に居るので root は 3 つ上、
 * 生成プロジェクトでは `tests/e2e/` なので **2 つ上**である。
 * 段数を数え打ちにすると、**配った先だけで静かに何も比較しなくなる**
 * （`loadPages()` は manifest が無いと `null` を返して test.skip する = 緑に見える）。
 */
function findManifestDir(): string {
    let dir = HERE;

    for (let up = 0; up < 6; up += 1) {
        const candidate = resolve(dir, '.agentcms/manifest');

        if (existsSync(candidate)) {
            return candidate;
        }

        const parent = dirname(dir);

        if (parent === dir) {
            break;
        }

        dir = parent;
    }

    return resolve(HERE, '../../.agentcms/manifest');
}

const MANIFEST_DIR = findManifestDir();
const OUT_DIR = resolve(HERE, '__screenshots__');

/**
 * 許容差分は**宣言から読む**（R-15 Stage 2）。
 *
 * 以前は kit の 4 ページぶんの例外が spec に直書きされていた。配ると
 * **利用者のプロジェクトに、そこに存在しないページの緩和が付いてくる**。
 *
 * 置き場所は `.agentcms/visual-thresholds.json`。**無ければ既定 2% だけ**。
 * `reason` は必須 — 数字だけ置ける形にすると「赤いから上げる」が通ってしまう
 * （B-21 で残した「大きすぎる許容は本物の崩れを隠す」への歯止め）。
 */
interface ThresholdDeclaration {
    default: number;
    overrides: Record<string, { ratio: number; reason: string }>;
}

function loadThresholds(): ThresholdDeclaration {
    const fallback: ThresholdDeclaration = { default: 0.02, overrides: {} };
    const path = resolve(MANIFEST_DIR, '..', 'visual-thresholds.json');

    if (!existsSync(path)) {
        return fallback;
    }

    const raw = JSON.parse(readFileSync(path, 'utf8')) as Partial<{
        default: unknown;
        overrides: Record<string, { ratio?: unknown; reason?: unknown }>;
    }>;

    const overrides: ThresholdDeclaration['overrides'] = {};

    for (const [key, value] of Object.entries(raw.overrides ?? {})) {
        if (typeof value.ratio !== 'number') {
            throw new Error(
                `visual-thresholds.json: ${key} の ratio が数値ではありません`,
            );
        }

        // 理由の無い緩和は受け取らない（緩めた記録が残らないと、次に誰も戻せない）
        if (typeof value.reason !== 'string' || value.reason.trim() === '') {
            throw new Error(
                `visual-thresholds.json: ${key} に reason がありません（なぜ緩めるのかを書いてください）`,
            );
        }

        overrides[key] = { ratio: value.ratio, reason: value.reason };
    }

    return {
        default: typeof raw.default === 'number' ? raw.default : fallback.default,
        overrides,
    };
}

const THRESHOLDS = loadThresholds();

interface VisualPage {
    file: string;
    path: string;
    name: string;
}

function loadPages(): { staticDir: string; pages: VisualPage[] } | null {
    const manifestPath = join(MANIFEST_DIR, 'template.json');
    const planPath = join(MANIFEST_DIR, 'template-plan.json');

    if (!existsSync(manifestPath) || !existsSync(planPath)) {
        return null;
    }

    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8')) as {
        source: string;
    };
    const plan = JSON.parse(readFileSync(planPath, 'utf8')) as {
        pages: { source: string; url: string | null }[];
        seeds: Record<string, unknown>;
    };

    /*
     * seed の**型名を名指ししない**。
     *
     * `plan.seeds.works` と書くと、`works` を持たないプロジェクト
     * （= 利用者。配布境界が `works`/`news` を配らない）では
     * **詳細ページを 1 枚も比較しないまま緑になる**。
     * 見るのは形だけ — `slug` と `source_page` を持つ seed の配列なら使う。
     */
    const slugSeeds: { slug: string; source_page?: string | null }[] =
        Object.values(plan.seeds)
            .filter((v): v is Record<string, unknown>[] => Array.isArray(v))
            .flat()
            .filter(
                (v): v is { slug: string; source_page?: string | null } =>
                    typeof v === 'object' &&
                    v !== null &&
                    typeof (v as { slug?: unknown }).slug === 'string',
            );
    // manifest.source は inspect 実行時のラベル。相対パスは kit リポジトリルート基準で解決する
    const staticDir = manifest.source.startsWith('/')
        ? manifest.source
        : resolve(MANIFEST_DIR, '../..', manifest.source);

    if (!existsSync(staticDir)) {
        return null;
    }

    const pages: VisualPage[] = [];
    const seenPaths = new Set<string>();

    for (const p of plan.pages) {
        if (!p.url) {
            continue;
        }

        if (p.url.includes('{slug}')) {
            for (const w of slugSeeds) {
                if (!w.source_page) {
                    continue;
                }

                const path = p.url.replace('{slug}', w.slug);

                if (seenPaths.has(path)) {
                    continue;
                }

                seenPaths.add(path);
                pages.push({
                    file: w.source_page,
                    path,
                    name: w.source_page.replace(/\.html?$/, ''),
                });
            }

            continue;
        }

        if (seenPaths.has(p.url)) {
            continue;
        }

        seenPaths.add(p.url);
        pages.push({
            file: p.source,
            path: p.url,
            name:
                p.url === '/'
                    ? 'home'
                    : p.url.replace(/^\//, '').replaceAll('/', '-'),
        });
    }

    return { staticDir, pages };
}

const loaded = loadPages();

// 決定論比較のための正規化: アニメーション・トランジションを止める（両側同一処理）
//
// 本文の書体も同じ条件へ揃える。原本は Google Fonts の Noto Sans JP を読むが、
// 同梱サンプルは外部 CDN を使わず端末内蔵の sans を使う（配布物としての判断）。
// 揃えないと、比較しているのがレイアウトなのか書体なのか分からなくなる。
// **両側へ同じ指定を当てる**ので、構造・内容のズレは今までどおり差分に出る。
// 「外部フォントを取りに行かないこと」自体は packages/cli/tests/productization.test.ts が
// ファイルの中身で検査しており、ここは字面を揃えたうえでの版面比較に徹する。
const FREEZE_CSS = `
*, *::before, *::after {
  animation-duration: 0s !important;
  animation-delay: 0s !important;
  transition: none !important;
  caret-color: transparent !important;
}
html { scroll-behavior: auto !important; }
body {
  font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN",
    "Hiragino Sans", "BIZ UDPGothic", Meiryo, sans-serif !important;
}
`;

async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('load');
    await page.addStyleTag({ content: FREEZE_CSS });
    await page.evaluate(() => document.fonts.ready.then(() => undefined));
    await page.waitForTimeout(1200);
    // reveal 系（IntersectionObserver）を発火させてから先頭へ戻す（両側同一処理）
    await page.evaluate(async () => {
        const step = window.innerHeight;

        for (let y = 0; y < document.body.scrollHeight; y += step) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 60));
        }

        window.scrollTo(0, 0);
    });
    await page.waitForTimeout(600);
    // Turnstile widget は変換で追加された要素のため非表示（占有スペースの shift は閾値側で扱う）
    await page.evaluate(() => {
        for (const el of document.querySelectorAll<HTMLElement>(
            '.cf-turnstile',
        )) {
            el.style.visibility = 'hidden';
        }
    });
}

function toPng(buffer: Buffer): PNG {
    return PNG.sync.read(buffer);
}

/** サイズ差は白でパディングして比較する（高さ僅差の吸収。大きな差は diff に現れる） */
function padTo(png: PNG, width: number, height: number): PNG {
    if (png.width === width && png.height === height) {
        return png;
    }

    const out = new PNG({ width, height, fill: true });
    out.data.fill(255);
    PNG.bitblt(png, out, 0, 0, png.width, png.height, 0, 0);

    return out;
}

test.describe('Template Import — Visual Regression (static vs converted)', () => {
    test.skip(
        loaded === null,
        'manifest / static source が無い環境ではスキップ',
    );

    for (const { file, path, name } of loaded?.pages ?? []) {
        const limit = THRESHOLDS.overrides[path]?.ratio ?? THRESHOLDS.default;
        test(`${name}: static と converted の視覚差分が ${limit * 100}% 以下`, async ({
            page,
        }, testInfo) => {
            await page.goto(`file://${join(loaded!.staticDir, file)}`);
            await settle(page);
            const staticShot = toPng(await page.screenshot({ fullPage: true }));

            await page.goto(path);
            await settle(page);
            const convertedShot = toPng(
                await page.screenshot({ fullPage: true }),
            );

            const width = Math.max(staticShot.width, convertedShot.width);
            const height = Math.max(staticShot.height, convertedShot.height);
            const a = padTo(staticShot, width, height);
            const b = padTo(convertedShot, width, height);
            const diff = new PNG({ width, height });
            const diffPixels = pixelmatch(
                a.data,
                b.data,
                diff.data,
                width,
                height,
                { threshold: 0.1 },
            );
            const ratio = diffPixels / (width * height);

            const dir = join(OUT_DIR, testInfo.project.name, 'template-import');
            mkdirSync(dir, { recursive: true });
            writeFileSync(join(dir, `${name}-static.png`), PNG.sync.write(a));
            writeFileSync(
                join(dir, `${name}-converted.png`),
                PNG.sync.write(b),
            );
            writeFileSync(join(dir, `${name}-diff.png`), PNG.sync.write(diff));

            expect(
                ratio,
                `${name}: diff ${(ratio * 100).toFixed(3)}% (${diffPixels}px / ${width}x${height})`,
            ).toBeLessThanOrEqual(limit);
        });
    }
});
