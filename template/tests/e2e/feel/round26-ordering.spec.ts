import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test.use({
    trace: 'off',
    storageState:
        process.env.FEEL_AUTH_STATE ?? 'tests/e2e/.auth/feel-admin.json',
});

function tinker(code: string): string {
    try {
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
            {
                cwd: process.env.AGENTCMS_FEEL_PROJECT ?? process.cwd(),
                encoding: 'utf8',
                timeout: 30000,
                stdio: ['pipe', 'pipe', 'pipe'],
            },
        ).trim();
    } catch {
        throw new Error('掲載順の試験準備に失敗しました（artisan）');
    }
}

function phpString(value: unknown): string {
    return (
        "'" +
        JSON.stringify(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'") +
        "'"
    );
}

for (const width of [1366, 390]) {
    test(`記事の上下移動が公開newsと最近のお知らせに反映される ${width}`, async ({
        page,
    }, info) => {
        await page.setViewportSize({ width, height: 844 });
        const marker = `掲載順${Date.now()}${width}`;
        const before = JSON.parse(
            tinker(
                `echo json_encode(['orders' => DB::table('post')->pluck('sort_order', 'id')->all(), 'setting' => DB::table('content_types')->where('content_type_id', 'post')->first(['public_slug', 'registration'])]);`,
            ),
        ) as {
            orders: Record<string, number>;
            setting: { public_slug: string | null; registration: string };
        };
        const titles = [`${marker}古い記事`, `${marker}新しい記事`];

        try {
            tinker(
                `DB::table('post')->update(['sort_order' => DB::raw('sort_order + 2')]); DB::table('content_types')->where('content_type_id', 'post')->update(['public_slug' => 'news', 'registration' => json_encode(['public' => true, 'has_archive' => true, 'publicly_queryable' => true])]); foreach (json_decode(${phpString(titles)}, true) as $i => $title) { DB::table('post')->insert(['title' => $title, 'slug' => '${marker}-'.$i, 'body' => '<p>掲載順確認</p>', 'status' => 'published', 'published_at' => now()->subSeconds(2 - $i), 'sort_order' => 1 - $i, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]); }`,
            );
            await page.goto('/admin/post');
            await expect(page.getByTestId('reorder-note')).toContainText(
                '公開ページにも反映されます',
            );
            const rows = page.locator('tbody tr').filter({ hasText: marker });
            await expect(rows).toHaveCount(2);
            await expect(rows.nth(0)).toContainText(titles[1]);
            const response = page.waitForResponse(
                (r) =>
                    r.url().includes('/reorder') &&
                    r.request().method() === 'POST',
            );
            await page
                .getByRole('button', {
                    name: `${titles[1]}を1つ下へ`,
                    exact: true,
                })
                .click();
            expect((await response).status()).toBe(200);
            await expect(rows.nth(0)).toContainText(titles[0]);
            await page.reload();
            await expect(rows.nth(0)).toContainText(titles[0]);
            await page.screenshot({
                path: info.outputPath('admin-order.png'),
                fullPage: true,
            });
            expect((await page.goto('/news'))?.status()).toBe(200);
            const publicTitles = page
                .locator('.news-item a')
                .filter({ hasText: marker });
            await page.screenshot({
                path: info.outputPath('public-before-assert.png'),
                fullPage: true,
            });
            await expect(publicTitles).toHaveText([titles[0], titles[1]]);
            await page.screenshot({
                path: info.outputPath('public-order.png'),
                fullPage: true,
            });
            await page.goto('/dashboard');
            const recent = page.locator('li').filter({ hasText: marker });
            await expect(recent).toHaveCount(2);
            await expect(recent.nth(0)).toContainText(titles[0]);
            await expect(recent.nth(1)).toContainText(titles[1]);
            await page.screenshot({
                path: info.outputPath('dashboard-order.png'),
                fullPage: true,
            });
            // 同じ掲載順でも、後から作った記事を古い公開日にしたら日付の順を守る。
            tinker(
                `foreach (json_decode(${phpString(titles)}, true) as $i => $title) { DB::table('post')->where('title', $title)->update(['sort_order' => 0, 'published_at' => now()->subSeconds(1 + $i)]); }`,
            );
            expect((await page.goto('/news'))?.status()).toBe(200);
            await expect(
                page.locator('.news-item a').filter({ hasText: marker }),
            ).toHaveText([titles[0], titles[1]]);
            await page.screenshot({
                path: info.outputPath('public-tie-order.png'),
                fullPage: true,
            });
            await page.goto('/dashboard');
            await expect(recent.nth(0)).toContainText(titles[0]);
            await expect(recent.nth(1)).toContainText(titles[1]);
            await page.screenshot({
                path: info.outputPath('dashboard-tie-order.png'),
                fullPage: true,
            });
        } finally {
            tinker(
                `DB::table('post')->whereIn('title', json_decode(${phpString(titles)}, true))->delete(); foreach (json_decode(${phpString(before.orders)}, true) as $id => $order) { DB::table('post')->where('id', $id)->update(['sort_order' => $order]); } DB::table('content_types')->where('content_type_id', 'post')->update(json_decode(${phpString(before.setting)}, true));`,
            );
        }
    });
}
