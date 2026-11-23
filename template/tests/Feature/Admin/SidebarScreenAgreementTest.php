<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use Database\Seeders\ContentTypeMenuDefaultsSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\Models\ContactSubmission;
use Tests\TestCase;

/**
 * **サイドバーの出し分けと、実際に開ける画面を突き合わせる**（B-30 の番人）。
 *
 * ## なぜ番人が要るのか
 *
 * サイドバーは `admin-nav.ts` の `can()` で項目を隠すが、**実際の門は `OperationPolicy`** で、
 * **管理 route に capability の middleware は 1 つも無い**。
 * 2 つは別々に書かれていて、**突き合わせる番人が無かった**。
 *
 * ここが無いまま「案件ごとに隠す」（B-39）へ軸を増やすと、
 * **広げた分だけ食い違いが増えたまま置かれる**。だから**先に番人**を置く。
 *
 * ## 何を守るか — 食い違いを 0 にはしない。**増やさない**
 *
 * いま 3 画面が「隠しているのに開ける」で、**見せてよいかは未決**である（B-30）。
 * 決まっていないものを試験で決めない。代わりに**いまの食い違いを表に書いて固定する**:
 *
 * - 表に無い食い違いが出たら赤（**増えた**）
 * - 表に在るのに食い違わなくなったら赤（**表が古い**。直したなら表から消す）
 *
 * ## 一覧はソースから引く
 *
 * 項目と権限の対応を**この試験に書き写さない**。書き写すと 3 つ目の正本になる。
 * `admin-nav.ts` を読んで `can('X') ? [ … href … ]` を取り出す。
 */
