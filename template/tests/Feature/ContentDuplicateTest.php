<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DuplicateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Post\Models\Post;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/** 実際の投稿型で複製が独立した下書きになり、元の公開内容を変えないことを確認する。 */
final class ContentDuplicateTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'duplicate-test');
    }

    private function source(): Post
    {
        $result = app(CreateContent::class)->execute('post', [
            'title' => '週末のお知らせ', 'slug' => 'weekend', 'body' => '<p>大切な本文</p>',
            'excerpt' => '短い説明', 'status' => 'published', 'published_at' => now()->addDay()->toIso8601String(),
        ], $this->context());
        $this->assertTrue($result->isSuccess(), $result->message ?? '作成失敗');

        return Post::query()->findOrFail($result->data['item']['id']);
    }

    public function test_saved_fields_become_an_independent_draft_without_schedule_or_history(): void
    {
        $source = $this->source();
        $before = $source->getAttributes();
        $result = app(DuplicateContent::class)->execute('post', (int) $source->id, $this->context());
        $this->assertTrue($result->isSuccess(), $result->message ?? '複製失敗');
        $copy = Post::query()->findOrFail($result->data['item']['id']);
        $this->assertSame('週末のお知らせ（コピー）', $copy->title);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertSame($source->body, $copy->body);
        $this->assertSame($source->excerpt, $copy->excerpt);
        $this->assertStringStartsWith('weekend-copy-', $copy->slug);
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame($before, $source->fresh()->getAttributes());
        $copy->update(['body' => '<p>複製だけ変更</p>']);
        $this->assertSame($source->body, $source->fresh()->body);
        $second = app(DuplicateContent::class)->execute('post', (int) $source->id, $this->context());
        $this->assertTrue($second->isSuccess());
        $this->assertNotSame($copy->slug, $second->data['item']['slug']);
        $this->assertSame(2, DB::table('audit_logs')->where('operation', 'content.duplicate')->count());
    }

    public function test_admin_api_and_catalog_use_the_same_copy_operation_and_viewer_cannot_write(): void
    {
        $source = $this->source();
        $response = $this->actingAs($this->operator())->post('/admin/post/'.$source->id.'/duplicate');
        $copy = Post::query()->latest('id')->firstOrFail();
        $response->assertRedirect('/admin/post/'.$copy->id.'/edit');
        $this->actingAs($this->operator())->postJson('/api/v1/content/post/'.$source->id.'/duplicate')
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertTrue(app(OperationCatalog::class)->invoke('content.duplicate', ['type' => 'post', 'id' => $source->id], $this->context())->isSuccess());
        $viewer = $this->userWithRole(Role::Viewer);
        $count = Post::query()->count();
        $this->actingAs($viewer)->post('/admin/post/'.$source->id.'/duplicate')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/content/post/'.$source->id.'/duplicate')->assertForbidden();
        $this->assertSame($count, Post::query()->count());
    }

    public function test_missing_unknown_and_trashed_sources_cannot_create_copies(): void
    {
        $source = $this->source();
        $count = Post::query()->count();
        $this->assertSame(OperationStatus::NotFound, app(DuplicateContent::class)->execute('unknown', (int) $source->id, $this->context())->status);
        $this->assertSame(OperationStatus::NotFound, app(DuplicateContent::class)->execute('post', 987654, $this->context())->status);
        DB::table('post')->where('id', $source->id)->update(['deleted_at' => now()]);
        $this->assertSame(OperationStatus::NotFound, app(DuplicateContent::class)->execute('post', (int) $source->id, $this->context())->status);
        $this->assertSame($count, DB::table('post')->count());
    }

    public function test_maximum_length_title_and_slug_still_fit_the_declared_schema(): void
    {
        $source = $this->source();
        $source->update(['title' => str_repeat('題', 120), 'slug' => str_repeat('a', 120)]);
        $result = app(DuplicateContent::class)->execute('post', (int) $source->id, $this->context());
        $this->assertTrue($result->isSuccess(), $result->message ?? '複製失敗');
        $this->assertSame(120, mb_strlen($result->data['item']['title']));
        $this->assertLessThanOrEqual(120, strlen($result->data['item']['slug']));
        $this->assertStringEndsWith('（コピー）', $result->data['item']['title']);
    }
}
