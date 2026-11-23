import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * 自動アクセシビリティ検査（Phase 8.5-F / Workstream C）。
 *
 * 参照: デジタル庁「ウェブアクセシビリティ導入ガイドブック」/ JIS X 8341-3:2016。
 * ただし **automated test だけで規格準拠とは判定しない**。ここで見るのは
 * 機械的に検出できる違反（critical / serious）を 0 に保つことだけ。
 */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

type Violation = {
    id: string;
    impact?: string | null;
    nodes: unknown[];
};

async function scan(page: Page): Promise<Violation[]> {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

    return results.violations as Violation[];
}

function describe(violations: Violation[]): string {
    return violations
        .map(
            (v) => `${v.impact ?? 'unknown'}: ${v.id} (${v.nodes.length} 箇所)`,
        )
        .join('\n');
}

/**
 * toast のフェードインが終わるまで待つ。
 *
 * axe は要素の **合成後の見た目**で contrast を計算する。
 * フェード途中（opacity 0.18 など）に測ると、実際の文字色が #0a0a0a
 * （白地に対し 19:1）でも背景と混ざって #7c7c7c 相当になり、
 * 「contrast 4.17 で不足」という**その瞬間だけの違反**が出る。
 *
 * 色トークンを濃くしても opacity 0.18 の合成は救えない。
 * 見るべきは **落ち着いた状態の UI** なので、ここで待つ。
 */
async function settleToast(page: Page): Promise<void> {
    await expect
        .poll(async () =>
            page.evaluate(() => {
                const toast = document.querySelector('li[data-sonner-toast]');

                // 消えたあとは待つものが無い
                return toast ? Number(getComputedStyle(toast).opacity) : 1;
            }),
        )
        .toBe(1);
}

/** critical / serious は 0 を要件とする（moderate / minor は記録のみ） */
async function expectNoSeriousViolations(page: Page): Promise<void> {
    const violations = await scan(page);
    const blocking = violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious',
    );

    expect(blocking, describe(blocking)).toEqual([]);
}

test.describe('アクセシビリティ — 公開サイト', () => {
    const publicRoutes = [
        '/',
        '/news',
        '/works',
        '/about',
        '/service',
        '/contact',
    ];

    for (const route of publicRoutes) {
        test(`${route} に critical / serious の違反がない`, async ({
            page,
        }) => {
            await page.goto(route);
            await expectNoSeriousViolations(page);
        });
    }

    test('news 詳細に critical / serious の違反がない', async ({ page }) => {
        await page.goto('/news');
        const first = page.locator('main a[href*="/news/"]').first();

        if ((await first.count()) === 0) {
            test.skip(true, '公開済みニュースがない');
        }

        await first.click();
        await expectNoSeriousViolations(page);
    });
});

test.describe('アクセシビリティ — 管理画面（未認証）', () => {
    test('/login に critical / serious の違反がない', async ({ page }) => {
        await page.goto('/login');
        await expectNoSeriousViolations(page);
    });
});

test.describe('アクセシビリティ — 管理画面（認証済み）', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    const adminRoutes = [
        '/dashboard',
        '/admin/news',
        '/admin/works',
        '/admin/media',
        '/admin/contact',
        '/admin/site',
        '/admin/notifications',
        // Phase 9.3B で足した画面も同じ baseline を保つ（SPEC §AB）
        '/admin/pages',
        '/admin/pages/create',
        '/admin/taxonomies',
        '/admin/taxonomies/news_category',
    ];

    for (const route of adminRoutes) {
        test(`${route} に critical / serious の違反がない`, async ({
            page,
        }) => {
            await page.goto(route);
            await expectNoSeriousViolations(page);
        });
    }

    test('ニュース編集画面に critical / serious の違反がない', async ({
        page,
    }) => {
        await page.goto('/admin/news');
        await page.locator('[data-testid="row-title-link"]').first().click();
        await page.waitForURL('**/edit');
        await expectNoSeriousViolations(page);
    });

    test('メディア編集画面に critical / serious の違反がない', async ({
        page,
    }) => {
        await page.goto('/admin/media');
        const link = page.locator('[data-testid="media-name-link"]').first();

        if ((await link.count()) === 0) {
            test.skip(true, 'メディアが無い');
        }

        await link.click();
        await page.waitForURL(/\/admin\/media\/\d+$/);
        await expectNoSeriousViolations(page);
    });

    /**
     * 一覧だけでなく **入力中の状態**も見る。
     * 折りたたまれたフォームやモーダルは、開いたときにだけ label / focus の
     * 問題が出る。閉じたまま測っても「違反 0」にしかならない。
     *
     * 作るものには実行ごとの stamp を付ける。
     * 他の spec や前回の実行が残したデータに依存しない
     */
    const stamp = `${Date.now()}-${process.env.TEST_WORKER_INDEX ?? '0'}`;

    test('ページの編集画面に critical / serious の違反がない', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`A11y ページ ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`a11y-page-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();

        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/edit$/);
        await settleToast(page);
        await expectNoSeriousViolations(page);
    });

    test('分類の項目追加フォームを開いた状態に critical / serious の違反がない', async ({
        page,
    }) => {
        await page.goto('/admin/taxonomies/news_category');
        await page.getByTestId('term-add-open').click();

        await expect(page.getByTestId('term-name-input')).toBeVisible();
        await expectNoSeriousViolations(page);
    });

    test('分類の項目編集フォームを開いた状態に critical / serious の違反がない', async ({
        page,
    }) => {
        const termSlug = `a11y-term-${stamp}`;

        await page.goto('/admin/taxonomies/news_category');
        await page.getByTestId('term-add-open').click();
        await page.getByTestId('term-name-input').fill(`A11y 項目 ${stamp}`);
        await page.locator('#term-slug').fill(termSlug);
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await expect(page.getByTestId(`term-row-${termSlug}`)).toBeVisible();

        await page.getByTestId(`term-edit-${termSlug}`).click();
        await expect(page.getByTestId('term-edit-name-input')).toBeVisible();
        await settleToast(page);
        await expectNoSeriousViolations(page);
    });

    test('ダークモードでも critical / serious の違反がない', async ({
        page,
        context,
    }) => {
        // 読み込み前に確定させる（途中適用だと light / dark が混ざって測定できない）
        await context.addCookies([
            { name: 'appearance', value: 'dark', url: 'http://localhost:8181' },
        ]);
        await page.addInitScript(() =>
            window.localStorage.setItem('appearance', 'dark'),
        );
        await page.goto('/dashboard');
        await expect(page.locator('html')).toHaveClass(/dark/);
        await expectNoSeriousViolations(page);
    });

    test('モバイル幅のナビゲーションに critical / serious の違反がない', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');
        await page.locator('[data-testid="sidebar-trigger"]').click();
        await expect(page.locator('#app-sidebar')).toBeVisible();
        await expectNoSeriousViolations(page);
    });
});
