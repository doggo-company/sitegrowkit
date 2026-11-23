import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * メニューが **画面として成立しているか**（Phase 9.9 / STAGE 22E の受入）。
 *
 * Feature テストは経路・認可・確認・楽観ロックを担保する。ここで見たいのはそれではなく、
 * 実際にログインして組み替えられるか — **キーボードだけで**並べ替えと階層化ができるか、
 * 保存した内容が公開ページに出るか、slug を変えたら URL も変わるか、
 * リンク先をゴミ箱へ入れたら導線が消えるか、仕込んだ script が文字として出るか、まで。
 *
 * 標準スイートへは入れない。この spec はメニューと投稿を書き換える。
 *
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8188 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/menus.isolated.spec.ts
 * ```
 */

/** 表示名へ仕込む script。**文字として出る**ことを確かめるための餌 */
const XSS_TITLE = '<script>window.__xss = true;</script>導線';

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

/** 入力行の増減では保存完了を判定しない。PATCH の応答まで待ってから離れる。 */
async function saveMenu(page: Page, keyboard = false): Promise<void> {
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().endsWith('/admin/menus/header') &&
                response.request().method() === 'PATCH' &&
                response.status() === 303,
        ),
        keyboard
            ? page.keyboard.press('Enter')
            : page.getByTestId('menu-save').click(),
    ]);
}

/** 開き終わるまで待つ（fade-in の途中で axe を回すと偽の contrast 違反が出る） */
async function settled(page: Page, selector: string): Promise<void> {
    await expect
        .poll(() =>
            page.evaluate((css: string) => {
                const element = document.querySelector(css);

                return element ? getComputedStyle(element).opacity : '0';
            }, selector),
        )
        .toBe('1');
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
 * 公開面で `layouts/public.blade.php` を使う URL。
 *
 * 生成物の `/` と `/about` は**取り込み済みテーマ**の layout を使っており、
 * そちらは provenance を壊さないため 9.9 では触っていない（SPEC §A.2・§H.3）。
 * データから導線を出しているのは差し替えた layout のほうなので、そこで見る。
 */
const PUBLIC_URL = '/blog';

/** `post` を公開できる型にする（公開スラッグと public フラグの両方が要る） */
function publishPostType(): void {
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("content_types")->updateOrInsert(
            ["content_type_id" => "post"],
            ["status" => "active", "lifecycle" => "ready", "public_slug" => "blog",
             "registration" => json_encode(["public" => true]),
             "menu_order" => 0, "lock_version" => 0, "created_at" => now(), "updated_at" => now()],
        );
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

    // 毎回同じ地点から始める（seeder が入れた既定の 2 本へ戻す）
    artisan(`
        \\Illuminate\\Support\\Facades\\DB::table("menu_items")->delete();
        \\Illuminate\\Support\\Facades\\DB::table("menus")->delete();
        \\Illuminate\\Support\\Facades\\DB::table("post")->delete();
        (new \\Database\\Seeders\\MenuSeeder)->run();
    `);

    publishPostType();
});

test('既定のメニューが公開ページに出る', async ({ page }) => {
    // 生成直後から導線がある（空で始めない — OG11）
    await page.goto(PUBLIC_URL);

    const nav = page.locator('nav.site-nav');

    await expect(nav).toBeVisible();
    await expect(nav).toHaveAttribute('aria-label', 'メイン');
    await expect(
        nav.getByRole('link', { name: '私たちについて' }),
    ).toBeVisible();
    await expect(nav.getByRole('link', { name: 'お問い合わせ' })).toBeVisible();
});

test('管理画面から項目を足すと公開ページに出る', async ({ page }) => {
    await login(page);
    await page.goto('/admin/menus');

    await expect(page.getByTestId('menu-row')).toHaveCount(2);

    await page.getByRole('link', { name: 'ヘッダー' }).click();
    await page.waitForURL(/\/admin\/menus\/header$/);

    const before = await page.getByTestId('menu-item').count();

    await page.getByTestId('menu-add').click();

    const last = before;

    // URL を直接指定（既定の種類）
    await page
        .getByLabel(`${last + 1} 番目の項目の URL`)
        .fill('https://example.com/added');
    await page.getByLabel(`${last + 1} 番目の項目の表示名`).fill('足した導線');

    await saveMenu(page);
    await expect(page.getByTestId('menu-item')).toHaveCount(before + 1);

    await page.goto(PUBLIC_URL);
    await expect(page.getByRole('link', { name: '足した導線' })).toBeVisible();
});

test('キーボードだけで並べ替えと階層化ができる', async ({ page }) => {
    await login(page);
    await page.goto('/admin/menus/header');

    const titleOf = async (index: number): Promise<string> =>
        (
            await page
                .getByLabel(`${index + 1} 番目の項目の表示名`)
                .inputValue()
        ).trim();

    const first = await titleOf(0);
    const second = await titleOf(1);

    // 「下へ」を focus して Enter（マウスを使わない）
    const down = page.getByTestId('menu-down-0');
    await down.focus();
    await expect(down).toBeFocused();
    await page.keyboard.press('Enter');

    expect(await titleOf(0)).toBe(second);
    expect(await titleOf(1)).toBe(first);

    // 2 番目を字下げして子にする
    const indent = page.getByTestId('menu-indent-1');
    await indent.focus();
    await expect(indent).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.getByTestId('menu-item-child')).toHaveCount(1);

    const save = page.getByTestId('menu-save');
    await save.focus();
    await saveMenu(page, true);

    // 保存された形が残っている（読み直しても子のまま）
    await page.reload();
    await expect(page.getByTestId('menu-item-child')).toHaveCount(1);
    expect(await titleOf(0)).toBe(second);
});

test('3 階層目は作れない', async ({ page }) => {
    await login(page);
    await page.goto('/admin/menus/header');

    // 1 番目を子にする
    await page.getByTestId('menu-indent-1').click();
    await expect(page.getByTestId('menu-item-child')).toHaveCount(1);

    // 子を持つ親は字下げできない（3 階層目を作らせない）
    await expect(page.getByTestId('menu-indent-0')).toBeDisabled();
});

test('slug を変えると導線の URL も変わる', async ({ page }) => {
    // 公開できる投稿タイプと投稿を用意する
    artisan(`
        $id = \\Illuminate\\Support\\Facades\\DB::table("post")->insertGetId([
            "title" => "記事", "slug" => "before", "body" => "<p>本文</p>", "status" => "published",
            "published_at" => now()->subDay(), "lock_version" => 0,
            "created_at" => now(), "updated_at" => now(),
        ]);
        $menu = \\Illuminate\\Support\\Facades\\DB::table("menus")->where("key", "header")->first();
        \\Illuminate\\Support\\Facades\\DB::table("menu_items")->insert([
            "menu_id" => $menu->id, "sort_order" => 99, "title" => "記事へ",
            "link_type" => "content", "content_type" => "post", "content_id" => $id,
            "target" => "_self", "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await page.goto(PUBLIC_URL);
    await expect(page.getByRole('link', { name: '記事へ' })).toHaveAttribute(
        'href',
        '/blog/before',
    );

    // **URL は保存されていない。** slug を変えれば導線も追随する
    artisan(
        '\\Illuminate\\Support\\Facades\\DB::table("post")->update(["slug" => "after"]);',
    );

    await page.goto(PUBLIC_URL);
    await expect(page.getByRole('link', { name: '記事へ' })).toHaveAttribute(
        'href',
        '/blog/after',
    );
});

