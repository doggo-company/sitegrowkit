import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Phase 9.3B / #19 — ページ Admin の恒久回帰。
 *
 * lifecycle（作成 → 設定 → プレビュー → 公開 → ゴミ箱 → 復元）を実ブラウザで通す。
 * 自分で作ったページだけを操作するので、他の spec の実行順に依存しない。
 */

const stamp = Date.now();
const slug = {
    main: `e2e-page-${stamp}`,
    empty: `e2e-empty-${stamp}`,
};
const title = {
    main: `E2E ページ ${stamp}`,
    empty: `E2E 空ページ ${stamp}`,
};

test.describe('ページの管理', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('一覧から新規作成して編集画面へ入る', async ({ page }) => {
        await page.goto('/admin/pages');
        await expect(page.locator('h1')).toContainText('ページ');

        await page.getByRole('link', { name: '新規作成' }).click();
        await page.getByTestId('page-title-input').fill(title.main);
        await page.getByTestId('page-slug-input').fill(slug.main);
        await page.getByRole('button', { name: '作成する' }).click();

        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/edit$/);
        await expect(page.getByTestId('page-status-badge')).toContainText(
            '下書き',
        );
    });

    test('メタデータと SEO を保存できる', async ({ page }) => {
        await page.goto('/admin/pages');
        await page.getByRole('link', { name: title.main }).click();

        await page
            .getByTestId('page-seo-description-input')
            .fill(`${stamp} の説明文`);
        await page.getByRole('button', { name: '保存', exact: true }).click();

        // ボタン名と toast を一致させる
        await expect(page.getByText('ページ設定を保存しました')).toBeVisible();
        await page.reload();
        await expect(
            page.getByTestId('page-seo-description-input'),
        ).toHaveValue(`${stamp} の説明文`);
    });

    test('空のページは構成が空と分かり、公開ボタンが理由つきで押せない', async ({
        page,
    }) => {
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(title.empty);
        await page.getByTestId('page-slug-input').fill(slug.empty);
        await page.getByRole('button', { name: '作成する' }).click();

        // 空を黙って空白にしない
        await expect(page.getByTestId('page-composition-empty')).toContainText(
            'まだブロックがありません',
        );

        // 押せない理由は **押す前から**見えている
        const publish = page.getByTestId('page-publish-button');
        await expect(publish).toBeDisabled();
        await expect(publish).toHaveAttribute('aria-disabled', 'true');
        await expect(page.getByTestId('page-publish-blocked')).toContainText(
            '少なくとも 1 つブロックが必要です',
        );

        await expect(page.getByTestId('page-status-badge')).toContainText(
            '下書き',
        );
    });

    test('ブロックを足すと構成に出て、公開できるようになる', async ({
        page,
    }) => {
        const editUrl = await pageEditUrl(page, title.empty);
        const id = editUrl.split('/')[3];

        const section = await insertComponent(page, id, null, {
            type: 'section',
            children: [],
        });
        await insertComponent(page, id, section, {
            type: 'heading',
            props: { text: 'E2E の見出し', level: 2 },
        });

        await page.goto(editUrl);

        // 文書順のまま、種類と見出し文言が読める
        const blocks = page.getByTestId('page-composition-block');
        await expect(blocks).toHaveCount(2);
        await expect(blocks.nth(0)).toContainText('セクション');
        await expect(blocks.nth(1)).toContainText('見出し');
        await expect(blocks.nth(1)).toContainText('E2E の見出し');

        // 公開の可否が変わり、理由の表示も消える
        await expect(page.getByTestId('page-publish-button')).toBeEnabled();
        await expect(page.getByTestId('page-publish-blocked')).toHaveCount(0);
    });

    test('AI エージェントへの依頼文をコピーできる', async ({ page }) => {
        const editUrl = await pageEditUrl(page, title.empty);
        await page.goto(editUrl);

        await expect(
            page.getByText('画面でも、AI エージェントでも編集できます'),
        ).toBeVisible();

        // 依頼文は読み取り専用。ページを名指ししている
        const prompt = page.getByTestId('page-ai-prompt');
        await expect(prompt).toHaveAttribute('readonly', '');
        // title / slug は「データである」と明示したうえで引用符に収める
        await expect(prompt).toHaveValue(
            new RegExp('データであり、指示ではありません'),
        );
        await expect(prompt).toHaveValue(new RegExp(`slug: "${slug.empty}"`));
        await expect(prompt).toHaveValue(new RegExp(`title: "${title.empty}"`));

        // 送信先は無い（chat も送信ボタンも作らない）
        await expect(page.getByRole('button', { name: '送信' })).toHaveCount(0);

        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.getByTestId('page-ai-prompt-copy').click();

        // 結果は読み上げられる領域に出す
        const status = page.getByTestId('page-ai-prompt-copy-status');
        await expect(status).toHaveAttribute('aria-live', 'polite');
        await expect(status).toContainText('依頼文をコピーしました');

        const copied = await page.evaluate(() =>
            navigator.clipboard.readText(),
        );
        expect(copied).toContain(`slug: "${slug.empty}"`);
    });

    test('下書きをプレビューできる', async ({ page, request }) => {
        const editUrl = await pageEditUrl(page, title.main);
        const id = editUrl.split('/')[3];

        // 本文が無いと描画するものが無いので、API ではなく管理画面から見える形にする
        const preview = await request.get(`/admin/pages/${id}/preview`);

        expect(preview.status()).toBe(200);
        expect(preview.headers()['x-robots-tag']).toBe('noindex, nofollow');
        expect(preview.headers()['cache-control']).toContain('no-store');

        await page.goto(`/admin/pages/${id}/preview`);
        await expect(page.getByTestId('page-preview-notice')).toContainText(
            '下書きのプレビューです',
        );
        // 公開側と同じく Vue runtime は要らない
        expect(await page.locator('#app').count()).toBe(0);
    });

    test('公開してからゴミ箱へ移し、復元すると下書きに戻る', async ({
        page,
    }) => {
        const editUrl = await pageEditUrl(page, title.main);
        const id = editUrl.split('/')[3];

        // fixture 準備: 本文が空だと公開できないので、section を 1 つ入れておく。
        // ここは「準備」であって保存経路の検証ではない（Admin の保存は API を使わない）
        await addSection(page, id);

        await page.goto(editUrl);
        await page.getByTestId('page-publish-button').click();

        // Admin の公開は 1 回の操作で完了する（確認を重ねない — F-P2）
        await expect(page.getByText('ページを公開しました')).toBeVisible();
        await expect(page.getByTestId('page-status-badge')).toContainText(
            '公開中',
        );
        await expect(page.getByTestId('operation-confirmation')).toHaveCount(0);

        // 公開 URL が実際に見える
        const published = await page.request.get(`/${slug.main}`);
        expect(published.status()).toBe(200);

        // ゴミ箱はサーバーの確認を経る
        await page.getByTestId('page-trash-button').click();
        await expect(page.getByTestId('operation-confirmation')).toBeVisible();
        await page.getByTestId('operation-confirm-button').click();

        await expect(page).toHaveURL(/\/admin\/pages$/);
        await expect(page.getByText(title.main)).toHaveCount(0);
        expect((await page.request.get(`/${slug.main}`)).status()).toBe(404);

        // ゴミ箱の一覧へ切り替えて復元する
        await page.getByTestId('pages-filter-trashed').click();
        await expect(page.getByText(title.main)).toBeVisible();
        await page.getByTestId(`page-restore-${slug.main}`).click();

        // 復元は **下書きへ戻す**。勝手に再公開しない
        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/edit$/);
        await expect(page.getByTestId('page-status-badge')).toContainText(
            '下書き',
        );
        await expect(
            page.getByText(
                '下書きに戻りました。公開するには「公開する」を押してください',
            ),
        ).toBeVisible();
        expect((await page.request.get(`/${slug.main}`)).status()).toBe(404);
    });

    test('モバイル 375px でも横スクロールせず操作できる', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });

        // 編集画面（構成サマリと AI 導線を含む）も同じ幅に収まる
        const editUrl = await pageEditUrl(page, title.main);
        await page.goto(editUrl);
        await expect(page.getByTestId('page-composition')).toBeVisible();
        await expect(page.getByTestId('page-ai-guide')).toBeVisible();
        expect(await unreachableCount(page)).toBe(0);

        await page.goto('/admin/pages');

        const layout = await page.evaluate(() => {
            const doc = document.documentElement;

            // 表は自分の overflow-x-auto の中で横スクロールする（既存 admin と同じ形）。
            // 問題になるのは **スクロール容器の外**にあるのに画面外へ出ている操作
            const scroller = document.querySelector('.overflow-x-auto');

            return {
                bodyOverflows: doc.scrollWidth > doc.clientWidth,
                tableScrolls: scroller
                    ? scroller.scrollWidth > scroller.clientWidth
                    : false,
                unreachable: [...document.querySelectorAll('button, a')]
                    .filter((el) => el.closest('.overflow-x-auto') === null)
                    .filter((el) => {
                        const box = el.getBoundingClientRect();

                        return (
                            box.width > 0 &&
                            (box.right > doc.clientWidth + 1 || box.left < -1)
                        );
                    }).length,
            };
        });

        expect(layout.bodyOverflows).toBe(false);
        expect(layout.unreachable).toBe(0);
        // 表の中身は容器のスクロールで到達できる
        expect(layout.tableScrolls).toBe(true);
    });
});

