<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\DuplicatePage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/** 複製が公開状態を継承せず、元の本文・履歴を変更しないことを確認する。 */
final class PageDuplicateTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function source(): Page
    {
        $result = app(CreatePage::class)->execute(['title' => '会社案内', 'slug' => 'company'], $this->context());

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'duplicate-test');
    }

    public function test_copy_has_its_own_draft_and_url_without_published_state_or_history(): void
    {
        $source = $this->source();
        $source->update(['status' => 'published', 'published_at' => now()->addDay(), 'published_version_id' => $source->draft_version_id, 'seo' => ['description' => '会社説明', 'noindex' => true]]);
        $before = $source->fresh()->getAttributes();
        $result = app(DuplicatePage::class)->execute($source->id, $this->context());
        $this->assertTrue($result->isSuccess());
        $copy = Page::query()->findOrFail($result->data['page']['id']);
        $this->assertSame('company-copy', $copy->slug);
        $this->assertSame('会社案内（コピー）', $copy->title);
        $this->assertSame(PageStatus::Draft, $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->published_version_id);
        $this->assertSame($source->seo, $copy->seo);
        $this->assertNotSame($source->draft_version_id, $copy->draft_version_id);
        $this->assertSame($source->draftVersion->document, $copy->draftVersion->document);
        $this->assertSame(1, $copy->versions()->count());
        $this->assertSame($before, $source->fresh()->getAttributes());
        $this->get('/company-copy')->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('operation', 'page.duplicate')->count());
        $second = app(DuplicatePage::class)->execute($source->id, $this->context());
        $this->assertSame('company-copy-2', $second->data['page']['slug']);
    }

    public function test_invalid_sources_do_not_create_empty_copies(): void
    {
        $source = $this->source();
        foreach (['blade', 'trashed', 'missing-version', 'other-page-version'] as $case) {
            $source->update(['managed_by' => 'document', 'status' => 'draft']);
            if ($case === 'blade') {
                $source->update(['managed_by' => 'blade']);
            }
            if ($case === 'trashed') {
                $source->update(['status' => 'trashed']);
            }
            if ($case === 'missing-version') {
                $source->update(['draft_version_id' => 999999]);
            }
            if ($case === 'other-page-version') {
                $other = app(CreatePage::class)->execute(['title' => '別ページ', 'slug' => 'other'], $this->context());
                $source->update(['draft_version_id' => $other->data['page']['draftVersionId'] ?? Page::query()->findOrFail($other->data['page']['id'])->draft_version_id]);
            }
            $count = Page::query()->count();
            $versions = PageVersion::query()->count();
            $this->assertFalse(app(DuplicatePage::class)->execute($source->id, $this->context())->isSuccess(), $case);
            $this->assertSame($count, Page::query()->count());
            $this->assertSame($versions, PageVersion::query()->count());
        }
    }

    public function test_admin_api_and_mcp_share_the_same_operation_and_viewer_is_denied(): void
    {
        $source = $this->source();
        $this->actingAs($this->operator())->post('/admin/pages/'.$source->id.'/duplicate')->assertRedirect();
        $this->actingAs($this->operator())->postJson('/api/v1/pages/'.$source->id.'/duplicate')->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertTrue(app(OperationCatalog::class)->invoke('page.duplicate', ['pageId' => $source->id], $this->context())->isSuccess());
        $viewer = $this->userWithRole(Role::Viewer);
        $count = Page::query()->count();
        $this->actingAs($viewer)->post('/admin/pages/'.$source->id.'/duplicate')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/pages/'.$source->id.'/duplicate')->assertForbidden();
        $this->assertSame($count, Page::query()->count());
    }
}
