import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * ゴミ箱と履歴が **画面として成立しているか**（Phase 9.8 / STAGE 21E の受入）。
 *
 * Feature テストは経路・認可・確認・楽観ロックを担保する。ここで見たいのはそれではなく、
 * 実際にログインして辿り着けるか — 一覧を切り替えられるか、**キーボードだけで**
 * ゴミ箱へ移して戻せるか、ゴミ箱の投稿を開いても編集フォームが出ないか、
 * 履歴が読めるか、仕込んだ script が文字として出るか、まで。
 *
 * 標準スイートへは入れない。この spec は投稿の状態を書き換える。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8184 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/trash-history.isolated.spec.ts
 * ```
 */

/** 題名へ仕込む script。**文字として出る**ことを確かめるための餌 */
const XSS_TITLE = '<script>window.__xss = true;</script>記事';

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

function artisan(code: string): string {
    return execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            code,
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    );
}

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

test.beforeEach(async () => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        {
            cwd: composeCwd,
            encoding: 'utf8',
        },
    );

    // 毎回同じ地点から始める
    artisan(
        '\\Illuminate\\Support\\Facades\\DB::table("content_entry_revisions")->delete();' +
            '\\Illuminate\\Support\\Facades\\DB::table("post")->delete();',
    );
});

/**
 * 開き終わるまで待つ。
 *
 * modal は fade-in の途中まだ半透明で、その間は背後の幕と色が混ざる。
 * その状態で axe を回すと、**実際には出ていない contrast 違反**を数えてしまう。
 * 「見えているか」ではなく「開き終わったか」で待つ。
 */
async function settled(page: Page, testId: string): Promise<void> {
    await expect
        .poll(() =>
            page.evaluate((id: string) => {
                const element = document.querySelector(`[data-testid="${id}"]`);

                return element ? getComputedStyle(element).opacity : '0';
            }, testId),
        )
        .toBe('1');
}

/** 管理画面から 1 件作る（正規の経路。裏から差し込まない） */
async function createPost(page: Page, title: string): Promise<void> {
    await page.goto('/admin/post/create');
    await page.getByRole('textbox', { name: 'タイトル' }).fill(title);
    // スラッグは必須。題名が日本語だと自動生成できないので明示する
    await page
        .locator('#slug')
        .fill(`e2e-${Date.now()}-${Math.floor(Math.random() * 1000)}`);

    // ステータスも必須。既定では選ばれていない（native select ではないので開いて選ぶ）
    await page.locator('#status').click();
    await page.getByRole('option', { name: '下書き' }).click();

    await page.getByRole('button', { name: '保存' }).click();
    await page.waitForURL(/\/admin\/post$/);
}

/**
 * critical / serious だけを返す（既存 spec と同じ作法）。
 *
 * 落ちたときに **どの要素か**が分かるよう、規則名だけでなく位置も添える。
 */
async function blocking(builder: AxeBuilder): Promise<string[]> {
    const results = await builder
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    return results.violations
        .filter((violation) =>
            ['critical', 'serious'].includes(violation.impact ?? ''),
        )
        .flatMap((violation) =>
            violation.nodes.map(
                (node) => `${violation.id}: ${node.target.join(' ')}`,
            ),
        );
}

test('一覧を切り替えて、ゴミ箱へ移して戻せる', async ({ page }) => {
    await login(page);
    await createPost(page, 'のこる記事');

    await expect(page.getByTestId('state-active')).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByTestId('row-trash')).toBeVisible();

    // 確認はサーバーが出す（ブラウザの confirm は使わない）
    await page.getByTestId('row-trash').click();

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('元に戻せます');

    /*
     * **1 枚だけ。** flash は router 全体へ届くので、確認を待っていない
     * （履歴側の）instance まで同じ内容を拾うと、同じダイアログが 2 枚出て、
     * 片方の承認が別の操作へ token を渡してしまう。
     */
    await expect(dialog).toHaveCount(1);

    await dialog.getByTestId('operation-confirm-button').click();

    // 通常一覧から消える
    await expect(page.getByTestId('empty-state')).toContainText('まだ');

    // ゴミ箱には在る
    await page.getByTestId('state-trashed').click();
    await expect(page.getByTestId('state-trashed')).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByTestId('trash-note')).toBeVisible();
    await expect(page.getByText('のこる記事')).toBeVisible();

    // ゴミ箱では編集も公開も出さない
    await expect(page.getByRole('link', { name: '編集' })).toHaveCount(0);
    await expect(page.getByTestId('row-public-link')).toHaveCount(0);
    await expect(page.getByTestId('row-trash')).toHaveCount(0);

    // 戻すのに確認は要らない。戻した先（通常の一覧）へ着く
    await page.getByTestId('row-restore').click();
    await expect(page.getByTestId('state-active')).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByText('のこる記事')).toBeVisible();

    // ゴミ箱は空になっている
    await page.getByTestId('state-trashed').click();
    await expect(page.getByTestId('empty-state')).toContainText(
        'ゴミ箱は空です',
    );
});

test('キーボードだけでゴミ箱へ移して戻せる', async ({ page }) => {
    await login(page);
    await createPost(page, 'キーボード操作');

    const trash = page.getByTestId('row-trash');
    await trash.focus();
    await expect(trash).toBeFocused();
    await page.keyboard.press('Enter');

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();

    // ダイアログは Escape で閉じられる
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await trash.focus();
    await page.keyboard.press('Enter');
    await expect(dialog).toBeVisible();

    const confirm = dialog.getByTestId('operation-confirm-button');
    await confirm.focus();
    await expect(confirm).toBeFocused();
    await page.keyboard.press('Enter');

    /*
     * **移し終わるまで待つ。** ここで次の画面へ進むと、Inertia が
     * 実行中の要求を打ち切ってしまい、移したはずのものが残る。
     */
    await expect(page.getByTestId('empty-state')).toContainText('まだ');

    await page.getByTestId('state-trashed').focus();
    await page.keyboard.press('Enter');

    const restore = page.getByTestId('row-restore');
    await restore.focus();
    await expect(restore).toBeFocused();
    await page.keyboard.press('Enter');

    // 戻すと通常の一覧へ着く
    await expect(page.getByTestId('state-active')).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByText('キーボード操作')).toBeVisible();
});

test('ゴミ箱の投稿を開いても編集フォームが出ない', async ({ page }) => {
    await login(page);
    await createPost(page, '直接ひらく');

    await page.getByTestId('row-trash').click();
    await page
        .getByTestId('operation-confirmation')
        .getByTestId('operation-confirm-button')
        .click();

    await page.getByTestId('state-trashed').click();
    const id = await page
        .getByTestId('row-history')
        .getAttribute('data-testid');
    expect(id).not.toBeNull();

    // 編集 URL を直接叩く
    const entryId = artisan(
        'echo \\Illuminate\\Support\\Facades\\DB::table("post")->value("id");',
    ).trim();

    await page.goto(`/admin/post/${entryId}/edit`);

    await expect(page.getByTestId('trashed-notice')).toBeVisible();
    await expect(page.getByTestId('trashed-restore')).toBeVisible();
    await expect(page.getByTestId('trashed-history')).toBeVisible();

    // 入力欄も保存も公開も無い
    await expect(page.locator('form input[name="title"]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: '保存' })).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'ゴミ箱へ移す' }),
    ).toHaveCount(0);
});

test('履歴が読めて、過去の版へ戻せる', async ({ page }) => {
    await login(page);
    await createPost(page, '版 1');

    // 内容を変えて版を増やす
    await page.getByRole('link', { name: '版 1' }).click();
    await page.getByRole('textbox', { name: 'タイトル' }).fill('版 2');
    await page.getByRole('button', { name: '保存' }).click();
    await page.waitForURL(/\/admin\/post$/);

    await page.getByTestId('row-history').click();

    const history = page.getByTestId('revision-history');
    await expect(history).toBeVisible();
    await expect(history.getByTestId('history-list')).toBeVisible();

    // 最新は「現在」として示し、戻す導線は出さない
    await expect(history.getByText('現在')).toBeVisible();
    await expect(history.getByTestId('history-restore-2')).toHaveCount(0);

    await history.getByTestId('history-restore-1').click();

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();
    // 影響は省略せず出す
    await expect(dialog).toContainText('過去の版は変わりません');

    await dialog.getByTestId('operation-confirm-button').click();

    await expect(page.getByRole('link', { name: '版 1' })).toBeVisible();
});

test('履歴に種別と実行者が出る', async ({ page }) => {
    await login(page);
    await createPost(page, 'メタ情報');

    /*
     * 版 1 は画面から作ったので manual / 管理画面 / 実行者あり。
     * そこへ **別の種類**の版を 2 つ足す（自動保存はまだ書かれないので直接入れる）。
     *
     * ・版 2 = 読めない記録。metadata だけは出ること
     * ・版 3 = 知らない種別。捨てず、かつ HTML として解釈しないこと
     */
    const actorId = artisan(
        'echo \\App\\Models\\User::query()->orderBy("id")->value("id");',
    ).trim();

    artisan(`
        $base = \\Illuminate\\Support\\Facades\\DB::table("content_entry_revisions")
            ->where("content_type", "post")->orderByDesc("revision")->first();
        \\Illuminate\\Support\\Facades\\DB::table("content_entry_revisions")->insert([
            ["content_type" => "post", "content_id" => $base->content_id, "revision" => 2,
             // MySQL の json 列には壊れた文字列を入れられない。
             // JSON としては正しいが **object ではない**値を入れて「読めない記録」を作る
             "schema_fingerprint" => $base->schema_fingerprint, "snapshot" => '"読めない記録"',
             "reason" => "update", "kind" => "autosave", "created_interface" => "cli",
             "created_by" => null, "created_at" => now()],
            ["content_type" => "post", "content_id" => $base->content_id, "revision" => 3,
             "schema_fingerprint" => $base->schema_fingerprint, "snapshot" => $base->snapshot,
             // 列は 16 文字までなので、収まる範囲で **タグに見える値**を入れる
             "reason" => "update", "kind" => "<img src=x>",
             "created_interface" => "mcp", "created_by" => null, "created_at" => now()],
        ]);
    `);

    await page.goto('/admin/post');
    await page.getByTestId('row-history').click();

    const history = page.getByTestId('revision-history');
    await expect(history).toBeVisible();

    // 既知の種別と、記録されている実行者
    await expect(history.getByTestId('history-kind-1')).toHaveText('手動');
    await expect(history.getByTestId('history-actor-1')).toHaveText(
        `利用者 #${actorId}`,
    );

    // 実行者の記録が無い版。「システム」と言い切らない
    await expect(history.getByTestId('history-kind-2')).toHaveText('自動保存');
    await expect(history.getByTestId('history-actor-2')).toHaveText(
        '実行者の記録なし',
    );

    // 読めない版も metadata は出す。ただし戻す導線は出さない
    await expect(history.getByTestId('history-unreadable')).toBeVisible();
    await expect(history.getByTestId('history-restore-2')).toHaveCount(0);

    // 知らない種別は捨てない。そして **文字として**出る
    await expect(history.getByTestId('history-kind-3')).toHaveText(
        '<img src=x>',
    );
    await expect(history.locator('img')).toHaveCount(0);

    // 最新（版 3）は戻す対象にしない
    await expect(history.getByTestId('history-restore-3')).toHaveCount(0);
});

