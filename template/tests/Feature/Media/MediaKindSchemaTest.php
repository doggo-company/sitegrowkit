<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaVisibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use RuntimeException;
use Tests\TestCase;

/**
 * `media` の種別列と `media_variants`（Phase 9.5-D / ADR-0018・SPEC §2・§12.1）。
 *
 * 既存行の意味が変わらないこと、down が **fail-closed** であることを固定する。
 */
final class MediaKindSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function media(array $overrides = []): Media
    {
        return Media::query()->create(array_merge([
            'name' => 'サンプル',
            'path' => 'media/'.fake()->uuid().'.png',
            'mime' => 'image/png',
            'size' => 1024,
            'width' => 800,
            'height' => 600,
        ], $overrides));
    }

    public function test_new_media_defaults_to_a_public_image_on_the_public_disk(): void
    {
        $media = $this->media()->refresh();

        $this->assertSame(MediaKind::Image, $media->kind);
        $this->assertSame(MediaVisibility::Public, $media->visibility);
        $this->assertSame('public', $media->disk);
        $this->assertNull($media->original_name);
    }

    public function test_dimensions_became_nullable_for_general_files(): void
    {
        $file = $this->media([
            'kind' => 'file',
            'disk' => 'local',
            'path' => 'files/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'width' => null,
            'height' => null,
        ])->refresh();

        $this->assertNull($file->width);
        $this->assertNull($file->height);
    }

    public function test_has_webp_is_not_dropped_yet(): void
    {
        // 写し替えが終わるまで legacy fallback の情報源として残す（OG5）
        $this->assertTrue(Schema::hasColumn('media', 'has_webp'));
    }

    public function test_one_variant_per_format(): void
    {
        $media = $this->media();

        MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/a/webp.webp',
            'mime' => 'image/webp',
            'size' => 512,
            'width' => 800,
            'height' => 600,
        ]);

        $this->expectException(QueryException::class);

        MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/a/webp-2.webp',
            'mime' => 'image/webp',
            'size' => 512,
            'width' => 800,
            'height' => 600,
        ]);
    }

    public function test_two_formats_of_the_same_media_coexist(): void
    {
        $media = $this->media();

        foreach (['avif', 'webp'] as $format) {
            MediaVariant::query()->create([
                'media_id' => $media->id,
                'format' => $format,
                'disk' => 'public',
                'path' => "media/variants/a/{$format}.{$format}",
                'mime' => "image/{$format}",
                'size' => 512,
                'width' => 800,
                'height' => 600,
            ]);
        }

        $this->assertSame(2, $media->variants()->count());
    }

    public function test_variant_url_is_built_from_disk_and_path_not_stored(): void
    {
        // 導出できる値を列で持たない（SPEC §2.2）
        $this->assertFalse(Schema::hasColumn('media_variants', 'url'));
    }

    public function test_deleting_media_with_variants_is_restricted(): void
    {
        $media = $this->media();

        MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/a/webp.webp',
            'mime' => 'image/webp',
            'size' => 512,
            'width' => 800,
            'height' => 600,
        ]);

        // CASCADE ではない。掃除は Application 層が明示的に行う
        $this->expectException(QueryException::class);
        $media->delete();
    }

    public function test_rollback_stops_without_changing_anything_when_a_general_file_exists(): void
    {
        $this->media([
            'kind' => 'file',
            'disk' => 'local',
            'mime' => 'application/pdf',
            'width' => null,
            'height' => null,
        ]);

        try {
            $this->migration()->down();
            $this->fail('一般ファイルが在るのに rollback が通ってしまった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('一般ファイルが 1 件', $e->getMessage());
            $this->assertStringContainsString('何も変更していません', $e->getMessage());
        }

        // 部分 rollback を作らない: 何ひとつ落ちていないこと
        $this->assertTrue(Schema::hasTable('media_variants'));
        $this->assertTrue(Schema::hasColumn('media', 'kind'));
        $this->assertTrue(Schema::hasColumn('media', 'disk'));
        $this->assertTrue(Schema::hasColumn('media', 'visibility'));
        $this->assertTrue(Schema::hasColumn('media', 'original_name'));
    }

    public function test_rollback_stops_when_variants_exist(): void
    {
        $media = $this->media();

        MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => 'webp',
            'disk' => 'public',
            'path' => 'media/variants/a/webp.webp',
            'mime' => 'image/webp',
            'size' => 512,
            'width' => 800,
            'height' => 600,
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    public function test_rollback_stops_when_dimensions_are_null(): void
    {
        // kind は image のままでも、寸法が無ければ NOT NULL へ戻せない
        DB::table('media')->insert([
            'kind' => 'image',
            'visibility' => 'public',
            'disk' => 'public',
            'name' => '寸法なし',
            'path' => 'media/x.png',
            'mime' => 'image/png',
            'size' => 1,
            'width' => null,
            'height' => null,
            'has_webp' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    public function test_rollback_succeeds_when_nothing_would_be_broken(): void
    {
        $this->media();

        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('media_variants'));
        $this->assertFalse(Schema::hasColumn('media', 'kind'));
        $this->assertFalse(Schema::hasColumn('media', 'visibility'));
        $this->assertFalse(Schema::hasColumn('media', 'disk'));
        $this->assertFalse(Schema::hasColumn('media', 'original_name'));
        $this->assertTrue(Schema::hasColumn('media', 'has_webp'));
    }

    private function migration(): object
    {
        return require base_path(
            'modules/Media/database/migrations/2026_08_15_000010_add_kind_and_variants_to_media.php'
        );
    }
}
