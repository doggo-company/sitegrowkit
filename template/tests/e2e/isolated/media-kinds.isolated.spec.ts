import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page, Request } from '@playwright/test';

/**
 * 画像と一般ファイルが **画面として成立しているか**（Phase 9.5-D / ADR-0018）。
 *
 * Feature テストは経路・認可・fail closed を担保する。ここで見たいのはそれではなく、
 * 実際にログインして操作したときに、種別で見え方が分かれ、公開範囲の変更に確認が挟まり、
 * 公開ページが AVIF → WebP → 原本の順で配信されるか — まで。
 *
 * 標準スイート（`playwright.config.ts`）へは入れない。この spec は DB と disk へ
 * メディアを足すので、件数を前提にした回帰を壊す。**使い捨てプロジェクトに対してだけ**実行する:
 *
 * ```
 * APP_URL=http://localhost:8181 \
 * AGENTCMS_COMPOSE_CWD=/path/to/generated-project \
 *   pnpm exec playwright test --config=playwright.isolated.config.ts \
 *     tests/e2e/isolated/media-kinds.isolated.spec.ts
 * ```
 *
 * `execFileSync` を使うのは既存の isolated spec と同じ理由で、
 * **shell を介さない**ため引数の解釈が起きないからである（`exec` は使わない）。
 */

/**
 * **使い捨てのプロジェクトでしか動かさない。**
 *
 * この spec は works の参照を書き換え、Field Group を消し、cache を落とす。
 * isolated config の既定（`APP_URL` 未指定で 8180、`AGENTCMS_COMPOSE_CWD` 未指定で
 * カレント）に乗ると、**稼働中のローカル環境をそのまま壊す**。
 * だから既定に頼らず、ここで止める。
 */
function isolatedTarget(): { appUrl: string; composeCwd: string } {
    const appUrl = process.env.APP_URL;
    const composeCwd = process.env.AGENTCMS_COMPOSE_CWD;

    if (!appUrl || !composeCwd) {
        throw new Error(
            'APP_URL と AGENTCMS_COMPOSE_CWD を明示してください（使い捨てプロジェクト専用の spec です）',
        );
    }

    // **宛先を文字列で判断しない。** `localhost.evil` や https の外部ホストが
    // 「localhost を含む」で通ってしまう。URL として解いて、手元の使い捨てだけを許す
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

    if (parsed.port === '') {
        throw new Error(
            `port を明示してください（既定 port は使わない）: ${appUrl}`,
        );
    }

    if (parsed.port === '8180') {
        throw new Error(`8180 は稼働中のローカル環境です: ${appUrl}`);
    }

    if (resolve(composeCwd) === resolve(process.cwd())) {
        throw new Error(`repo 自身を対象にできません: ${composeCwd}`);
    }

    // 生成プロジェクトである印。どれか 1 つでも欠けたら進めない
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

    const env = readFileSync(join(composeCwd, '.env'), 'utf8');
    const project = /^COMPOSE_PROJECT_NAME=(.+)$/m.exec(env)?.[1]?.trim() ?? '';

    if (!project || project === 'agentcms') {
        throw new Error(
            `compose project が分離されていません: ${project || '(未設定)'}`,
        );
    }

    return { appUrl, composeCwd };
}

const { composeCwd } = isolatedTarget();
const fixtures = mkdtempSync(join(tmpdir(), 'agentcms-media-'));

/**
 * この実行を表す印。
 *
 * 名前を固定すると、2 回目の実行で **前回の行**を拾って通ってしまう。
 * 「新しく登録できた」ことを確かめたいので、実行ごとに違う名前にする。
 */
const RUN = Date.now().toString(36);

function unique(name: string): string {
    return `${name} ${RUN}`;
}

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

function value(code: string): string {
    return artisan(`echo ${code};`).trim();
}

/** この spec が作る投稿の slug。**既存の実績には触れない** */
const POST_SLUG = 'e2e-media-kinds';

/**
 * 画像を **生成サイトにもある、この spec 専用の投稿**へ結び付ける。
 *
 * 既存行の `main_image_id` を一括で外すと、無関係なデータを壊す。
 * 自分で作った 1 行だけを作り直して使う。
 */
