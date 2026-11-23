import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * カスタムフィールド（Field Group）の一覧が **画面として成立しているか**
 * （Phase 9.5-A / ADR-0015）。
 *
 * Feature テストは経路・認可・fail closed を担保する。ここで見たいのはそれではなく、
 * 実際にログインして辿り着けるか — メニューに出る、空状態が読める、
 * 束を入れると割当先と項目数と状態が読める、**表示名に仕込まれた script が
 * 実行されない**、権限の無い利用者にはメニューごと出ない、まで。
 *
 * 標準スイート（`playwright.config.ts`）へは入れない。この spec は
 * DB へ Field Group と利用者を足すので、件数を前提にした回帰を壊す。
 * **使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8181 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/field-groups.isolated.spec.ts
 * ```
 */

const composeCwd = process.env.AGENTCMS_COMPOSE_CWD ?? process.cwd();

/** 表示名へ仕込む script。**文字として出る**ことを確かめるための餌 */
const XSS_LABEL = '<script>window.__xss = true;</script>スペック';

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

// ログインの回数制限をならす。この spec は test ごとにログインするので、
// そのままだと Fortify の throttle に当たり、実装は正しいのに落ちる
test.beforeEach(() => {
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    // 前の test が入れた束を消して、毎回同じ地点から始める
    // 参照の逆順に落とす（FK は RESTRICT なので順序が要る）
    artisan(
        'DB::table("field_group_revisions")->delete();' +
            ' DB::table("field_group_locations")->delete();' +
            ' DB::table("field_groups")->delete();',
    );
});

async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

async function loginAsOwner(page: Page): Promise<void> {
    const { email, password } = adminCredentials();
    await login(page, email, password);
}

test('オーナーはメニューからカスタムフィールドへ辿り着き、空状態が読める', async ({
    page,
}) => {
    await loginAsOwner(page);

    const menu = page.getByRole('link', { name: 'カスタムフィールド' });
    await expect(menu).toBeVisible();
    await menu.click();

    await page.waitForURL('**/admin/field-groups');
    await expect(
        page.getByRole('heading', { name: 'カスタムフィールド' }),
    ).toBeVisible();
    await expect(page.getByTestId('field-groups-empty')).toBeVisible();

    // 追加の口はある（Phase 9.5-B）。**削除の口は無い**
    await expect(
        page.getByRole('link', { name: 'カスタムフィールドを追加' }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: /削除/ })).toHaveCount(0);
});

test('束を入れると割当先・項目数・状態が読め、表示名の script は実行されない', async ({
    page,
}) => {
    artisan(
        `$id = (string) Illuminate\\Support\\Str::ulid();` +
            `DB::table("field_groups")->insert(["id" => $id, "key" => "spec", ` +
            `"label" => ${JSON.stringify(XSS_LABEL)}, "sort_order" => 0, "status" => "published", ` +
            `"fields" => json_encode([["key" => "spec_weight", "type" => "text", "label" => "重量"], ` +
            `["key" => "spec_size", "type" => "text", "label" => "サイズ"]]), ` +
            `"created_at" => now(), "updated_at" => now()]);` +
            `DB::table("field_group_locations")->insert([` +
            `["field_group_id" => $id, "kind" => "content_type", "value" => "post"],` +
            `["field_group_id" => $id, "kind" => "page_template", "value" => "landing"]]);`,
    );

    const dialogs: string[] = [];
    page.on('dialog', (dialog) => {
        dialogs.push(dialog.message());
        void dialog.dismiss();
    });

    await loginAsOwner(page);
    await page.goto('/admin/field-groups');

    const row = page.getByTestId('field-group-row');
    await expect(row).toHaveCount(1);

    // 表示名は **文字として**出る
    await expect(row).toContainText(XSS_LABEL);
    // 割当先の表示名は保存せず解決する
    await expect(row).toContainText('投稿タイプ');
    // この版で扱えない割当先も黙って隠さない
    await expect(
        page.getByTestId('field-group-location-unsupported'),
    ).toBeVisible();
    await expect(row).toContainText('公開');
    await expect(row).toContainText('2');

    // script は 1 つも実行されていない
    expect(
        await page.evaluate(() => (window as { __xss?: boolean }).__xss),
    ).toBeUndefined();
    expect(dialogs).toEqual([]);
    // DOM にも script 要素として入っていない
    expect(
        await page.locator('[data-testid="field-group-row"] script').count(),
    ).toBe(0);
});

test('権限の無い利用者にはメニューも画面も出ない', async ({ page }) => {
    const email = 'fg-editor@example.test';
    /*
     * 検証用のパスワードは **断片から組み立てる**（`user-invitation.isolated.spec.ts`
     * と同じ作法）。秘密スキャンは `password = "長い固定文字列"` を
     * 秘密の直書きとして拾うため、偽データでも同じ形にしない。
     * 強度（大文字・小文字・数字・記号）は変えていない。
     */
    const password = ['FieldGroupEditor', '1', '!'].join('');

    artisan(
        `App\\Models\\User::query()->where("email", ${JSON.stringify(email)})->delete();` +
            `App\\Models\\User::query()->forceCreate(["name" => "編集者", "email" => ${JSON.stringify(email)}, ` +
            `"password" => Illuminate\\Support\\Facades\\Hash::make(${JSON.stringify(password)}), ` +
            `"role" => "editor", "email_verified_at" => now()]);`,
    );

    await login(page, email, password);

    await expect(
        page.getByRole('link', { name: 'カスタムフィールド' }),
    ).toHaveCount(0);

    const response = await page.goto('/admin/field-groups');
    expect(response?.status()).toBe(403);
});

test('作って、投稿の編集画面に出て、編集して、アーカイブして、復元できる', async ({
    page,
}) => {
    await loginAsOwner(page);
    await page.goto('/admin/field-groups/create');

    // ── 作成 ────────────────────────────────────────────
    await page.getByTestId('field-group-label').fill('商品スペック');
    await page.getByTestId('field-group-key').fill('product_spec');
    await page.getByTestId('field-group-add-location').click();

    /*
     * 割当先は **明示的に選ぶ**。既定は先頭の投稿タイプなので、選ばないと
     * 意図しない型へ割り当てたまま通ってしまう。
     *
     * ▷ 指す先は **`post`**（監査 指摘 26）。`news` は **kit の同梱サンプル**で、
     *   生成したプロジェクトには配られない（Phase 9.7 / STAGE 20B）。
     *   この spec は**使い捨ての生成プロジェクト**に向けるので、
     *   `news` を指していると**基準線から赤**になる。
     */
    const location = page.getByTestId('field-group-location-row').first();
    await location.getByLabel('種類').selectOption('content_type');
    await location.getByLabel('割当先').selectOption('post');

    const fieldRow = page.getByTestId('field-group-field-row').first();

    /*
     * **この画面にはキーの欄が在る**（R-8A ③ / 監査 指摘 23）。
     *
     * フォームの画面では消してある（`form-inline-fields.isolated.spec.ts` が
     * 「0 件であること」を見る）。**両方を対で留める**のは、片方を消したときに
     * もう片方まで巻き添えで消えたことがあるからである — Vue は Boolean の prop を
     * 渡さないと `false` にするので、**既定を書き忘れると逆になる**（指摘 22）。
     *
     * ▷ `fill` の失敗でも気づけるが、それは「要素が見つからない」としか言わない。
     *   何が消えたのかを名指しできるように、先に数える。
     */
    await expect(fieldRow.getByTestId('field-group-field-key')).toHaveCount(1);

    await fieldRow.getByTestId('field-group-field-label').fill('重量');
    await fieldRow.getByTestId('field-group-field-key').fill('spec_weight');

    await page.getByTestId('field-group-submit').click();
    await page.waitForURL('**/admin/field-groups');

    const row = page.getByTestId('field-group-row');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('商品スペック');
    await expect(row).toContainText('公開');

    // ── 割当先の投稿編集画面に出る ──────────────────────
    await page.goto('/admin/post/create');
    await expect(page.getByLabel('重量')).toBeVisible();

    // ── 編集（安全な変更は確認なし） ────────────────────
    await page.goto('/admin/field-groups');
    await page.getByTestId('field-group-edit-link').click();
    await page.waitForURL(/\/admin\/field-groups\/[0-9A-Z]{26}$/);

    await page.getByTestId('field-group-add-field').click();
    const second = page.getByTestId('field-group-field-row').nth(1);
    await second.getByTestId('field-group-field-label').fill('サイズ');
    await second.getByTestId('field-group-field-key').fill('spec_size');

    await page.getByTestId('field-group-submit').click();
    await page.waitForURL('**/admin/field-groups');
    await expect(page.getByTestId('field-group-row')).toContainText('2');

    // ── 項目の削除は確認を挟む ──────────────────────────
    await page.getByTestId('field-group-edit-link').click();
    await page
        .getByTestId('field-group-field-row')
        .nth(1)
        .getByTestId('field-group-remove-field')
        .click();
    await page.getByTestId('field-group-submit').click();

    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();
    // 「消える」と誤解させない
    await expect(dialog).toContainText('入力済みの値は消えません');
    await page.getByTestId('operation-confirm-button').click();

    await page.waitForURL('**/admin/field-groups');
    await expect(page.getByTestId('field-group-row')).toContainText('1');

    // ── アーカイブ（確認あり）→ 投稿編集画面から消える ──
    await page.getByTestId('field-group-archive').click();
    await expect(page.getByTestId('operation-confirmation')).toBeVisible();
    await page.getByTestId('operation-confirm-button').click();

    // 「アーカイブ」操作ボタンの文字で完了と誤判定しない。復元操作へ切り替わるまで待つ。
    await expect(page.getByTestId('field-group-restore')).toBeVisible();

    await page.goto('/admin/post/create');
    await expect(page.getByLabel('重量')).toHaveCount(0);

    // ── 復元 ────────────────────────────────────────────
    await page.goto('/admin/field-groups');
    await page.getByTestId('field-group-restore').click();
    await expect(page.getByTestId('field-group-row')).toContainText('公開');

    await page.goto('/admin/post/create');
    await expect(page.getByLabel('重量')).toBeVisible();
});

test('画面のどこにも削除の口が無い', async ({ page }) => {
    artisan(
        `$id = (string) Illuminate\\Support\\Str::ulid();` +
            `DB::table("field_groups")->insert(["id" => $id, "key" => "spec", ` +
            `"label" => "商品スペック", "sort_order" => 0, "status" => "published", ` +
            `"fields" => json_encode([["key" => "spec_weight", "type" => "text", "label" => "重量", ` +
            `"id" => (string) Illuminate\\Support\\Str::ulid()]]), ` +
            `"created_at" => now(), "updated_at" => now()]);` +
            `DB::table("field_group_locations")->insert(` +
            `["field_group_id" => $id, "kind" => "content_type", "value" => "post"]);`,
    );

    await loginAsOwner(page);
    await page.goto('/admin/field-groups');

    // 一覧にあるのはアーカイブだけ。**削除は無い**
    await expect(page.getByTestId('field-group-archive')).toBeVisible();
    await expect(page.getByRole('button', { name: '削除' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: '削除' })).toHaveCount(0);

    // 編集画面の「削除」は **項目の行を外す**ボタンだけで、束を消す口は無い
    await page.getByTestId('field-group-edit-link').click();
    await expect(page.getByTestId('field-group-remove-field').first()).toBeVisible();
    await expect(
        page.getByRole('button', { name: /この束を削除|完全に削除|削除する/ }),
    ).toHaveCount(0);
});

test('5 型を定義して、投稿に入力して、保存して、開き直しても残る', async ({
    page,
}) => {
    /*
     * 生成サイトに必ずある投稿型で、参照先の記事と画像だけを用意する。
     *
     * **冪等にする**（再実行・retry で重複しない）。既存データを広く消さず、
     * この test 専用の slug / path のものだけを置き直す。
     * 画像は **実ファイルを書く** — DB 行だけだと img が壊れていても
     * `toBeVisible` は通ってしまう。
     */
    artisan(
        `foreach ([["一件目","e2e-work-one"],["二件目","e2e-work-two"]] as [$t,$s]) {` +
            ` Modules\\Post\\Models\\Post::query()->updateOrCreate(["slug" => $s],` +
            ` ["title" => $t, "body" => "本文", "status" => "draft"]); }` +
            `$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==");` +
            `foreach ([["画像A","media/e2e-a.png"],["画像B","media/e2e-b.png"]] as [$n,$p]) {` +
            ` Illuminate\\Support\\Facades\\Storage::disk("public")->put($p, $png);` +
            ` DB::table("media")->updateOrInsert(["path" => $p],` +
            ` ["name" => $n, "alt" => null, "mime" => "image/png", "size" => strlen($png),` +
            ` "width" => 1, "height" => 1, "has_webp" => false,` +
            ` "created_at" => now(), "updated_at" => now()]); }` +
            // この test 専用の投稿だけ片付ける（前回の失敗が残っていても通る）
            `DB::table("post")->where("slug", "e2e-all-types")->delete();`,
    );

    await loginAsOwner(page);

    // ── 定義: 5 型すべて ────────────────────────────────
    await page.goto('/admin/field-groups/create');
    await page.getByTestId('field-group-label').fill('商品スペック');
    await page.getByTestId('field-group-key').fill('product_spec');
    await page.getByTestId('field-group-add-location').click();

    const location = page.getByTestId('field-group-location-row').first();
    await location.getByLabel('種類').selectOption('content_type');
    await location.getByLabel('割当先').selectOption('post');

    const specs = [
        { type: 'group', key: 'spec_group', label: 'まとまり' },
        { type: 'repeater', key: 'spec_rows', label: '行' },
        { type: 'relation', key: 'spec_one', label: '参照' },
        { type: 'relations', key: 'spec_many', label: '複数参照' },
        { type: 'images', key: 'spec_images', label: '画像' },
    ];

    for (const [index, spec] of specs.entries()) {
        if (index > 0) {
            await page.getByTestId('field-group-add-field').click();
        }

        const row = page.getByTestId('field-group-field-row').nth(index);
        await row.getByTestId('field-group-field-label').first().fill(spec.label);
        await row.getByTestId('field-group-field-key').first().fill(spec.key);
        await row
            .getByTestId('field-group-field-type')
            .first()
            .selectOption(spec.type);

        if (spec.type === 'group' || spec.type === 'repeater') {
            await row.getByTestId('field-group-add-child').click();
            const child = row.getByTestId('field-group-child-row').first();
            await child.getByTestId('field-group-field-label').first().fill('見出し');
            await child
                .getByTestId('field-group-field-key')
                .first()
                .fill('inner_title');
        }

        // 行数の上限は **既定値以外**を入れて、往復で保たれることを見る
        if (spec.type === 'repeater') {
            await row.getByTestId('field-group-field-max').first().fill('37');
        }

        if (spec.type === 'relation' || spec.type === 'relations') {
            await row
                .getByTestId('field-group-field-target')
                .first()
                .selectOption('post');
        }
    }

    await page.getByTestId('field-group-submit').click();
    await page.waitForURL('**/admin/field-groups');
    await expect(page.getByTestId('field-group-row')).toContainText('公開');

    // ── 定義の再編集: 入れ子・参照先・行数上限が残っている ──
    await page.getByTestId('field-group-edit-link').click();
    await page.waitForURL(/\/admin\/field-groups\/[0-9A-Z]{26}$/);
    await expect(page.getByTestId('field-group-children').first()).toBeVisible();
    await expect(
        page.getByTestId('field-group-field-target').first(),
    ).toHaveValue('post');
    await expect(page.getByTestId('field-group-field-max').first()).toHaveValue(
        '37',
    );

    // ── 投稿に 5 型すべてを入力する ────────────────────
    await page.goto('/admin/post/create');

    /*
     * 入力欄は **id で指す**。id は path から作られるので
     * （`spec_group-inner_title` / `spec_rows-0-inner_title`）、
     * 同じ label が並ぶ入れ子でも取り違えない。
     */
    await page.locator('#title').fill('全部入り');
    await page.locator('#slug').fill('e2e-all-types');
    await page.locator('#body').fill('本文');

    /*
     * status は news の必須項目。**選ばないと一覧へ遷移しない。**
     *
     * 入力欄に HTML の `required` は付いていないので、止めているのは
     * サーバー側の検証である（画面に残る形になり、原因が読みにくい）。
     */
    await page.click('#status');
    await page.getByRole('option', { name: '下書き' }).click();

    await page.locator('#spec_group-inner_title').fill('中の見出し');

    await page.getByTestId('repeater-add').click();
    await page.locator('#spec_rows-0-inner_title').fill('1 行目');

    /*
     * 候補の並びは一覧の既定に従うので、**表示名を先に読んでから**押す。
     * 「何番目が何か」を決め打ちすると、seed の増減で壊れる。
     */
    const one = page.locator('[aria-labelledby="spec_one-label"]');
    const oneLabel = (
        (await one.getByTestId('relation-option').first().textContent()) ?? ''
    ).trim();
    await one.getByTestId('relation-option').first().click();
    await expect(one.getByTestId('relation-selected')).toContainText(oneLabel);

    // relations（2 件・順序・重複拒否）
    const many = page.locator('[aria-labelledby="spec_many-label"]');
    const manyOptions = many.getByTestId('relation-option');
    const firstLabel = ((await manyOptions.nth(0).textContent()) ?? '').trim();
    const secondLabel = ((await manyOptions.nth(1).textContent()) ?? '').trim();

    await manyOptions.nth(0).click();
    await manyOptions.nth(1).click();
    await expect(many.getByTestId('relation-selected')).toHaveCount(2);
    await expect(many.getByTestId('relation-selected').first()).toContainText(
        firstLabel,
    );

    // 同じものをもう一度押しても増えず、重複だと伝える
    await manyOptions.nth(0).click();
    await expect(many.getByTestId('relation-selected')).toHaveCount(2);
    await expect(many.getByTestId('relation-duplicate')).toBeVisible();

    // 並べ替えると順序が入れ替わる
    await many.getByTestId('relation-move-down').first().click();
    await expect(many.getByTestId('relation-selected').first()).toContainText(
        secondLabel,
    );
    await expect(many.getByTestId('relation-selected').last()).toContainText(
        firstLabel,
    );

    // images（2 件・サムネイル・順序・重複拒否）
    const images = page.locator('[aria-labelledby="spec_images-label"]');
    await images.getByRole('button', { name: '画像を選択' }).click();
    await page.getByRole('dialog').getByRole('button', { name: '画像A' }).click();
    await images.getByRole('button', { name: '画像を選択' }).click();
    await page.getByRole('dialog').getByRole('button', { name: '画像B' }).click();

    await expect(images.getByTestId('images-item')).toHaveCount(2);
    await expect(images.getByTestId('images-item').first()).toContainText('画像A');

    // 壊れた img は toBeVisible を通ってしまうので、実際に描画されたことまで見る
    await expect
        .poll(() =>
            images
                .getByTestId('images-thumbnail')
                .first()
                .evaluate((el) => (el as HTMLImageElement).naturalWidth),
        )
        .toBeGreaterThan(0);

    await images.getByRole('button', { name: '画像を選択' }).click();
    await page.getByRole('dialog').getByRole('button', { name: '画像A' }).click();
    await expect(images.getByTestId('images-duplicate')).toBeVisible();
    await expect(images.getByTestId('images-item')).toHaveCount(2);

    await images.getByTestId('images-move-down').first().click();
    await expect(images.getByTestId('images-item').first()).toContainText('画像B');
    await expect(images.getByTestId('images-item').last()).toContainText('画像A');

    await page.getByRole('button', { name: '保存' }).click();
    await page.waitForURL('**/admin/post');

    // ── 開き直しても 5 型すべて残っている ──────────────
    await page.getByRole('link', { name: '全部入り' }).click();
    await page.waitForURL(/\/admin\/post\/\d+\/edit$/);

    await expect(page.locator('#spec_group-inner_title')).toHaveValue(
        '中の見出し',
    );
    await expect(page.locator('#spec_rows-0-inner_title')).toHaveValue('1 行目');

    await expect(
        page
            .locator('[aria-labelledby="spec_one-label"]')
            .getByTestId('relation-selected'),
    ).toContainText(oneLabel);

    // 並べ替えた順序が保存されている
    const savedMany = page.locator('[aria-labelledby="spec_many-label"]');
    await expect(savedMany.getByTestId('relation-selected')).toHaveCount(2);
    await expect(savedMany.getByTestId('relation-selected').first()).toContainText(
        secondLabel,
    );
    await expect(savedMany.getByTestId('relation-selected').last()).toContainText(
        firstLabel,
    );

    const savedImages = page.locator('[aria-labelledby="spec_images-label"]');
    await expect(savedImages.getByTestId('images-item')).toHaveCount(2);
    await expect(savedImages.getByTestId('images-item').first()).toContainText(
        '画像B',
    );
    await expect(savedImages.getByTestId('images-item').last()).toContainText(
        '画像A',
    );
    await expect
        .poll(() =>
            savedImages
                .getByTestId('images-thumbnail')
                .first()
                .evaluate((el) => (el as HTMLImageElement).naturalWidth),
        )
        .toBeGreaterThan(0);
});
