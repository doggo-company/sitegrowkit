<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use RuntimeException;
use Tests\TestCase;

/**
 * 派生物の同一性を「形式」から「形式 + 幅」へ広げる migration（release-20260909）。
 *
 * 守りたいのは 3 つ: 幅ごとの行を持てること・同じ形式と幅はなお一意であること・
 * **既存の行を 1 つも壊さず、戻すときは fail-closed** であること。
 */
final class ResponsiveVariantMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_INDEX = 'uq_media_variant_format_width';

    private const OLD_INDEX = 'uq_media_variant_format';

    private function widthMigration(): Migration
    {
        // 後続の別機能の migration が増えても、この索引変更だけを往復する。
        return require base_path('modules/Media/database/migrations/2026_09_09_000010_add_width_to_media_variant_uniqueness.php');
    }

    private function image(): Media
    {
        return Media::query()->create([
            'name' => '写真',
            'path' => 'media/photo.png',
            'mime' => 'image/png',
            'size' => 10,
            'width' => 400,
            'height' => 300,
        ]);
    }

    private function row(Media $media, string $format, int $width): MediaVariant
    {
        return MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => $format,
            'disk' => 'public',
            'path' => "media/variants/photo/{$format}-{$width}.{$format}",
            'mime' => "image/{$format}",
            'size' => 3,
            'width' => $width,
            'height' => intdiv($width * 3, 4),
        ]);
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        $names = array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes('media_variants'),
        );
        sort($names);

        return $names;
    }

    public function test_the_same_format_may_exist_at_several_widths(): void
    {
        $media = $this->image();
        $this->row($media, 'webp', 400);
        $this->row($media, 'webp', 200);

        $this->assertSame(2, $media->variants()->where('format', 'webp')->count());
    }

    public function test_the_same_format_and_width_is_still_unique(): void
    {
        $media = $this->image();
        $this->row($media, 'webp', 400);

        $this->expectException(QueryException::class);
        $this->row($media, 'webp', 400);
    }

    public function test_the_old_index_is_replaced_by_the_wider_one(): void
    {
        $names = $this->indexNames();

        $this->assertContains(self::NEW_INDEX, $names);
        $this->assertNotContains(self::OLD_INDEX, $names);
    }

    /** 幅候補の行があるうちは戻せない。**何も変更せず**停止する */
    public function test_rollback_refuses_while_width_variants_exist(): void
    {
        $media = $this->image();
        $this->row($media, 'webp', 400);
        $this->row($media, 'webp', 200);

        try {
            $this->widthMigration()->down();
            $this->fail('幅候補があるのに rollback が通った');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('戻せません', $e->getMessage());
        }

        $this->assertContains(self::NEW_INDEX, $this->indexNames(), '停止したのに索引が変わっている');
        $this->assertSame(2, MediaVariant::count());
    }

    /**
     * 既存サイトの道筋そのもの: 旧 unique の世界にある行がそのまま残り、
     * 新 unique の世界になってから幅候補を足せる。
     */
    public function test_migrating_an_existing_site_keeps_its_legacy_rows_and_then_allows_widths(): void
    {
        $media = $this->image();
        $legacy = $this->row($media, 'webp', 400);

        // 幅候補が無ければ戻せる（旧 unique の世界へ）
        $this->widthMigration()->down();
        $this->assertContains(self::OLD_INDEX, $this->indexNames());

        // 旧実装が書く形の行（同寸）をもう 1 つ足す
        $this->row($media, 'avif', 400);

        $this->widthMigration()->up();

        $this->assertContains(self::NEW_INDEX, $this->indexNames());
        $this->assertNotContains(self::OLD_INDEX, $this->indexNames());
        $this->assertSame(2, MediaVariant::count(), '既存の行が失われている');
        $this->assertSame('media/variants/photo/webp-400.webp', $legacy->fresh()?->path);

        $this->row($media, 'webp', 200);
        $this->assertSame(3, MediaVariant::count());
    }
}