function attachToSinglePost(mediaId: string): string {
    artisan(
        `DB::table("content_types")->updateOrInsert(["content_type_id"=>"post"], ["status"=>"active", "lifecycle"=>"ready", "public_slug"=>"blog", "registration"=>json_encode(["public"=>true,"publicly_queryable"=>true]), "updated_at"=>now()]);` +
            `DB::table("post")->where("slug", "${POST_SLUG}")->delete();` +
            'DB::table("post")->insert(["title" => "E2E メディア確認", ' +
            `"slug" => "${POST_SLUG}", "body" => "<p>本文</p>", ` +
            `"status" => "published", "published_at" => now()->subDay(), ` +
            `"main_image_id" => ${mediaId}, "created_at" => now(), "updated_at" => now()]);`,
    );

    return POST_SLUG;
}

function adminCredentials(): { email: string; password: string } {
    const raw = readFileSync(
        `${composeCwd}/.agentcms/local/admin-credentials.json`,
        'utf8',
    );

    return JSON.parse(raw) as { email: string; password: string };
}

/** 実体のある PNG（GD が読める画像を php 側で作って取り出す） */
function pngFixture(name = 'photo.png'): string {
    const path = join(fixtures, name);
    const base64 = execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            '-r',
            'ob_start(); $i = imagecreatetruecolor(120, 90);' +
                'imagefilledrectangle($i, 0, 0, 120, 90, imagecolorallocate($i, 20, 80, 160));' +
                'imagepng($i); echo base64_encode(ob_get_clean());',
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    writeFileSync(path, Buffer.from(base64.trim(), 'base64'));

    return path;
}

function pdfFixture(name = 'kaisha.pdf', body = '1 0 obj'): string {
    const path = join(fixtures, name);
    writeFileSync(
        path,
        `%PDF-1.7\n${body}\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n`,
    );

    return path;
}

/** console error と失敗した request を集める。**0 件**であること */
function watchFailures(page: Page): { errors: string[]; failed: string[] } {
    const errors: string[] = [];
    const failed: string[] = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    page.on('pageerror', (error) => errors.push(String(error)));
    page.on('requestfailed', (request: Request) =>
        failed.push(`${request.method()} ${request.url()}`),
    );

    return { errors, failed };
}

test.beforeEach(() => {
    // ログインの回数制限をならす（test ごとにログインするので throttle に当たる）
    execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'],
        { cwd: composeCwd, encoding: 'utf8' },
    );
});

