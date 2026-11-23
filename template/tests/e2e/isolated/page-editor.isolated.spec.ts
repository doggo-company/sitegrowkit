import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Visual Page Editor が **画面として成立しているか**（Phase 9.6 / P0-C2）。
 *
 * Feature テストは経路・認可・検証を担保する。ここで見たいのはそれではなく、
 * 制作者が実際に触って組み立てられるか — ブロックを足す、Canvas に出る、
 * Layers で選べる、並べ替わる、複製できる、削除は確認を挟む、
 * **画面を開き直しても残っている**、まで。
 *
 * 標準スイート（`playwright.config.ts`）へは入れない。この spec は
 * ページとブロックを増やすので、`template-visual` の静的比較や
 * 一覧の件数を前提にした回帰を壊す。実行は明示的に:
 *
 * ```
 * APP_URL=http://localhost:8180 \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/page-editor.isolated.spec.ts
 * ```
 */

const composeCwd = process.env.AGENTCMS_COMPOSE_CWD ?? process.cwd();

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

// ログインの回数制限をならす。
// この spec は **test ごとに**ログインするので、そのままだと Fortify の throttle
// （email+IP で 5 回/分）に当たり、実装は正しいのに落ちる。
// 使い捨ての環境に対してだけ実行する前提なので、毎回記録を消してから始める。
test.beforeEach(() => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );
});

