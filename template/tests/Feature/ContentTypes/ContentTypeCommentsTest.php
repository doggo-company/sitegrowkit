<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\ContentTypes\SetContentTypeComments;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use App\Models\User;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 投稿タイプ側のコメント利用可否（Phase 9.7 / STAGE 20E・SPEC §L）。
 *
 * ここで固定するのは 4 つ。
 *
 * 1. **既定はすべて OFF** — 設定行が無ければ false。`post` も例外ではない
 * 2. **system facility は恒久的に false** — 設定を読みに行かない
 * 3. **OFF は何も消さない** — `content_types` の 1 行 1 列以外に触れない
 * 4. **受け付ける仕組みは作らない** — route も operation も catalog も増やさない
 */
final class ContentTypeCommentsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actor = null;

    private function owner(): User
    {
        return $this->actor ??= User::factory()->create();
    }

    private function context(?User $user = null, ?string $token = null): OperationContext
    {
        $user ??= $this->owner();

        return OperationContext::admin($user->getKey(), (string) $user->name)
            ->withConfirmationToken($token);
    }

    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
    }

    private function lockVersion(string $id): int
    {
        $row = DB::table('content_types')->where('content_type_id', $id)->first();

        return $row === null ? 0 : (int) $row->lock_version;
    }

    /** 確認を通して切り替える */
    private function setComments(string $id, bool $enabled): void
    {
        $version = $this->lockVersion($id);

        $first = app(SetContentTypeComments::class)->execute($id, $enabled, $version, $this->context());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);

        $applied = app(SetContentTypeComments::class)->execute(
            $id,
            $enabled,
            $version,
            $this->context(null, $first->confirmation?->token),
        );

        $this->assertTrue($applied->isSuccess(), (string) $applied->message);
        $this->nextRequest();
    }

    // ── 既定 ──────────────────────────────────────────────────────

    /** @return list<array{0: string}> */
    public static function editorialTypes(): array
    {
        return [['post'], ['news'], ['works'], ['product']];
    }

    #[DataProvider('editorialTypes')]
    public function test_既定は全ての型でoff(string $id): void
    {
        $this->assertSame(0, DB::table('content_types')->count(), '既定のために行を作っている');
        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->commentsEnabled($id));
    }

    /** @return list<array{0: string}> */
    public static function systemFacilities(): array
    {
        return [['media'], ['contact'], ['site']];
    }

    /**
     * system facility は **設定を読みに行かない**。
     *
     * 行が在っても、`comments_enabled = true` でも false のままである。
     */
    #[DataProvider('systemFacilities')]
    public function test_system_facilityは行があっても常にfalse(string $id): void
    {
        DB::table('content_types')->insert([
            'content_type_id' => $id, 'status' => 'active', 'lifecycle' => 'ready',
            'menu_order' => 0, 'comments_enabled' => true, 'revisions_enabled' => true,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->nextRequest();

        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->commentsEnabled($id));
    }

    public function test_system_facilityは設定を変えられない(): void
    {
        foreach (['media', 'contact', 'site'] as $id) {
            $result = app(SetContentTypeComments::class)->execute($id, true, 0, $this->context());

            $this->assertSame(OperationStatus::Forbidden, $result->status, "{$id} が通ってしまった");
        }

        $this->assertSame(0, DB::table('content_types')->count());
    }

    // ── 権限と確認 ────────────────────────────────────────────────

    public function test_権限が無ければ変えられない(): void
    {
        $viewer = User::factory()->role(Role::Viewer)->create();

        $result = app(SetContentTypeComments::class)->execute('post', true, 0, $this->context($viewer));

        $this->assertSame(OperationStatus::Forbidden, $result->status);
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_確認の前は1行も書かない(): void
    {
        $result = app(SetContentTypeComments::class)->execute('post', true, 0, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertSame(0, DB::table('content_types')->count());
    }

    /**
     * 確認は、**ON にした瞬間に起きること**を言う。
     *
     * 🔴 ここは 2026-09-04 に期待を入れ替えている。前は
     * 「まだ誰もコメントを書けません」を求めていたが、**実物はそうではなかった** —
     * ON にした瞬間に記事ページへ投稿欄が出て、その場で受け付けが始まる。
     * 実物より控えめに書くのは、大きく書くのと同じくらい危ない。
     * 片方は失望で済むが、こちらは**事故**である
     *（「まだ書けないなら先に入れておこう」と押した案件が、公開受付になる）。
     */
    public function test_確認の説明は受付の実体と取り違えさせない(): void
    {
        $result = app(SetContentTypeComments::class)->execute('post', true, 0, $this->context());
        $impact = implode('', $result->confirmation?->impact ?? []);

        $this->assertStringContainsString('受け付けが始まります', $impact);
        $this->assertStringContainsString('投稿欄が出ます', $impact);

        // 承認するまで公開されないことも、押す前に言う
        $this->assertStringContainsString('承認するまで公開ページには出ません', $impact);

        // 画面はこの文字列をそのまま描く。記号を読ませない
        $this->assertStringNotContainsString('**', $impact);
    }

    /**
     * 済んだあとの帯も、**確認ダイアログと同じことを言う**（WP 一周 8 周目 2026-09-04 / 効くもの ①）。
     *
     * 🔴 ここは実物で踏んでいる。押す前は「受け付けが始まります」なのに、
     * 押したあとの帯はこう出ていた:
     *
     * > この投稿タイプでコメントを使えるようにしました（**受け付ける仕組みは今後の更新で追加します**）
     *
     * 同じ操作の 2 秒間で、正反対のことを 2 回言われる。
     * 文言は Phase 9.7 / STAGE 20E のまま置き去りで、**その後続 Stage（P0-9）はもう入っている** —
     * 公開ページに投稿欄が出て、その場で受け付けが始まり、承認待ちに並ぶ（実測）。
     *
     * どちらへ倒れても悪いが、**こちらは「まだ動いていない」と読ませる**ぶん重い。
     * オーナーは承認待ちを見に行かないので、公開サイトが名前とメールアドレスつきの
     * 書き込みを受け続けていることに、気づく手がかりが 1 つも無い。
     */
    public function test_済んだ帯も受付が始まったことを言う(): void
    {
        $message = $this->flashAfterSwitch(true);

        $this->assertStringContainsString('受け付けが始まりました', $message);

        // 「まだ動いていない」と読ませる言い方をしない
        $this->assertStringNotContainsString('今後の更新', $message);
        $this->assertStringNotContainsString('追加します', $message);
    }

    /** 承認待ちの置き場も、その場で指す（見に行けなければ気づけない） */
    public function test_済んだ帯は承認待ちの置き場を指す(): void
    {
        $message = $this->flashAfterSwitch(true);

        $this->assertStringContainsString('承認するまで公開ページには出ません', $message);
        $this->assertStringContainsString('コメント', $message);
    }

    /** off に戻したときは、残ることを言う（今までどおり） */
    public function test_offの帯は書き込みが残ることを言う(): void
    {
        $this->flashAfterSwitch(true);

        $this->assertStringContainsString('書き込まれたコメントは残ります', $this->flashAfterSwitch(false));
    }

    /** 帯は route を通したときに出るので、確認まで済ませて session を読む */
    private function flashAfterSwitch(bool $enabled): string
    {
        $version = $this->lockVersion('post');

        $first = $this->actingAs($this->owner())->post('/admin/content-types/post/comments', [
            'comments_enabled' => $enabled,
            'lock_version' => $version,
        ]);

        $token = (string) app(SetContentTypeComments::class)
            ->execute('post', $enabled, $version, $this->context())
            ->confirmation?->token;

        $first->assertRedirect();

        $this->actingAs($this->owner())->post('/admin/content-types/post/comments', [
            'comments_enabled' => $enabled,
            'lock_version' => $version,
            'confirmation_token' => $token,
        ])->assertRedirect();

        $this->nextRequest();

        return (string) session('success');
    }

    public function test_確認トークンは1回で使い切る(): void
    {
        $first = app(SetContentTypeComments::class)->execute('post', true, 0, $this->context());
        $token = $first->confirmation?->token;

        $this->assertNotNull($token);
        $this->assertTrue(app(SetContentTypeComments::class)->execute('post', true, 0, $this->context(null, $token))->isSuccess());
        $this->nextRequest();

        $again = app(SetContentTypeComments::class)->execute('post', false, $this->lockVersion('post'), $this->context(null, $token));
        $this->assertNotSame(OperationStatus::Success, $again->status);
    }

    // ── ON / OFF ─────────────────────────────────────────────────

    public function test_onとoffを行き来でき値が戻る(): void
    {
        $this->setComments('post', true);
        $this->assertTrue(app(ContentTypeLifecyclePolicy::class)->commentsEnabled('post'));

        $this->setComments('post', false);
        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->commentsEnabled('post'));

        $this->setComments('post', true);
        $this->assertTrue(app(ContentTypeLifecyclePolicy::class)->commentsEnabled('post'), 'ON へ戻せない');
    }

    public function test_同じ値への変更は競合として返す(): void
    {
        $result = app(SetContentTypeComments::class)->execute('post', false, 0, $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(0, DB::table('content_types')->count());
    }

    /**
     * OFF にしても **`content_types` の 1 行 1 列以外は 1 バイトも変わらない**。
     *
     * この Stage ではコメントの表そのものが無いので、消えるものが無いことを
     * 「ほかのテーブルの行数と、その型の投稿が変わらない」で確かめる。
     */
    public function test_offにしても他のデータを消さない(): void
    {
        $this->setComments('post', true);

        DB::table('post')->insert([
            'title' => '残る投稿', 'slug' => 'stays', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = [];

        foreach (['post', 'news', 'works', 'taxonomies', 'taxonomy_terms', 'taxonomy_term_assignments', 'media', 'users', 'audit_logs'] as $table) {
            $before[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        $settingsBefore = DB::table('content_types')->where('content_type_id', 'post')->first();

        $this->setComments('post', false);

        foreach ($before as $table => $count) {
            if ($count === null) {
                continue;
            }

            // audit_logs だけは増える（結末を記録するのが仕様）
            if ($table === 'audit_logs') {
                $this->assertGreaterThanOrEqual($count, DB::table($table)->count());

                continue;
            }

            $this->assertSame($count, DB::table($table)->count(), "{$table} の行数が変わっている");
        }

        $settingsAfter = DB::table('content_types')->where('content_type_id', 'post')->first();

        // 変わってよいのは comments_enabled と lock_version / updated_at だけ
        $this->assertSame($settingsBefore->status, $settingsAfter->status);
        $this->assertSame($settingsBefore->menu_order, $settingsAfter->menu_order);
        $this->assertSame($settingsBefore->public_slug, $settingsAfter->public_slug);
        $this->assertSame($settingsBefore->registration, $settingsAfter->registration);
        $this->assertSame($settingsBefore->display_overrides, $settingsAfter->display_overrides);
        $this->assertSame($settingsBefore->revisions_enabled, $settingsAfter->revisions_enabled);
    }

    // ── 楽観ロックと監査 ──────────────────────────────────────────

    public function test_版が食い違えば保存せず知らせる(): void
    {
        $this->setComments('post', true);

        // 古い版のまま送る（別のタブで開いていた状態）
        $first = app(SetContentTypeComments::class)->execute('post', false, 0, $this->context());
        $result = app(SetContentTypeComments::class)->execute('post', false, 0, $this->context(null, $first->confirmation?->token));

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertTrue((bool) DB::table('content_types')->where('content_type_id', 'post')->value('comments_enabled'), '後勝ちになっている');

        $audit = DB::table('audit_logs')->where('operation', 'content.type.comments')->latest('id')->first();
        $this->assertSame('conflict', $audit?->result);
        $metadata = json_decode((string) $audit?->metadata, true);
        $this->assertSame(0, $metadata['expectedVersion'] ?? null);
        $this->assertSame(1, $metadata['actualVersion'] ?? null);
    }

    public function test_全ての結末を監査へ記録し中身を入れない(): void
    {
        // forbidden
        app(SetContentTypeComments::class)->execute('post', true, 0, $this->context(User::factory()->role(Role::Viewer)->create()));
        $this->assertSame('forbidden', DB::table('audit_logs')->where('operation', 'content.type.comments')->latest('id')->value('result'));

        // confirmation_required → success
        $this->setComments('post', true);

        $audit = DB::table('audit_logs')->where('operation', 'content.type.comments')->latest('id')->first();
        $metadata = (string) $audit?->metadata;

        $this->assertSame('success', $audit?->result);
        $this->assertStringContainsString('comments_enabled', $metadata);

        // **コメントの中身に関わるものは 1 つも入れない**
        foreach (['@', 'ip', '本文', 'author', 'body'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $metadata, "監査に [{$forbidden}] が入っている");
        }
    }

    // ── 無効な型 / 進行中の型 ─────────────────────────────────────

    public function test_無効な型ではコメントを使えない(): void
    {
        $this->setComments('post', true);

        DB::table('content_types')->where('content_type_id', 'post')->update(['status' => 'archived']);
        $this->nextRequest();

        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->commentsEnabled('post'), '無効な型で使えてしまう');
        // 値そのものは残っている（戻せば元どおり）
        $this->assertTrue((bool) DB::table('content_types')->where('content_type_id', 'post')->value('comments_enabled'));
    }

    // ── 型側と受付側の境界（受付の実体は P0-9 が持つ） ──────────────

    public function test_コメントの操作をaiへ公開していない(): void
    {
        $this->assertNotContains('content.type.comments', OperationCatalog::ids());
    }

    /**
     * 型ごとに route を増やしていない。
     *
     * **9.7 の時点では「コメントの route が 1 本も無い」ことを確かめていた。**
     * P0-9（9.13）で受付が入ったので、確かめる対象を
     * 「型ごとに増えていないこと」へ移す — 型を 10 個作ったら route が
     * 10 本増える、という形にしないための歯止めである。
     */
    public function test_型ごとにコメントのrouteを増やしていない(): void
    {
        $allowed = [
            // 型側の設定（9.7 / STAGE 20E）
            'admin/content-types/{type}/comments',
            // 受付と moderation（9.13 / P0-9）。**型の数に依らず固定**
            'comments/{contentType}/{contentId}',
            'admin/comments',
            'admin/comments/status',
            'admin/comments/{id}',
            'admin/comments/{id}/reply',
            'admin/comments/entry/{contentType}/{contentId}',
        ];

        foreach (app('router')->getRoutes() as $route) {
            $uri = (string) $route->uri();

            if (! str_contains($uri, 'comment')) {
                continue;
            }

            $this->assertContains($uri, $allowed, "コメントの route が増えている: {$uri}");
        }
    }

    /**
     * 型ごとにコメントの表を増やしていない。
     *
     * P0-9 は `content_comments` 1 本に `(content_type, content_id)` で入れる
     * （`content_entry_revisions` と同じ形）。**型ごとの表を作らない。**
     */
    public function test_型ごとにコメントの表を増やしていない(): void
    {
        $this->assertTrue(Schema::hasTable('content_comments'));

        foreach (['comments', 'post_comments', 'news_comments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} を作っている（1 本にまとめる）");
        }
    }

    // ── 画面 ──────────────────────────────────────────────────────

    public function test_編集画面から切り替えられる(): void
    {
        $this->actingAs($this->owner())->get('/admin/content-types/post/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('contentType.commentsEnabled', false));

        $first = $this->actingAs($this->owner())
            ->followingRedirects()
            ->from('/admin/content-types/post/edit')
            ->post('/admin/content-types/post/comments', ['comments_enabled' => true, 'lock_version' => 0]);

        $first->assertOk();
        $this->assertSame(0, DB::table('content_types')->count(), '確認の前に書いている');

        $confirmation = $this->inertiaFlash($first->getContent() ?: '')['confirmation'] ?? null;
        $this->assertNotNull($confirmation);

        $this->actingAs($this->owner())->post('/admin/content-types/post/comments', [
            'comments_enabled' => true,
            'lock_version' => 0,
            'confirmation_token' => $confirmation['token'],
        ])->assertRedirect('/admin/content-types');

        $this->assertTrue((bool) DB::table('content_types')->where('content_type_id', 'post')->value('comments_enabled'));
    }

    /**
     * **保存ボタンのついでに切り替わらない。**
     *
     * コメントの切り替えは表示・登録の `<form>` の中に置いてある。共通の `Button` は
     * `as="button"` を渡すだけで `type` を与えないので、`type="button"` を書き忘れると
     * HTML 既定の `submit` になり、**1 クリックで「コメントの確認要求」と
     * 「設定の保存」が同時に飛ぶ**（別の operation として扱う契約が壊れる）。
     *
     * ブラウザで click → submit が起きないことを直接確かめたいが、
     * この repo に Vue の component test 基盤（vitest 等）が無く、
     * 本 Stage は migration 禁止のため隔離ブラウザ環境も用意できない。
     * そこで **SFC の記述そのもの**を固定する。
     */
    public function test_コメントの切替ボタンは保存フォームを送信しない(): void
    {
        $source = File::get(
            base_path('resources/js/pages/admin/content-types/Edit.vue'),
        );

        foreach (['content-type-comments-enable', 'content-type-comments-disable'] as $testId) {
            $this->assertMatchesRegularExpression(
                '/<Button\b(?:(?!<\/Button>).)*?type="button"(?:(?!<\/Button>).)*?data-testid="'.$testId.'"/s',
                $source,
                "{$testId} に type=\"button\" が無い（既定の submit になる）",
            );
        }

        // 送信は「保存する」の 1 つだけ
        $this->assertSame(1, substr_count($source, 'type="submit"'));
    }

    /** 画面の文字に markdown の記法をそのまま出さない */
    public function test_画面の強調はhtmlで書く(): void
    {
        $source = File::get(
            base_path('resources/js/pages/admin/content-types/Edit.vue'),
        );

        // `<template>` に生の ** が残っていないこと（script の doc コメントは対象外）
        $template = substr($source, (int) strpos($source, '<template>'));

        $this->assertStringNotContainsString('**', $template, '画面にアスタリスクがそのまま出る');

        // 🔴 強調する中身も 2026-09-04 に入れ替えた（上の確認文言と同じ理由）
        $this->assertStringContainsString(
            '<strong>公開ページの記事に投稿欄が出て、その場で受け付けが始まります</strong>',
            $template,
        );
    }

    public function test_未認証では設定を変えられない(): void
    {
        $this->post('/admin/content-types/post/comments', ['comments_enabled' => true])->assertRedirect('/login');
        $this->assertSame(0, DB::table('content_types')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function inertiaFlash(string $html): array
    {
        if (preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $m) !== 1) {
            return [];
        }

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return is_array($page['flash'] ?? null) ? $page['flash'] : [];
    }
}
