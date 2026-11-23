<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\UnpublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\DocumentPageView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

final class Round3NotFoundTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    public function test_public_missing_and_unpublished_pages_have_site_frame(): void
    {
        app(SettingsStore::class)->put(['site_name' => '犬の庭']);
        $page = Page::query()->create(['title' => '公開を終えたページ', 'slug' => 'private-page', 'status' => 'published', 'managed_by' => 'document', 'published_at' => now()]);
        $version = PageVersion::query()->create(['page_id' => $page->id, 'revision' => 1, 'document' => ['root' => ['type' => 'page', 'children' => []]], 'created_interface' => 'admin']);
        $page->forceFill(['draft_version_id' => $version->id, 'published_version_id' => $version->id])->save();
        $this->assertTrue($page->isPublicallyVisible());
        $unpublished = app(UnpublishPage::class)->execute($page->id, OperationContext::admin($this->operatorId(), 'tester'));
        $this->assertTrue($unpublished->isSuccess());
        foreach (['/no-such-round3', '/private-page'] as $url) {
            $this->get($url)->assertNotFound()->assertSee('ページが見つかりません')
                ->assertSee('犬の庭')->assertSee('site-header')->assertSee('トップへ戻る')->assertSee('href="'.url('/').'"', false);
        }
    }

    public function test_site_frame_404_loads_the_parts_css_for_its_width(): void
    {
        // 見本・取り込んだ枠に載せた 404 で、本文が左端に貼り付いていた（直し3回目の一周で見た）
        $layout = app(DocumentPageView::class)->siteLayout();
        if ($layout === 'layouts.public') {
            $this->markTestSkipped('この環境は kit 既定の枠');
        }
        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Vite の manifest が無い（先に build が要る）');
        }
        $this->withVite();
        $this->get('/no-such-round3')->assertNotFound()->assertSee('site-parts', false);
    }

    public function test_admin_missing_page_uses_simple_japanese_screen(): void
    {
        $this->get('/admin/no-such-round3')->assertNotFound()->assertSee('ページが見つかりません')
            ->assertSee('管理画面へ戻る')->assertDontSee('site-header');
    }

    public function test_layout_failure_keeps_a_japanese_404(): void
    {
        View::composer('layouts.*', function (): void {
            throw new \RuntimeException('private detail');
        });
        $this->get('/no-such-round3')->assertNotFound()->assertSee('ページが見つかりません')
            ->assertSee('トップへ戻る')->assertDontSee('private detail');
    }
}
