<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Page;
use App\Models\User;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * Phase 9.1B / STEP 10: 同一オリジン + セッションの操作面（/api/v1）。
 *
 * owner 決定 G1 = Option A — トークン認証は実装しない。
 * ここは「Admin と同じ業務操作を JSON で叩けるか」だけを固定する。
 */
final class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * F-P1 是正（Phase 9.3B / #17・MB-3 / MB-5）。
     *
     * controller は `$request->all()` をそのまま Application Service へ渡す。
     * 禁止キーを controller で落として迂回させない — 拒否の判定者は CreatePage 1 箇所。
     */
    public function test_page_create_rejects_managed_by_from_the_api(): void
    {
        $admin = $this->admin();
        $before = Page::query()->count();

        foreach ([Page::MANAGED_BY_BLADE, Page::MANAGED_BY_DOCUMENT] as $value) {
            $this->actingAs($admin)
                ->postJson('/api/v1/pages', [
                    'title' => 'API から',
                    'slug' => 'api-managed',
                    'managedBy' => $value,
                ])
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['managedBy']]);
        }

        $this->assertSame($before, Page::query()->count(), 'API から Page が作られた');
    }

    public function test_page_create_succeeds_with_title_and_slug_only(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/pages', ['title' => 'API から', 'slug' => 'api-ok'])
            ->assertOk()
            ->assertJsonPath('meta.operation', 'page.create');

        $page = Page::query()->where('slug', 'api-ok')->sole();

        $this->assertSame(Page::MANAGED_BY_DOCUMENT, $page->managed_by);
        $this->assertSame('draft', $page->status->value);
    }

    public function test_api_is_not_reachable_without_a_session(): void
    {
        $this->getJson('/api/v1/content/news')->assertUnauthorized();
        $this->getJson('/api/v1/media')->assertUnauthorized();
        $this->getJson('/api/v1/settings')->assertUnauthorized();
    }

    public function test_content_types_are_discoverable_at_runtime(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/content-types');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains('news', $ids);
        $this->assertContains('works', $ids);
    }

    public function test_content_schema_is_returned_for_a_known_type(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/content-types/news');

        $response->assertOk()->assertJsonPath('data.id', 'news');
        $this->assertNotEmpty($response->json('data.fields'));
    }

    public function test_unknown_content_type_returns_404_with_a_message(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/content-types/nope')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_list_uses_the_agreed_data_and_meta_shape(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/content/news?perPage=2');

        $response->assertOk()->assertJsonStructure([
            'data',
            'meta' => ['page', 'perPage', 'total', 'lastPage', 'operation', 'interface'],
        ]);
        $this->assertSame('content.list', $response->json('meta.operation'));
        $this->assertSame('api', $response->json('meta.interface'));
        $this->assertLessThanOrEqual(2, count($response->json('data')));
    }

    public function test_show_returns_a_single_item(): void
    {
        $news = News::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->getJson("/api/v1/content/news/{$news->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $news->id);
    }

    public function test_patch_updates_only_the_given_fields(): void
    {
        $news = News::query()->firstOrFail();
        $body = $news->body;

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/content/news/{$news->id}", ['data' => ['title' => 'API から更新']])
            ->assertOk()
            ->assertJsonPath('data.title', 'API から更新');

        $news->refresh();
        $this->assertSame($body, $news->body);
        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'api',
            'operation' => 'content.update',
            'result' => 'success',
        ]);
    }

    public function test_patch_returns_422_with_field_errors(): void
    {
        $news = News::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/content/news/{$news->id}", ['data' => ['title' => '']])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['title']]);
    }

    /**
     * 受信（`/api/v1/contacts`）は **もう無い**（B-43）。
     * 塞いだことは `ContactSubmissionsAgentBoundaryTest` が固定している。
     */
    public function test_media_and_settings_are_readable(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/media')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->actingAs($admin)->getJson('/api/v1/settings')->assertOk()
            ->assertJsonStructure(['data' => ['schema', 'values']]);
    }

    public function test_v1_write_routes_are_limited_to_the_approved_surface(): void
    {
        // owner 決定（Phase 9.1D-5）: PageDocument 操作に必要な write のみ追加を許可。
        // public headless API 化しない方針は維持するため、増えたら気づくよう固定する
        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'PATCH api/v1/components/reusable/{id}',
            'PATCH api/v1/content/{type}/{id}',
            'PATCH api/v1/pages/{id}',
            'PATCH api/v1/pages/{id}/document',
            'PATCH api/v1/terms/{id}',
            /*
             * 再利用 component（Phase 9.6 / P0-C7）。**切り離しの口は無い** —
             * document を変える操作は `PATCH api/v1/pages/{id}/document` の
             * command として入る（口を 2 本にしない）。
             */
            'POST api/v1/components/reusable',
            'POST api/v1/components/reusable/{id}/archive',
            'POST api/v1/components/reusable/{id}/publish',
            /*
             * 掲載順（P1 ②）。**送るのは「どれの直後へ」だけ**で、並び全体は受けない
             * （全件を受ける口にすると、頁の外の行を上書きできてしまう）。
             */
            // 保存済み記事の下書き複製。ContentDuplicateTestが権限と内容を検証する。
            'POST api/v1/content/{type}/{id}/duplicate',
            'POST api/v1/content/{type}/{id}/reorder',
            // ゴミ箱への出し入れ（Phase 9.8 / STAGE 21C）。完全削除の route は無い
            'POST api/v1/content/{type}/{id}/restore',
            'POST api/v1/content/{type}/{id}/trash',
            'POST api/v1/pages',
            'POST api/v1/pages/{id}/duplicate',
            'POST api/v1/pages/{id}/publish',
            'POST api/v1/pages/{id}/restore',
            'POST api/v1/pages/{id}/trash',
            'POST api/v1/pages/{id}/unpublish',
            // 版の復元（P0-C6c）。ゴミ箱からの復元とは**別の口**である
            'POST api/v1/pages/{id}/versions/restore',
            'POST api/v1/taxonomies/{key}/terms',
            'POST api/v1/taxonomies/{key}/terms/reorder',
            'POST api/v1/terms/{id}/delete',
        ], $writes);

        // 破壊的操作に DELETE 動詞を使わない（既存 api/v1 の形に揃える）
        $this->assertSame([], collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all());
    }
}
