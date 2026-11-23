<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 管理画面の公開・検索除外が、検索エンジンの受け取る内容まで一致する。 */
final class DocumentPageSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_includes_only_visible_indexable_document_pages(): void
    {
        $this->page('visible-page');
        $this->page('hidden-draft', ['status' => 'draft']);
        $this->page('hidden-trash', ['status' => 'trashed', 'trashed_at' => now()]);
        $this->page('hidden-search', ['seo' => json_encode(['noindex' => true])]);
        $broken = $this->page('missing-version');
        DB::table('pages')->where('id', $broken)->update(['published_version_id' => null]);

        $response = $this->get('/sitemap.xml')->assertOk()->assertSee(url('/visible-page'));
        foreach (['hidden-draft', 'hidden-trash', 'hidden-search', 'missing-version'] as $slug) {
            $response->assertDontSee('/'.$slug);
        }
    }

    public function test_front_page_has_one_canonical_sitemap_entry_and_respects_noindex(): void
    {
        $id = $this->page('selected-home');
        app(SettingsStore::class)->put([FrontPage::SETTING_KEY => $id]);
        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($xml, '<loc>'.url('/').'</loc>'));
        $this->assertStringNotContainsString('/selected-home', $xml);

        DB::table('pages')->where('id', $id)->update(['seo' => json_encode(['noindex' => true])]);
        $this->get('/sitemap.xml')->assertDontSee('<loc>'.url('/').'</loc>', false);
        $this->get('/')->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get('/selected-home')->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_noindex_is_absent_on_normal_public_page(): void
    {
        $this->page('ordinary-page');
        $this->get('/ordinary-page')->assertOk()->assertDontSee('<meta name="robots"', false)
            // ナビの開閉ボタンがある（載る枠によって id と JS は違う）
            ->assertSeeInOrder(['aria-controls="', 'aria-expanded="false"'], false);
    }

    /** @param array<string, mixed> $overrides */
    private function page(string $slug, array $overrides = []): int
    {
        $id = (int) DB::table('pages')->insertGetId(array_replace([
            'slug' => $slug, 'title' => '公開確認', 'status' => 'published',
            'managed_by' => 'document', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        $version = DB::table('page_versions')->insertGetId([
            'page_id' => $id, 'revision' => 1, 'schema_version' => 1,
            'document' => json_encode(['root' => ['id' => 'root', 'type' => 'page', 'children' => []]]),
            'created_interface' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pages')->where('id', $id)->update(['published_version_id' => $version]);

        return $id;
    }
}