test('ゴミ箱の検索結果はページを送っても絞り込みが残る', async ({ page }) => {
    await login(page);

    // 21 件で 2 ページ。1 件だけ検索に合わない
    artisan(`
        $rows = [];
        for ($i = 0; $i < 21; $i++) {
            $rows[] = ["title" => "りんご {$i}", "slug" => "ringo-{$i}", "body" => "<p>本文</p>",
                "status" => "draft", "lock_version" => 0, "deleted_at" => now(),
                "created_at" => now(), "updated_at" => now()];
        }
        $rows[] = ["title" => "みかん", "slug" => "mikan", "body" => "<p>本文</p>",
            "status" => "draft", "lock_version" => 0, "deleted_at" => now(),
                "created_at" => now(), "updated_at" => now()];
        \\Illuminate\\Support\\Facades\\DB::table("post")->insert($rows);
    `);

    await page.goto(
        `/admin/post?state=trashed&q=${encodeURIComponent('りんご')}`,
    );
    await expect(page.getByTestId('state-trashed')).toHaveAttribute(
        'aria-selected',
        'true',
    );

    // 2 ページ目へ進んでも、ゴミ箱のままで検索も効いたまま
    await page.getByRole('link', { name: '2', exact: true }).click();
    await page.waitForURL(/page=2/);

    await expect(page).toHaveURL(/state=trashed/);
    await expect(page).toHaveURL(/q=/);
    await expect(page.getByTestId('state-trashed')).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page.getByTestId('row-restore')).toHaveCount(1);
    await expect(page.getByText('みかん')).toHaveCount(0);
});

