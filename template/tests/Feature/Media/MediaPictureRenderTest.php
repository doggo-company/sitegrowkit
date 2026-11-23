<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Media\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Media\Models\Media;
use Modules\Media\Models\MediaVariant;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * 公開側の画像出力（Phase 9.5-D / SPEC §5・§7.1）。
 *
 * 出すのは **DB に行がある派生物だけ**。配信のたびに実在を確かめない。
 * 何も無ければ `<picture>` で包まず `<img>` だけを出す —
 * 空の `<picture>` は「候補があるように見えて 1 つも無い」という誤解しか生まない。
 */
final class MediaPictureRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @param array<string, mixed> $overrides */
    private function image(array $overrides = []): Media
    {
        return Media::query()->create(array_merge([
            'name' => '写真',
            'alt' => '青い空',
            'path' => 'media/photo.png',
            'mime' => 'image/png',
            'size' => 10,
            'width' => 400,
            'height' => 300,
        ], $overrides));
    }

    private function variant(Media $media, string $format): MediaVariant
    {
        return MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => $format,
            'disk' => 'public',
            'path' => "media/variants/photo/{$format}.{$format}",
            'mime' => "image/{$format}",
            'size' => 5,
            'width' => 400,
            'height' => 300,
        ]);
    }

    private function render(Media $media): string
    {
        return Blade::render(
            '<x-media-img :media="$media" />',
            ['media' => $media->load('variants')],
        );
    }

    public function test_without_any_variant_it_renders_a_bare_img(): void
    {
        $html = $this->render($this->image());

        $this->assertStringNotContainsString('<picture', $html);
        $this->assertStringNotContainsString('<source', $html);
        $this->assertStringContainsString('src="'.Storage::disk('public')->url('media/photo.png').'"', $html);
        $this->assertStringContainsString('alt="青い空"', $html);
        $this->assertStringContainsString('width="400"', $html);
        $this->assertStringContainsString('height="300"', $html);
    }

    public function test_avif_comes_before_webp_and_the_original_stays_in_the_img(): void
    {
        $media = $this->image();
        $this->variant($media, 'webp');
        $this->variant($media, 'avif');

        $html = $this->render($media);

        $avif = strpos($html, 'type="image/avif"');
        $webp = strpos($html, 'type="image/webp"');
        $img = strpos($html, '<img');

        $this->assertIsInt($avif);
        $this->assertIsInt($webp);
        $this->assertLessThan($webp, $avif, 'AVIF を WebP より先に出す');
        $this->assertLessThan($img, $webp, '<img> は最後（対応していないブラウザの受け皿）');
        $this->assertStringContainsString('media/photo.png', $html);
    }

    public function test_only_rows_in_the_database_become_sources(): void
    {
        $media = $this->image();
        $this->variant($media, 'webp');

        // ファイルは在るが行が無い AVIF。**推測して出さない**
        Storage::disk('public')->put('media/variants/photo/avif.avif', 'avif');

        $html = $this->render($media);

        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringNotContainsString('type="image/avif"', $html);
    }

    /**
     * 写し替えが済むまでは、旧 `has_webp` も配信の情報源である（SPEC §7.1）。
     * migration で backfill しないので、ここが繋がっていないと既存サイトの配信が落ちる。
     */
    public function test_a_legacy_flag_still_produces_a_webp_source(): void
    {
        $media = $this->image(['has_webp' => true]);

        $html = $this->render($media);

        $this->assertStringContainsString('<picture', $html);
        $this->assertStringContainsString(
            Storage::disk('public')->url(ImageProcessor::webpPath('media/photo.png')),
            $html,
        );
    }

    public function test_a_database_row_wins_over_the_legacy_flag(): void
    {
        $media = $this->image(['has_webp' => true]);
        $this->variant($media, 'webp');

        $html = $this->render($media);

        // 行が正。旧パスは出さない（同じ形式の <source> を 2 つ並べない）
        $this->assertStringContainsString('media/variants/photo/webp.webp', $html);
        $this->assertStringNotContainsString('media/photo.webp', $html);
        $this->assertSame(1, substr_count($html, 'type="image/webp"'));
    }

    /** 旧実装は AVIF を作っていない。legacy から AVIF を推測しない */
    public function test_the_legacy_flag_never_implies_avif(): void
    {
        $html = $this->render($this->image(['has_webp' => true]));

        $this->assertStringNotContainsString('type="image/avif"', $html);
    }

    // --- 公開ページ -------------------------------------------------------

    public function test_the_public_pages_render_through_the_component(): void
    {
        $media = $this->image();
        $this->variant($media, 'webp');

        Work::query()->create([
            'title' => '実績',
            'slug' => 'jisseki',
            'body' => '<p>本文</p>',
            'category' => 'web',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'main_image_id' => $media->id,
        ]);

        foreach (['/', '/works', '/works/jisseki'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                'type="image/webp"',
                (string) $html,
                "{$url} が派生物を出していない（直 <img> のままではないか）",
            );
        }
    }

    /** 一覧で 1 行ごとに派生物を引かない（N+1 を持ち込まない） */
    public function test_listing_pages_do_not_query_variants_per_row(): void
    {
        foreach (range(1, 3) as $n) {
            $media = $this->image(['path' => "media/photo-{$n}.png"]);
            $this->variant($media, 'webp');

            Work::query()->create([
                'title' => "実績 {$n}",
                'slug' => "jisseki-{$n}",
                'body' => '<p>本文</p>',
                'category' => 'web',
                'status' => 'published',
                'published_at' => now()->subDay(),
                'main_image_id' => $media->id,
            ]);
        }

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'media_variants')) {
                $queries++;
            }
        });

        $this->get('/works')->assertOk();

        $this->assertLessThanOrEqual(1, $queries, 'media_variants を行ごとに引いている');
    }

    // --- 幅候補（レスポンシブ画像 / release-20260909） ---------------------------

    private function widthVariant(Media $media, string $format, int $width, int $height): MediaVariant
    {
        return MediaVariant::query()->create([
            'media_id' => $media->id,
            'format' => $format,
            'disk' => 'public',
            'path' => "media/variants/photo/{$format}-{$width}.{$format}",
            'mime' => "image/{$format}",
            'size' => 3,
            'width' => $width,
            'height' => $height,
        ]);
    }

    /** 同じ MIME の行は 1 つの `<source>` に畳み、幅の昇順に `w` descriptor で並べる */
    public function test_same_mime_rows_are_folded_into_one_source_with_width_descriptors(): void
    {
        $media = $this->image();
        $this->variant($media, 'webp');
        $this->widthVariant($media, 'webp', 200, 150);
        $this->widthVariant($media, 'webp', 100, 75);

        $html = $this->render($media);
        $url = static fn (string $path): string => Storage::disk('public')->url($path);

        $this->assertSame(1, substr_count($html, 'type="image/webp"'), '同じ MIME の <source> は 1 つ');
        $this->assertStringContainsString(
            'srcset="'.$url('media/variants/photo/webp-100.webp').' 100w, '
            .$url('media/variants/photo/webp-200.webp').' 200w, '
            .$url('media/variants/photo/webp.webp').' 400w"',
            $html,
        );
        $this->assertStringContainsString('sizes="100vw"', $html);
        // <img> は原本のまま（対応していないブラウザの受け皿）
        $this->assertStringContainsString('src="'.$url('media/photo.png').'"', $html);
        $this->assertStringContainsString('width="400"', $html);
    }

    public function test_sizes_can_be_passed_by_the_caller(): void
    {
        $media = $this->image();
        $this->variant($media, 'webp');
        $this->widthVariant($media, 'webp', 200, 150);

        $html = Blade::render(
            '<x-media-img :media="$media" sizes="(min-width: 768px) 33vw, 100vw" />',
            ['media' => $media->load('variants')],
        );

        $this->assertStringContainsString('sizes="(min-width: 768px) 33vw, 100vw"', $html);
    }

    /** 旧実装で登録された画像（同寸だけ）は、出力が 1 文字も変わらない */
    public function test_a_single_candidate_keeps_the_plain_srcset(): void
    {
        $media = $this->image();
        $this->variant($media, 'webp');

        $html = $this->render($media);

        $this->assertStringContainsString('srcset="'.Storage::disk('public')->url('media/variants/photo/webp.webp').'"', $html);
        $this->assertStringNotContainsString('sizes=', $html);
        $this->assertStringNotContainsString(' 400w', $html);
    }

    /** 配信のたびにファイルを触らない — 幅も URL もすべて DB の行から導く */
    public function test_rendering_never_touches_the_disk(): void
    {
        $media = $this->image();
        $this->variant($media, 'avif');
        $this->variant($media, 'webp');
        $this->widthVariant($media, 'webp', 200, 150);

        $spy = Mockery::mock(Storage::disk('public'))->makePartial();
        $spy->shouldNotReceive('exists');
        $spy->shouldNotReceive('size');
        $spy->shouldNotReceive('get');
        $spy->shouldNotReceive('allFiles');
        $spy->shouldNotReceive('files');
        Storage::set('public', $spy);

        $html = $this->render($media);

        $this->assertStringContainsString(' 200w, ', $html);
    }

    /** 管理画面・ピッカーの `variants` は形式ごとに 1 つ（いちばん大きい幅）のまま */
    public function test_the_picker_still_sees_one_entry_per_format_pointing_at_the_largest(): void
    {
        $media = $this->image();
        $this->widthVariant($media, 'webp', 200, 150);
        $this->variant($media, 'webp');

        $picker = $media->load('variants')->toPickerArray();

        $this->assertCount(1, $picker['variants']);
        $this->assertSame(Storage::disk('public')->url('media/variants/photo/webp.webp'), $picker['variants'][0]['url']);
        $this->assertSame($picker['variants'][0]['url'], $media->webpUrl());
    }
}