final class SidebarScreenAgreementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **いまの食い違い**（B-30 の実測。`ad294d1` 時点の 4 画面から、2026-09-28 に /admin/redirects を制作者だけに閉じて 3 画面）。
     *
     * 値は「なぜ食い違ったままか」。**空にしてはいけない** — 理由の無い例外は、
     * 次に読む人から見て「直し忘れ」と区別できない。
     *
     * @var array<string, string>
     */
    private const KNOWN_DISAGREEMENTS = [
        '/admin/forms' => 'B-30 未決: 一覧の閲覧を viewer に見せてよいか決まっていない',
        '/admin/backups' => 'B-30 未決: **バックアップの一覧が viewer に見える**。意図かどうかの判断が要る',
    ];

    public function test_サイドバーと開ける画面の食い違いが増えていない(): void
    {
        $gated = $this->gatedEntries();

        /*
         * 読み取りが空振りしたら、この試験は何も見ていない。
         *
         * **実数で止める**（いま 12）。「〜以上」にすると 1 件消えても通ってしまう。
         * 項目を増やした / 減らしたときは、**ここが赤くなって手が止まる**のが正しい —
         * 増減はどちらも「サイドバーの出し分けが変わった」なので、一度見るべきである。
         *
         * ▷ **11 → 10**（2026-08-30 / R-12 受付の一本化）。
         *   「お問い合わせ（従来）」を畳んだ分。route は開いたままなので、
         *   `KNOWN_DISAGREEMENTS` ではなく**項目そのものが消えた**。
         */
        // 公開・保守チェックに加えて、Owner専用の一括置換入口を追加。
        $this->assertCount(
            11,
            $gated,
            'admin-nav.ts の権限つき項目の数が変わりました（意図した増減なら、この数を直してください）',
        );

        $viewer = User::factory()->create(['role' => 'viewer']);
        $openable = [];

        foreach ($gated as $href => $capability) {
            if ($this->actingAs($viewer)->get($href)->getStatusCode() === 200) {
                $openable[] = $href;
            }
        }

        sort($openable);
        $known = array_keys(self::KNOWN_DISAGREEMENTS);
        sort($known);

        $this->assertSame(
            $known,
            $openable,
            "サイドバーが隠している画面の「開ける」集合が変わっています。\n"
            ."増えたなら、その画面を閉じるか、表へ理由つきで足してください。\n"
            .'減ったなら（＝直したなら）、表から消してください。',
        );
    }

    /** 表の項目が**いまもサイドバーの対象**であること（消えた画面を守り続けない） */
    public function test_表に書いた画面はいまもサイドバーの出し分け対象である(): void
    {
        $gated = $this->gatedEntries();

        foreach (self::KNOWN_DISAGREEMENTS as $href => $reason) {
            $this->assertArrayHasKey(
                $href,
                $gated,
                "{$href} は admin-nav.ts の出し分け対象ではなくなっています（表が古い）",
            );
            $this->assertNotSame('', $reason, "{$href} の理由が空です");
        }
    }

    /**
     * **2 つ目の軸: 案件の設定で外した項目**（B-39）。
     *
     * 権限の軸（上）と違い、こちらは**権限を持っている人でも項目が出ない**。
     * ただし **route は閉じない**と決めた（B-39 裁定）ので、**URL では開ける**。
     * それは未決ではなく**決定**なので、上の表とは理由の質が違う — 混ぜずに別の表で持つ。
     *
     * 見るのは **owner**（権限を持つ人）である。viewer で叩くと、
     * 権限の側で落ちてしまい**この軸を通らない**。
     *
     * @var array<string, string>
     */
    private const HIDDEN_BY_PROJECT_SETTING = [
        '/admin/comments' => 'B-39 裁定: route は閉じないと決めた（サイドバーから外すだけ）',
        '/admin/post' => 'B-39: 0 件の同梱型は既定で出さない。**それでも URL では開ける**（route は閉じない）',
    ];

    public function test_案件の設定で外した項目も権限があれば開ける(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        foreach (self::HIDDEN_BY_PROJECT_SETTING as $href => $reason) {
            $this->assertNotSame('', $reason, "{$href} の理由が空です");

            $this->assertSame(
                200,
                $this->actingAs($owner)->get($href)->getStatusCode(),
                "{$href} が開けません。route を閉じたなら、この表から消してください",
            );
        }
    }

    /** その項目が**設定で本当に外れる**こと（外れないなら、上の表は何も守っていない） */
    public function test_案件の設定で外すと項目が消える(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $shown = fn (): array => array_keys(
            $this->actingAs($owner)->get('/admin/site')
                ->viewData('page')['props']['nav']['features'] ?? [],
        );

        $this->assertContains('comments', $shown(), '既定では出ている前提');

        app(SettingsStore::class)->put(['show_comments_menu' => false]);
        app()->forgetScopedInstances();

        $features = $this->actingAs($owner)->get('/admin/site')
            ->viewData('page')['props']['nav']['features'] ?? [];

        $this->assertFalse($features['comments'] ?? true, '設定を切っても出したままになっている');
    }

    /**
     * **3 つ目の軸: 生成したときの既定**（B-39・オーナー決定 2026-08-24）。
     *
     * 「**中身が空の投稿メニューが並んでいると、どちらに書くのか分からない**」が一次情報である。
     * だから **0 件の同梱型は、生成直後の既定では出さない**。使う案件で有効にする。
     *
     * 権限の軸でも案件の設定の軸でもない — **納品時に 1 回だけ効く既定**である。
     * 混ぜると「設定を切っていないのに出ない」を設定のせいだと読み違える。
     *
     * ここで守るのは 4 つ:
     * 1. 0 件の型は既定で出ない / 中身が在る型は出たまま
     * 2. 使う案件で有効にすれば戻る
     * 3. **既存の生成物では何も起きない**（`agentcms dev` は既存でも毎回 `db:seed` を回す）
     * 4. 外れても URL では開ける（上の表の `/admin/post`）
     */
    public function test_生成直後は0件の同梱型がサイドバーに出ない(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        // 「使っている型」を 1 件だけ作る（0 件かどうかで分かれることを見る）
        DB::table('post')->insert([
            'title' => '書き始めた投稿', 'slug' => 'started', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContentTypeMenuDefaultsSeeder::class)->run(newProject: true);
        app()->forgetScopedInstances();

        $shown = $this->contentTypeNav($owner);

        $this->assertContains('post', $shown, '中身が在る型まで外している');
        $this->assertNotContains('works', $shown, '0 件の型が既定のまま出ている');
        $this->assertNotContains('news', $shown, '0 件の型が既定のまま出ている');
    }

    /**
     * 🔴 **書ける入れ物が、1 つは必ず残る**（オーナー決定 2026-09-06）。
     *
     * 元の決定（2026-08-24）は「中身が空の投稿メニューが並んでいると、
     * どちらに書くのか分からない」だった。当時は空の型が複数あった。
     *
     * ところが**利用者へ配られる投稿タイプは「投稿」1 つだけ**である（配布境界）。
     * 全部隠すと**メニューから記事を書く道が消える** — ダッシュボードは
     * 「コンテンツを編集する」と案内しているのに行き先が無い、という状態になっていた
     * （2026-08-30 に実測）。
     *
     * ▷ **kit の試験環境では再現しない。** ここには `post` / `news` / `works` / `product` が
     *   在るので「全部空」でも複数残る。**利用者の形（1 つだけ）は kit では作れない**ので、
     *   規則そのもの（**0 にはしない**）を固定する。
     */
    public function test_全部空でも書ける入れ物が1つは残る(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        // 1 件も無い状態（＝生成直後）で既定を書く
        app(ContentTypeMenuDefaultsSeeder::class)->run(newProject: true);
        app()->forgetScopedInstances();

        $shown = $this->contentTypeNav($owner);

        $this->assertNotSame(
            [],
            $shown,
            '空の型を全部隠したので、メニューから記事を書く道が消えている',
        );
    }

    /** **「並べない」の意図は残っている**（空が複数あるなら、余りは隠す） */
    public function test_空の型が複数あるなら余りは隠れたまま(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        app(ContentTypeMenuDefaultsSeeder::class)->run(newProject: true);
        app()->forgetScopedInstances();

        $editorial = ['post', 'news', 'works', 'product'];
        $shown = array_values(array_intersect($editorial, $this->contentTypeNav($owner)));

        $this->assertCount(
            1,
            $shown,
            '空の型が並んでいる（残すのは 1 つだけ）: '.implode(', ', $shown),
        );
    }

    public function test_使う案件で有効にすればサイドバーへ戻る(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        app(ContentTypeMenuDefaultsSeeder::class)->run(newProject: true);
        app()->forgetScopedInstances();

        $this->assertNotContains('post', $this->contentTypeNav($owner), '前提: 既定では出ていない');

        // 画面の「サイドバーに出す」と同じ列を書く（`UpdateContentType` が使う writer）
        app(ContentTypeSettingsWriter::class)->save(
            'post',
            ['display_overrides' => json_encode(['show_in_menu' => true])],
            1,
        );
        app()->forgetScopedInstances();

        $this->assertContains('post', $this->contentTypeNav($owner), '有効にしても戻らない');
    }

    /**
     * **既存の案件で「急にメニューが消えた」を起こさない**（オーナーの条件）。
     *
     * `agentcms dev` は既存の案件でも**毎回** `db:seed` を回す（`packages/cli/src/commands/dev.ts`）。
     * だから「行が無い＝まだ決めていない」だけを見て既定を書くと、**運用中の案件から項目が消える**。
     * 見分けるのは **管理者がまだ 1 人も居ないか**（＝この `db:seed` が最初の 1 回か）である。
     */
    public function test_既存の生成物ではメニューが急に消えない(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->configureAdminSeed();

        $this->seed(DatabaseSeeder::class);
        app()->forgetScopedInstances();

        $this->assertContains('post', $this->contentTypeNav($owner), '0 件でも既存の案件から消してはいけない');
        $this->assertSame(0, DB::table('content_types')->count(), '既存の案件へ既定を書き込んでいる');
    }

    /** 生成直後の `db:seed` 1 本で効くこと（**中身を入れたあとに数える**配線も一緒に見る） */
    public function test_生成直後のシードで0件の型が外れる(): void
    {
        $this->configureAdminSeed();

        $this->seed(DatabaseSeeder::class);
        app()->forgetScopedInstances();

        $owner = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $shown = $this->contentTypeNav($owner);

        $this->assertNotContains('post', $shown, '0 件の同梱型が出たままになっている');
        $this->assertContains('works', $shown, 'シードが入れた型まで外れている（数える順番が逆）');
    }

    /**
     * **「受付」に並ぶのは 1 つだけ**（R-12 / 受付の一本化）。
     *
     * オーナー決定（2026-08-30）「**二つに分ける意味がないでしょ**」。
     * それまでは「サイトの状態」という 4 つ目の軸（`contactUsesForm`）で
     * 「お問い合わせ（従来）」を出し分けていた。**その軸ごと畳んだ** —
     * 生成直後は必ず「従来を出す」側だったので、買った人が最初に見る画面では
     * **空の「フォーム」と、実際に動いている「お問い合わせ（従来）」**が並んでいた。
     *
     * いまは `ContactFormSeeder` が受付フォームを 1 本入れるので、窓口は 1 つである。
     * 項目が実際に 1 つであることは `resources/js/lib/__tests__/admin-nav.test.ts` が見る。
     *
     * ▷ **ここで見るのは「サーバーがもう出し分けの信号を渡していない」こと。**
     *   渡し続けていると、次に見た人が「まだ出し分けが在る」と読む。
     */
    public function test_出し分けの信号はもう渡らない(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $nav = $this->actingAs($owner)->get('/admin/site')
            ->viewData('page')['props']['nav'] ?? [];

        $this->assertArrayNotHasKey(
            'contactUsesForm',
            $nav,
            '受付は一本化した。出し分けの信号を渡し続けている',
        );
    }

    /**
     * **項目を外しても画面は開ける**（B-39 裁定と同じ）。
     *
     * メニューから消しただけで**過去の受信が読めなくなる**状態を作らない。
     * **次に戻すときの足場**でもあるので、route は閉じない。
     */
    public function test_従来の画面は入口を外しても開ける(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $this->publishContactForm();
        app()->forgetScopedInstances();

        foreach (['/admin/contact'] as $href) {
            $this->assertSame(
                200,
                $this->actingAs($owner)->get($href)->getStatusCode(),
                "{$href} が開けません。route を閉じたなら B-39 裁定を見直してください",
            );
        }
    }

    /**
     * **読めなくしない。**
     *
     * メニューから外した以上、**移していない旧受信が在る案件には画面が入口を出す**。
     * 件数はフォーム一覧が受け取る（`FormAdminController::legacyPending()`）。
     * ここが渡らないと、`Index.vue` 側では `undefined` になって**黙って出ない**。
     */
    public function test_移していない旧受信の件数がフォーム一覧へ渡る(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $props = $this->actingAs($owner)->get('/admin/forms')
            ->viewData('page')['props'];

        $this->assertArrayHasKey(
            'legacyPending',
            $props,
            'フォーム一覧が旧受信の件数を受け取っていない（画面では undefined になる）',
        );
        $this->assertSame(0, $props['legacyPending'], '旧受信が無いのに 0 でない');

        ContactSubmission::query()->create([
            'name' => '山田太郎',
            'email' => 'a@example.test',
            'subject' => 'other',
            'message' => '本文です',
            'status' => 'received',
        ]);

        $after = $this->actingAs($owner)->get('/admin/forms')
            ->viewData('page')['props']['legacyPending'] ?? null;

        $this->assertSame(1, $after, '旧受信が 1 件あるのに、フォーム一覧が数えていない');
    }

    /**
     * **サーバーの信号が、実際にサイドバーへ渡っていること**。
     *
     * 判定と消費にはそれぞれ試験があるが、**その 2 つを繋ぐ配線には番人が無かった。**
     * 変異で実測したところ:
     *
     * - `AppSidebar.vue` の **配線 1 行だけ**消す → `eslint` が「使われていない」で赤（捕まる）
     * - **`computed` ごと消す** → **vitest も `vue-tsc` も `eslint` も緑のまま**（すり抜ける）
     *
     * すり抜けると、**サーバーは正しく答えているのに項目が消えない** —
     * しかもどの門も何も言わない。だから **file を読んで確かめる**
     * （`gatedEntries()` と同じ作法。component の試験機構をここで新設しない）。
     *
     * ▷ **`contactUsesForm` はもう渡らない**（受付は一本化した）。
     *   残っているのは `features` の 1 本なので、**これが空振りすると番人ごと無意味になる**。
     */
    public function test_サーバーの信号がサイドバーへ渡っている(): void
    {
        $src = (string) file_get_contents(base_path('resources/js/components/AppSidebar.vue'));

        foreach (['features'] as $signal) {
            $this->assertStringContainsString(
                '?.'.$signal,
                $src,
                "AppSidebar.vue が共有 props から {$signal} を読んでいません",
            );

            $this->assertMatchesRegularExpression(
                '/'.preg_quote($signal, '/').':\s*'.preg_quote($signal, '/').'\.value/',
                $src,
                "AppSidebar.vue が {$signal} を buildAdminNav へ渡していません",
            );
        }

        $this->assertStringNotContainsString(
            'contactUsesForm',
            $src,
            '受付は一本化した。出し分けの配線が残っている',
        );
    }

    /**
     * **確認ダイアログの配線が合っている**（R-12 / 2026-08-30 に実際に踏んだ）。
     *
     * 🔴 `ConfirmationDialog` が出すのは `confirm` / `cancel` である。
     * `@accept` / `@dismiss` と書いても **Vue は黙って無視する** —
     * テンプレートの未知の listener は型検査も lint も通る。
     *
     * 実際そうなった: route も operation も Feature 試験も緑なのに、
     * **画面では「削除する」を押しても何も起きなかった**（POST が 1 回で止まる）。
     * 押して初めて分かる類なので、**綴りをファイルで見る**（`AppSidebar` と同じ作法）。
     */
    public function test_確認ダイアログの配線が合っている(): void
    {
        $screens = [
            'resources/js/pages/admin/forms/Submission.vue',
            'resources/js/pages/admin/forms/Index.vue',
        ];

        foreach ($screens as $rel) {
            $src = (string) file_get_contents(base_path($rel));

            if (! str_contains($src, '<ConfirmationDialog')) {
                continue;
            }

            $this->assertStringContainsString(
                '@confirm=',
                $src,
                "{$rel} の ConfirmationDialog が @confirm を繋いでいません（@accept は無視されます）",
            );
            $this->assertStringContainsString(
                '@cancel=',
                $src,
                "{$rel} の ConfirmationDialog が @cancel を繋いでいません",
            );
        }
    }

    /** 予約識別子（`contact`）の公開フォームを 1 本作る */
    private function publishContactForm(): void
    {
        $groupId = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.strtolower(substr($groupId, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('forms')->insert([
            'key' => 'contact', 'name' => 'お問い合わせ', 'field_group_id' => $groupId,
            'status' => 'published', 'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 管理者シードに要る設定（値は `AdminUserSeederTest` と同じ扱い） */
    private function configureAdminSeed(): void
    {
        config()->set('agentcms.admin.email', 'admin@example.test');
        config()->set('agentcms.admin.password', 'Kx7mPq2wNr9vTb4Zc6Ye8Uh3');
    }

    /**
     * サイドバーへ出ている投稿タイプの id（共有 props から読む）。
     *
     * @return list<string>
     */
    private function contentTypeNav(User $user): array
    {
        /** @var list<array<string, string>> $types */
        $types = $this->actingAs($user)->get('/admin/site')
            ->viewData('page')['props']['nav']['contentTypes'] ?? [];

        return array_values(array_column($types, 'id'));
    }

    /**
     * `admin-nav.ts` の「権限で囲まれた項目」を読み取る。
     *
     * @return array<string, string> href => capability
     */
    private function gatedEntries(): array
    {
        $src = (string) file_get_contents(base_path('resources/js/lib/admin-nav.ts'));

        /*
         * `can('X') ? [ … ] : []` を拾う。
         *
         * **条件が `can()` だけとは限らない** — B-39 で
         * `can('comment.moderate') && uses('comments')` のように**軸が 2 つ**になった。
         * `?` の手前を緩く読まないと、**その項目が権限の軸から静かに落ちる**
         * （実測: 落ちて 11 → 10 になり、この試験が赤くなって気づけた）。
         */
        preg_match_all(
            "/can\('([^']+)'\)[^?\[\]]*\?\s*\[([\s\S]*?)\]\s*\n?\s*:\s*\[\]/",
            $src,
            $blocks,
            PREG_SET_ORDER,
        );

        $out = [];

        foreach ($blocks as $block) {
            preg_match_all("/href:\s*'([^']+)'/", $block[2], $hrefs);

            foreach ($hrefs[1] as $href) {
                $out[$href] = $block[1];
            }
        }

        return $out;
    }
}
