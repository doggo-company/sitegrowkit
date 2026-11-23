import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * フォームを、**画面を 1 度も跨がずに**作れること（R-8A ④）。
 *
 * ## 何を数えるか
 *
 * オーナーの報告は「フォームの作成がうまくできなかった。カスタムフィールドを
 * 作らないとダメで**画面を跨ぐのは NG**」だった。実測すると、項目を 1 つ足すのに
 * **画面 2 つを 2 往復・保存 3 回**必要だった。
 *
 * ▷ だからここでは**往復の回数そのものを数える**。
 *   歩いているあいだに一度でも `/admin/field-groups` へ行ったら赤にする。
 *   「項目が保存できた」だけを見ると、**遠回りして辿り着いても緑**になる。
 *
 * ## 技術語彙を出していないこと（canary 作法）
 *
 * 「CSS や HTML を利用者に意識させない」が最上位の原則なので、
 * 画面に出る文字から**技術語彙が消えていること**を見る。
 * ▷ **0 件を名乗る前に、わざと 1 語出して捕まることを確かめる。**
 *   「1 語も無かった」と「そもそも見ていなかった」は結果の見た目が同じである。
 * ▷ 見るのは **`main` の中だけ**。サイドバーには「カスタムフィールド」の項目が
 *   在り、そちらは制作会社が使う道である（顧客面と制作会社面を分けるのは B-25）。
 *
 * ## 標準スイートへは入れない
 *
 * フォームと項目を作る。**使い捨てプロジェクト専用**の隔離レーンに置く。
 *
 * ```
 * APP_URL=http://localhost:8189 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/form-inline-fields.isolated.spec.ts
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

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

/**
 * 利用者に見せない語。
 *
 * **「キー」は入れていない。** 「主キー」のような別の意味で出る余地があり、
 * ここで赤にすると本題（跨がずに作れるか）が語の議論に埋もれる。
 * 見せない設計自体は `showKey` の既定で決めてある。
 */
const FORBIDDEN = [
    'カスタムフィールド',
    'フィールドグループ',
    '割当先',
    'スラッグ',
    'CSS',
    'HTML',
];

async function contentText(page: Page): Promise<string> {
    return (await page.locator('main').innerText()).replace(/\s+/g, ' ');
}

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

test('フォームは画面を跨がずに作れて、技術語彙が出ない', async ({ page }) => {
    await login(page);

    // ── 歩いた先を全部覚える（往復を数えるため）──────────────
    const visited: string[] = [];

    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) {
            visited.push(new URL(frame.url()).pathname);
        }
    });

    const key = `inquiry${Date.now().toString().slice(-6)}`;

    await page.goto('/admin/forms');
    await page.goto('/admin/forms/new');

    /*
     * **新規の画面が描けていることを、まず見る。**
     *
     * ここは生成直後の利用者が最初に触る画面である（フォームは 0 件なので、
     * 一覧からは必ずここへ来る）。props を 1 つ渡し忘れただけで白い画面になり、
     * **その先の assertion は「要素が見つからない」としか言わない** — 原因が
     * 白画面だったことは、この 1 行が無いと分からない。
     */
    await expect(page.getByTestId('form-key')).toBeVisible();

    await page.getByTestId('form-name').fill('お問い合わせ');
    await page.getByTestId('form-key').fill(key);
    await page.getByTestId('form-save').click();
    await page.waitForURL(`**/admin/forms/${key}`);

    // ── 項目をこの画面の中で足す ─────────────────────
    const fields = page.getByTestId('form-fields-section');

    await expect(fields).toBeVisible();

    await page.getByTestId('form-add-field').click();

    // 表示名だけ入れる。**キーは書かせない**（画面に欄が無いことも見る）
    await fields.getByTestId('field-group-field-label').first().fill('お名前');
    await expect(fields.getByTestId('field-group-field-key')).toHaveCount(0);

    await page.getByTestId('form-save').click();

    /*
     * **保存が終わるのを待ってから次へ行く**（監査 指摘 19・指摘 10 と同じ形）。
     * 待たずに `goto` すると、保存の応答が届く前に画面を捨てることがある。
     * 保存後はこの画面へ帰ってくる（`redirect_to`）ので、それを待つ。
     */
    await expect(page.getByTestId('flash-success')).toHaveText(
        '入力項目を保存しました',
    );

    // 保存されたことを、**読み直して**確かめる
    await page.goto(`/admin/forms/${key}`);
    await expect(
        page
            .getByTestId('form-fields-section')
            .getByTestId('field-group-field-label')
            .first(),
    ).toHaveValue('お名前');

    // 「キー」の見出しごと消えていること（R-8A ③・監査 指摘 16-a）
    await expect(fields).not.toContainText('キー');

    /*
     * 消す操作の確認文は、画面のどこよりも読まれる（監査 指摘 16-b）。
     * **サーバーの文言をそのまま出すと「カスタムフィールド」「束」が出る**ので、
     * この画面では言い換えている。言い換えが効いていることを、実物で見る。
     */
    await fields.getByRole('button', { name: '削除' }).first().click();
    await page.getByTestId('form-save').click();

    const dialog = page.getByTestId('operation-confirmation');

    await expect(dialog).toBeVisible();
    await expect(dialog).not.toContainText('カスタムフィールド');
    await expect(dialog).not.toContainText('束');
    // 事実は残っている（柔らかく言い換えていない）
    await expect(dialog).toContainText('入力項目');

    /*
     * **消した項目も名前で呼ぶ**（監査 指摘 20）。
     * 消した行は画面から消えているので、現在の行だけを見ると
     * **一番説明が要る項目だけが `field_1` のまま**出る。
     * ここは unit では見えない（表を併合しているのは画面の配線）。
     */
    await expect(dialog).toContainText('お名前');
    await expect(dialog).not.toContainText('field_1');

    await dialog.getByRole('button', { name: 'キャンセル' }).click();

    /*
     * **往復 0。** 項目を作るために別の画面へ行っていない。
     * 「保存できた」だけを見ると、遠回りしても緑になる。
     *
     * ▷ ここが最初に捕まえたのは**最後の 1 押し**だった — 保存すると
     *   カスタムフィールドの一覧へ飛ばされていた（監査 指摘 18）。
     *   歩き終わりまで数えないと、途中まで往復 0 でも意味が無い。
     */
    expect(
        visited.filter((path) => path.startsWith('/admin/field-groups')),
        `別の画面へ行っています: ${visited.join(' → ')}`,
    ).toEqual([]);

    // ── 技術語彙が出ていないこと（先に canary で見張りを確かめる）──
    await page.evaluate(() => {
        const canary = document.createElement('p');

        canary.id = '__vocab_canary__';
        canary.textContent = 'カスタムフィールド';
        document.querySelector('main')?.appendChild(canary);
    });

    const withCanary = await contentText(page);

    expect(
        withCanary.includes('カスタムフィールド'),
        'わざと出した語を拾えていません（この検査は 0 件を名乗れません）',
    ).toBe(true);

    await page.evaluate(() => {
        document.querySelector('#__vocab_canary__')?.remove();
    });

    const text = await contentText(page);
    const found = FORBIDDEN.filter((word) => text.includes(word));

    /*
     * 指摘 24 で flash が**初めて画面に出るようになった**。既存の文言には
     * 「カスタムフィールド「…」を保存しました」もあり、**その画面では正しい語彙**だが、
     * **この画面に出てよいのは中立の文言だけ**である（監査 指摘 24-a）。
     */

    expect(
        found,
        `利用者に見せない語が画面に出ています: ${found.join(', ')}`,
    ).toEqual([]);
});
