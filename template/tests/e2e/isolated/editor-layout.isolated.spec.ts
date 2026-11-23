import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * フォームを、**画面を 1 度も跨がずに**作れること（R-8A ④）。
 *
 * ## 何を数えるか
 *
 * オーナーの報告は「フォームの作成がうまくできなかった。カスタムフィールドを
 * 作らないとダメで**画面を跨ぐのは NG**」だった。実測すると、項目を 1 つ足すのに
 * **画面 2 つを 2 往復・保存 3 回**必要だった。
 *
 * ▷ だからここでは**往復の回数そのものを数える**。
 *   歩いているあいだに一度でも `/admin/field-groups` へ行ったら赤にする。
 *   「項目が保存できた」だけを見ると、**遠回りして辿り着いても緑**になる。
 *
 * ## 技術語彙を出していないこと（canary 作法）
 *
 * 「CSS や HTML を利用者に意識させない」が最上位の原則なので、
 * 画面に出る文字から**技術語彙が消えていること**を見る。
 * ▷ **0 件を名乗る前に、わざと 1 語出して捕まることを確かめる。**
 *   「1 語も無かった」と「そもそも見ていなかった」は結果の見た目が同じである。
 * ▷ 見るのは **`main` の中だけ**。サイドバーには「カスタムフィールド」の項目が
 *   在り、そちらは制作会社が使う道である（顧客面と制作会社面を分けるのは B-25）。
 *
 * ## 標準スイートへは入れない
 *
 * フォームと項目を作る。**使い捨てプロジェクト専用**の隔離レーンに置く。
 *
 * ```
 * APP_URL=http://localhost:8189 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/form-inline-fields.isolated.spec.ts
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

/**
 * エディタの区画が、**中身によって入れ替わらない**こと（R-9 Stage 0 ①）。
 *
 * ## 何が起きていたか
 *
 * grid は 3 列なのに子が 4 つあり、3 列ぶんを占める「気づき」パネルが Canvas より
 * 前に居た。CSS grid の既定（sparse）では**後の子が前の空きへ戻らない**ので、
 * **気づきが 1 件でもあると Canvas が狭い列へ落ちていた**。
 * ccb の実ブラウザ計測（1440×900）で **640px → 256px**、しかも Inspector と幅が入れ替わる。
 * **空の見出しを 1 つ置くだけで再現した。**
 *
 * ## 両方の状態を、対で見る
 *
 * 「気づきが在るときに広い」だけを見ても、**気づきが無いときに壊れる**変更は捕まらない。
 * 逆も同じ。**同じページの、気づきの有無だけが違う 2 つの状態**を測って、
 * **幅が変わらないこと**を見る。
 *
 * ```
 * APP_URL=http://localhost:8189 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/editor-layout.isolated.spec.ts
 * ```
 */

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

/** Canvas の実寸（枠そのもの。中の iframe ではなく区画を測る） */
async function canvasWidth(page: Page): Promise<number> {
    const box = await page.getByTestId('editor-canvas-viewport').boundingBox();

    if (box === null) {
        throw new Error('Canvas が見つかりません（画面が描けていない可能性）');
    }

    return Math.round(box.width);
}

test('気づきの有無で、Canvas の幅が変わらない', async ({ page }) => {
    // **幅の話なので、画面の大きさを固定する。** 既定に任せると数字の意味が変わる
    await page.setViewportSize({ width: 1440, height: 900 });

    await login(page);

    const slug = `layout-${Date.now()}`;

    await page.goto('/admin/pages/create');
    await page.fill('#page-title', `レイアウト確認 ${slug}`);
    await page.fill('#page-slug', slug);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/pages/*/edit');

    await page.getByTestId('page-editor-link').click();
    await page.getByTestId('palette-section').click();
    await page.getByTestId('layer-section').click();

    /*
     * ── 気づきが出ない状態 ─────────────────────────
     *
     * **本文ブロックを置く。** 見出しは（文字を入れても）`heading-order` の気づきを出すので、
     * 「文字が空だと出る」という私の前提は**誤りだった**（監査 指摘 30・ccb が worktree で確認）。
     * **0 件側を作れる型を選ぶ**のが正しい作り方である。
     */
    await page.getByTestId('palette-text').click();
    await expect(page.getByTestId('a11y-panel')).toHaveCount(0);

    const withoutFindings = await canvasWidth(page);

    /*
     * **広いことを先に確かめる。** 「変わらない」だけを見ると、
     * 両方とも狭いまま揃っていても緑になる。
     */
    expect(
        withoutFindings,
        `Canvas が狭すぎます（${withoutFindings}px）`,
    ).toBeGreaterThan(500);

    /*
     * ── 気づきが出る状態 ─────────────────────────
     *
     * **見出しを足すだけ**でよい。**中身は同じページのまま**なので、
     * 変わったのは「気づきの有無」だけである — そこが対で見る意味である。
     */
    await page.getByTestId('layer-section').click();
    await page.getByTestId('palette-heading').click();
    await expect(page.getByTestId('a11y-panel')).toBeVisible();

    const withFindings = await canvasWidth(page);

    expect(
        withFindings,
        `気づきの有無で Canvas の幅が変わりました（${withoutFindings}px → ${withFindings}px）`,
    ).toBe(withoutFindings);
});
