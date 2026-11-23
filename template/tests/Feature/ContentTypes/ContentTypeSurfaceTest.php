<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\UpdateContent;
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
use Tests\TestCase;

/**
 * 無効にした投稿タイプが、どの面でどう振る舞うか（Phase 9.7 / STAGE 20C・SPEC §F.2.1）。
 *
 * **読めるが書けない**（Owner Decision OG-F = (a)）。
 * 中身を確認して別の型へ移せるようにしておきたいので read は残し、
 * 「凍結した」と言った以上 write は止める。
 *
 * 判定は `ContentTypeLifecyclePolicy` の 1 か所だけが持つ。面ごとに `status` を
 * 読みに行く実装にすると、1 つ書き忘れた面だけが archived な型へ書けてしまう。
 */
final class ContentTypeSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->owner->getKey(), (string) $this->owner->name);
    }

    /** `post` を無効にした状態を作る（確認フローは ContentTypeLifecycleTest が固定している） */
    private function archivePost(): int
    {
        $id = DB::table('post')->insertGetId([
            'title' => '無効にしても残る投稿', 'slug' => 'stays', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'archived', 'lifecycle' => 'ready',
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->forgetScopedInstances();

        return $id;
    }

    // ── 読めるが書けない ──────────────────────────────────────────

    public function test_無効な型でも中身は読める(): void
    {
        $id = $this->archivePost();

        $this->assertTrue(app(ListContent::class)->execute('post', $this->context())->isSuccess());
        $this->assertTrue(app(GetContent::class)->execute('post', $id, $this->context())->isSuccess());
    }

    public function test_無効な型へは作成も更新も削除もできない(): void
    {
        $id = $this->archivePost();

        $create = app(CreateContent::class)->execute('post', ['title' => 'だめ', 'slug' => 'nope', 'status' => 'draft'], $this->context());
        $update = app(UpdateContent::class)->execute('post', $id, ['title' => 'だめ'], $this->context(), partial: true);
        $delete = app(DeleteContent::class)->execute('post', $id, $this->context());

        foreach (['create' => $create, 'update' => $update, 'delete' => $delete] as $name => $result) {
            $this->assertSame(OperationStatus::Conflict, $result->status, "{$name} が通ってしまった");
        }

        // 1 件も増えていない / 消えていない
        $this->assertSame(1, DB::table('post')->count());
        $this->assertSame('無効にしても残る投稿', DB::table('post')->where('id', $id)->value('title'));
    }

    public function test_同じ規則がadminと_ap_iと_mc_pに効く(): void
    {
        $this->archivePost();

        // Admin（Inertia）— 409 で止まる
        $this->actingAs($this->owner)
            ->post('/admin/post', ['title' => 'だめ', 'slug' => 'nope', 'status' => 'draft'])
            ->assertStatus(409);

        // API v1（同一オリジン）— 作成の口は無いので更新で確かめる
        $entry = (int) DB::table('post')->value('id');

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/content/post/{$entry}", ['title' => 'だめ'])
            ->assertStatus(409);

        // MCP / AI が通る catalog も同じ Application Service を通る
        $result = app(OperationCatalog::class)->invoke(
            'content.create',
            ['type' => 'post', 'attributes' => ['title' => 'だめ', 'slug' => 'nope3', 'status' => 'draft']],
            $this->context(),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(1, DB::table('post')->count());
    }

    public function test_無効な型はサイドバーから外れる(): void
    {
        $this->archivePost();

        $types = $this->actingAs($this->owner)->get('/admin/works')
            ->viewData('page')['props']['nav']['contentTypes'] ?? [];

        $this->assertNotContains('post', array_column($types, 'id'));
        $this->assertContains('works', array_column($types, 'id'), 'ほかの型まで消えている');
    }

    /**
     * **有効なまま「サイドバーに出さない」だけを切る**（B-39 (3)）。
     *
     * これまでの試験は**無効化（archive）経由**しか通っていなかった。
     * 案件で使わない型を隠すのに使うのは `show_in_menu` の側で、
     * **そこがサイドバーまで効くことは一度も見ていなかった**（policy 層までで止まっていた）。
     */
    public function test_サイドバーに出さない設定なら有効なままでも外れる(): void
    {
        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'active', 'lifecycle' => 'ready',
            'display_overrides' => json_encode(['show_in_menu' => false]),
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $types = $this->actingAs($this->owner)->get('/admin/works')
            ->viewData('page')['props']['nav']['contentTypes'] ?? [];

        $this->assertNotContains('post', array_column($types, 'id'));
        $this->assertContains('works', array_column($types, 'id'), 'ほかの型まで消えている');

        // **無効化とは別の軸**である（読み書きは通ったまま）
        $this->assertTrue(app(ContentTypeLifecyclePolicy::class)->canWrite('post'));
    }

    /** 一覧の画面でも「出す / 出さない」が読める（機能が在ると気づけるように） */
    public function test_一覧にサイドバーの列が出る(): void
    {
        $this->actingAs($this->owner)->get('/admin/content-types')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var list<array<string, mixed>> $types */
                $types = $page->toArray()['props']['types'];
                $post = array_column($types, null, 'id')['post'] ?? null;

                $this->assertNotNull($post);
                $this->assertArrayHasKey('showInMenu', $post['capabilities']);
            });
    }

    public function test_無効な型は一覧には残り復元の導線がある(): void
    {
        $this->archivePost();

        $this->actingAs($this->owner)->get('/admin/content-types')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var list<array<string, mixed>> $types */
                $types = $page->toArray()['props']['types'];
                $post = array_column($types, null, 'id')['post'] ?? null;

                $this->assertNotNull($post, '無効にした型が一覧から消えている（復元できなくなる）');
                $this->assertSame('archived', $post['settings']['status']);
                $this->assertTrue($post['capabilities']['canRead']);
                $this->assertFalse($post['capabilities']['canWrite']);
                $this->assertFalse($post['capabilities']['showInMenu']);
            });
    }

    public function test_無効な型は公開面に出ない(): void
    {
        $this->archivePost();

        // 公開スラッグを持っていても、無効なら公開面には出さない（OG-D = archived は 404）
        DB::table('content_types')->where('content_type_id', 'post')->update([
            'public_slug' => 'blog',
            'registration' => json_encode(['public' => true]),
        ]);
        app()->forgetScopedInstances();

        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->isPublic('post'));
    }

    /**
     * cms 管理の型には**まだ公開 route が無い**（生成は Stage 20C の範囲外）。
     *
     * つまり「無効にしたのに公開 URL が残る」は**起こりようがない**。
     * 汎用の公開テンプレートを足すときは、`isPublic()` を通してから route を生やすこと。
     */
    public function test_cms管理の型に公開routeがまだ無い(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = (string) $route->uri();

            if (str_starts_with($uri, 'admin') || str_starts_with($uri, 'api/')) {
                continue;
            }

            $this->assertNotSame('post', $uri, '公開 route が生えている。archived の 404 契約を先に実装すること');
            $this->assertNotSame('blog', $uri);
        }
    }

    // ── 編集画面 ──────────────────────────────────────────────────

    public function test_編集画面はcms管理の型だけ開ける(): void
    {
        $this->actingAs($this->owner)->get('/admin/content-types/post/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/content-types/Edit')
                ->where('contentType.id', 'post')
                ->has('contentType.baseFields', 7)
                ->has('contentType.fieldGroups')
                ->has('options.icons'));

        foreach (['news', 'works', 'product', 'media'] as $id) {
            $this->actingAs($this->owner)->get("/admin/content-types/{$id}/edit")->assertNotFound();
        }
    }

    public function test_編集画面は権限が要る(): void
    {
        $viewer = User::factory()->role(Role::Viewer)->create();

        $this->actingAs($viewer)
            ->patch('/admin/content-types/post', ['lock_version' => 0, 'display_overrides' => ['label' => 'だめ']])
            ->assertForbidden();

        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_未認証では投稿タイプのlifecycle_routeが拒否される(): void
    {
        $this->get('/admin/content-types/post/edit')->assertRedirect('/login');
        $this->patch('/admin/content-types/post', [])->assertRedirect('/login');
        $this->post('/admin/content-types/post/archive', [])->assertRedirect('/login');
        $this->post('/admin/content-types/post/restore', [])->assertRedirect('/login');
        $this->post('/admin/content-types/reorder', [])->assertRedirect('/login');

        $this->assertSame(0, DB::table('content_types')->count());
    }

    /**
     * 画面からの無効化が **確認を経て最後まで通る**こと。
     *
     * Application Service 単体では通っても、controller が渡す引数が 1 回目と 2 回目で
     * ずれていると、確認ダイアログで承認しても何も起きない（指紋が食い違う）。
     * その食い違いは HTTP を通さないと出ない。
     */
    public function test_画面からの無効化は確認を経て通る(): void
    {
        $first = $this->actingAs($this->owner)
            ->followingRedirects()
            ->from('/admin/content-types')
            ->post('/admin/content-types/post/archive', ['lock_version' => 0]);

        $first->assertOk();

        $confirmation = $this->inertiaFlash($first->getContent() ?: '')['confirmation'] ?? null;

        $this->assertNotNull($confirmation, '確認トークンが返っていない');
        $this->assertSame(0, DB::table('content_types')->count(), '確認の前に書いている');

        $this->actingAs($this->owner)
            ->post('/admin/content-types/post/archive', [
                'lock_version' => 0,
                'confirmation_token' => $confirmation['token'],
            ])
            ->assertRedirect('/admin/content-types');

        $this->assertSame('archived', DB::table('content_types')->where('content_type_id', 'post')->value('status'));
    }

    /** Inertia の flash（確認ダイアログへ渡る値）を HTML から読む */
    private function inertiaFlash(string $html): array
    {
        if (preg_match('/data-page="app" type="application\\/json">(.*?)<\\/script>/s', $html, $m) !== 1) {
            return [];
        }

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return is_array($page['flash'] ?? null) ? $page['flash'] : [];
    }

    // ── XSS ──────────────────────────────────────────────────────

    public function test_表示名のscriptは文字列として扱われる(): void
    {
        $payload = '<script>alert(1)</script>';

        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'active', 'lifecycle' => 'ready',
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'display_overrides' => json_encode(['label' => $payload]),
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->forgetScopedInstances();

        $response = $this->actingAs($this->owner)->get('/admin/content-types')->assertOk();

        // props には文字列として入り、HTML には生のタグとして出ない
        $types = $response->viewData('page')['props']['types'];
        $post = array_column($types, null, 'id')['post'];

        $this->assertSame($payload, $post['label']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent() ?: '');
    }

    public function test_投稿タイプの画面はv_htmlを使わない(): void
    {
        foreach (['Index.vue', 'Edit.vue', 'Create.vue'] as $file) {
            $source = File::get(base_path("resources/js/pages/admin/content-types/{$file}"));

            $this->assertStringNotContainsString('v-html', $source, "{$file} が v-html を使っている");
        }
    }

    // ── 画面の状態表示 ────────────────────────────────────────────

    public function test_一覧は設定を読めなくても落ちない(): void
    {
        Schema::drop('content_types');
        app()->forgetScopedInstances();

        $this->actingAs($this->owner)->get('/admin/content-types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readiness', 'unmigrated')
                ->whereNot('readinessMessage', null)
                ->has('types'));
    }

    public function test_止まっている型は診断できる(): void
    {
        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'active', 'lifecycle' => 'provisioning',
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->forgetScopedInstances();

        $this->actingAs($this->owner)->get('/admin/content-types')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $post = array_column($page->toArray()['props']['types'], null, 'id')['post'];

                $this->assertSame('provisioning', $post['settings']['lifecycle']);
                // 進行中の型へは読み書きとも許さない
                $this->assertFalse($post['capabilities']['canRead']);
                $this->assertFalse($post['capabilities']['canWrite']);
            });
    }

    // ── 非回帰 ────────────────────────────────────────────────────

    public function test_有効な型の投稿は今までどおり書ける(): void
    {
        $result = app(CreateContent::class)->execute(
            'post',
            ['title' => '書ける', 'slug' => 'ok', 'status' => 'draft'],
            $this->context(),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame(1, DB::table('post')->count());
    }

    public function test_型の管理操作をaiへ公開していない(): void
    {

        foreach (OperationCatalog::ids() as $id) {
            $this->assertStringStartsNotWith('content.type.', $id, "管理 operation [{$id}] が AI へ公開されている");
        }
    }
}
