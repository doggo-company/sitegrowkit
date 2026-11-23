import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * フォームが **画面として成立しているか**（Phase 9.12 / STAGE 25E の受入）。
 *
 * Feature テストは送信順序・bot 対策・保持期間の中身を担保する。ここで見たいのは
 * それではなく、**管理画面で作ったフォームが公開面で実際に送れるか**、
 * 確認画面を経て届くか、届いたものが管理画面で読めるか、まで。
 *
 * 標準スイートへは入れない。この spec はフォームと受信を作っては消す。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8182 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/forms.isolated.spec.ts
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
 * ボット確認が終わるのを待つ。
 *
 * Turnstile を設定してある project では、widget が token を作るまで送信できない。
 * **人も同じように待つ**（確認欄にチェックが入るのを見てから押す）ので、
 * ここで待つのは「テストのための細工」ではなく、実際の操作の再現である。
 * 設定していない project では widget 自体が無いので、そのまま進む。
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

/** 項目の束と、それを使う公開中のフォームを 1 つ用意する */
function seedForm(): void {
    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $id = (string) \\Illuminate\\Support\\Str::ulid();
        $db::table("field_groups")->insert([
            "id" => $id,
            "key" => "e2e_form_fields",
            "label" => "お問い合わせの項目",
            "sort_order" => 0,
            "status" => "published",
            "fields" => json_encode([
                ["id" => (string) \\Illuminate\\Support\\Str::ulid(), "key" => "name", "type" => "text", "label" => "お名前", "required" => true],
                ["id" => (string) \\Illuminate\\Support\\Str::ulid(), "key" => "email", "type" => "email", "label" => "メールアドレス", "required" => true],
                ["id" => (string) \\Illuminate\\Support\\Str::ulid(), "key" => "body", "type" => "textarea", "label" => "ご相談内容"],
            ], JSON_UNESCAPED_UNICODE),
            "created_at" => now(), "updated_at" => now(),
        ]);
        $db::table("field_group_locations")->insert([
            "field_group_id" => $id, "kind" => "form", "value" => "e2e-contact",
        ]);
        $db::table("forms")->insert([
            "key" => "e2e-contact", "name" => "E2E お問い合わせ",
            "field_group_id" => $id, "status" => "published",
            "confirm_step" => 1, "submit_label" => "この内容で送信する",
            "autoreply_field" => "email",
            "lock_version" => 0, "created_at" => now(), "updated_at" => now(),
        ]);
    `);
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

    // 毎回同じ地点から始める（受信 → 弾いた記録 → フォーム → 束 の順で消す）
    artisan(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $db::table("form_submissions")->delete();
        $db::table("spam_blocks")->where("kind", "form")->delete();
        $db::table("forms")->delete();
        $db::table("field_group_locations")->where("kind", "form")->delete();
        $db::table("field_groups")->where("key", "e2e_form_fields")->delete();
    `);

    seedForm();
});

test('公開フォームは入力→確認→完了まで通り、届いた内容が管理画面で読める', async ({
    page,
}) => {
    await page.goto('/forms/e2e-contact');

    await expect(
        page.getByRole('heading', { name: 'E2E お問い合わせ' }),
    ).toBeVisible();

    await page.fill('#f-name', '山田太郎');
    await page.fill('#f-email', 'taro@example.test');
    await page.fill('#f-body', '見積もりをお願いします');
    await awaitBotCheck(page);
    await page.getByRole('button', { name: '確認画面へ' }).click();

    // 確認画面は「まだ送っていない」と分かる
    await expect(page.getByText('まだ送信は完了していません')).toBeVisible();
    await expect(page.getByText('山田太郎')).toBeVisible();

    await page.getByRole('button', { name: 'この内容で送信する' }).click();

    await expect(page).toHaveURL(/\/forms\/e2e-contact\/complete$/);
    await expect(page.getByText('送信しました')).toBeVisible();

    // 届いたものが管理画面で読める
    await login(page);
    await page.goto('/admin/forms/e2e-contact/submissions');

    await expect(page.getByTestId('submission-row')).toHaveCount(1);

    await page.getByTestId('submission-row').getByRole('link').click();

    // 届いたときの並びで出る（保存順ではない）
    await expect(page.getByTestId('submission-value').first()).toContainText(
        '山田太郎',
    );
});