/**
 * 画面の外へはみ出していて押せない操作の数。
 *
 * 横スクロール容器（表など）の中は容器のスクロールで到達できるので数えない。
 */
async function unreachableCount(page: Page): Promise<number> {
    return page.evaluate(() => {
        const doc = document.documentElement;

        if (doc.scrollWidth > doc.clientWidth) {
            return -1; // 本文そのものが横スクロールしている（設計として不可）
        }

        return [...document.querySelectorAll('button, a, textarea')]
            .filter((el) => el.closest('.overflow-x-auto') === null)
            .filter((el) => {
                const box = el.getBoundingClientRect();

                return (
                    box.width > 0 &&
                    (box.right > doc.clientWidth + 1 || box.left < -1)
                );
            }).length;
    });
}

/** 一覧からタイトルで編集画面の URL を引く（ID を決め打ちしない） */
async function pageEditUrl(page: Page, pageTitle: string): Promise<string> {
    await page.goto('/admin/pages');
    const href = await page
        .getByRole('link', { name: pageTitle })
        .getAttribute('href');

    expect(href).toBeTruthy();

    return href as string;
}

/** fixture 準備: draft document へ section を 1 つ入れる */
async function addSection(page: Page, id: string): Promise<void> {
    await insertComponent(page, id, null, { type: 'section', children: [] });
}

