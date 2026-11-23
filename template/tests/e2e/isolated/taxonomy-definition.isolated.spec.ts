import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 分類の定義管理が **画面として成立しているか**（Phase 9.7 / STAGE 20D の受入）。
 *
 * Feature テストは経路・権限・CAS・監査を担保する。ここで見たいのはそれではなく、
 * 実際にログインして分類を作れるか、関連付けを外しても投稿に付いた分類が残るか、
 * 表示名に仕込んだ script が実行されないか、まで。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8182 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/taxonomy-definition.isolated.spec.ts
 * ```
 */

/** 表示名へ仕込む script。**文字として出る**ことを確かめるための餌 */
const XSS_LABEL = '<script>window.__xss = true;</script>タグ';

/** この spec が作る分類。毎回同じ地点から始めるため beforeEach で消す */
const KEY = 'spec_topic';

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

function row(page: Page, key: string) {
    return page.locator(`[data-taxonomy="${key}"]`);
}

test.beforeEach(() => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    // この spec が作ったものだけを片付ける（参照の逆順に落とす）。
    // `tinker --execute` は `use` 文を受け付けないので完全修飾で書く
    artisan(
        '$id = \\Illuminate\\Support\\Facades\\DB::table("taxonomies")->where("key", "' +
            KEY +
            '")->value("id");' +
            'if ($id) {' +
            '  \\Illuminate\\Support\\Facades\\DB::table("content_type_taxonomies")->where("taxonomy_id", $id)->delete();' +
            '  \\Illuminate\\Support\\Facades\\DB::table("taxonomy_term_assignments")->whereIn("term_id", \\Illuminate\\Support\\Facades\\DB::table("taxonomy_terms")->where("taxonomy_id", $id)->pluck("id"))->delete();' +
            '  \\Illuminate\\Support\\Facades\\DB::table("taxonomy_terms")->where("taxonomy_id", $id)->delete();' +
            '  \\Illuminate\\Support\\Facades\\DB::table("taxonomies")->where("id", $id)->delete();' +
            '}' +
            // この spec が作った投稿も片付ける（slug は一意なので残すと 2 回目が入らない）
            '\\Illuminate\\Support\\Facades\\DB::table("post")->where("slug", "with-term")->delete();',
    );
});

test('分類を作り、投稿タイプへ関連付け、外しても付いた分類は残る', async ({
    page,
}) => {
    await login(page);

    // 作る
    await page.goto('/admin/taxonomies/create');
    await page.getByTestId('taxonomy-key').fill(KEY);
    await page.getByTestId('taxonomy-label').fill(XSS_LABEL);
    await page.getByTestId('taxonomy-description').fill('受入で作った分類');
    await page.getByRole('button', { name: '作成する' }).click();

    await page.waitForURL(/\/admin\/taxonomies$/);

    // 表示名の script は **文字として**出る
    await expect(page.getByText(XSS_LABEL).first()).toBeVisible();
    expect(await page.evaluate(() => '__xss' in window)).toBe(false);

    await expect(row(page, KEY).getByTestId('taxonomy-status')).toContainText(
        '有効',
    );

    // 関連付ける
    await row(page, KEY).getByTestId('taxonomy-edit-link').click();
    await page.waitForURL(new RegExp(`/admin/taxonomies/${KEY}/edit$`));

    await page.getByTestId('taxonomy-attach-select').selectOption('post');
    await page.getByTestId('taxonomy-attach').click();

    await expect(
        page.locator(
            '[data-testid="taxonomy-association"][data-content-type="post"]',
        ),
    ).toBeVisible();

    // 投稿へこの分類を 1 件付ける（画面ではなく DB へ直接。ここで見たいのは detach の挙動）
    const assigned = artisan(
        '$tid = \\Illuminate\\Support\\Facades\\DB::table("taxonomies")->where("key", "' +
            KEY +
            '")->value("id");' +
            '$term = (string) \\Illuminate\\Support\\Str::ulid();' +
            '\\Illuminate\\Support\\Facades\\DB::table("taxonomy_terms")->insert(["id" => $term, "taxonomy_id" => $tid, "name" => "受入", "slug" => "acceptance", "sort_order" => 0, "created_at" => now(), "updated_at" => now()]);' +
            '$post = \\Illuminate\\Support\\Facades\\DB::table("post")->insertGetId(["title" => "分類つき", "slug" => "with-term", "status" => "published", "created_at" => now(), "updated_at" => now()]);' +
            '\\Illuminate\\Support\\Facades\\DB::table("taxonomy_term_assignments")->insert(["term_id" => $term, "content_type" => "post", "content_id" => $post, "sort_order" => 0, "created_at" => now()]);' +
            'echo \\Illuminate\\Support\\Facades\\DB::table("taxonomy_term_assignments")->count();',
    );

    expect(Number(assigned.trim())).toBeGreaterThan(0);

    // 外す（確認を通る）
    await page.reload();
    await page
        .locator(
            '[data-testid="taxonomy-association"][data-content-type="post"]',
        )
        .getByTestId('taxonomy-detach')
        .click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('削除されません');
    await dialog.getByTestId('operation-confirm-button').click();

    await expect(page.getByTestId('taxonomy-live')).toContainText(
        '関連付けを外しました',
    );

    // **付いている分類は 1 件も消えていない**
    const after = artisan(
        'echo \\Illuminate\\Support\\Facades\\DB::table("taxonomy_term_assignments")->count();',
    );

    expect(after.trim()).toBe(assigned.trim());
});

test('taxonomy-sync は管理画面で作った分類を書き換えない', async ({ page }) => {
    await login(page);

    await page.goto('/admin/taxonomies/create');
    await page.getByTestId('taxonomy-key').fill(KEY);
    await page.getByTestId('taxonomy-label').fill('同期しても残る');
    await page.getByRole('button', { name: '作成する' }).click();
    await page.waitForURL(/\/admin\/taxonomies$/);

    execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'agentcms:taxonomy-sync',
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    await page.reload();

    await expect(row(page, KEY)).toContainText('同期しても残る');
    await expect(row(page, KEY).getByTestId('taxonomy-status')).toContainText(
        '有効',
    );
});

test('分類の画面に a11y の critical / serious が無い', async ({ page }) => {
    await login(page);

    for (const path of ['/admin/taxonomies', '/admin/taxonomies/create']) {
        await page.goto(path);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        const blocking = results.violations.filter((violation) =>
            ['critical', 'serious'].includes(violation.impact ?? ''),
        );

        expect(
            blocking.map((violation) => `${path}: ${violation.id}`),
            JSON.stringify(blocking, null, 2),
        ).toEqual([]);
    }
});