async function login(page: Page): Promise<void> {
    const { email, password } = adminCredentials();

    await page.goto('/login');
    await page.fill('input[type="email"]', email);
    await page.fill('input[type="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

/**
 * 登録して、**今できた行の ID** を返す。
 *
 * 名前は実行ごとに一意化し、さらに直前の最大 ID より大きい行だけを見る。
 * 固定名のままだと、2 回目の実行で **前回の行**を拾って通ってしまう。
 * `.first()` で重複を隠すこともしない — 1 件に定まることまでが確認したいことである。
 */
async function upload(
    page: Page,
    file: string,
    label: string,
): Promise<string> {
    const name = unique(label);
    const before = value('DB::table("media")->max("id") ?? 0');

    await page.goto('/admin/media');
    await page.setInputFiles('#media-file', file);
    await page.fill('#media-name', name);
    await page.getByRole('button', { name: 'アップロード' }).click();
    await expect(page.getByText(name)).toBeVisible();

    const id = value(
        `DB::table("media")->where("name", ${JSON.stringify(name)})` +
            `->where("id", ">", ${before})->value("id")`,
    );

    expect(id, `${name} が登録されていない`).toMatch(/^\d+$/);

    return id;
}

// --- 1 + 2: ログインと画像 ------------------------------------------------

test('日本語のログイン画面から入り、画像は従来どおり登録・表示できる', async ({
    page,
}) => {
    const seen = watchFailures(page);

    await page.goto('/login');
    // 日本語の画面であること（送信ボタンとパスキーの導線が日本語で出ている）
    await expect(page.locator('[data-test="login-button"]')).toHaveText(
        /ログイン/,
    );
    await expect(
        page.getByRole('button', { name: 'パスキーでログイン' }),
    ).toBeVisible();
    await login(page);

    const id = await upload(page, pngFixture(), '青い写真');

    const row = value(
        `json_encode(DB::table("media")->where("id",${id})->first())`,
    );

    expect(row).toContain('"kind":"image"');
    expect(row).toContain('"width":120');
    expect(row).toContain('"disk":"public"');

    await page.goto('/admin/media?kind=image');
    await expect(page.getByTestId('media-tab-image')).toBeVisible();
    await expect(page.getByTestId(`media-item-${id}`)).toBeVisible();

    expect(seen.errors, seen.errors.join('\n')).toEqual([]);
    expect(seen.failed, seen.failed.join('\n')).toEqual([]);
});

// --- 3: 公開側の配信順 ----------------------------------------------------

test('公開ページは AVIF → WebP → 原本の順で配信し、実 URL が 200 を返す', async ({
    page,
    request,
}) => {
    await login(page);
    const id = await upload(page, pngFixture('work.png'), '実績の写真');

    const formats = value(
        `json_encode(DB::table("media_variants")->where("media_id",${id})->pluck("format"))`,
    );

    test.info().annotations.push({ type: 'variants', description: formats });

    // この環境は decode / encode とも AVIF・WebP を備えている（下で実測して assert する）。
    // 条件分岐で空振りさせない
    expect(formats, 'AVIF が作られていない').toContain('avif');
    expect(formats, 'WebP が作られていない').toContain('webp');

    const slug = attachToSinglePost(id);
    const html = await (await request.get(`/blog/${slug}`)).text();
    const picture = html.slice(
        html.indexOf('<picture'),
        html.indexOf('</picture>'),
    );

    expect(picture, '<picture> が出ていない').toContain('<img');
    expect(picture.indexOf('image/avif')).toBeGreaterThan(-1);
    expect(picture.indexOf('image/avif')).toBeLessThan(
        picture.indexOf('image/webp'),
    );
    expect(picture.indexOf('image/webp')).toBeLessThan(picture.indexOf('<img'));

    // 並び順だけでなく、その URL が **その形式で**取れること
    const sources = [
        ...picture.matchAll(/<source srcset="([^"]+)" type="([^"]+)"/g),
    ];

    expect(sources).toHaveLength(2);

    for (const [, url, type] of sources) {
        const response = await request.get(url);

        expect(response.status(), url).toBe(200);
        expect(response.headers()['content-type'], url).toContain(type);
    }

    const original = /<img[^>]+src="([^"]+)"/.exec(picture)?.[1] ?? '';
    const originalResponse = await request.get(original);

    expect(originalResponse.status()).toBe(200);
    expect(originalResponse.headers()['content-type']).toContain('image/png');
});

test('SVG は原本のみで配信され、<picture> に包まれない', async ({
    page,
    request,
}) => {
    await login(page);

    const svg = join(fixtures, 'logo.svg');
    writeFileSync(
        svg,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>',
    );

    const id = await upload(page, svg, 'ロゴ');

    expect(
        value(`DB::table("media_variants")->where("media_id",${id})->count()`),
    ).toContain('0');

    const slug = attachToSinglePost(id);
    const response = await request.get(`/blog/${slug}`);
    const html = await response.text();

    expect(html).not.toContain('<picture');

    const src = /<img[^>]+src="([^"]*\/media\/[^"]+)"/.exec(html)?.[1] ?? '';
    const original = await request.get(src);

    expect(original.status()).toBe(200);
    expect(original.headers()['content-type']).toContain('image/svg');
});

// --- 4: 一般ファイル ------------------------------------------------------