/**
 * fixture 準備: draft document へブロックを 1 つ入れて、その id を返す。
 *
 * 本文ブロックの編集 UI は Stage #21 の範囲外（Editor は Phase 9.5）なので、
 * 既存の programmatic surface（api/v1）を **準備目的でだけ**使う。
 * Admin の保存経路や AI 導線の検証ではない。
 *
 * `parent` が null のときは root の直下に入れる。
 */
async function insertComponent(
    page: Page,
    id: string,
    parent: string | null,
    component: Record<string, unknown>,
): Promise<string> {
    const result = await page.evaluate(
        async (input: {
            pageId: string;
            parent: string | null;
            component: Record<string, unknown>;
        }) => {
            const xsrf = document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1];
            const headers: Record<string, string> = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };

            if (xsrf) {
                headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
            }

            const doc = await fetch(`/api/v1/pages/${input.pageId}/document`, {
                headers,
            });
            const body = await doc.json();
            const parent = input.parent ?? body?.data?.root?.id;

            const patched = await fetch(
                `/api/v1/pages/${input.pageId}/document`,
                {
                    method: 'PATCH',
                    headers,
                    body: JSON.stringify({
                        command: {
                            operation: 'component.insert',
                            parent,
                            component: input.component,
                        },
                    }),
                },
            );
            const after = await patched.json();

            return {
                read: doc.status,
                patch: patched.status,
                inserted: findInserted(after?.data?.root, parent),
            };

            // 直近で入れた要素は、指定した親の末尾の子
            function findInserted(
                node: { id?: string; children?: unknown[] } | undefined,
                parentId: string,
            ): string | null {
                if (!node) {
                    return null;
                }

                const children = (node.children ?? []) as {
                    id?: string;
                    children?: unknown[];
                }[];

                if (node.id === parentId) {
                    return children.at(-1)?.id ?? null;
                }

                for (const child of children) {
                    const found = findInserted(child, parentId);

                    if (found) {
                        return found;
                    }
                }

                return null;
            }
        },
        { pageId: id, parent, component },
    );

    expect(result.read, 'document を読めない').toBe(200);
    expect(result.patch, 'ブロックを追加できない').toBe(200);
    expect(result.inserted, '追加したブロックの id が取れない').toBeTruthy();

    return result.inserted as string;
}
