import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * コメントが **画面として成立しているか**（Phase 9.13 / STAGE 26F の受入）。
 *
 * Feature テストは二層 opt-in・spam・保持期間の中身を担保する。ここで見たいのは
 * それではなく、**型を ON にすると記事ページに欄が出て、書いたものが承認するまで
 * 出ないこと**、そして **承認すると出ること**まで。
 *
 * 標準スイートへは入れない。この spec は投稿とコメントを作っては消す。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8182 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/comments.isolated.spec.ts
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

/** critical / serious だけを、どの要素かも添えて返す */
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

/**
 * ボット確認が終わるのを待つ（9.12 と同じ理由）。
 *
 * Turnstile を設定してある project では、widget が token を作るまで送信できない。
 * **人も同じように待つ**ので、ここで待つのは操作の再現である。
 */
async function awaitBotCheck(page: Page): Promise<void> {
    if ((await page.locator('.cf-turnstile').count()) === 0) {
        return;
    }

    await expect
        .poll(
            async () =>
                await page.evaluate(() => {
                    const el = document.querySelector<HTMLInputElement>(
                        'input[name="cf-turnstile-response"]',
                    );

                    return el?.value ?? '';
                }),
            { timeout: 20000 },
        )
        .not.toBe('');
}

/** 公開した投稿タイプと記事を 1 つ用意する（コメントは既定 OFF） */
function seedPost(comments: boolean): void {
    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $db::table("content_types")->updateOrInsert(
            ["content_type_id" => "post"],
            [
                "comments_enabled" => ${comments ? 'true' : 'false'},
                "status" => "active",
                "lifecycle" => "ready",
                "public_slug" => "blog",
                "registration" => json_encode(["public" => true]),
                "lock_version" => 0,
                "created_at" => now(), "updated_at" => now(),
            ],
        );
        $db::table("post")->insert([
            "title" => "E2E の記事", "slug" => "e2e-post", "body" => "<p>本文</p>",
            "status" => "published", "published_at" => now(),
            "lock_version" => 0, "created_at" => now(), "updated_at" => now(),
        ]);
    `);
}

test.beforeEach(async () => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    // 毎回同じ地点から始める（返信 → 親 の順で消す）
    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $db::table("content_comments")->whereNotNull("parent_id")->delete();
        $db::table("content_comments")->delete();
        $db::table("content_comment_settings")->delete();
        $db::table("spam_blocks")->where("kind", "comment")->delete();
        $db::table("post")->where("slug", "e2e-post")->delete();
        $db::table("content_types")->where("content_type_id", "post")->delete();
    `);
});

test('型が OFF のあいだは記事ページにコメント欄が出ない', async ({ page }) => {
    seedPost(false);

    await page.goto('/blog/e2e-post');

    await expect(page.getByRole('heading', { name: 'E2E の記事' })).toBeVisible();
    // 「隠す」ではなく無い
    await expect(page.getByText('コメントを書く')).toHaveCount(0);
});

test('型を ON にすると欄が出て、承認するまで公開ページに出ない', async ({
    page,
}) => {
    seedPost(true);

    await page.goto('/blog/e2e-post');
    await expect(page.getByText('コメントを書く')).toBeVisible();

    await page.fill('#c-author-name', '山田太郎');
    await page.fill('#c-author-email', 'taro@example.test');
    await page.fill('#c-body', 'とても参考になりました');
    await awaitBotCheck(page);
    await page.getByRole('button', { name: 'コメントを送信' }).click();

    // 送信できたのに何も起きない、を作らない
    await expect(page.getByText('承認後に表示されます')).toBeVisible();
    await expect(page.getByText('とても参考になりました')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'コメント（0）' })).toBeVisible();

    // 承認すると出る
    await login(page);
    await page.goto('/admin/comments');

    await expect(page.getByTestId('comment-row')).toHaveCount(1);

    await page.getByTestId('comment-select').click();
    await page.getByTestId('comment-bulk-approve').click();

    /*
     * **承認が届くまで待つ。** すぐ次へ移ると、Inertia が飛ばしている最中の
     * 要求を中断してしまい、承認がサーバへ届かないことがある。
     * 一覧は「承認待ち」で絞ってあるので、承認できたら行が消える。
     */
    await expect(page.getByTestId('comment-empty')).toBeVisible();

    await page.goto('/blog/e2e-post');
    await expect(page.getByText('とても参考になりました')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'コメント（1）' })).toBeVisible();
});

