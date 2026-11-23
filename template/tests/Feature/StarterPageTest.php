<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\DocumentPageView;
use App\Support\SiteTemplates\StarterSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Tests\TestCase;

final class StarterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_an_editable_draft_without_changing_the_live_site(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'owner']);
        app(SettingsStore::class)->put([FrontPage::SETTING_KEY => 321]);
        $this->actingAs($owner)->post('/admin/starter-page')->assertRedirect();
        $page = app(StarterSite::class)->page();
        $this->assertNotNull($page);
        $this->assertSame(PageStatus::Draft, $page->status);
        $this->assertNull($page->published_version_id);
        $this->assertSame(321, app(FrontPage::class)->selectedId());
        $this->assertSame(3, Media::query()->count());
        $this->assertSame('layouts.starter-a', app(DocumentPageView::class)->layoutFor($page));
        $this->get('/'.$page->slug)->assertNotFound();
        $this->get('/admin/pages/'.$page->id.'/preview')->assertOk()->assertSee('site-tpl-a')->assertSee('食パンの朝。')->assertSee('生成画像')->assertSee('site-templates/a.css')->assertDontSee('doc-unknown');
    }

    public function test_reopening_preserves_edits_and_does_not_duplicate_media(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/admin/starter-page');
        $page = app(StarterSite::class)->page();
        $this->assertNotNull($page);
        $node = $page->draftVersion->document['root']['children'][0];
        $this->assertTrue(app(PatchPageDocument::class)->execute($page->id, ['operation' => 'component.update', 'target' => $node['id'], 'changes' => ['props' => ['title' => '自分のお店の見出し']]], OperationContext::admin($owner->id))->isSuccess());
        $before = $page->fresh()->draftVersion->document;
        $this->post('/admin/starter-page')->assertRedirect('/admin/pages/'.$page->id.'/editor');
        $this->assertSame($before, $page->fresh()->draftVersion->document);
        $this->assertSame(1, Page::query()->count());
        $this->assertSame(3, Media::query()->count());
    }

    public function test_editor_and_viewer_cannot_create_the_starter(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post('/admin/starter-page')->assertForbidden();
        }
        $this->assertSame(0, Page::query()->count());
        $this->assertSame(0, Media::query()->count());
    }

    public function test_existing_pages_do_not_receive_the_starter_layout(): void
    {
        $page = Page::query()->create(['title' => '既存ページ', 'slug' => 'existing', 'status' => PageStatus::Draft, 'managed_by' => Page::MANAGED_BY_DOCUMENT]);
        $this->assertSame('layouts.imported', app(DocumentPageView::class)->layoutFor($page));
        $this->get('/')->assertOk()->assertDontSee('site-templates/a.css');
    }

    public function test_failed_photo_upload_returns_an_actionable_error_and_rolls_back_the_draft(): void
    {
        Storage::fake('public');
        config(['agentcms.uploads.max_kilobytes' => 1]);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->from('/dashboard')->post('/admin/starter-page')
            ->assertRedirect('/dashboard')->assertSessionHasErrors('starter');
        $this->assertSame(0, Page::query()->count());
        $this->assertSame(0, Media::query()->count());
        $this->assertNull(app(StarterSite::class)->page());
    }

    public function test_new_starter_exposes_an_editable_shop_label_and_unique_product_target(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/admin/starter-page');
        $page = app(StarterSite::class)->page();
        $this->assertNotNull($page);
        $nodes = $page->draftVersion->document['root']['children'];
        $this->assertSame('editorial', $nodes[0]['props']['variant']);
        $this->assertSame('パン屋 麦と日々', $nodes[0]['props']['eyebrow']);
        $this->assertSame('#bread-menu', $nodes[0]['props']['buttonHref']);
        $this->assertSame('bread-menu', $nodes[1]['key']);
        $html = $this->get('/admin/pages/'.$page->id.'/preview')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'id="bread-menu"'));
        $this->assertStringContainsString('href="#bread-menu"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('写真は生成した見本です。', $html);
    }

    public function test_legacy_full_and_band_covers_remain_renderable(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post('/admin/starter-page');
        $page = app(StarterSite::class)->page();
        $this->assertNotNull($page);
        $node = $page->draftVersion->document['root']['children'][0];
        foreach (['full', 'band'] as $variant) {
            $this->assertTrue(app(PatchPageDocument::class)->execute($page->id, ['operation' => 'component.update', 'target' => $node['id'], 'changes' => ['props' => ['variant' => $variant]]], OperationContext::admin($owner->id))->isSuccess());
            $this->get('/admin/pages/'.$page->id.'/preview')->assertOk()->assertSee('doc-cover--'.$variant)->assertSee('doc-cover__bg');
        }
    }
}
