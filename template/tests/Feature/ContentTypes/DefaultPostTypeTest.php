<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\CmsManagedContentTypes;
use AgentCms\Core\ContentTypes\ContentTypeBuilderPresets;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Providers\ModulesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Post\Models\Post;
use Tests\TestCase;

/**
 * 汎用の「投稿」型と、既存 3 型の非回帰（Phase 9.7 / STAGE 20B・SPEC §C）。
 *
 * **kit は「既に生成済みの project」と同じ状態である** — `news` / `works` / `product` が
 * 在り、公開 route も view も動く。新しく生成するサイトへ配らないだけであり、
 * ここから何かが消えることはない（配布境界の変更であって migration ではない）。
 * 配布の判定そのものは `packages/cli/tests/default-post-type.test.ts` が持つ。
 */
final class DefaultPostTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_投稿型がcms管理として発見される(): void
    {
        $schemas = app(SchemaRegistry::class);

        $this->assertArrayHasKey('post', $schemas->declared());

        $cmsManaged = CmsManagedContentTypes::discover($schemas);

        $this->assertArrayHasKey('post', $cmsManaged, 'post が cms 管理として発見されない');
        $this->assertSame(Post::class, $cmsManaged['post']);
    }

    public function test_投稿型の骨格は仕様どおり(): void
    {
        $schema = app(SchemaRegistry::class)->get('post');

        $this->assertSame('投稿', $schema->label);
        $this->assertSame('collection', $schema->kind);
        $this->assertSame('post', $schema->table);
        $this->assertSame('cms', $schema->managedBy);
        $this->assertSame(
            ['title', 'slug', 'main_image_id', 'excerpt', 'body', 'status', 'published_at'],
            array_map(static fn ($field): string => $field->key, $schema->fields),
        );
        // news_category は news 専用。汎用の型に押しつけない
        $this->assertSame([], $schema->taxonomies);
    }

    public function test_投稿型のテーブルが作られる(): void
    {
        $this->assertTrue(Schema::hasTable('post'));

        foreach (['title', 'slug', 'body', 'status', 'published_at', 'data'] as $column) {
            $this->assertTrue(Schema::hasColumn('post', $column), "post.{$column} が無い");
        }
    }

    public function test_投稿型の_cru_d_routeが生える(): void
    {
        foreach (['index', 'create', 'store', 'edit', 'update', 'destroy'] as $action) {
            $this->assertTrue(Route::has("admin.post.{$action}"), "admin.post.{$action} が無い");
        }
    }

    public function test_adminの入口は存在する型へ向かう(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');

        // kit には news が在るのでそこへ向かう。**行き先は登録済みの route だけ**
        $this->assertMatchesRegularExpression('#/admin/[a-z][a-z0-9_]*$#', $target);
        $this->assertTrue(
            Route::has('admin.'.basename($target).'.index'),
            "存在しない型 [{$target}] へ redirect している",
        );
    }

    // ── 既存 3 型の非回帰 ─────────────────────────────────────────

    public function test_kitでは既存3型がこれまでどおり動く(): void
    {
        $schemas = app(SchemaRegistry::class);

        foreach (['news', 'works', 'product'] as $id) {
            $this->assertTrue($schemas->has($id), "{$id} schema が消えている");
        }

        foreach (['news', 'works'] as $id) {
            $this->assertTrue(Route::has("admin.{$id}.index"), "admin.{$id}.index が消えている");
            $this->assertTrue(Route::has("{$id}.index"), "公開 route {$id}.index が消えている");
        }

        $this->assertTrue(Schema::hasTable('news'));
        $this->assertTrue(Schema::hasTable('works'));
        $this->assertTrue(Schema::hasTable('products'));
    }

    public function test_code管理の型に読み込めないものは無い(): void
    {
        // schema は在るのにモデルが無い型は「配っていない」ではなく「壊れている」
        $this->assertSame([], ModulesServiceProvider::unavailableContentTypes());
    }

    public function test_既存3型を消すmigrationを足していない(): void
    {
        // 撤去は配布境界の変更であって migration ではない（SPEC §C.4）
        $paths = glob(base_path('modules/*/database/migrations/*.php')) ?: [];
        $paths = array_merge($paths, glob(base_path('core/database/migrations/*.php')) ?: []);

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);

            foreach (["dropIfExists('news')", "dropIfExists('works')", "dropIfExists('products')"] as $needle) {
                if (! str_contains($source, $needle)) {
                    continue;
                }

                // 自分自身を作った migration の down だけは許す
                $this->assertStringContainsString(
                    str_replace(["dropIfExists('", "')"], ['', ''], $needle),
                    basename($path),
                    "{$path} が他の型のテーブルを落としている",
                );
            }
        }
    }

    // ── preset ───────────────────────────────────────────────────

    public function test_presetは4種で正本ではない(): void
    {
        $presets = ContentTypeBuilderPresets::presets();

        $this->assertSame(['blank', 'post', 'news', 'works'], array_column($presets, 'id'));

        foreach ($presets as $preset) {
            $this->assertNotSame([], $preset['list'], "{$preset['id']} の一覧列が空");
        }

        $blank = $presets[0];
        $this->assertSame(['title', 'slug'], array_column($blank['fields'], 'key'));

        // product は 20 種の field 型を網羅した検証用 fixture。preset にしない
        $this->assertNotContains('product', array_column($presets, 'id'));
    }

    public function test_presetは作成フォームへ渡る(): void
    {
        $options = ContentTypeBuilderPresets::formOptions();

        $this->assertArrayHasKey('presets', $options);
        $this->assertCount(4, $options['presets']);
    }
}
