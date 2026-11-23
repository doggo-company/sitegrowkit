import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/*
 * 触り心地の門（docs/feel-bar.md）。**線を超えたら赤 = その版は出さない。**
 *
 * 2026-11-05 にメンテナから「UI の触り心地が大事。ずっと言い続けてる」「毎回ここで終わるの」。
 * 謝って決まりを足すだけで、触り心地が良くなったことが一度も無かった。だから合格線を数字にして、
 * 誰が作っても落ちたら止まるようにする。数字はその夜に測った値から決めた（docs/feel-bar.md）。
 *
 * 測り方（docs/feel-bar.md と同じ。変えるときは両方）:
 * - CPU 4 倍遅く（ノート PC を想定）・1366×768
 * - 押すのは人と同じく 0.12 秒押してから離す（Playwright の click は 0 秒で離すので、押した見た目が測れない）
 * - 「押下の見た目」= 押してから、押したものの見た目（色・影・位置・透明度）が変わるまで
 * - 「完了」= 押してから、完了の表示（「公開しました」等）が出るまで
 */

const BAR = {
    pressMs: 100,
    doneMs: 600,
    cls: 0.1,
    targetPx: 44,
} as const;

const THROTTLE = 4;

test.use({
    viewport: { width: 1366, height: 768 },
    // 標準の E2E（playwright.config.ts）では setup が作る状態、門（playwright.feel.config.ts）では門の setup が作る状態
    storageState:
        process.env.FEEL_AUTH_STATE ??
        process.env.AGENTCMS_E2E_AUTH_STATE ??
        'tests/e2e/.auth/admin.json',
});

test.beforeEach(async ({ page, context }) => {
    await context.addInitScript(() => {
        const w = window as unknown as { __feelShifts: number[] };
        w.__feelShifts = [];

        try {
            new PerformanceObserver((list) => {
                for (const e of list.getEntries()) {
                    w.__feelShifts.push(
                        (e as unknown as { value: number }).value,
                    );
                }
            }).observe({ type: 'layout-shift', buffered: true });
        } catch {
            // layout-shift を持たないブラウザでは CLS を 0 として扱う（Chromium だけで回す門）
        }
    });
    const cdp = await context.newCDPSession(page);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: THROTTLE });
});

type Done = { text?: string; selector?: string; gone?: string; path?: RegExp };

type Feel = {
    pressMs: number | null;
    doneMs: number;
    cls: number;
    blankMs: number;
};

/**
 * 1 回押して、押下の見た目・完了・CLS・白くなった時間を測る。
 * 測るのは**ページの中の時計**（Playwright の待ちは 100ms 刻みで粗く、数字が嘘になる）。
 */