test('PDF はファイルタブに拡張子バッジと元の名前で出て、寸法も alt も画像 preview も出ない', async ({
    page,
}) => {
    const seen = watchFailures(page);
    await login(page);

    const id = await upload(page, pdfFixture(), '会社案内');

    await page.goto('/admin/media?kind=file');
    await expect(page.getByTestId(`media-badge-${id}`)).toHaveText('PDF');
    await expect(page.getByTestId(`media-original-name-${id}`)).toHaveText(
        'kaisha.pdf',
    );
    await expect(page.getByTestId(`media-download-${id}`)).toBeVisible();
    await expect(page.getByTestId(`media-visibility-${id}`)).toHaveText('公開');

    await page.goto('/admin/media?kind=image');
    await expect(page.getByTestId(`media-item-${id}`)).toHaveCount(0);

    await page.goto(`/admin/media/${id}`);
    await expect(page.getByTestId('media-preview')).toHaveCount(0);
    await expect(page.getByTestId('media-preview-badge')).toBeVisible();
    await expect(page.getByLabel('代替テキスト（alt）')).toHaveCount(0);
    await expect(page.getByText('寸法')).toHaveCount(0);
    await expect(page.getByTestId('media-visibility-section')).toBeVisible();
    await expect(page.getByTestId('media-download-link')).toBeVisible();

    expect(seen.errors, seen.errors.join('\n')).toEqual([]);
    expect(seen.failed, seen.failed.join('\n')).toEqual([]);
});

// --- 5: 公開範囲 ----------------------------------------------------------

test('公開範囲は 1 回目では変わらず、確認を経て両方向とも変わる', async ({
    page,
    request,
}) => {
    await login(page);

    const id = await upload(page, pdfFixture('gentei.pdf'), '限定資料');
    const visibility = () =>
        value(`DB::table("media")->where("id",${id})->value("visibility")`);

    await page.goto(`/admin/media/${id}`);

    // public → private。閉じれば変わらない
    await page.getByTestId('media-visibility-toggle').click();
    const dialog = page.getByTestId('operation-confirmation');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('管理者だけが開けます');

    await page.getByRole('button', { name: 'キャンセル' }).click();
    expect(visibility()).toContain('public');

    await page.getByTestId('media-visibility-toggle').click();
    await page.getByTestId('operation-confirm-button').click();
    await expect(page.getByTestId('operation-confirmation')).toHaveCount(0);
    expect(visibility()).toContain('private');

    // private は未認証から 404、管理画面からは 200
    const path = `/media/file/${id}/x.pdf`;
    expect((await request.get(path)).status()).toBe(404);
    expect((await page.request.get(`/admin/media/file/${id}`)).status()).toBe(
        200,
    );

    // private → public も確認を経る
    await page.reload();
    await page.getByTestId('media-visibility-toggle').click();
    await expect(page.getByTestId('operation-confirmation')).toContainText(
        '誰でも',
    );
    await page.getByTestId('operation-confirm-button').click();
    expect(visibility()).toContain('public');

    const open = await request.get(path);
    expect(open.status()).toBe(200);
    expect(open.headers()['content-disposition']).toContain('attachment');
    expect(open.headers()['x-content-type-options']).toBe('nosniff');
    expect(open.headers()['content-security-policy']).toContain(
        "default-src 'none'",
    );
});

// --- 7: 差し替えと削除 ----------------------------------------------------

test('同じ種別なら差し替えられ、違う種別は拒否される', async ({ page }) => {
    await login(page);

    const id = await upload(page, pdfFixture('sashikae.pdf'), '差し替え資料');

    await page.goto(`/admin/media/${id}`);
    await page.setInputFiles('#replace-file', pngFixture('ng.png'));
    await page.getByTestId('media-replace-submit').click();
    await expect(page.getByText(/差し替えることはできません/)).toBeVisible();

    await page.setInputFiles(
        '#replace-file',
        pdfFixture('atarashii.pdf', '2 0 obj'),
    );
    await page.getByTestId('media-replace-submit').click();

    await expect(page.getByText('atarashii.pdf')).toBeVisible();
    expect(
        value(`DB::table("media")->where("id",${id})->value("kind")`),
    ).toContain('file');
});

