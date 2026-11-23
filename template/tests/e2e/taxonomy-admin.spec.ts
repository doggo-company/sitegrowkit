import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Phase 9.3B / #16 — 分類の管理画面と Content 分類選択 UI の恒久回帰。
 *
 * **自分で作った項目だけを操作する。** demo / import コンテンツの中身に依存しないので、
 * 他の spec の実行順に左右されない（slug に実行ごとの stamp を付ける）。
 */

const stamp = Date.now();
const slug = {
    parent: `e2e-parent-${stamp}`,
    child: `e2e-child-${stamp}`,
    used: `e2e-used-${stamp}`,
    spare: `e2e-spare-${stamp}`,
    xss: `e2e-xss-${stamp}`,
};

const TAXONOMY = '/admin/taxonomies/news_category';

/** 追加フォームから項目を 1 件作る（parent はラベルで選ぶ） */
async function createTerm(
    page: Page,
    name: string,
    termSlug: string,
    parentLabel?: string,
): Promise<void> {
    await page.goto(TAXONOMY);
    await page.getByTestId('term-add-open').click();
    await page.getByTestId('term-name-input').fill(name);
    await page.locator('#term-slug').fill(termSlug);

    if (parentLabel !== undefined) {
        await page
            .getByTestId('term-parent-select')
            .selectOption({ label: parentLabel });
    }

    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByTestId(`term-row-${termSlug}`)).toBeVisible();
}