async function loginAsOwner(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

/** 新しいページを作り、その編集画面の URL を返す */
async function createPage(page: Page, slug: string): Promise<string> {
    await page.goto('/admin/pages/create');
    await page.fill('#page-title', `編集テスト ${slug}`);
    await page.fill('#page-slug', slug);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/pages/*/edit');

    return page.url();
}

/**
 * 画面に出ている画面幅の名前。**テストにも名前を書かない**
 * （theme.json を変えた案件でもそのまま動く）。
 */
async function breakpointNames(page: Page): Promise<string[]> {
    return page.locator('[data-testid^="breakpoint-"]').evaluateAll((els) =>
        els
            .map((el) => (el as HTMLElement).dataset.testid ?? '')
            .map((id) => id.replace('breakpoint-', ''))
            .filter((name) => name !== 'all'),
    );
}

/** Canvas（iframe）の中身。公開と同じ描画が出ている */
function canvas(page: Page) {
    return page.frameLocator('[data-testid="editor-canvas"]');
}

test.describe('ページをブロックで組み立てられる', () => {
    test('スマホで階層の移動と本文編集を行い、開き直しても残る', async ({
        page,
    }) => {
        await loginAsOwner(page);
        await page.setViewportSize({ width: 390, height: 844 });
        const slug = `mobile-editor-${Date.now()}`;
        await createPage(page, slug);
        await page.getByTestId('page-editor-link').click();
        await page.getByTestId('palette-section').click();
        await page.getByTestId('palette-heading').click();
        await page.getByTestId('palette-heading').click();
        await expect(page.getByTestId('layer-heading')).toHaveCount(2);
        const firstId = await page
            .getByTestId('layer-heading')
            .first()
            .getAttribute('data-node-id');
        await page.getByTestId('layer-heading').first().click();
        await expect(page.getByTestId('layer-move-up')).toBeDisabled();
        await page.getByTestId('layer-move-down').click();
        await expect(page.getByTestId('layer-heading').last()).toHaveAttribute(
            'data-node-id',
            firstId!,
        );

        // 下側の入力欄まで普通にスクロールして編集できる。保存・再表示の結果を見る。
        await page
            .getByTestId('inspector-prop-text')
            .fill('スマホで更新した見出し');
        await page.getByTestId('inspector-prop-text').blur();
        await expect(
            canvas(page).getByRole('heading', {
                name: 'スマホで更新した見出し',
            }),
        ).toBeVisible();
        await page.getByTestId('inspector-prop-level').selectOption('1');
        await expect(
            canvas(page).getByRole('heading', {
                name: 'スマホで更新した見出し',
            }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.reload();
        await expect(
            canvas(page).getByRole('heading', {
                name: 'スマホで更新した見出し',
            }),
        ).toBeVisible();
        await expect(page.getByTestId('layer-heading').last()).toHaveAttribute(
            'data-node-id',
            firstId!,
        );
        await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
        await page.getByTestId('page-publish-button').click();
        await expect(page.getByTestId('page-status-badge')).toHaveText(
            '公開中',
        );
        await page.goto(`/${slug}`);
        await expect(
            page.getByRole('heading', { name: 'スマホで更新した見出し' }),
        ).toBeVisible();
        const toggle = page.getByRole('button', {
            name: 'メニュー',
            exact: true,
        });
        const nav = page.getByRole('navigation', {
            name: 'メイン',
            exact: true,
        });
        await expect(nav).toBeHidden();
        await toggle.click();
        await expect(nav).toBeVisible();
        await toggle.press('Escape');
        await expect(nav).toBeHidden();
        await expect(toggle).toBeFocused();
        expect(await (await page.request.get('/sitemap.xml')).text()).toContain(
            `/${slug}</loc>`,
        );
    });

    test('追加 → 選択 → 並べ替え → 複製 → 削除 → 開き直しても残る', async ({
        page,
    }) => {
        await loginAsOwner(page);

        const editUrl = await createPage(page, `editor-${Date.now()}`);

        // 1. 設定画面から「ブロックを編集」で入れる
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        // まだ何も無い
        await expect(page.getByTestId('editor-no-selection')).toBeVisible();

        // 2. セクションを足す → Layers にも Canvas にも出る
        await page.getByTestId('palette-section').click();
        await expect(page.getByTestId('layer-section')).toBeVisible();
        await expect(canvas(page).locator('.doc-section')).toHaveCount(1);

        // 3. セクションを選んでから見出しを 2 つ足す
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-heading').click();
        await page.getByTestId('palette-heading').click();

        await expect(page.getByTestId('layer-heading')).toHaveCount(2);
        await expect(canvas(page).locator('.doc-heading')).toHaveCount(2);

        // 4. Canvas をクリックすると Layers の選択と揃う
        await canvas(page).locator('.doc-heading').first().click();
        await expect(page.getByTestId('editor-selection-label')).toHaveText(
            '見出し',
        );
        await expect(page.getByTestId('editor-canvas-selection')).toBeVisible();

        // 5. 並べ替え。先頭は「上へ」できず、「下へ」で 2 番目になる
        const before = await page
            .getByTestId('layer-heading')
            .evaluateAll((els) =>
                els.map((el) => (el as HTMLElement).dataset.nodeId ?? ''),
            );

        await expect(page.getByTestId('editor-move-up')).toBeDisabled();
        await page.getByTestId('editor-move-down').click();

        await expect
            .poll(() =>
                page
                    .getByTestId('layer-heading')
                    .evaluateAll((els) =>
                        els.map(
                            (el) => (el as HTMLElement).dataset.nodeId ?? '',
                        ),
                    ),
            )
            .toEqual([before[1], before[0]]);

        // 6. 複製すると 1 つ増える
        await page.getByTestId('editor-duplicate').click();
        await expect(page.getByTestId('layer-heading')).toHaveCount(3);
        await expect(canvas(page).locator('.doc-heading')).toHaveCount(3);

        // 7. 削除は **サーバーの確認**を挟む（ブラウザの confirm ではない）
        await page.getByTestId('editor-delete').click();

        const dialog = page.getByTestId('operation-confirmation');
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('削除');

        await dialog.getByRole('button', { name: '削除する' }).click();

        await expect(page.getByTestId('layer-heading')).toHaveCount(2);
        await expect(canvas(page).locator('.doc-heading')).toHaveCount(2);

        // 8. 開き直しても残っている（下書きへ保存されている）
        await page.reload();
        await expect(page.getByTestId('layer-heading')).toHaveCount(2);
        await expect(canvas(page).locator('.doc-heading')).toHaveCount(2);

        // 9. 設定画面へ戻れる
        await page.getByRole('link', { name: 'ページ設定へ戻る' }).click();
        await page.waitForURL(editUrl);
    });

    /** 階層に並んでいるブロックの id を上から順に */
    async function layerIds(page: Page, type: string): Promise<string[]> {
        return page
            .getByTestId(`layer-${type}`)
            .evaluateAll((els) =>
                els.map((el) => (el as HTMLElement).dataset.nodeId ?? ''),
            );
    }

    /** section 1 つと見出し 2 つを置いた編集画面まで進む */
    async function editorWithTwoHeadings(
        page: Page,
        slug: string,
    ): Promise<void> {
        await loginAsOwner(page);
        await createPage(page, slug);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-heading').click();
        await page.getByTestId('palette-heading').click();
        await expect(page.getByTestId('layer-heading')).toHaveCount(2);
    }

    test('ドラッグで同じ親の中を並べ替えられる', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-drag-${Date.now()}`);

        const before = await layerIds(page, 'heading');

        // 2 つめを 1 つめの手前の線へ落とす
        await page
            .getByTestId('layer-heading')
            .nth(1)
            .dragTo(page.getByTestId(`layer-line-${before[0]}`));

        await expect
            .poll(() => layerIds(page, 'heading'))
            .toEqual([before[1], before[0]]);
    });

    test('ドラッグで別の親の中へ入れ子にできる', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-nest-${Date.now()}`);

        // section の中に入れ物を足す
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-container').click();
        await expect(page.getByTestId('layer-container')).toHaveCount(1);

        const heading = (await layerIds(page, 'heading'))[0];

        await page
            .getByTestId('layer-heading')
            .first()
            .dragTo(page.getByTestId('layer-container'));

        // 入れ物の中の一覧に、その見出しが現れる
        await expect
            .poll(async () =>
                page
                    .getByTestId('layer-container')
                    .locator('xpath=following-sibling::ul')
                    .getByTestId('layer-heading')
                    .evaluateAll((els) =>
                        els.map(
                            (el) => (el as HTMLElement).dataset.nodeId ?? '',
                        ),
                    ),
            )
            .toEqual([heading]);
    });

    test('置けない場所へは落とせない', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-invaliddrop-${Date.now()}`);

        const before = await layerIds(page, 'heading');

        // セクションは見出しの中へは入れられない（allowedParents は page だけ）
        await page
            .getByTestId('layer-section')
            .dragTo(page.getByTestId('layer-heading').first());

        // 何も起きない。見出しは 2 つのまま、セクションも 1 つのまま
        await expect(page.getByTestId('layer-heading')).toHaveCount(2);
        await expect(page.getByTestId('layer-section')).toHaveCount(1);
        expect(await layerIds(page, 'heading')).toEqual(before);
        await expect(page.getByTestId('editor-error')).toHaveCount(0);
    });

    test('キーボードでも移動と入れ子ができる', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-keys-${Date.now()}`);

        const before = await layerIds(page, 'heading');

        // Alt + ↓ で 1 つめが後ろへ
        await page.getByTestId('layer-heading').first().focus();
        await page.keyboard.press('Alt+ArrowDown');

        await expect
            .poll(() => layerIds(page, 'heading'))
            .toEqual([before[1], before[0]]);

        // 入れ物を足して、Alt + → で直前の兄弟の中へ
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-container').click();
        await expect(page.getByTestId('layer-container')).toHaveCount(1);

        const last = (await layerIds(page, 'heading'))[1];

        // 見出し（2 番目）を入れ物の直前へ動かしてから中へ入れる
        await page.getByTestId('layer-container').focus();
        await page.keyboard.press('Alt+ArrowUp');
        await page.waitForTimeout(300);

        await page.getByTestId('layer-heading').last().focus();
        await page.keyboard.press('Alt+ArrowRight');

        await expect
            .poll(async () =>
                page
                    .getByTestId('layer-container')
                    .locator('xpath=following-sibling::ul')
                    .getByTestId('layer-heading')
                    .evaluateAll((els) =>
                        els.map(
                            (el) => (el as HTMLElement).dataset.nodeId ?? '',
                        ),
                    ),
            )
            .toEqual([last]);
    });

    test('競合したら読み込み直すまで何も変更させない', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-conflict-${Date.now()}`);

        const pageId = page.url().match(/\/pages\/(\d+)\//)?.[1];
        expect(pageId).toBeTruthy();

        // 画面が知らないところで版を進める（別のセッション / AI からの編集に相当）
        const cookies = await page.context().cookies();
        const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';

        const outOfBand = await page.request.patch(
            `/api/v1/pages/${pageId}/document`,
            {
                headers: {
                    'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                    Accept: 'application/json',
                },
                data: {
                    command: {
                        operation: 'component.insert',
                        parent: 'page',
                        component: { type: 'section', props: {} },
                    },
                },
            },
        );

        expect(outOfBand.ok()).toBeTruthy();

        // 古い版のまま操作すると競合になる
        await page.getByTestId('layer-section').first().click();
        await page.getByTestId('palette-heading').click();

        await expect(page.getByTestId('editor-conflict')).toBeVisible();

        // **読み込み直すまで、どの変更操作も受け付けない**
        await expect(page.getByTestId('palette-heading')).toBeDisabled();
        await expect(page.getByTestId('palette-section')).toBeDisabled();
        await expect(page.getByTestId('editor-duplicate')).toBeDisabled();
        await expect(page.getByTestId('editor-delete')).toBeDisabled();
        await expect(page.getByTestId('editor-move-down')).toBeDisabled();

        // 読み込み直す前に、消える内容を確認できる
        await page.getByRole('button', { name: '読み込み直す' }).click();
        await expect(page.getByTestId('editor-reload-confirm')).toBeVisible();
        await page.getByTestId('editor-confirm-reload').click();
        await page.waitForLoadState();
        await expect(page.getByTestId('editor-conflict')).toHaveCount(0);
        await expect(page.getByTestId('palette-section')).toBeEnabled();
    });

    test('Inspector で内容と見た目を変えられる', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-inspect-${Date.now()}`);

        await page.getByTestId('layer-heading').first().click();

        // 1. 内容を変えると Canvas に出る
        await page.getByTestId('inspector-prop-text').fill('変えた見出し');
        await page.getByTestId('inspector-prop-text').blur();

        await expect(canvas(page).locator('.doc-heading').first()).toHaveText(
            '変えた見出し',
        );

        // 2. 宣言された選択肢しか出さない（見出しレベルは 1〜4）
        await expect(
            page.getByTestId('inspector-prop-level').locator('option'),
        ).toHaveText(['指定しない', '1', '2', '3', '4']);

        // 3. 見た目を付けるとクラスが増え、外すと消える
        await page.getByTestId('inspector-style-spaceTop').selectOption('gap');
        await expect(canvas(page).locator('.doc-heading').first()).toHaveClass(
            /doc-spaceTop-gap/,
        );

        await page.getByTestId('inspector-style-spaceTop').selectOption('');
        await expect(
            canvas(page).locator('.doc-heading').first(),
        ).not.toHaveClass(/doc-spaceTop-gap/);

        // 4. 開き直しても残っている
        await page.reload();
        await expect(canvas(page).locator('.doc-heading').first()).toHaveText(
            '変えた見出し',
        );
    });

    test('宣言に無い入力欄は出さない', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-decl-${Date.now()}`);

        await page.getByTestId('layer-heading').first().click();

        // 見出しが宣言しているのは text / level と、5 つの style だけ
        await expect(page.getByTestId('inspector-prop-text')).toBeVisible();
        await expect(page.getByTestId('inspector-prop-level')).toBeVisible();
        await expect(page.getByTestId('inspector-prop-href')).toHaveCount(0);
        await expect(page.getByTestId('inspector-prop-html')).toHaveCount(0);

        await expect(
            page.getByTestId('inspector-style-textAlign'),
        ).toBeVisible();
        await expect(page.getByTestId('inspector-style-color')).toBeVisible();
        // 一覧の設定は見出しには無い
        await expect(page.getByTestId('inspector-prop-limit')).toHaveCount(0);

        // section は逆に text を持たない
        await page.getByTestId('layer-section').click();
        await expect(page.getByTestId('inspector-prop-text')).toHaveCount(0);
        await expect(
            page.getByTestId('inspector-prop-background'),
        ).toBeVisible();
    });

    /**
     * 数値も選択肢も **外せる**こと。
     *
     * 外したら宣言された既定へ戻る。数値欄を空にしたときに 0 が入ると、
     * 「外した」ではなく「0 を選んだ」になり、二度と戻せない。
     */
    test('数値と選択肢は空にすると既定へ戻る', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-unset-${Date.now()}`);

        // container を置く（columns は number・default 2、layout は enum・default stack）
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-container').click();
        await page.getByTestId('layer-container').click();

        // 数値を変える → 空にする → 既定へ戻る
        await page.getByTestId('inspector-prop-columns').fill('4');
        await page.getByTestId('inspector-prop-columns').blur();
        await expect(page.getByTestId('inspector-prop-columns')).toHaveValue(
            '4',
        );

        await page.getByTestId('inspector-prop-columns').fill('');
        await page.getByTestId('inspector-prop-columns').press('Tab');

        // 外した時点で宣言の既定に戻る（保存を待たずに画面へ出る）
        await expect(page.getByTestId('inspector-prop-columns')).toHaveValue(
            '2',
        );

        await page.reload();
        await page.getByTestId('layer-container').click();
        await expect(page.getByTestId('inspector-prop-columns')).toHaveValue(
            '2',
        );
        await expect(page.getByTestId('editor-error')).toHaveCount(0);

        // 選択肢を変える → 「指定しない」 → 既定へ戻る
        await page.getByTestId('inspector-prop-layout').selectOption('grid');
        await expect(page.getByTestId('inspector-prop-layout')).toHaveValue(
            'grid',
        );

        await page.getByTestId('inspector-prop-layout').selectOption('');

        // 外した時点で既定へ戻る。**保存の往復を待ってから**開き直す
        // （待たずに reload すると、送信中の変更が中断されて落ちる）
        await expect(page.getByTestId('inspector-prop-layout')).toHaveValue(
            'stack',
        );
        await expect(page.getByTestId('editor-save-state')).toHaveText(
            '保存済み（下書き）',
        );

        await page.reload();
        await page.getByTestId('layer-container').click();
        await expect(page.getByTestId('inspector-prop-layout')).toHaveValue(
            'stack',
        );
    });

    test('画像は選んで保存でき、競合したら触れなくなる', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-media-${Date.now()}`);
        // 新品サイトには画像が無い。利用者と同じ入口でアップロードしてから選ぶ。
        const editorUrl = page.url();
        await page.goto('/admin/media');
        await page.setInputFiles(
            '#media-file',
            'public/brand/sitegrowkit-mark.png',
        );
        const imageName = `ページ編集用画像 ${Date.now()}`;
        await page.fill('#media-name', imageName);
        await page.fill('#media-alt', 'ページ編集用画像');
        await page
            .locator('[data-testid="media-upload-form"] button[type="submit"]')
            .click();
        await expect(page.getByTestId('media-grid')).toContainText(imageName);
        await page.goto(editorUrl);

        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-image').click();
        await page.getByTestId('layer-image').click();

        // 1. 宣言駆動で media の入力欄が出ている
        await page.getByTestId('media-picker-open').click();

        const choice = page.locator('[data-testid^="media-choice-"]').first();
        await expect(choice).toBeVisible();
        await choice.click();

        // 2. 選んだものが Canvas と入力欄に反映され、開き直しても残る
        await expect(canvas(page).locator('.doc-image img')).toHaveCount(1);

        await page.reload();
        await page.getByTestId('layer-image').click();
        await expect(page.getByTestId('media-picker-current')).toBeVisible();

        // 3. 競合したら画像の入れ替えも解除もできない
        const pageId = page.url().match(/\/pages\/(\d+)\//)?.[1];
        const cookies = await page.context().cookies();
        const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';

        await page.request.patch(`/api/v1/pages/${pageId}/document`, {
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
            },
            data: {
                command: {
                    operation: 'component.insert',
                    parent: 'page',
                    component: { type: 'section', props: {} },
                },
            },
        });

        await page.getByTestId('editor-duplicate').click();
        await expect(page.getByTestId('editor-conflict')).toBeVisible();

        await expect(page.getByTestId('media-picker-open')).toBeDisabled();
        await expect(page.getByTestId('media-picker-clear')).toBeDisabled();
    });

    test('画面幅ごとに上書きでき、解除すると継承へ戻る', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-bp-${Date.now()}`);

        await page.getByTestId('layer-heading').first().click();

        // base で中央寄せにしておく
        await page
            .getByTestId('inspector-style-textAlign')
            .selectOption('center');
        await expect(canvas(page).locator('.doc-heading').first()).toHaveClass(
            /doc-textAlign-center/,
        );

        // 画面幅を切り替えると Canvas の幅が変わる（名前は宣言から取る）
        const [narrowest] = await breakpointNames(page);

        await page.getByTestId(`breakpoint-${narrowest}`).click();
        await expect(page.getByTestId('editor-canvas')).toHaveAttribute(
            'data-canvas-width',
            /^\d+$/,
        );

        // まだ指定していないので「継承」
        await expect(page.getByTestId('inspector-state-textAlign')).toHaveText(
            '継承',
        );

        // この画面幅だけ右寄せにする
        await page
            .getByTestId('inspector-style-textAlign')
            .selectOption('right');
        await expect(page.getByTestId('inspector-state-textAlign')).toHaveText(
            'この画面幅で指定',
        );
        await expect(canvas(page).locator('.doc-heading').first()).toHaveClass(
            /doc-mobile-textAlign-right/,
        );

        // base は変わっていない
        await page.getByTestId('breakpoint-all').click();
        await expect(page.getByTestId('inspector-style-textAlign')).toHaveValue(
            'center',
        );

        // 開き直しても残る
        await page.reload();
        await page.getByTestId('layer-heading').first().click();
        await page.getByTestId(`breakpoint-${narrowest}`).click();
        await expect(page.getByTestId('inspector-state-textAlign')).toHaveText(
            'この画面幅で指定',
        );

        // 解除すると継承へ戻り、クラスも消える
        await page.getByTestId('inspector-style-textAlign').selectOption('');
        await expect(page.getByTestId('inspector-state-textAlign')).toHaveText(
            '継承',
        );
        await expect(
            canvas(page).locator('.doc-heading').first(),
        ).not.toHaveClass(new RegExp(`doc-${narrowest}-textAlign-right`));
        await expect(canvas(page).locator('.doc-heading').first()).toHaveClass(
            /doc-textAlign-center/,
        );
    });

    /**
     * **宣言した画面幅が、枠の中で本当に効いていること。**
     *
     * `max-width` を付けただけでは、管理画面の列がそれより狭いときに枠が縮み、
     * 媒体クエリは縮んだ幅で解決してしまう（desktop を選んでいるのに mobile の見た目になる）。
     */
    test('選んだ画面幅が枠の中で実際に効いている', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-viewport-${Date.now()}`);

        const names = await breakpointNames(page);

        expect(names.length).toBeGreaterThan(0);

        for (const name of names) {
            const button = page.getByTestId(`breakpoint-${name}`);
            // 条件はサーバーが渡した宣言そのもの（画面にもテストにも px を書かない）
            const condition = await button.getAttribute('title');

            expect(condition).toBeTruthy();

            await button.click();

            const frame = page.getByTestId('editor-canvas');
            const declared = Number(
                await frame.getAttribute('data-canvas-width'),
            );

            expect(declared).toBeGreaterThan(0);

            // **枠の中の viewport** が宣言幅であること（枠線ぶん狭くならない）
            await expect
                .poll(() =>
                    canvas(page)
                        .locator('body')
                        .evaluate(() => document.documentElement.clientWidth),
                )
                .toBe(declared);

            // 枠の中で、その宣言の条件が成立していること
            await expect
                .poll(() =>
                    canvas(page)
                        .locator('body')
                        .evaluate(
                            (_el, query) => window.matchMedia(query).matches,
                            condition as string,
                        ),
                )
                .toBe(true);
        }
    });

    test('保存中と競合後は画面幅の切替もできない', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-bp-blocked-${Date.now()}`);

        // 保存を遅らせて、送信中の状態を作る
        await page.route('**/api/v1/pages/*/document', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.continue();
        });

        await page.getByTestId('layer-heading').first().click();
        await page.getByTestId('editor-duplicate').click();

        await expect(page.getByTestId('editor-save-state')).toHaveText(
            '保存中…',
        );
        const [first] = await breakpointNames(page);

        await expect(page.getByTestId('breakpoint-all')).toBeDisabled();
        await expect(page.getByTestId(`breakpoint-${first}`)).toBeDisabled();

        await page.unroute('**/api/v1/pages/*/document');
        await expect(page.getByTestId(`breakpoint-${first}`)).toBeEnabled();

        // 競合したあとも切替を止める（古い版のまま見せない）
        const pageId = page.url().match(/\/pages\/(\d+)\//)?.[1];
        const cookies = await page.context().cookies();
        const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';

        await page.request.patch(`/api/v1/pages/${pageId}/document`, {
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
            },
            data: {
                command: {
                    operation: 'component.insert',
                    parent: 'page',
                    component: { type: 'section', props: {} },
                },
            },
        });

        await page.getByTestId('editor-duplicate').click();
        await expect(page.getByTestId('editor-conflict')).toBeVisible();

        await expect(page.getByTestId('breakpoint-all')).toBeDisabled();

        for (const name of await breakpointNames(page)) {
            await expect(page.getByTestId(`breakpoint-${name}`)).toBeDisabled();
        }
    });

    test('画面幅ごとに変えられない項目は、その画面幅では触らせない', async ({
        page,
    }) => {
        await editorWithTwoHeadings(page, `editor-bp-lock-${Date.now()}`);

        await page.getByTestId('layer-heading').first().click();

        const names = await breakpointNames(page);

        await page.getByTestId(`breakpoint-${names[names.length - 1]}`).click();

        // 見出しの文言は画面幅ごとに変えられると宣言していない
        await expect(page.getByTestId('inspector-state-text')).toHaveText(
            'すべての画面幅で共通',
        );
        await expect(page.getByTestId('inspector-prop-text')).toBeDisabled();

        // 余白は宣言してあるので触れる
        await expect(
            page.getByTestId('inspector-style-spaceTop'),
        ).toBeEnabled();
    });

    test('親を非表示にすると、子は表示を選んでも出てこない', async ({
        page,
    }) => {
        await editorWithTwoHeadings(page, `editor-hidden-${Date.now()}`);

        // セクションごと非表示にする
        await page.getByTestId('layer-section').click();
        await page
            .getByTestId('inspector-style-visibility')
            .selectOption('hidden');

        await expect(canvas(page).locator('.doc-section')).toHaveClass(
            /doc-visibility-hidden/,
        );

        // 子を選ぶと理由が出る
        await page.getByTestId('layer-heading').first().click();
        await expect(
            page.getByTestId('inspector-hidden-by-ancestor'),
        ).toBeVisible();

        // 子は実際に見えていない（親が display:none なので子孫ごと消える）
        await expect(canvas(page).locator('.doc-heading').first()).toBeHidden();

        // 親を戻せば警告も消える
        await page.getByTestId('layer-section').click();
        await page.getByTestId('inspector-style-visibility').selectOption('');
        await page.getByTestId('layer-heading').first().click();
        await expect(
            page.getByTestId('inspector-hidden-by-ancestor'),
        ).toHaveCount(0);
        await expect(
            canvas(page).locator('.doc-heading').first(),
        ).toBeVisible();
    });

    /** 気づきの一覧に出ている rule の一覧 */
    async function findingRules(page: Page): Promise<string[]> {
        return page
            .locator('[data-testid^="a11y-finding-"]')
            .evaluateAll((els) =>
                els.map((el) =>
                    ((el as HTMLElement).dataset.testid ?? '').replace(
                        'a11y-finding-',
                        '',
                    ),
                ),
            );
    }

    test('アクセシビリティの気づきが出て、直すと消える', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-a11y-${Date.now()}`);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        // まだ何も無いので気づきも無い
        await expect(page.getByTestId('a11y-panel')).toHaveCount(0);

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();

        // 見出しを 3 段目から始める → ページの最初の見出しとして指摘される
        await page.getByTestId('palette-heading').click();
        await page.getByTestId('layer-heading').click();
        await page.getByTestId('inspector-prop-level').selectOption('3');

        await expect(page.getByTestId('a11y-panel')).toBeVisible();
        await expect.poll(() => findingRules(page)).toContain('heading-order');

        // 日本語で理由が読める
        await expect(
            page.getByTestId('a11y-finding-heading-order'),
        ).toContainText('見出し');

        // 直すと消える
        await page.getByTestId('inspector-prop-level').selectOption('1');
        await expect
            .poll(() => findingRules(page))
            .not.toContain('heading-order');
    });

    test('気づきから原因のブロックへ行ける', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-a11y-jump-${Date.now()}`);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-image').click();

        // 画像の代替テキストが無い
        await expect.poll(() => findingRules(page)).toContain('image-alt');

        // 別のブロックを選んでおいてから、気づきをクリックする
        await page.getByTestId('layer-section').click();
        await expect(page.getByTestId('editor-selection-label')).toHaveText(
            'セクション',
        );

        await page.getByTestId('a11y-finding-image-alt').click();

        await expect(page.getByTestId('editor-selection-label')).toHaveText(
            '画像',
        );
        // そのまま直せる位置に居る
        await expect(page.getByTestId('inspector-prop-alt')).toBeVisible();
    });

    test('判定できないものは「要確認」にする', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-a11y-unknown-${Date.now()}`);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-text').click();
        await page.getByTestId('layer-text').click();

        // テーマの色ではないので、背景との差を判定できない
        await page.getByTestId('inspector-style-color').fill('rebeccapurple');
        await page.getByTestId('inspector-style-color').blur();

        await expect.poll(() => findingRules(page)).toContain('contrast');

        // 「合格」ではなく「要確認」として出る
        await expect(page.getByTestId('a11y-finding-contrast')).toContainText(
            '要確認',
        );
    });

    test('気づきがあっても保存も公開も止まらない', async ({ page }) => {
        await loginAsOwner(page);
        const editUrl = await createPage(
            page,
            `editor-a11y-publish-${Date.now()}`,
        );
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-image').click();

        await expect(page.getByTestId('a11y-panel')).toBeVisible();

        // 保存は止まらない（開き直しても残っている）
        await expect(page.getByTestId('editor-save-state')).toHaveText(
            '保存済み（下書き）',
        );
        await page.reload();
        await expect(page.getByTestId('layer-image')).toHaveCount(1);

        // 公開も止まらない
        await page.goto(editUrl);
        await page.getByTestId('page-publish-button').click();
        await expect(page.locator('body')).toContainText('公開中');
    });

    test('危険なリンク先は保存させない', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-xss-${Date.now()}`);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-button').click();
        await page.getByTestId('layer-button').click();

        await page
            .getByTestId('inspector-prop-href')
            .fill('javascript:alert(1)');
        await page.getByTestId('inspector-prop-href').blur();

        // サーバーが断り、画面は理由を出す
        await expect(page.getByTestId('editor-error')).toBeVisible();

        // 公開側の描画にも入っていない
        await expect(
            canvas(page).locator('a[href^="javascript:"]'),
        ).toHaveCount(0);
    });

    test('本文に script を入れても実行されない', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-script-${Date.now()}`);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId('palette-section').click();
        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-richtext').click();
        await page.getByTestId('layer-richtext').click();

        await page
            .getByTestId('inspector-prop-html')
            .fill('<p>本文</p><script>window.__pwned = true;</script>');
        await page.getByTestId('inspector-prop-html').blur();

        await expect(
            canvas(page).locator('.doc-html, .doc-richtext'),
        ).toContainText('本文');
        // ナビ用の正規スクリプトは存在する。入力本文へ注入した script だけを検査する。
        await expect(
            canvas(page).locator('.doc-html script, .doc-richtext script'),
        ).toHaveCount(0);
        expect(
            await canvas(page)
                .locator('body')
                .evaluate(
                    () => (window as Window & { __pwned?: boolean }).__pwned,
                ),
        ).toBeUndefined();
    });

    test('置けない場所には置かせない', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-guard-${Date.now()}`);

        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        // section がまだ無いので、section の中にしか置けない見出しは押せない
        await expect(page.getByTestId('palette-heading')).toBeDisabled();
        await expect(page.getByTestId('palette-section')).toBeEnabled();

        // section を置くと押せるようになる
        await page.getByTestId('palette-section').click();
        await expect(page.getByTestId('palette-heading')).toBeEnabled();
    });

    // ── データ接続（Phase 9.6 / P0-C5・SPEC §4.9）──────────────

    test('見出しに記事の項目を接続でき、外せる', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-bind-${Date.now()}`);

        await page.getByTestId('layer-heading').first().click();

        // まだ接続していないので、始める口だけが出ている
        await expect(page.getByTestId('inspector-binding-prop')).toHaveCount(0);
        await page.getByTestId('inspector-binding-start').click();

        // 流し先は **bindable な prop だけ**（見出しは text のみ。level は出ない）
        await expect(
            page.getByTestId('inspector-binding-prop').locator('option'),
        ).toHaveCount(1);

        // 項目の候補は **その流し先に流せる型だけ**。本文（richtext）は出ない
        const fields = page.getByTestId('inspector-binding-field');
        await expect(fields.locator('option', { hasText: '本文' })).toHaveCount(
            0,
        );

        await fields.selectOption('title');
        await expect(page.getByTestId('inspector-binding')).toContainText(
            'title',
        );

        // 開き直しても残っている（選び直さないと Inspector が出ない）
        await page.reload();
        await page.getByTestId('layer-heading').first().click();
        await expect(page.getByTestId('inspector-binding')).toHaveCount(1);

        // 外せる。**外したらキーごと消える**ので、始める口に戻る
        await page.getByTestId('inspector-binding-remove').click();
        await expect(page.getByTestId('inspector-binding-start')).toBeVisible();

        await page.reload();
        await page.getByTestId('layer-heading').first().click();
        await expect(page.getByTestId('inspector-binding')).toHaveCount(0);
    });

    test('リッチテキストの接続先には本文しか出ない', async ({ page }) => {
        await editorWithTwoHeadings(page, `editor-bind-html-${Date.now()}`);

        await page.getByTestId('layer-section').click();
        await page.getByTestId('palette-richtext').click();
        await page.getByTestId('layer-richtext').first().click();

        await page.getByTestId('inspector-binding-start').click();

        /*
         * `richtext.html` は `format: "html"` を宣言しているので、
         * **richtext の Field しか流せない**（§4.9 の双方向）。
         */
        const options = page
            .getByTestId('inspector-binding-field')
            .locator('option');

        await expect(options.filter({ hasText: '本文' })).toHaveCount(1);
        await expect(options.filter({ hasText: 'タイトル' })).toHaveCount(0);
    });

    // ── 再利用ブロック（Phase 9.6 / P0-C7・SPEC §4.5）─────────

    /**
     * **1 か所の変更が、使っている全ページへ反映される**（DoD）。
     *
     * そして**公開するまでは反映されない** — これが 2 document 案の要点である。
     */
    test('再利用ブロックを作り、公開したときにページへ反映される', async ({
        page,
    }) => {
        const key = `hdr-${Date.now()}`;

        await loginAsOwner(page);

        // 1. 作る（一覧から）
        await page.goto('/admin/reusable-components');
        await page.getByTestId('reusable-key').fill(key);
        await page.getByTestId('reusable-label').fill('共通ヘッダー');
        await page.getByTestId('reusable-create').click();

        // 編集画面へ移る
        await page.waitForURL('**/admin/reusable-components/**');
        await expect(page.getByTestId('reusable-unpublished')).toBeVisible();

        // まだどこにも使われていない、と言う
        await expect(page.getByTestId('reusable-impact')).toContainText(
            'まだどのページにも使われていません',
        );

        // 2. 公開する。**確認の文面はサーバーが出す**
        await page.getByTestId('reusable-publish').click();
        await expect(page.getByTestId('reusable-confirmation')).toBeVisible();
        await page.getByTestId('reusable-publish-confirm').click();

        await expect(page.getByTestId('reusable-unpublished')).toHaveCount(0);
    });

    /**
     * **参照している node は、中を選べない。**
     *
     * 参照側は `props` / `children` を持たないので、中を編集する先が無い。
     * 代わりに **2 つの道**（実体を編集する / 切り離す）が出る。
     */
    test('参照しているブロックは中を編集できず、2つの道が出る', async ({
        page,
    }) => {
        const key = `ref-${Date.now()}`;
        const slug = `uses-reusable-${Date.now()}`;

        await loginAsOwner(page);

        // 公開済みの実体を用意する
        await page.goto('/admin/reusable-components');
        await page.getByTestId('reusable-key').fill(key);
        await page.getByTestId('reusable-label').fill('参照されるもの');
        await page.getByTestId('reusable-create').click();
        await page.waitForURL('**/admin/reusable-components/**');
        await page.getByTestId('reusable-publish').click();
        await page.getByTestId('reusable-publish-confirm').click();

        const reusableId = (page.url().split('/').pop() ?? '').split('?')[0];

        // ページを作り、API から参照を置く（**入れ物の type は起点と同じ section**）
        await createPage(page, slug);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        const pageId = page.url().match(/pages\/(\d+)\/editor/)?.[1] ?? '';

        /*
         * **参照を置く口は、まだ画面に無い**（パレットへ出すのは別の作業）。
         * だから画面と**同じ経路**（同一オリジンの fetch + XSRF cookie）で置く。
         * `page.request` は XSRF ヘッダを付けないので使わない。
         */
        const placed = await page.evaluate(
            async ([id, reusable]) => {
                const token = document.cookie.match(
                    /(?:^|;\s*)XSRF-TOKEN=([^;]+)/,
                );

                const current = await fetch(`/api/v1/pages/${id}/document`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const meta = (await current.json()) as {
                    meta?: { version?: number };
                };

                const response = await fetch(`/api/v1/pages/${id}/document`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN':
                            token === null ? '' : decodeURIComponent(token[1]),
                    },
                    // **`command` キーで包む**（`PageApiController::patch()` がそう読む）
                    body: JSON.stringify({
                        command: {
                            operation: 'component.insert',
                            parent: 'page',
                            component: {
                                type: 'section',
                                instanceOf: reusable,
                            },
                            expectedVersion: meta.meta?.version ?? 0,
                        },
                    }),
                });

                return { ok: response.ok, body: await response.text() };
            },
            [pageId, reusableId] as const,
        );

        expect(placed.ok, placed.body).toBeTruthy();

        await page.reload();
        await page.getByTestId('layer-section').first().click();

        // 中身の欄は出ず、2 つの道が出る
        await expect(page.getByTestId('inspector-instance')).toBeVisible();
        await expect(page.getByTestId('inspector-instance-edit')).toBeVisible();
        await expect(
            page.getByTestId('inspector-instance-detach'),
        ).toBeVisible();
        await expect(page.getByTestId('inspector-prop-text')).toHaveCount(0);
    });

    /**
     * **DoD そのもの**（SPEC §4.5）。
     *
     * 再利用ブロックを作って公開し、**画面からページへ置き**、実体を直して公開すると
     * そのページへ反映される。ここまで全部**画面の操作だけ**で通す。
     */
    test('画面から再利用ブロックを置き、直して公開するとページへ反映される', async ({
        page,
    }) => {
        const key = `place-${Date.now()}`;
        const slug = `place-page-${Date.now()}`;

        await loginAsOwner(page);

        // 1. 作って公開する
        await page.goto('/admin/reusable-components');
        await page.getByTestId('reusable-key').fill(key);
        await page.getByTestId('reusable-label').fill('置くヘッダー');
        await page.getByTestId('reusable-create').click();
        await page.waitForURL('**/admin/reusable-components/**');
        await page.getByTestId('reusable-publish').click();
        await page.getByTestId('reusable-publish-confirm').click();
        await expect(page.getByTestId('reusable-unpublished')).toHaveCount(0);

        const reusableUrl = page.url();

        // 2. ページを作り、**画面から**置く
        await createPage(page, slug);
        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await page.getByTestId(`palette-reusable-${key}`).click();
        await expect(page.getByTestId('layer-section')).toHaveCount(1);

        // 参照として置かれている（中身の欄が出ず、2 つの道が出る）
        await page.getByTestId('layer-section').first().click();
        await expect(page.getByTestId('inspector-instance')).toBeVisible();

        const editorUrl = page.url();

        /*
         * 3. 実体の画面に戻ると、**置いたページが数に出る**。
         *
         * ページ自体はまだ公開していないので、**公開中ではなく下書きとして**数える。
         * ここが「公開中の 1 ページ」になっていたら、過大な警告を出していることになる。
         */
        await page.goto(reusableUrl);
        await expect(page.getByTestId('reusable-impact')).toContainText(
            '下書きのみ 1 ページ',
        );
        await expect(page.getByTestId('reusable-impact')).toContainText(
            '公開中のページには、まだ使われていません',
        );

        // 4. ページ側の Canvas に、実体の中身が出ている
        await page.goto(editorUrl);
        await expect(canvas(page).locator('.doc-section')).toHaveCount(1);
    });

    test('任意の HTML を貼る入口がパレットに無い', async ({ page }) => {
        await loginAsOwner(page);
        await createPage(page, `editor-html-${Date.now()}`);

        await page.getByTestId('page-editor-link').click();
        await page.waitForURL('**/editor');

        await expect(page.getByTestId('palette-html')).toHaveCount(0);
    });
});