test('参照中は削除できず、参照を外せば確認を経て削除できる', async ({
    page,
}) => {
    await login(page);

    const id = await upload(page, pngFixture('sanshou.png'), '参照される写真');

    // **この spec 専用の実績**からだけ参照する。無関係な行は触らない
    attachToSinglePost(id);

    await page.goto(`/admin/media/${id}`);
    await expect(page.getByTestId('media-delete')).toBeDisabled();

    artisan(
        `DB::table("post")->where("slug", "${'e2e-media-kinds'}")->delete();`,
    );

    await page.reload();
    await page.getByTestId('media-delete').click();
    await expect(page.getByTestId('operation-confirmation')).toBeVisible();
    await page.getByTestId('operation-confirm-button').click();

    await page.waitForURL('**/admin/media**');
    expect(value(`DB::table("media")->where("id",${id})->count()`)).toContain(
        '0',
    );
});

// --- 8: 出していない口 ----------------------------------------------------

test('公開フォームから一般ファイルを送る口は無い', async ({ request }) => {
    const contact = await (await request.get('/contact')).text();

    expect(contact).not.toContain('type="file"');
    expect(contact).not.toContain('multipart/form-data');
});

// --- 9: キーボード到達と dialog ------------------------------------------

test('公開範囲の切り替えへキーボードで届き、ダイアログに見出しがある', async ({
    page,
}) => {
    await login(page);

    const id = await upload(page, pdfFixture('keyboard.pdf'), 'キーボード資料');

    await page.goto(`/admin/media/${id}`);

    const toggle = page.getByTestId('media-visibility-toggle');
    await toggle.focus();
    await expect(toggle).toBeFocused();
    await page.keyboard.press('Enter');

    // **role で引けること自体が要件**。ダイアログが 2 枚重なると Radix が
    // どちらにも aria-hidden を付け、支援技術からも role からも消える
    const dialog = page.getByRole('dialog');
    await expect(dialog).toHaveCount(1);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
});

// --- 6: Field Group の file 型（入れ子）と picker 分離 --------------------