test('リンク先をゴミ箱へ入れると導線が消え、戻せば戻る', async ({ page }) => {
    artisan(`
        $id = \\Illuminate\\Support\\Facades\\DB::table("post")->insertGetId([
            "title" => "消える記事", "slug" => "gone", "body" => "<p>本文</p>", "status" => "published",
            "published_at" => now()->subDay(), "lock_version" => 0,
            "created_at" => now(), "updated_at" => now(),
        ]);
        $menu = \\Illuminate\\Support\\Facades\\DB::table("menus")->where("key", "header")->first();
        \\Illuminate\\Support\\Facades\\DB::table("menu_items")->insert([
            "menu_id" => $menu->id, "sort_order" => 99, "title" => "消える導線",
            "link_type" => "content", "content_type" => "post", "content_id" => $id,
            "target" => "_self", "created_at" => now(), "updated_at" => now(),
        ]);
    `);

    await page.goto(PUBLIC_URL);
    await expect(page.getByRole('link', { name: '消える導線' })).toBeVisible();

    artisan(
        '\\Illuminate\\Support\\Facades\\DB::table("post")->update(["deleted_at" => now()]);',
    );

    await page.goto(PUBLIC_URL);
    await expect(page.getByRole('link', { name: '消える導線' })).toHaveCount(0);

    // 管理画面には残り、理由が出る（消すかどうかは編集者が決める）
    await login(page);
    await page.goto('/admin/menus/header');
    await expect(
        page.getByTestId('menu-unavailable').filter({ hasText: 'ゴミ箱' }),
    ).toHaveCount(1);

    artisan(
        '\\Illuminate\\Support\\Facades\\DB::table("post")->update(["deleted_at" => null]);',
    );

    await page.goto(PUBLIC_URL);
    await expect(page.getByRole('link', { name: '消える導線' })).toBeVisible();
});