test.describe('分類の管理画面', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('一覧から項目管理画面へ入れる', async ({ page }) => {
        await page.goto('/admin/taxonomies');

        await expect(page.locator('h1')).toContainText('分類管理');
        // code 管理の分類は変更不可であることを明示する
        await expect(page.locator('table')).toContainText(
            'コード管理（変更不可）',
        );

        await page.getByTestId('taxonomy-link').first().click();
        await expect(page).toHaveURL(/\/admin\/taxonomies\/news_category$/);
        await expect(page.locator('h1')).toContainText('カテゴリ');
    });

    test('項目を作成し、子を階層で表示する', async ({ page }) => {
        await createTerm(page, `E2E 親 ${stamp}`, slug.parent);
        await createTerm(
            page,
            `E2E 子 ${stamp}`,
            slug.child,
            `E2E 親 ${stamp}`,
        );

        // 子は親の配下（入れ子の ul）にある
        const child = page.getByTestId(`term-row-${slug.child}`);
        await expect(child).toBeVisible();
        await expect(
            page
                .getByTestId(`term-row-${slug.parent}`)
                .locator('xpath=ancestor::li[1]')
                .getByTestId(`term-row-${slug.child}`),
        ).toHaveCount(1);
    });

    test('項目を編集できる', async ({ page }) => {
        await page.goto(TAXONOMY);
        await page.getByTestId(`term-edit-${slug.child}`).click();

        await page
            .getByTestId('term-edit-name-input')
            .fill(`E2E 子（改名） ${stamp}`);
        await page.getByRole('button', { name: '更新' }).click();

        await expect(page.getByTestId(`term-row-${slug.child}`)).toContainText(
            `E2E 子（改名） ${stamp}`,
        );
    });

    test('archive すると既定表示から外れ、子の階層は保たれる', async ({
        page,
    }) => {
        await page.goto(TAXONOMY);
        await page.getByTestId(`term-archive-${slug.parent}`).click();

        const parent = page.getByTestId(`term-row-${slug.parent}`);

        // 親は archived だが、表示すべき子がいるので階層のために残る（HR-4）
        await expect(parent).toContainText('アーカイブ済み');
        await expect(parent).toContainText('階層のため表示中');
        // 子は active のまま、親の配下に残る（root へ昇格しない）
        const child = page.getByTestId(`term-row-${slug.child}`);
        await expect(child).toBeVisible();
        await expect(child).not.toContainText('アーカイブ済み');
    });

    test('アーカイブ済みの表示を切り替えられる', async ({ page }) => {
        // 子を持たない archived は既定で隠れる
        await createTerm(page, `E2E 予備 ${stamp}`, slug.spare);
        await page.getByTestId(`term-archive-${slug.spare}`).click();
        await expect(page.getByTestId(`term-row-${slug.spare}`)).toHaveCount(0);

        await page.getByTestId('show-archived-toggle').click();
        await expect(page.getByTestId(`term-row-${slug.spare}`)).toBeVisible();
        await expect(page.getByTestId(`term-row-${slug.spare}`)).toContainText(
            'アーカイブ済み',
        );

        // 元に戻す（後続テストの前提を変えない）
        await page.getByTestId(`term-unarchive-${slug.spare}`).click();
        await expect(
            page.getByTestId(`term-row-${slug.spare}`),
        ).not.toContainText('アーカイブ済み');
    });

    test('キーボードで並べ替えできる', async ({ page }) => {
        await page.goto(TAXONOMY);

        const rootSlugs = async (): Promise<string[]> =>
            page.evaluate(() =>
                [
                    ...document.querySelectorAll('[data-testid^="term-row-"]'),
                ].map((el) =>
                    (el.getAttribute('data-testid') ?? '').replace(
                        'term-row-',
                        '',
                    ),
                ),
            );

        const before = await rootSlugs();
        const target = page.getByTestId(`term-move-up-${slug.spare}`);

        // drag ではなくキーボード操作で動く（focus → Enter）
        await target.focus();
        await expect(target).toBeFocused();
        await page.keyboard.press('Enter');

        await expect
            .poll(async () => (await rootSlugs()).join(','))
            .not.toBe(before.join(','));
    });

    test('並べ替えは非表示のアーカイブ済み兄弟を動かさない', async ({
        page,
    }) => {
        // 兄弟集合を確定させるため、専用の親の下に active / archived / active を作る
        const box = `e2e-box-${stamp}`;
        const a = `e2e-ord-a-${stamp}`;
        const x = `e2e-ord-x-${stamp}`;
        const b = `e2e-ord-b-${stamp}`;
        const boxLabel = `E2E 並べ替え箱 ${stamp}`;

        await createTerm(page, boxLabel, box);
        await createTerm(page, `E2E 並び A ${stamp}`, a, boxLabel);
        await createTerm(page, `E2E 並び X ${stamp}`, x, boxLabel);
        await createTerm(page, `E2E 並び B ${stamp}`, b, boxLabel);

        // 真ん中を archive して既定表示から隠す（full order は A, X, B のまま）
        await page.getByTestId(`term-archive-${x}`).click();
        await expect(page.getByTestId(`term-row-${x}`)).toHaveCount(0);

        // 表示上の隣（B の上は A）と入れ替える
        const up = page.getByTestId(`term-move-up-${b}`);
        await up.focus();
        await expect(up).toBeFocused();
        await page.keyboard.press('Enter');

        // アーカイブ済みも表示して**全順序**を確認する:
        // 交換されたのは A と B だけで、X は元の位置（真ん中）に留まる
        await page.getByTestId('show-archived-toggle').click();
        await expect
            .poll(async () =>
                page.evaluate(
                    (boxId: string) =>
                        [
                            // 箱の li の**中にある ul**（= 子の階層）だけを見る。
                            // 箱自身の行は ul の外にあるので入らない
                            ...(document
                                .querySelector(
                                    `[data-testid="term-row-${boxId}"]`,
                                )
                                ?.closest('li')
                                ?.querySelectorAll(
                                    ':scope ul > li > [data-testid^="term-row-"]',
                                ) ?? []),
                        ].map((el) =>
                            (el.getAttribute('data-testid') ?? '').replace(
                                'term-row-',
                                '',
                            ),
                        ),
                    box,
                ),
            )
            .toEqual([b, x, a]);
    });

    test('参照ゼロの項目は確認を経て削除できる', async ({ page }) => {
        await page.goto(TAXONOMY);
        await page.getByTestId(`term-delete-${slug.spare}`).click();

        // ブラウザの confirm ではなくサーバーの ConfirmationGate
        const dialog = page.getByTestId('operation-confirmation');
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('アーカイブ');

        await page.getByTestId('operation-confirm-button').click();
        await expect(page.getByTestId(`term-row-${slug.spare}`)).toHaveCount(0);
    });

    test('項目名の HTML は実行されない', async ({ page }) => {
        await createTerm(
            page,
            '</script><script>window.__e2eXss__=1;</script>',
            slug.xss,
        );

        await page.goto(TAXONOMY);
        await expect(page.getByTestId(`term-row-${slug.xss}`)).toContainText(
            '</script><script>',
        );

        // 実行されていない（script ブロックから脱出できていない）
        expect(await page.evaluate(() => '__e2eXss__' in window)).toBe(false);

        const injection = await page.evaluate((testId: string) => {
            const row = document.querySelector(`[data-testid="${testId}"]`);
            // Inertia の初期化 payload（type="application/json"）は実行されない。
            // 「実行され得る script」だけを数える
            const executable = [...document.querySelectorAll('script')].filter(
                (s) => {
                    const type = (s.getAttribute('type') ?? '').toLowerCase();

                    return (
                        type === '' ||
                        type === 'text/javascript' ||
                        type === 'module'
                    );
                },
            );

            return {
                executableWithPayload: executable.filter((s) =>
                    s.textContent?.includes('__e2eXss__'),
                ).length,
                // 描画された行の中に注入された要素が生えていない（テキストとして出ている）
                elementsInRow: row
                    ? row.querySelectorAll('script, img').length
                    : -1,
                renderedAsText:
                    row?.textContent?.includes('window.__e2eXss__=1;') ?? false,
            };
        }, `term-row-${slug.xss}`);

        expect(injection.executableWithPayload).toBe(0);
        expect(injection.elementsInRow).toBe(0);
        expect(injection.renderedAsText).toBe(true);
    });

    test('モバイル 375px でも横スクロールせず操作できる', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(TAXONOMY);
        await expect(page.getByTestId(`term-row-${slug.parent}`)).toBeVisible();

        const layout = await page.evaluate(() => {
            const doc = document.documentElement;
            const rows = [
                ...document.querySelectorAll('[data-testid^="term-row-"]'),
            ];

            return {
                bodyOverflows: doc.scrollWidth > doc.clientWidth,
                clippedRows: rows.filter(
                    (r) => r.scrollWidth > r.clientWidth + 1,
                ).length,
                unreachableButtons: rows.filter((r) =>
                    [...r.querySelectorAll('button')].some((b) => {
                        const box = b.getBoundingClientRect();

                        return box.right > doc.clientWidth + 1 || box.left < -1;
                    }),
                ).length,
            };
        });

        expect(layout.bodyOverflows).toBe(false);
        expect(layout.clippedRows).toBe(0);
        expect(layout.unreachableButtons).toBe(0);
    });
});