test('入れ子の file 型を画面から選んで保存し、開き直しても選ばれている', async ({
    page,
}) => {
    const seen = watchFailures(page);
    await login(page);

    const fileId = await upload(page, pdfFixture('nested.pdf'), '入れ子の資料');
    const imageId = await upload(
        page,
        pngFixture('nested.png'),
        '入れ子の写真',
    );

    // 前の実行が残した束を落としてから入れる（key は一意なので二度目が通らない）。
    // 参照の逆順に消す（FK は RESTRICT）
    artisan(
        '$ids = DB::table("field_groups")->where("key","tenpu")->pluck("id");' +
            'DB::table("field_group_revisions")->whereIn("field_group_id", $ids)->delete();' +
            'DB::table("field_group_locations")->whereIn("field_group_id", $ids)->delete();' +
            'DB::table("field_groups")->whereIn("id", $ids)->delete();' +
            'DB::table("post")->where("slug","tenpu-oshirase")->delete();',
    );

    // 束を入れる。group の中に file、repeater の中に media（種別が混ざらないことを見るため）
    artisan(
        '$id = (string) Illuminate\\Support\\Str::ulid();' +
            'DB::table("field_groups")->insert(["id" => $id, "key" => "tenpu", "label" => "添付", ' +
            '"sort_order" => 0, "status" => "published", "fields" => json_encode([' +
            '["key" => "tenpu_group", "type" => "group", "label" => "まとまり", "fields" => [' +
            '["key" => "tenpu_file", "type" => "file", "label" => "資料"]]],' +
            '["key" => "tenpu_rows", "type" => "repeater", "label" => "行", "fields" => [' +
            '["key" => "row_image", "type" => "media", "label" => "画像"]]]' +
            ']), "created_at" => now(), "updated_at" => now()]);' +
            'DB::table("field_group_locations")->insert(["field_group_id" => $id, ' +
            '"kind" => "content_type", "value" => "post"]);',
    );

    await page.goto('/admin/post/create');

    await page.fill('#title', '添付つきのお知らせ');
    await page.fill('#slug', 'tenpu-oshirase');
    await page.fill('#body', '本文');
    // status は必須。下書きのまま保存する
    await page.click('#status');
    await page.getByRole('option', { name: '下書き' }).click();

    // 入れ子の file の picker は **ファイルだけ**を出す
    const filePicker = page.locator(
        '[data-testid="media-picker"][data-kind="file"]',
    );
    await expect(filePicker).toHaveCount(1);
    await filePicker.getByTestId('media-picker-open').click();

    await expect(page.getByTestId(`media-choice-${fileId}`)).toBeVisible();
    await expect(page.getByTestId(`media-choice-${imageId}`)).toHaveCount(0);
    await page.getByTestId(`media-choice-${fileId}`).click();

    await expect(
        filePicker.getByTestId('media-picker-current-file'),
    ).toHaveText('PDF');

    // repeater の行を足すと、そちらの picker は **画像だけ**を出す。
    // ここの .first() は「最初の repeater を操作する」意味で、
    // データの重複を隠すためのものではない
    await page
        .locator('#tenpu_rows-label')
        .locator('..')
        .getByTestId('repeater-add')
        .click();

    // 既存のアイキャッチや別の画像欄と混同せず、追加した行を検証する。
    const imagePicker = page
        .getByTestId('repeater-row')
        .filter({ has: page.locator('[id*="tenpu_rows"]') })
        .locator('[data-testid="media-picker"][data-kind="image"]');
    await expect(imagePicker).toHaveCount(1);
    await imagePicker.getByTestId('media-picker-open').click();

    await expect(page.getByTestId(`media-choice-${imageId}`)).toBeVisible();
    await expect(page.getByTestId(`media-choice-${fileId}`)).toHaveCount(0);
    await page.getByTestId(`media-choice-${imageId}`).click();

    await page.getByRole('button', { name: '保存', exact: true }).click();
    await page.waitForURL('**/admin/post');

    // 保存された値
    const saved = value(
        'DB::table("post")->where("slug","tenpu-oshirase")->value("data")',
    );

    // MySQL の JSON は空白を入れて返すので、文字列ではなく **値**で見る
    const data = JSON.parse(saved) as {
        tenpu_group: { tenpu_file: number };
        tenpu_rows: { row_image: number }[];
    };

    expect(String(data.tenpu_group.tenpu_file)).toBe(fileId);
    expect(String(data.tenpu_rows[0].row_image)).toBe(imageId);

    // 開き直しても選ばれている
    const postId = value(
        'DB::table("post")->where("slug","tenpu-oshirase")->value("id")',
    );

    await page.goto(`/admin/post/${postId}/edit`);
    await expect(
        page
            .locator('[data-testid="media-picker"][data-kind="file"]')
            .getByTestId('media-picker-current-file'),
    ).toHaveText('PDF');
    await expect(
        page
            .getByTestId('repeater-row')
            .filter({ has: page.locator('[id*="tenpu_rows"]') })
            .locator('[data-testid="media-picker"][data-kind="image"]')
            .getByTestId('media-picker-current'),
    ).toBeVisible();

    expect(seen.errors, seen.errors.join('\n')).toEqual([]);
    expect(seen.failed, seen.failed.join('\n')).toEqual([]);
});

// --- 3 の補足: GIF は原本のみ、Content-Type まで確かめる --------------------

test('GIF は原本のみで配信され、Content-Type が image/gif のまま届く', async ({
    page,
    request,
}) => {
    await login(page);

    const gif = join(fixtures, 'motion.gif');
    const base64 = execFileSync(
        'docker',
        [
            'compose',
            'exec',
            '-T',
            'app',
            'php',
            '-r',
            'ob_start(); $i = imagecreatetruecolor(40, 30);' +
                'imagefilledrectangle($i, 0, 0, 40, 30, imagecolorallocate($i, 200, 40, 40));' +
                'imagegif($i); echo base64_encode(ob_get_clean());',
        ],
        { cwd: composeCwd, encoding: 'utf8' },
    );

    writeFileSync(gif, Buffer.from(base64.trim(), 'base64'));

    const id = await upload(page, gif, '動く画像');

    // 再エンコードするとアニメーションが失われるので、派生物は作らない
    expect(
        value(`DB::table("media_variants")->where("media_id",${id})->count()`),
    ).toContain('0');
    expect(
        value(`DB::table("media")->where("id",${id})->value("mime")`),
    ).toContain('image/gif');

    const slug = attachToSinglePost(id);
    const html = await (await request.get(`/blog/${slug}`)).text();

    expect(html).not.toContain('<picture');

    const src = /<img[^>]+src="([^"]*\/media\/[^"]+)"/.exec(html)?.[1] ?? '';
    const original = await request.get(src);

    expect(original.status()).toBe(200);
    expect(original.headers()['content-type']).toContain('image/gif');
});