// 識別子の手入力や HTML の貼り付けなしで、ページから問い合わせ受信まで到達できる。
test('ページ編集でフォームを選んで公開し、スマホから送った内容を受信できる', async ({
    page,
}) => {
    await login(page);
    const slug = `embedded-contact-${Date.now()}`;
    await page.goto('/admin/pages/create');
    await page.fill('#page-title', 'ご相談ページ');
    await page.fill('#page-slug', slug);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/pages/*/edit');
    await page.getByTestId('page-editor-link').click();
    await page.getByTestId('palette-section').click();
    await page.getByTestId('palette-form').click();
    await page.getByTestId('layer-form').click();
    await page
        .getByTestId('inspector-prop-formKey')
        .selectOption({ label: 'E2E お問い合わせ（受付中）' });
    const preview = page.frameLocator('[data-testid="editor-canvas"]');
    await expect(
        preview.getByRole('heading', { name: 'E2E お問い合わせ' }),
    ).toBeVisible();
    await expect(
        preview.getByRole('button', { name: '確認画面へ' }),
    ).toBeDisabled();
    await page.reload();
    await expect(
        preview.getByRole('heading', { name: 'E2E お問い合わせ' }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
    await page.getByTestId('page-publish-button').click();
    await expect(page.getByTestId('page-status-badge')).toHaveText('公開中');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/${slug}`);
    await page
        .getByLabel('お名前', { exact: false })
        .fill('ページ内フォームの利用者');
    await page
        .getByLabel('メールアドレス', { exact: false })
        .fill('embedded@example.test');
    await page
        .getByLabel('ご相談内容', { exact: true })
        .fill('スマホから相談します');
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(await blocking(new AxeBuilder({ page }).include('main'))).toEqual(
        [],
    );
    await awaitBotCheck(page);
    await page.getByRole('button', { name: '確認画面へ' }).click();
    await expect(page.getByText('まだ送信は完了していません')).toBeVisible();
    await page.getByRole('button', { name: 'この内容で送信する' }).click();
    await expect(page).toHaveURL(/\/forms\/e2e-contact\/complete$/);
    await page.goto('/admin/forms/e2e-contact/submissions');
    await expect(page.getByTestId('submission-row')).toHaveCount(1);
    await page.getByTestId('submission-row').getByRole('link').click();
    await expect(page.getByTestId('submission-value').first()).toContainText(
        'ページ内フォームの利用者',
    );
    await expect(page.getByText('スマホから相談します')).toBeVisible();
});

test('確認画面から戻ると入力が残る', async ({ page }) => {
    await page.goto('/forms/e2e-contact');

    await page.fill('#f-name', '鈴木花子');
    await page.fill('#f-email', 'hanako@example.test');
    await awaitBotCheck(page);
    await page.getByRole('button', { name: '確認画面へ' }).click();

    await page.getByRole('button', { name: '入力へ戻る' }).click();

    // 戻ったのに空だと、長い相談文を書き直させることになる
    await expect(page.locator('#f-name')).toHaveValue('鈴木花子');
});

test('必須が空なら入力画面に理由が出る', async ({ page }) => {
    await page.goto('/forms/e2e-contact');

    await page.fill('#f-email', 'taro@example.test');
    await page.getByRole('button', { name: '確認画面へ' }).click();

    await expect(page).toHaveURL(/\/forms\/e2e-contact$/);
    // 標準テーマ・取り込みテーマの双方で、入力欄と読み上げる理由を確かめる。
    await expect(page.locator('#f-name')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(
        page.getByRole('alert').filter({ hasText: 'お名前は必須です' }),
    ).toBeVisible();
    // 書いたものは消えない
    await expect(page.locator('#f-email')).toHaveValue('taro@example.test');
});

test('下書きのフォームは外から見えず、受付終了は理由が出る', async ({
    page,
}) => {
    artisan(
        `\\Illuminate\\Support\\Facades\\DB::table("forms")->update(["status" => "draft"]);`,
    );

    let response = await page.goto('/forms/e2e-contact');
    expect(response?.status()).toBe(404);

    artisan(
        `\\Illuminate\\Support\\Facades\\DB::table("forms")->update(["status" => "closed"]);`,
    );

    response = await page.goto('/forms/e2e-contact');

    // 配ってしまった URL を無言の 404 にしない
    expect(response?.status()).toBe(200);
    await expect(page.getByText('受付を終了しました')).toBeVisible();
});

test('管理画面からフォームを作れて、公開 URL がその場で分かる', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/forms');

    await page.getByTestId('form-create').click();

    await page.getByTestId('form-name').fill('資料請求');
    await page.getByTestId('form-key').fill('shiryou');

    await expect(page.getByText('/forms/shiryou')).toBeVisible();

    await page.getByTestId('form-save').click();

    await expect(page).toHaveURL(/\/admin\/forms\/shiryou$/);
    // 識別子は作ったあと変えられない（配った URL が死ぬ）
    await expect(page.getByTestId('form-key')).toBeDisabled();
});

test('公開できない理由が一覧に出る', async ({ page }) => {
    // 束を下書きへ戻す = 項目が 1 つも出ない（ADR-0015 の fail closed）
    artisan(
        `\\Illuminate\\Support\\Facades\\DB::table("field_groups")->where("key", "e2e_form_fields")->update(["status" => "draft"]);`,
    );

    await login(page);
    await page.goto('/admin/forms');

    // 「公開ボタンを押したら断られる」で気づかせない
    await expect(page.getByTestId('form-problem')).toBeVisible();
});

test('受信が残っているフォームには削除の導線が出ない', async ({ page }) => {
    await page.goto('/forms/e2e-contact');
    await page.fill('#f-name', '山田太郎');
    await page.fill('#f-email', 'taro@example.test');
    await awaitBotCheck(page);
    await page.getByRole('button', { name: '確認画面へ' }).click();
    await page.getByRole('button', { name: 'この内容で送信する' }).click();
    await expect(page).toHaveURL(/\/forms\/e2e-contact\/complete$/);

    await login(page);
    await page.goto('/admin/forms');

    await expect(page.getByTestId('form-undeletable')).toBeVisible();
    await expect(page.getByTestId('form-delete')).toHaveCount(0);
});

test('キーボードだけで送信できる', async ({ page }) => {
    await page.goto('/forms/e2e-contact');

    await page.locator('#f-name').focus();
    await page.keyboard.type('山田太郎');
    await page.keyboard.press('Tab');
    await page.keyboard.type('taro@example.test');
    await page.keyboard.press('Tab');
    await page.keyboard.type('キーボードだけで書きました');

    await awaitBotCheck(page);

    // Tab で送信ボタンまで辿り着ける（途中で行き止まりにならない）
    const submit = page.getByRole('button', { name: '確認画面へ' });
    let reached = false;

    for (let i = 0; i < 12 && !reached; i++) {
        await page.keyboard.press('Tab');

        // 人には見えない項目は tabindex="-1"。Tab では絶対に触れない
        await expect(page.locator('#contact-url')).not.toBeFocused();

        reached = await submit.evaluate((el) => el === document.activeElement);
    }

    expect(reached).toBe(true);

    await page.keyboard.press('Enter');

    await expect(page.getByText('まだ送信は完了していません')).toBeVisible();
});

test('公開フォームと管理画面に critical / serious の指摘が無い', async ({
    page,
}) => {
    await page.goto('/forms/e2e-contact');

    expect(await blocking(new AxeBuilder({ page }).include('main'))).toEqual(
        [],
    );

    await page.fill('#f-name', '山田太郎');
    await page.fill('#f-email', 'taro@example.test');
    await page.getByRole('button', { name: '確認画面へ' }).click();

    expect(await blocking(new AxeBuilder({ page }).include('main'))).toEqual(
        [],
    );

    await login(page);
    await page.goto('/admin/forms');

    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);
});
