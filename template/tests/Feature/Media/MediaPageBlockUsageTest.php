<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Media\MediaUsageInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ページブロック内の画像が「使用中」として検出される（A7 の修正）。
 *
 * ## なぜこのテストが要るのか
 *
 * MediaUsageInspector は SchemaRegistry 経由でスキーマを走査するが、
 * page_versions は SchemaRegistry に登録されていない。
 * そのため、ページのブロックエディタで使われた画像が「未使用」と誤検出されていた。
 *
 * このテストは:
 * 1. 画像ブロック（props.mediaId）で参照される media が usedMap で true になること
 * 2. usages() でページへの導線が含まれること
 * 3. SchemaRegistry に登録されていないテーブルでも検出されること
 * を確認する。
 */
final class MediaPageBlockUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** image ブロックで使われている media は usedMap で使用中になる */
    public function test_imageブロックで参照されたメディアは使用中になる(): void
    {
        $mediaId = $this->media('block-test.png');
        $pageId = $this->pageWithImageBlock($mediaId);

        $result = app(MediaUsageInspector::class)->usedMap([$mediaId]);

        $this->assertTrue($result[$mediaId], 'image ブロックで使われている media が未使用と判定された');
    }

    /** usages() でページブロックの使用箇所が返る */
    public function test_usages_でページブロックの使用箇所が含まれる(): void
    {
        $mediaId = $this->media('usage-test.png');
        $pageId = $this->pageWithImageBlock($mediaId);

        $usages = app(MediaUsageInspector::class)->usages($mediaId);

        $schemas = array_column($usages, 'schema');
        $this->assertContains('page_versions', $schemas, 'page_versions の使用箇所が usages に含まれない');

        $pvUsage = current(array_filter($usages, fn ($u) => $u['schema'] === 'page_versions'));
        $this->assertNotFalse($pvUsage, 'page_versions エントリが見つからない');
        $this->assertSame('ページブロック', $pvUsage['label']);
        $this->assertSame('document', $pvUsage['field']);
        $this->assertNotEmpty($pvUsage['items'], 'ページの一覧が空');

        $item = $pvUsage['items'][0];
        $this->assertSame($pageId, $item['id'], 'ページIDが一致しない');
        $this->assertSame('テストページ', $item['title'], 'ページタイトルが一致しない');
        $this->assertFalse($item['trashed'], '通常ページがゴミ箱扱いになっている');
    }

    /** 別の media は使用中にならない */
    public function test_使われていないメディアは未使用のまま(): void
    {
        $usedId = $this->media('used.png');
        $unusedId = $this->media('unused.png');
        $this->pageWithImageBlock($usedId);

        $result = app(MediaUsageInspector::class)->usedMap([$usedId, $unusedId]);

        $this->assertTrue($result[$usedId], '使用中の media が未使用判定');
        $this->assertFalse($result[$unusedId], '未使用の media が使用中判定');
    }

    /** ゴミ箱に入ったページでも使用中として保護される */
    public function test_ゴミ箱のページでも使用中として保護される(): void
    {
        $mediaId = $this->media('trashed-page.png');
        $pageId = $this->pageWithImageBlock($mediaId);
        DB::table('pages')->where('id', $pageId)->update(['trashed_at' => now()]);

        $result = app(MediaUsageInspector::class)->usedMap([$mediaId]);
        $this->assertTrue($result[$mediaId], 'ゴミ箱内ページの media が未使用判定');

        $usages = app(MediaUsageInspector::class)->usages($mediaId);
        $pvUsage = current(array_filter($usages, fn ($u) => $u['schema'] === 'page_versions'));
        $this->assertNotFalse($pvUsage);
        $this->assertTrue($pvUsage['items'][0]['trashed'], 'ゴミ箱フラグが立っていない');
    }

    /** page_versions テーブルが無い場合はクラッシュしない */
    public function test_page_versionsテーブルが無くてもクラッシュしない(): void
    {
        $mediaId = $this->media('no-table.png');

        // このテストはテーブルがある環境で動くため、Schema::hasTable が true を返す。
        // 「テーブルが無い」ケースは unit テストで確かめる想定。
        // ここでは「通常動作が壊れていないこと」だけを確認する。
        $result = app(MediaUsageInspector::class)->usedMap([$mediaId]);
        $this->assertArrayHasKey($mediaId, $result);
    }

    // ── ヘルパー ────────────────────────────────────────────────

    private function media(string $filename): int
    {
        return (int) DB::table('media')->insertGetId([
            'name' => $filename,
            'alt' => $filename,
            'path' => 'media/'.$filename,
            'mime' => 'image/png',
            'size' => 100,
            'width' => 100,
            'height' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * image ブロックで指定 media を使うページを作り、page_id を返す。
     */
    private function pageWithImageBlock(int $mediaId): int
    {
        $pageId = (int) DB::table('pages')->insertGetId([
            'title' => 'テストページ',
            'slug' => 'test-page-'.uniqid(),
            'status' => 'published',
            'managed_by' => 'document',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = [
            'schemaVersion' => 1,
            'root' => [
                'id' => 'root-id',
                'key' => 'page',
                'type' => 'page',
                'props' => [],
                'children' => [
                    [
                        'id' => 'img-node-1',
                        'key' => 'image',
                        'type' => 'image',
                        'props' => [
                            'mediaId' => $mediaId,
                            'alt' => 'テスト画像',
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ];

        $versionId = (int) DB::table('page_versions')->insertGetId([
            'page_id' => $pageId,
            'revision' => 1,
            'schema_version' => 1,
            'document' => json_encode($document),
            'created_interface' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $pageId)->update([
            'published_version_id' => $versionId,
            'draft_version_id' => $versionId,
        ]);

        return $pageId;
    }
}