test('記事ごとに受付を止めても、承認済みは出続ける', async ({ page }) => {
    seedPost(true);

    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $post = $db::table("post")->where("slug", "e2e-post")->first();
        $db::table("content_comments")->insert([
            "content_type" => "post", "content_id" => $post->id,
            "status" => "approved", "author_name" => "先客",
            "body" => "先に書いてあったコメント", "is_staff" => false,
            "approved_at" => now(), "created_at" => now(), "updated_at" => now(),
        ]);
        $db::table("content_comment_settings")->insert([
            "content_type" => "post", "content_id" => $post->id,
            "is_open" => false, "updated_at" => now(),
        ]);
    `);

    await page.goto('/blog/e2e-post');

    // 止まるのは新規受付だけ
    await expect(page.getByText('先に書いてあったコメント')).toBeVisible();
    await expect(page.getByText('受付を終了しました')).toBeVisible();
    await expect(page.getByText('コメントを書く')).toHaveCount(0);
});

test('管理画面から返信すると、公開ページに運営として出る', async ({ page }) => {
    seedPost(true);

    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $post = $db::table("post")->where("slug", "e2e-post")->first();
        $db::table("content_comments")->insert([
            "content_type" => "post", "content_id" => $post->id,
            "status" => "approved", "author_name" => "読者",
            "body" => "質問があります", "is_staff" => false,
            "approved_at" => now(), "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await login(page);
    await page.goto('/admin/comments?status=approved');

    await page.getByTestId('comment-row').getByRole('link').click();

    await page.getByTestId('comment-reply-body').fill('お答えします');
    await page.getByTestId('comment-reply-send').click();

    // 返信が届いたこと（入力欄が空に戻る）を見てから移る
    await expect(page.getByTestId('comment-reply-body')).toHaveValue('');

    await page.goto('/blog/e2e-post');

    await expect(page.getByText('お答えします')).toBeVisible();
    await expect(page.getByText('運営')).toBeVisible();
});

test('本文の HTML は解釈されない', async ({ page }) => {
    seedPost(true);

    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $post = $db::table("post")->where("slug", "e2e-post")->first();
        $db::table("content_comments")->insert([
            "content_type" => "post", "content_id" => $post->id,
            "status" => "approved", "author_name" => "読者",
            "body" => "<img src=x onerror=alert(1)>危ない文字列", "is_staff" => false,
            "approved_at" => now(), "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await page.goto('/blog/e2e-post');

    // タグとしてではなく、文字として出る
    await expect(page.getByText('危ない文字列')).toBeVisible();
    expect(await page.locator('.comments__body img').count()).toBe(0);
});

test('詳細から記事の受付を止められる', async ({ page }) => {
    seedPost(true);

    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $post = $db::table("post")->where("slug", "e2e-post")->first();
        $db::table("content_comments")->insert([
            "content_type" => "post", "content_id" => $post->id,
            "status" => "pending", "author_name" => "読者",
            "body" => "はじめまして", "is_staff" => false,
            "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await login(page);
    await page.goto('/admin/comments');
    await page.getByTestId('comment-row').getByRole('link').click();

    await page.getByTestId('entry-close').click();

    // 同じ理由で、設定が届いたこと（ボタンが無効になる）を見てから移る
    await expect(page.getByTestId('entry-close')).toBeDisabled();

    await page.goto('/blog/e2e-post');
    await expect(page.getByText('受付を終了しました')).toBeVisible();
});

test('キーボードだけでコメントを書ける', async ({ page }) => {
    seedPost(true);

    await page.goto('/blog/e2e-post');

    await page.locator('#c-author-name').focus();
    await page.keyboard.type('山田太郎');
    await page.keyboard.press('Tab');
    await page.keyboard.type('taro@example.test');
    await page.keyboard.press('Tab');
    await page.keyboard.type('キーボードだけで書きました');

    await awaitBotCheck(page);

    const submit = page.getByRole('button', { name: 'コメントを送信' });
    let reached = false;

    for (let i = 0; i < 12 && !reached; i++) {
        await page.keyboard.press('Tab');

        // 人には見えない項目は tabindex="-1"。Tab では絶対に触れない
        await expect(page.locator('#contact-url')).not.toBeFocused();

        reached = await submit.evaluate((el) => el === document.activeElement);
    }

    expect(reached).toBe(true);

    await page.keyboard.press('Enter');

    await expect(page.getByText('承認後に表示されます')).toBeVisible();
});

test('公開ページと管理画面に critical / serious の指摘が無い', async ({
    page,
}) => {
    seedPost(true);

    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $post = $db::table("post")->where("slug", "e2e-post")->first();
        $db::table("content_comments")->insert([
            "content_type" => "post", "content_id" => $post->id,
            "status" => "approved", "author_name" => "読者",
            "body" => "読みました", "is_staff" => false,
            "approved_at" => now(), "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await page.goto('/blog/e2e-post');

    expect(await blocking(new AxeBuilder({ page }).include('#comments'))).toEqual(
        [],
    );

    await login(page);
    await page.goto('/admin/comments?status=approved');

    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);

    await page.getByTestId('comment-row').getByRole('link').click();

    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);
});