// --- 確認ダイアログの回帰（1 枚だけ・取り違えない） ------------------------

test('編集画面の確認は常に 1 枚で、承認した操作だけが飛ぶ', async ({
    page,
}) => {
    const seen = watchFailures(page);
    await login(page);

    const id = await upload(page, pdfFixture('kakunin.pdf'), '確認の資料');
    const state = () =>
        JSON.parse(
            value(
                `json_encode(DB::table("media")->where("id",${id})->first(["visibility"]))`,
            ),
        ) as { visibility: string } | null;

    await page.goto(`/admin/media/${id}`);

    // 削除でも 1 枚だけ（role で引ける = aria-hidden が付いていない）
    await page.getByTestId('media-delete').click();
    await expect(page.getByRole('dialog')).toHaveCount(1);
    await expect(page.getByTestId('operation-confirmation')).toHaveCount(1);

    // キャンセルすれば何も起きない
    await page.getByRole('button', { name: 'キャンセル' }).click();
    await expect(page.getByTestId('operation-confirmation')).toHaveCount(0);
    expect(state()).not.toBeNull();
    expect(state()?.visibility).toBe('public');

    // 公開範囲でも 1 枚だけ
    await page.getByTestId('media-visibility-toggle').click();
    await expect(page.getByRole('dialog')).toHaveCount(1);

    // 承認しても **削除は飛ばない**（直前に削除の確認を出していても）
    await page.getByTestId('operation-confirm-button').click();
    await expect(page.getByTestId('operation-confirmation')).toHaveCount(0);

    expect(state(), '公開範囲の承認で削除されている').not.toBeNull();
    expect(state()?.visibility).toBe('private');

    // 逆向きも同じ。公開範囲をキャンセルしたあとの削除は、削除として通る
    await page.reload();
    await page.getByTestId('media-visibility-toggle').click();
    await page.getByRole('button', { name: 'キャンセル' }).click();
    expect(state()?.visibility, 'キャンセルしたのに変わっている').toBe(
        'private',
    );

    await page.getByTestId('media-delete').click();
    await expect(page.getByRole('dialog')).toHaveCount(1);
    await page.getByTestId('operation-confirm-button').click();

    await page.waitForURL('**/admin/media**');
    expect(value(`DB::table("media")->where("id",${id})->count()`)).toContain(
        '0',
    );

    expect(seen.errors, seen.errors.join('\n')).toEqual([]);
    expect(seen.failed, seen.failed.join('\n')).toEqual([]);
});

// --- 主要画面を一通り歩いて console / network を確かめる -------------------

test('主要な画面を一通り開いても console error も失敗した request も出ない', async ({
    page,
}) => {
    const seen = watchFailures(page);
    await login(page);

    const image = await upload(page, pngFixture('walk.png'), '巡回の写真');
    const file = await upload(page, pdfFixture('walk.pdf'), '巡回の資料');

    for (const url of [
        '/admin/media',
        '/admin/media?kind=image',
        '/admin/media?kind=file',
        `/admin/media/${image}`,
        `/admin/media/${file}`,
        '/admin/field-groups',
        '/admin/post',
        '/admin/post/create',
        '/dashboard',
        '/',
        '/blog',
    ]) {
        await page.goto(url);
        await expect(page.locator('body')).toBeVisible();
    }

    expect(seen.errors, seen.errors.join('\n')).toEqual([]);
    expect(seen.failed, seen.failed.join('\n')).toEqual([]);
});
