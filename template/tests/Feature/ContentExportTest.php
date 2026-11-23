<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Wxr\WxrReader;
use App\Support\ContentExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentExportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $exports = [];

    protected function tearDown(): void
    {
        foreach ($this->exports as $path) {
            @unlink($path);
            @unlink($path.'.partial');
        }
        parent::tearDown();
    }

    private function id(): string
    {
        $id = strtolower((string) Str::ulid());
        $this->exports[] = ContentExport::path($id);

        return $id;
    }

    public function test_書き出しを_wx_rで読み直すと日本語と本文と公開状態を保持する(): void
    {
        foreach (['公開' => ['published', now()->subDay()], '予約' => ['published', now()->addDay()], '下書き' => ['draft', null]] as $title => [$status, $at]) {
            DB::table('news')->insert(['title' => $title.' & <犬> ]]> 日本語', 'slug' => Str::slug($status).'-'.Str::random(6),
                'body' => '<p>本文 <strong>大切</strong></p><script>secret-js</script>', 'excerpt' => '説明', 'status' => $status,
                'published_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
        }
        $progress = [];
        $id = $this->id();
        $count = app(ContentExport::class)->write($id, function (int $count) use (&$progress): void {
            $progress[] = $count;
        });
        $items = iterator_to_array((new WxrReader(ContentExport::path($id)))->items());
        $this->assertSame(3, $count);
        $this->assertSame([1, 2, 3], $progress);
        $this->assertSame(['publish', 'future', 'draft'], array_column($items, 'status'));
        $this->assertSame('公開 & <犬> ]]> 日本語', $items[0]->title);
        $this->assertSame('post', $items[0]->postType);
        $this->assertStringContainsString('<strong>大切</strong>', $items[0]->content);
        $this->assertStringNotContainsString('<script>', $items[0]->content);
        $this->assertSame('news', json_decode($items[0]->meta['sitegrowkit_content_type'], true));
        $this->assertSame(0600, fileperms(ContentExport::path($id)) & 0777);
        $xml = simplexml_load_file(ContentExport::path($id));
        $this->assertNotFalse($xml);
        $namespaces = $xml->getDocNamespaces();
        $this->assertSame('http://purl.org/dc/elements/1.1/', $namespaces['dc']);
        foreach ($xml->channel->item as $entry) {
            $wp = $entry->children($namespaces['wp']);
            $this->assertSame((string) $wp->post_date_gmt, (string) $wp->post_date);
            $this->assertNotSame('0000-00-00 00:00:00', (string) $wp->post_date);
            $this->assertSame('sitegrowkit-export', (string) $entry->children($namespaces['dc'])->creator);
        }
    }

    public function test_異なる型で同じ行番号でも外部_i_dを分け再書き出しで維持する(): void
    {
        DB::table('news')->insert(['id' => 1, 'title' => '記事', 'slug' => 'export-entry', 'body' => '<p>本文</p>', 'status' => 'draft']);
        $page = Page::query()->create(['title' => 'ページ', 'slug' => 'export-page', 'managed_by' => Page::MANAGED_BY_DOCUMENT, 'status' => 'draft']);
        $version = PageVersion::query()->create(['page_id' => $page->id, 'revision' => 1, 'schema_version' => 1,
            'document' => ['root' => ['id' => (string) Str::ulid(), 'type' => 'section', 'props' => [], 'children' => []]], 'created_interface' => 'admin']);
        $page->update(['draft_version_id' => $version->id]);
        $first = $this->id();
        $second = $this->id();
        foreach ([$first, $second] as $id) {
            app(ContentExport::class)->write($id, static function (int $count): void {});
        }
        $items = iterator_to_array((new WxrReader(ContentExport::path($first)))->items());
        $again = iterator_to_array((new WxrReader(ContentExport::path($second)))->items());
        $this->assertCount(2, $items);
        $this->assertNotSame($items[0]->postId, $items[1]->postId);
        foreach ($items as $item) {
            $this->assertLessThanOrEqual(4503599627370495, (int) $item->postId);
        }
        $this->assertSame(array_column($items, 'postId'), array_column($again, 'postId'));
        $this->assertSame('page', $items[1]->postType);
        $this->assertArrayHasKey('sitegrowkit_document_json', $items[1]->meta);
    }

    public function test_書き出し失敗は完成ファイルも途中ファイルも残さない(): void
    {
        DB::table('news')->insert(['title' => "不正\x01文字", 'slug' => 'bad-text', 'body' => '本文', 'status' => 'draft']);
        $id = $this->id();
        try {
            app(ContentExport::class)->write($id, static function (int $count): void {});
            $this->fail('不正文字で止まる必要があります');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('文字', $e->getMessage());
        }
        $this->assertFileDoesNotExist(ContentExport::path($id));
        $this->assertFileDoesNotExist(ContentExport::path($id).'.partial');
    }

    public function test_既存書き出しは上書きしない(): void
    {
        $id = $this->id();
        app(ContentExport::class)->write($id, static function (int $count): void {});
        $before = file_get_contents(ContentExport::path($id));
        try {
            app(ContentExport::class)->write($id, static function (int $count): void {});
            $this->fail('既存ファイルを上書きしてはいけません');
        } catch (\RuntimeException) {
            $this->assertSame($before, file_get_contents(ContentExport::path($id)));
        }
    }
}