async function press(page: Page, target: Locator, done: Done): Promise<Feel> {
    await target.scrollIntoViewIfNeeded();
    await target.hover();
    // hover の色の移り変わりが終わるのを待つ（押した見た目と混ぜない）
    await page.waitForTimeout(450);

    await target.evaluate(
        (el, d) => {
            const w = window as unknown as Record<string, unknown>;
            const props = [
                'background-color',
                'color',
                'border-color',
                'box-shadow',
                'transform',
                'translate',
                'scale',
                'opacity',
                'filter',
            ];
            const els = [el, ...Array.from(el.querySelectorAll('*'))].slice(
                0,
                20,
            );
            const snap = () =>
                els
                    .map((e) =>
                        props
                            .map((p) => getComputedStyle(e).getPropertyValue(p))
                            .join('|'),
                    )
                    .join('#');
            const before = snap();
            const main = () => document.querySelector('main') ?? document.body;
            const baseText = (main().textContent ?? '').length;
            const state = {
                t0: 0,
                pressAt: 0,
                doneAt: 0,
                blankMs: 0,
                shifts0: (w.__feelShifts as number[]).length,
            };
            w.__feel = state;
            const path = d.path ? new RegExp(d.path) : null;
            const isDone = () =>
                (!d.text || document.body.innerText.includes(d.text)) &&
                (!d.selector || document.querySelector(d.selector) !== null) &&
                (!d.gone || document.querySelector(d.gone) === null) &&
                (!path || path.test(location.pathname));
            document.addEventListener(
                'pointerdown',
                () => (state.t0 = Date.now()),
                { capture: true, once: true },
            );
            let blankSince = 0;
            const tick = () => {
                if (state.t0 !== 0) {
                    if (
                        state.pressAt === 0 &&
                        (!el.isConnected || snap() !== before)
                    ) {
                        state.pressAt = Date.now();
                    }

                    const ratio =
                        (main().textContent ?? '').length /
                        Math.max(baseText, 1);

                    if (baseText > 50 && ratio < 0.2) {
                        blankSince ||= Date.now();
                    } else if (blankSince) {
                        state.blankMs += Date.now() - blankSince;
                        blankSince = 0;
                    }

                    if (isDone()) {
                        state.doneAt = Date.now();

                        return;
                    }

                    if (Date.now() - state.t0 > 15000) {
                        return;
                    }
                }

                requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        },
        {
            text: done.text,
            selector: done.selector,
            gone: done.gone,
            path: done.path?.source,
        },
    );

    await page.mouse.down();
    await page.waitForTimeout(120);
    await page.mouse.up();

    await expect
        .poll(
            () =>
                page.evaluate(
                    () =>
                        (window as unknown as { __feel: { doneAt: number } })
                            .__feel.doneAt,
                ),
            { timeout: 15000 },
        )
        .toBeGreaterThan(0);
    // 完了のあと遅れて起きるずれも拾う
    await page.waitForTimeout(500);

    return page.evaluate(() => {
        const w = window as unknown as {
            __feel: {
                t0: number;
                pressAt: number;
                doneAt: number;
                blankMs: number;
                shifts0: number;
            };
            __feelShifts: number[];
        };
        const f = w.__feel;

        return {
            pressMs: f.pressAt ? f.pressAt - f.t0 : null,
            doneMs: f.doneAt - f.t0,
            cls: w.__feelShifts.slice(f.shifts0).reduce((a, v) => a + v, 0),
            blankMs: f.blankMs,
        };
    });
}

/** 合格線と比べる。どの操作で何が超えたかを、そのまま失敗の文にする */
function expectFeel(
    name: string,
    feel: Feel,
    opts: { press?: boolean } = {},
): void {
    test.info().annotations.push({
        type: 'feel',
        description: `${name}: ${JSON.stringify(feel)}`,
    });

    if (opts.press !== false) {
        expect(
            feel.pressMs,
            `${name}: 押しているあいだ見た目が変わらない（合格線 ${BAR.pressMs}ms 以内）`,
        ).not.toBeNull();
        expect(
            feel.pressMs!,
            `${name}: 押下の見た目が遅い`,
        ).toBeLessThanOrEqual(BAR.pressMs);
    }

    expect(
        feel.doneMs,
        `${name}: 完了の表示まで遅い（合格線 ${BAR.doneMs}ms）`,
    ).toBeLessThanOrEqual(BAR.doneMs);
    expect(
        feel.cls,
        `${name}: 画面がずれた（CLS 合格線 ${BAR.cls}）`,
    ).toBeLessThanOrEqual(BAR.cls);
    expect(feel.blankMs, `${name}: 画面が白くなった`).toBe(0);
}

/** 押せる所（ボタン・選択肢・サイドバー・表の中のリンク）が 44px 以上か。文中の文字リンクは数えない（WCAG 2.5.8 の例外） */
async function expectTargets(page: Page, where: string): Promise<void> {
    const small = await page.evaluate((min) => {
        const sel =
            'button, [role=button], [role=option], [role=combobox], [data-sidebar=menu-button], [data-slot=button], td a, th a';
        const visible = (e: Element) => {
            const b = e.getBoundingClientRect();

            return (
                b.width > 0 &&
                b.height > 0 &&
                getComputedStyle(e).visibility !== 'hidden' &&
                e.closest('[aria-hidden=true]') === null
            );
        };
        /*
         * チェックボックスは見た目の箱が小さくてよい。測るのは**押して当たる範囲**＝箱と、
         * 押すと切り替わるラベル（`for` で結んだもの・箱を包むもの）のうち高い方。
         * ラベルは押せる範囲の一部なので、ラベルの真ん中を押してラベルに当たることも見る。
         */
        const hitHeight = (el: HTMLElement) => {
            const labels = [
                ...Array.from(
                    el.id
                        ? document.querySelectorAll<HTMLLabelElement>(
                              `label[for="${CSS.escape(el.id)}"]`,
                          )
                        : [],
                ),
                ...(el.closest('label') ? [el.closest('label')!] : []),
            ].filter((l) => {
                const r = l.getBoundingClientRect();

                if (r.width === 0 || r.height === 0) {
                    return false;
                }

                l.scrollIntoView({ block: 'center' });
                const c = l.getBoundingClientRect();
                const hit = document.elementFromPoint(
                    c.left + c.width / 2,
                    c.top + c.height / 2,
                );

                return hit !== null && (hit === l || l.contains(hit));
            });

            return Math.max(
                el.getBoundingClientRect().height,
                ...labels.map((l) => l.getBoundingClientRect().height),
            );
        };
        const small: string[] = [];

        for (const e of Array.from(document.querySelectorAll(sel))) {
            if (!visible(e)) {
                continue;
            }

            const name = (
                e.textContent ||
                e.getAttribute('aria-label') ||
                e.id ||
                e.tagName
            )
                .trim()
                .slice(0, 16);

            if (e.getAttribute('role') === 'checkbox') {
                const h = hitHeight(e as HTMLElement);

                if (h < min) {
                    small.push(
                        `${name}（チェックボックスとラベル ${Math.round(h)}px）`,
                    );
                }

                continue;
            }

            if (e.getBoundingClientRect().height < min) {
                small.push(
                    `${name}(${Math.round(e.getBoundingClientRect().height)}px)`,
                );
            }
        }

        return small;
    }, BAR.targetPx);
    expect(small, `${where}: 押せる所が ${BAR.targetPx}px 未満`).toEqual([]);
}

test.describe('触り心地の門（docs/feel-bar.md）', () => {
    test('ページを1枚作って、ブロック編集の画面のまま公開する', async ({
        page,
    }) => {
        const stamp = `${Date.now()}`.slice(-7);
        await page.goto('/admin/pages');
        await expectTargets(page, 'ページ一覧');
        expectFeel(
            '新規作成',
            await press(
                page,
                page.getByRole('link', { name: '新規作成' }).first(),
                {
                    path: /\/admin\/pages\/create$/,
                    selector: '[data-testid=page-title-input]',
                },
            ),
        );
        await page.getByTestId('page-title-input').fill(`触り心地 ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`feel-${stamp}`);
        expectFeel(
            '作成する',
            await press(page, page.getByRole('button', { name: '作成する' }), {
                path: /\/admin\/pages\/\d+\/edit$/,
                selector: '[data-testid=page-editor-link]',
            }),
        );
        expectFeel(
            'ブロックを編集',
            await press(page, page.getByTestId('page-editor-link'), {
                selector: '[data-testid=editor-empty-start]',
            }),
        );
        await expectTargets(page, 'ブロック編集');
        expectFeel(
            '見出しを置く',
            await press(page, page.getByTestId('editor-empty-start'), {
                gone: '[data-testid=editor-empty-start]',
            }),
        );

        // 公開は編集の画面のまま。設定画面へ戻らない（戻ると画面が 2 回替わる）
        const publish = page.getByTestId('editor-publish-button');
        await expect(
            publish,
            'ブロック編集の画面に「公開する」が無い',
        ).toBeVisible();
        await expect(publish).toBeEnabled({ timeout: 10000 });
        expectFeel(
            '公開する',
            await press(page, publish, { text: 'ページを公開しました' }),
        );
        await expect(page).toHaveURL(/\/admin\/pages\/\d+\/editor$/);
        expect((await page.request.get(`/feel-${stamp}`)).status()).toBe(200);
    });

    test('お知らせ（記事）を書いて公開する', async ({ page }) => {
        const stamp = `${Date.now()}`.slice(-7);
        const type = 'post';
        await page.goto(`/admin/${type}`);
        const quick = page.getByTestId('public-type-quick-start');

        if (await quick.isVisible()) {
            await quick.click();
            await expect(quick).toHaveCount(0);
        }

        expectFeel(
            '新規作成',
            await press(
                page,
                page.getByRole('link', { name: '新規作成' }).first(),
                {
                    path: new RegExp(`/admin/${type}/create$`),
                    selector: '#title',
                },
            ),
        );
        await expectTargets(page, '記事の作成');
        await page.fill('#title', `教室をひらきました ${stamp}`);
        await page.fill('#body', '11月から土曜のクラスを始めます。');
        // 選択の欄は押した瞬間に開く（開くこと自体が見た目の変化）
        expectFeel(
            '状態を開く',
            await press(page, page.locator('#status'), {
                selector: '[role=option]',
            }),
            { press: false },
        );
        await expectTargets(page, '状態の選択肢');
        expectFeel(
            '公開を選ぶ',
            await press(
                page,
                page.getByRole('option', { name: '公開', exact: true }),
                { selector: '#status[data-state=closed]' },
            ),
        );
        expectFeel(
            '保存',
            await press(
                page,
                page.getByRole('button', { name: '保存', exact: true }),
                {
                    path: new RegExp(`^/admin/${type}$`),
                    text: `教室をひらきました ${stamp}`,
                },
            ),
        );
    });

    test('フォームの受信を開いて、対応済みにする', async ({
        page,
        browser,
    }) => {
        const stamp = `${Date.now()}`.slice(-7);
        // 訪問者として 1 件送る（ログインしていない別のブラウザ）
        const visitor = await browser.newContext();
        const v = await visitor.newPage();
        await v.goto('/contact');
        await v.waitForTimeout(3500); // 送信が早すぎると迷惑投稿として扱われる
        await v.fill('#f-name', `山田 ${stamp}`);
        await v.fill('#f-email', `feel-${stamp}@example.test`);
        await v.selectOption('#f-subject', { index: 1 });
        await v.fill('#f-message', '体験レッスンの申し込みです');
        await v.click('button[type=submit]');
        await v.waitForLoadState('load');
        await visitor.close();

        await page.goto('/admin/forms');
        await expectTargets(page, 'フォーム一覧');
        expectFeel(
            '受信一覧',
            await press(
                page,
                page.getByTestId('form-submissions-link').first(),
                { selector: '[data-testid=submission-open]' },
            ),
        );
        await expectTargets(page, '受信一覧');
        expectFeel(
            '受信を開く',
            await press(
                page,
                page
                    .getByTestId('submission-open')
                    .filter({ hasText: stamp })
                    .first(),
                { selector: '[data-testid=submission-status]' },
            ),
        );
        expectFeel(
            '状態を開く',
            await press(page, page.getByTestId('submission-status'), {
                selector: '[role=option]',
            }),
            { press: false },
        );
        expectFeel(
            '対応済みを選ぶ',
            await press(page, page.getByRole('option', { name: '対応済み' }), {
                selector: '[data-testid=submission-status][data-state=closed]',
            }),
        );
        expectFeel(
            '変更する',
            await press(page, page.getByTestId('submission-status-save'), {
                text: '状態を変更しました',
            }),
        );
        expectFeel(
            '一覧へ戻る',
            await press(page, page.getByRole('link', { name: '一覧へ戻る' }), {
                selector: '[data-testid=submission-open]',
            }),
        );
    });

    test('ダッシュボードの押せる所は 44px 以上（サイドバーを畳んでも）', async ({
        page,
    }) => {
        await page.goto('/dashboard');
        await expectTargets(page, 'ダッシュボード');

        // 畳んだサイドバー（アイコンだけ）でも 44px。畳んだ状態は次の画面にも残るので、最後に開き直す
        const trigger = page.getByTestId('sidebar-trigger');
        await trigger.click();
        await expect(
            page.locator('[data-collapsible=icon]').first(),
        ).toBeVisible();
        await page.waitForTimeout(400); // 畳む動き（幅の移り変わり）が終わるのを待つ

        try {
            await expectTargets(page, 'サイドバーを畳んだダッシュボード');
        } finally {
            await trigger.click();
            await expect(page.locator('[data-collapsible=icon]')).toHaveCount(
                0,
            );
        }
    });

    test('チェックボックスの押せる範囲は 44px 以上', async ({ page }) => {
        for (const [url, where] of [
            ['/admin/site', 'サイト設定'],
            ['/admin/forms/new', 'フォームの作成'],
            ['/admin/notifications', '通知設定'],
            ['/admin/content-types/create', '投稿タイプの作成'],
        ] as const) {
            await page.goto(url);
            await expect(
                page.locator('[role=checkbox]').first(),
                `${where}にチェックボックスが無い`,
            ).toBeVisible();
            await expectTargets(page, where);
        }

        // ページ設定の「検索エンジンに登録しない」
        const stamp = `${Date.now()}`.slice(-7);
        await page.goto('/admin/pages/create');
        await page.getByTestId('page-title-input').fill(`チェック ${stamp}`);
        await page.getByTestId('page-slug-input').fill(`check-${stamp}`);
        await page.getByRole('button', { name: '作成する' }).click();
        await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
        await expect(page.getByTestId('page-noindex-toggle')).toBeVisible();
        await expectTargets(page, 'ページ設定');
    });
});

for (const device of [
    { name: 'スマホ', width: 390, height: 844 },
    { name: 'タブレット縦', width: 820, height: 1180 },
] as const) {
    test.describe(`触り心地の門（${device.name} ${device.width}×${device.height}）`, () => {
        test.use({
            viewport: { width: device.width, height: device.height },
            hasTouch: true,
            isMobile: true,
        });

        /** その要素が画面の中にあり、真ん中を押すとその要素に当たるか（上に何かが重なっていないか） */
        async function reachable(page: Page, testId: string): Promise<boolean> {
            return page.getByTestId(testId).evaluate((el) => {
                const r = el.getBoundingClientRect();
                const hit = document.elementFromPoint(
                    r.left + r.width / 2,
                    r.top + r.height / 2,
                );

                return (
                    r.top >= 0 &&
                    r.bottom <= window.innerHeight &&
                    hit !== null &&
                    (hit === el || el.contains(hit))
                );
            });
        }

        test('ブロック編集で下までスクロールしても「公開する」が見えて押せる（15 周目 T1）', async ({
            page,
        }) => {
            const stamp = `${Date.now()}`.slice(-7);
            await page.goto('/admin/pages/create');
            await page
                .getByTestId('page-title-input')
                .fill(`スマホ公開 ${stamp}`);
            await page
                .getByTestId('page-slug-input')
                .fill(`sp${device.width}-${stamp}`);
            await page.getByRole('button', { name: '作成する' }).click();
            await page.waitForURL(/\/admin\/pages\/\d+\/edit$/);
            await page.getByTestId('page-editor-link').click();
            await page.waitForURL(/\/editor$/);
            await page.getByTestId('editor-empty-start').click();
            await expect(page.getByTestId('editor-save-state')).toContainText(
                '保存済み',
            );
            await page.getByTestId('palette-text').click();
            await expect(page.getByTestId('editor-save-state')).toContainText(
                '保存済み',
            );

            // 打っている間（キーボードが出ている間）は、下の帯を出さない（入力欄を隠さない）
            await page.locator('#prop-text').click();
            await page.keyboard.type('駅から歩いて 5 分です。');
            await expect(
                page.getByTestId('editor-bottom-bar'),
                '打っている間に下の帯が出ている（入力欄を隠す）',
            ).toHaveCount(0);

            // 打ち終えて欄を離れると帯が戻る
            await page.locator('h1').first().click();
            await expect(page.getByTestId('editor-bottom-bar')).toBeVisible();
            await expect(page.getByTestId('editor-save-state')).toContainText(
                '保存済み',
                { timeout: 10_000 },
            );

            // なかほど・いちばん下までスクロールしても、「公開する」が画面の中にあって押せる
            for (const where of ['なかほど', 'いちばん下'] as const) {
                await page.evaluate((w) => {
                    const max =
                        document.documentElement.scrollHeight -
                        window.innerHeight;
                    window.scrollTo(0, w === 'なかほど' ? max / 2 : max);
                }, where);
                await page.waitForTimeout(300);
                expect(
                    await reachable(page, 'editor-publish-button-bottom').catch(
                        () => false,
                    ),
                    `${where}までスクロールすると「公開する」に届かない`,
                ).toBe(true);
            }

            // 帯はサイドバーに重ならない（タブレットではサイドバーが出ている）
            expect(
                await page.evaluate(() => {
                    const bar = document.querySelector(
                        '[data-testid=editor-bottom-bar]',
                    );
                    const main = document.querySelector('main');

                    return (
                        bar !== null &&
                        main !== null &&
                        bar.getBoundingClientRect().left + 1 >=
                            main.getBoundingClientRect().left
                    );
                }),
                '下の帯がサイドバーに重なっている',
            ).toBe(true);

            const publish = page.getByTestId('editor-publish-button-bottom');
            await expect(publish).toBeEnabled();
            await expect(publish).toHaveText('公開する');
            await publish.tap();
            await expect(
                page.getByText('ページを公開しました').first(),
            ).toBeVisible();
            await expect(publish).toHaveText('公開中');
            expect(
                (
                    await page.request.get(`/sp${device.width}-${stamp}`)
                ).status(),
            ).toBe(200);
        });
    });
}