test('テーマが使っているメニューは削除できない', async ({ page }) => {
    await login(page);
    await page.goto('/admin/menus');

    // 既定の 2 本は code 管理。削除の導線を出さない
    await expect(page.getByTestId('menu-code-managed')).toHaveCount(2);
    await expect(page.getByTestId('menu-delete')).toHaveCount(0);
});

test('表示名に仕込んだ script は文字として出る', async ({ page }) => {
    await login(page);
    await page.goto('/admin/menus/header');

    const index = await page.getByTestId('menu-item').count();

    await page.getByTestId('menu-add').click();
    await page
        .getByLabel(`${index + 1} 番目の項目の URL`)
        .fill('https://example.com/xss');
    await page.getByLabel(`${index + 1} 番目の項目の表示名`).fill(XSS_TITLE);
    await saveMenu(page);
    await expect(page.getByTestId('menu-item')).toHaveCount(index + 1);

    await page.goto(PUBLIC_URL);

    await expect(page.getByText(XSS_TITLE)).toBeVisible();
    expect(
        await page.evaluate(() => (window as { __xss?: boolean }).__xss),
    ).toBeUndefined();
});

test('危険な URL は保存できない', async ({ page }) => {
    await login(page);
    await page.goto('/admin/menus/header');

    const index = await page.getByTestId('menu-item').count();

    await page.getByTestId('menu-add').click();
    await page
        .getByLabel(`${index + 1} 番目の項目の URL`)
        .fill('javascript:alert(1)');
    await page.getByLabel(`${index + 1} 番目の項目の表示名`).fill('罠');
    await saveMenu(page);

    // 保存されない（画面に残ったまま）
    await page.reload();
    await expect(page.getByTestId('menu-item')).toHaveCount(index);
});

test('メニューの画面に a11y の critical / serious が無い', async ({ page }) => {
    await login(page);

    await page.goto('/admin/menus');
    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);

    await page.goto('/admin/menus/header');
    await settled(page, '[data-testid="menu-items"]');
    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);

    // 公開面も見る（nav は landmark なので壊すと影響が大きい）
    await page.goto(PUBLIC_URL);
    expect(await blocking(new AxeBuilder({ page }))).toEqual([]);
});

test('狭い画面でも操作が欠けない', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
    await login(page);
    await page.goto('/admin/menus/header');

    await expect(page.getByTestId('menu-add')).toBeVisible();
    await expect(page.getByTestId('menu-save')).toBeVisible();
    await expect(page.getByTestId('menu-up-0')).toBeVisible();
    await expect(page.getByTestId('menu-down-0')).toBeVisible();

    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth >
            document.documentElement.clientWidth + 1,
    );

    expect(overflow).toBe(false);
});