test('履歴が無くても画面は落ちない', async ({ page }) => {
    await login(page);
    await createPost(page, '履歴を消す');

    artisan(
        '\\Illuminate\\Support\\Facades\\DB::table("content_entry_revisions")->delete();',
    );

    await page.reload();
    await page.getByTestId('row-history').click();

    await expect(page.getByTestId('history-empty')).toBeVisible();
});

test('題名に仕込んだ script は文字として出る', async ({ page }) => {
    await login(page);
    await createPost(page, XSS_TITLE);

    await expect(page.getByText(XSS_TITLE)).toBeVisible();
    expect(
        await page.evaluate(() => (window as { __xss?: boolean }).__xss),
    ).toBeUndefined();
});

test('ゴミ箱と履歴の画面に a11y の critical / serious が無い', async ({
    page,
}) => {
    await login(page);
    await createPost(page, 'a11y 確認');

    await page.goto('/admin/post?state=trashed');

    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);

    await page.goto('/admin/post');
    await page.getByTestId('row-history').click();
    await expect(page.getByTestId('revision-history')).toBeVisible();
    await settled(page, 'revision-history');

    /*
     * 履歴は modal なので **中だけ**を見る。開いている間、背後の一覧は
     * 半透明の幕（bg-black/80）越しになり、axe はその混色を実際の文字色として測る。
     * 幕の下の見えない文字の contrast を数えても、履歴が読めるかは分からない。
     */
    expect(
        await blocking(
            new AxeBuilder({ page }).include(
                '[data-testid="revision-history"]',
            ),
        ),
    ).toEqual([]);
});

test('狭い画面でも操作が欠けない', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
    await login(page);
    await createPost(page, 'スマートフォン');

    await expect(page.getByTestId('state-trashed')).toBeVisible();
    await expect(page.getByTestId('row-trash')).toBeVisible();
    await expect(page.getByTestId('row-history')).toBeVisible();

    // 画面ごと横へはみ出さない
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth >
            document.documentElement.clientWidth + 1,
    );

    expect(overflow).toBe(false);
});