test.describe('Content の分類選択', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    const newsTitle = `E2E 分類つきニュース ${stamp}`;

    test('active な項目を付与して保存できる', async ({ page }) => {
        await createTerm(page, `E2E 使用中 ${stamp}`, slug.used);

        await page.goto('/admin/news/create');
        await page.fill('#title', newsTitle);
        await page.fill('#body', 'E2E 本文');
        // status は必須。下書きのまま保存する
        await page.click('#status');
        await page.getByRole('option', { name: '下書き' }).click();

        // 候補は active のみ。ラベルで選ぶ（ULID に依存しない）
        await page
            .getByRole('checkbox', { name: `E2E 使用中 ${stamp}` })
            .click();
        await page.getByRole('button', { name: '保存', exact: true }).click();

        await page.waitForURL('**/admin/news');
        await expect(page.locator('table')).toContainText(newsTitle);
    });

    test('使用中の項目は理由つきで削除できず、アーカイブを案内する', async ({
        page,
    }) => {
        await page.goto(TAXONOMY);
        await page.getByTestId(`term-delete-${slug.used}`).click();

        const conflict = page.getByTestId('term-delete-conflict');
        await expect(conflict).toBeVisible();
        await expect(conflict).toContainText('削除できません');
        await expect(conflict).toContainText('コンテンツ 1 件');
        // conflict を確認ダイアログへ変換しない
        await expect(page.getByTestId('operation-confirmation')).toHaveCount(0);

        // その場から archive できる
        await page.getByTestId('term-archive-instead').click();
        await page.getByTestId('show-archived-toggle').click();
        await expect(page.getByTestId(`term-row-${slug.used}`)).toContainText(
            'アーカイブ済み',
        );
    });

    test('アーカイブ済みの割り当ては選択済みのまま残り、保存で消えない', async ({
        page,
    }) => {
        await page.goto('/admin/news');
        await page.getByRole('link', { name: newsTitle }).click();

        const assigned = page.getByRole('checkbox', {
            name: `E2E 使用中 ${stamp}（アーカイブ済み）`,
        });

        // 選択済み + 「アーカイブ済み」明示（黙って外さない）
        await expect(assigned).toBeVisible();
        await expect(assigned).toHaveAttribute('aria-checked', 'true');

        // そのまま保存しても割り当ては維持される
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await page.waitForURL('**/admin/news');

        await page.getByRole('link', { name: newsTitle }).click();
        await expect(
            page.getByRole('checkbox', {
                name: `E2E 使用中 ${stamp}（アーカイブ済み）`,
            }),
        ).toHaveAttribute('aria-checked', 'true');
    });

    test('アーカイブ済みの割り当ては外せる', async ({ page }) => {
        await page.goto('/admin/news');
        await page.getByRole('link', { name: newsTitle }).click();

        await page
            .getByRole('checkbox', {
                name: `E2E 使用中 ${stamp}（アーカイブ済み）`,
            })
            .click();
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await page.waitForURL('**/admin/news');

        await page.getByRole('link', { name: newsTitle }).click();
        // 外れた項目は archived なので候補にも出ない
        await expect(
            page.getByRole('checkbox', {
                name: `E2E 使用中 ${stamp}（アーカイブ済み）`,
            }),
        ).toHaveCount(0);
    });
});
