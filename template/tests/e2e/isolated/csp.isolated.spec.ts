import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 管理画面で CSP に弾かれるものが **1 件も無い**こと（F-50 / B-24）。
 *
 * ## なぜ「0 件」を見る必要があるのか
 *
 * CSP 違反は**画面が壊れない**。進捗バーが出ないだけで、機能は動き続ける。
 * だから人は気づかず、`console` に毎頁 1 行積まれるのを見過ごす。
 * 実際、`@inertiajs/core` が実行時に差し込む `<style>` が
 * **全ページで 1 件ずつ**ブロックされていた（`includeCSS: false` + 自前 stylesheet で止めた）。
 *
 * ▷ ここで見るのは「今の 1 件が消えたか」ではなく **「0 件であること」**である。
 *   件数を数えると、次に別の何かが増えたときに**通ってしまう**。
 *
 * ## 標準スイートへは入れない
 *
 * `securitypolicyviolation` はページ側の挙動なので破壊はしないが、
 * この spec は**使い捨てプロジェクト専用**の隔離レーンに置く
 * （標準レーンの global setup は `migrate:fresh` を走らせるため、
 * 稼働中の環境へ向けてはならない）。
 *
 * ```
 * APP_URL=http://localhost:8189 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/csp.isolated.spec.ts
 * ```
 */

function isolatedTarget(): { appUrl: string; composeCwd: string } {
    const appUrl = process.env.APP_URL;
    const composeCwd = process.env.AGENTCMS_COMPOSE_CWD;

    if (!appUrl || !composeCwd) {
        throw new Error(
            'APP_URL と AGENTCMS_COMPOSE_CWD を明示してください（使い捨てプロジェクト専用の spec です）',
        );
    }

    let parsed: URL;

    try {
        parsed = new URL(appUrl);
    } catch {
        throw new Error(`APP_URL を URL として読めません: ${appUrl}`);
    }

    if (parsed.protocol !== 'http:') {
        throw new Error(
            `手元の使い捨て以外へは向けられません（http のみ）: ${appUrl}`,
        );
    }

    if (!['localhost', '127.0.0.1', '[::1]'].includes(parsed.hostname)) {
        throw new Error(`手元のホストではありません: ${parsed.hostname}`);
    }

    if (parsed.port === '' || parsed.port === '8180') {
        throw new Error(`使い捨て専用の port を明示してください: ${appUrl}`);
    }

    if (resolve(composeCwd) === resolve(process.cwd())) {
        throw new Error(`repo 自身を対象にできません: ${composeCwd}`);
    }

    for (const marker of [
        '.agentcms/local/admin-credentials.json',
        'docker-compose.yml',
        '.env',
    ]) {
        if (!existsSync(join(composeCwd, marker))) {
            throw new Error(
                `生成プロジェクトに見えません（${marker} が無い）: ${composeCwd}`,
            );
        }
    }

    return { appUrl, composeCwd };
}

const { composeCwd } = isolatedTarget();

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

interface Violation {
    directive: string;
    sample: string;
    source: string;
}

/**
 * 違反を**取りこぼさずに**集める。
 *
 * `addInitScript` は**どのドキュメントよりも先**に走る。`page.on('console')` だけだと
 * 起動直後（まさに進捗バーの CSS が差し込まれる瞬間）の違反を落とすことがある。
 */
async function collectViolations(page: Page, into: Violation[]): Promise<void> {
    await page.exposeFunction('__cspViolation', (v: Violation) => {
        into.push(v);
    });

    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            void (
                window as unknown as {
                    __cspViolation: (v: Violation) => void;
                }
            ).__cspViolation({
                directive: event.violatedDirective,
                sample: event.sample?.slice(0, 60) ?? '',
                source: `${event.sourceFile ?? ''}:${event.lineNumber ?? 0}`,
            });
        });
    });
}

const readable = (found: Violation[]): string =>
    found.map((v) => `${v.directive} ← ${v.source} : ${v.sample}`).join('\n');

test('管理画面のどの頁でも CSP 違反が 0 件', async ({ page }) => {
    const found: Violation[] = [];

    await collectViolations(page, found);

    const { email, password } = adminCredentials();

    // ログイン画面そのものも管理画面である（P1 ⑪ の CSP はここにも掛かる）
    await page.goto('/login');

    /*
     * **見張っていることを先に確かめる（canary）。**
     *
     * 「0 件だった」と「そもそも拾えていなかった」は、結果の見た目が同じである。
     * listener が付いていない・イベント名が変わった・`exposeFunction` が届いていない —
     * どれが起きても **静かに緑**になる。だから**わざと 1 件出して捕まることを見てから**、
     * 数え直す。捕まらなければここで落ちる（**0 件を名乗る前に落ちる**）。
     */
    await page.evaluate(() => {
        const canary = document.createElement('style');

        // nonce を付けない = このページの style-src に弾かれる（弾かれるので見た目は変わらない）
        canary.textContent = '.__csp_canary__ { color: red; }';
        document.head.appendChild(canary);
    });

    await expect
        .poll(
            () => found.filter((v) => v.directive.includes('style-src')).length,
            {
                message:
                    'わざと出した違反を拾えていません（この spec は 0 件を名乗れません）',
                timeout: 5_000,
            },
        )
        .toBeGreaterThan(0);

    // 確かめ終わったので捨てる。**canary を本番の計数へ持ち込まない**
    found.length = 0;

    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');

    /*
     * **画面遷移をさせてから見る。** 進捗バーは遷移のときに動くので、
     * 1 頁見ただけでは「差し込まれる瞬間」を通らないことがある。
     *
     * 通るのは**どの生成プロジェクトにも在る道だけ**。型ごとの一覧（`/admin/news` 等）は
     * **そのサイトが持つ型次第**なので、焼き込むと「404 を 0 件と読む」ことになる。
     * `/admin` は最初の collection 型へ転送されるので、型名を知らずに一覧へ辿れる。
     */
    for (const path of [
        '/admin',
        '/admin/media',
        '/admin/pages',
        '/dashboard',
    ]) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
    }

    // Select は開いた瞬間に style を差し込む。読み込みだけでは B-29 を見逃す。
    await page.goto('/admin');
    await page.locator('a[href^="/admin/"][href$="/create"]').first().click();
    const status = page.locator('#status');
    await expect(status).toBeVisible();

    for (let count = 0; count < 2; count++) {
        await status.click();
        await expect(page.getByRole('listbox')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('listbox')).toBeHidden();
    }

    expect(found, `CSP に弾かれたものがあります:\n${readable(found)}`).toEqual(
        [],
    );
});
