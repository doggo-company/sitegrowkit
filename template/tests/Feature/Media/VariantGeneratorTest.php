<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use AgentCms\Core\Media\VariantCapability;
use AgentCms\Core\Media\VariantFormat;
use AgentCms\Core\Media\VariantGenerator;
use AgentCms\Core\Media\VariantTarget;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * 派生物の生成（Phase 9.5-D / SPEC §4・§5）。
 *
 * **生成できたかどうかを合格条件にしない。** この環境の GD が何を備えているかは
 * 分からないので、「能力があれば作られる」「無ければ静かに作られない」の両方を通す形で書く。
 * 例外を投げないこと・孤児を残さないことが、ここで固定したい契約である。
 */
final class VariantGeneratorTest extends TestCase
{
    private VariantGenerator $generator;

    private VariantCapability $capability;

    /** spy を挟んだあとも実体を確かめられるように、本物を持っておく */
    private Filesystem $realDisk;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->realDisk = Storage::disk('public');
        $this->capability = new VariantCapability;
        $this->generator = new VariantGenerator($this->capability);
    }

    private function putImage(string $path, string $name, int $width = 40, int $height = 30): void
    {
        $file = UploadedFile::fake()->image($name, $width, $height);
        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));
    }

    /** この環境で 1 つでも生成できるか。できないなら失敗の注入に意味が無い */
    private function skipUnlessGenerationIsPossible(): void
    {
        foreach (VariantFormat::preferenceOrder() as $format) {
            if ($this->capability->supports('image/png', $format, 40, 30)) {
                return;
            }
        }

        $this->markTestSkipped('この環境では variant を生成できない');
    }

    /** 実物の disk を包んで、書き込みだけを失敗させられるようにする */
    private function spyDisk(): MockInterface
    {
        $spy = Mockery::mock($this->realDisk)->makePartial();
        Storage::set('public', $spy);

        return $spy;
    }

    /** 書きかけ（staging）も確定パスの実体も残っていないこと */
    private function assertNoLeftovers(): void
    {
        // 判定は spy ではなく**実物**に対して行う
        $this->assertSame([], $this->realDisk->allFiles('media/variants'));
        $this->assertTrue($this->realDisk->exists('media/sample.png'), '原本が消えている');
    }

    public function test_variants_live_in_their_own_namespace_so_they_cannot_collide(): void
    {
        // 旧実装の欠陥（拡張子置換）の解消: a.png と a.webp が同じ場所を指さない
        $this->assertSame(
            'media/variants/a/webp.webp',
            VariantGenerator::pathFor('media/a.png', VariantFormat::Webp),
        );
        $this->assertSame(
            'media/variants/a/avif.avif',
            VariantGenerator::pathFor('media/a.png', VariantFormat::Avif),
        );
        $this->assertNotSame(
            VariantGenerator::directoryFor('media/a.png'),
            VariantGenerator::directoryFor('media/b.png'),
        );
    }

    public function test_png_produces_only_the_formats_this_environment_can_encode(): void
    {
        $this->putImage('media/sample.png', 'sample.png');

        $descriptors = $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30);

        $expected = [];

        foreach (VariantFormat::preferenceOrder() as $format) {
            if ($this->capability->supports('image/png', $format, 40, 30)) {
                $expected[] = $format->value;
            }
        }

        $this->assertSame($expected, array_column($descriptors, 'format'));

        foreach ($descriptors as $descriptor) {
            Storage::disk('public')->assertExists($descriptor['path']);
            $this->assertSame(40, $descriptor['width']);
            $this->assertSame(30, $descriptor['height']);
            $this->assertGreaterThan(0, $descriptor['size']);
            $this->assertSame('public', $descriptor['disk']);
        }
    }

    public function test_avif_comes_before_webp_so_the_browser_gets_the_smaller_one_first(): void
    {
        $formats = array_map(
            static fn (VariantFormat $format): string => $format->value,
            VariantFormat::preferenceOrder(),
        );

        $this->assertSame(['avif', 'webp'], $formats);
    }

    public function test_gif_keeps_its_animation_by_never_being_converted(): void
    {
        $this->putImage('media/motion.gif', 'motion.gif');

        $this->assertSame([], $this->generator->generate('public', 'media/motion.gif', 'image/gif', 40, 30));
        $this->assertSame([], Storage::disk('public')->allFiles('media/variants'));
    }

    public function test_svg_is_not_rasterised(): void
    {
        Storage::disk('public')->put('media/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->assertSame([], $this->generator->generate('public', 'media/logo.svg', 'image/svg+xml', 10, 10));
    }

    public function test_oversized_image_is_skipped_without_failing(): void
    {
        config()->set('agentcms.variants.max_pixels', 100);
        $this->putImage('media/big.png', 'big.png');

        $this->assertSame([], $this->generator->generate('public', 'media/big.png', 'image/png', 40, 30));
    }

    public function test_disabled_generation_produces_nothing(): void
    {
        config()->set('agentcms.variants.enabled', false);
        $this->putImage('media/sample.png', 'sample.png');

        $this->assertSame([], $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30));
    }

    public function test_a_broken_original_does_not_throw_and_leaves_nothing_behind(): void
    {
        Storage::disk('public')->put('media/broken.png', 'これは画像ではない');

        $this->assertSame([], $this->generator->generate('public', 'media/broken.png', 'image/png', 40, 30));
        $this->assertSame([], Storage::disk('public')->allFiles('media/variants'));
    }

    public function test_a_missing_original_does_not_throw(): void
    {
        $this->assertSame([], $this->generator->generate('public', 'media/gone.png', 'image/png', 40, 30));
    }

    /**
     * 書き込みに失敗しても、**確定パスに壊れた実体を残さない**。
     *
     * 確定パスへ直接書くと、途中で落ちたときに「DB 行の無いファイル」が残り、
     * それが variant として配信され得る（誰も消せない）。
     * staging へ書いて成功時だけ move する、が守りたい契約である。
     */
    public function test_a_failing_write_leaves_neither_a_staged_nor_a_final_file(): void
    {
        $this->skipUnlessGenerationIsPossible();
        $this->putImage('media/sample.png', 'sample.png');

        $disk = $this->spyDisk();
        $disk->shouldReceive('put')->andReturn(false);

        $this->assertSame([], $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30));

        $this->assertNoLeftovers();
    }

    /**
     * **途中まで書けてから落ちた**ときが、いちばん危ない。
     *
     * 確定パスへ直接書いていると、壊れた実体がそこに残る。DB 行は作られないので
     * 誰もそれを消せず、しかも配信の `<source>` が指し得る場所である。
     * staging に書いていれば、残ったものを確実に消せる。
     */
    public function test_a_write_that_dies_halfway_does_not_poison_the_final_path(): void
    {
        $this->skipUnlessGenerationIsPossible();
        $this->putImage('media/sample.png', 'sample.png');

        $disk = $this->spyDisk();
        $disk->shouldReceive('put')->andReturnUsing(function (string $path) {
            $this->realDisk->put($path, '壊れた書きかけ');

            throw new RuntimeException('disk full');
        });

        $this->assertSame([], $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30));

        $this->assertNoLeftovers();
    }

    public function test_a_throwing_write_leaves_nothing_behind(): void
    {
        $this->skipUnlessGenerationIsPossible();
        $this->putImage('media/sample.png', 'sample.png');

        $disk = $this->spyDisk();
        $disk->shouldReceive('put')->andThrow(new RuntimeException('disk full'));

        $this->assertSame([], $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30));

        $this->assertNoLeftovers();
    }

    public function test_a_failing_move_leaves_nothing_behind(): void
    {
        $this->skipUnlessGenerationIsPossible();
        $this->putImage('media/sample.png', 'sample.png');

        $disk = $this->spyDisk();
        $disk->shouldReceive('move')->andReturn(false);

        $this->assertSame([], $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30));

        $this->assertNoLeftovers();
    }

    public function test_a_failing_write_does_not_touch_an_existing_variant(): void
    {
        $this->skipUnlessGenerationIsPossible();
        $this->putImage('media/sample.png', 'sample.png');

        // 別の media の variant。今回の失敗と何の関係も無い
        Storage::disk('public')->put('media/variants/other/webp.webp', 'これは既存の派生物');

        $disk = $this->spyDisk();
        $disk->shouldReceive('put')->andReturn(false);

        $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30);

        $this->assertSame(
            'これは既存の派生物',
            Storage::disk('public')->get('media/variants/other/webp.webp'),
        );
    }

    public function test_discard_removes_what_was_written(): void
    {
        $this->putImage('media/sample.png', 'sample.png');

        $descriptors = $this->generator->generate('public', 'media/sample.png', 'image/png', 40, 30);

        if ($descriptors === []) {
            $this->markTestSkipped('この環境では variant を生成できない');
        }

        $this->generator->discard($descriptors);

        foreach ($descriptors as $descriptor) {
            Storage::disk('public')->assertMissing($descriptor['path']);
        }

        // 原本には触らない
        Storage::disk('public')->assertExists('media/sample.png');
    }

    // --- 幅候補（レスポンシブ画像 / release-20260909） ---------------------------

    /** @return list<string> `webp@640x320` の形 */
    private function keysOf(array $descriptors): array
    {
        return array_map(
            static fn (array $d): string => $d['format'].'@'.$d['width'].'x'.$d['height'],
            $descriptors,
        );
    }

    /** 左半分が不透明の赤、右半分が完全透過の PNG を置く */
    private function putTransparentPng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));

        ob_start();
        imagepng($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);
    }

    /** 幅候補のパスは同寸のパスと**決して一致しない**（既存の行が指す場所を上書きしない） */
    public function test_width_variants_get_their_own_path_next_to_the_full_size_one(): void
    {
        $this->assertSame('media/variants/a/webp.webp', VariantGenerator::pathFor('media/a.png', VariantFormat::Webp));
        $this->assertSame('media/variants/a/webp-640.webp', VariantGenerator::pathFor('media/a.png', VariantFormat::Webp, 640));

        $full = new VariantTarget(VariantFormat::Avif, 1000);
        $small = new VariantTarget(VariantFormat::Avif, 640);
        $this->assertSame('media/variants/a/avif.avif', VariantGenerator::pathForTarget('media/a.png', $full, 1000));
        $this->assertSame('media/variants/a/avif-640.avif', VariantGenerator::pathForTarget('media/a.png', $small, 1000));
    }

    /**
     * 2400px 級の写真をスマホ幅で原寸配信しないための本体。
     * 原本より小さい幅だけを比率を保って作り、**拡大はしない**。DB へ渡す寸法は実体を測った値。
     */
    public function test_a_large_photo_gets_smaller_widths_but_is_never_upscaled(): void
    {
        $this->skipUnlessGenerationIsPossible();
        config()->set('agentcms.variants.widths', '640,1280,2400');
        $this->putImage('media/sample.png', 'sample.png', 1000, 500);

        $descriptors = $this->generator->generate('public', 'media/sample.png', 'image/png', 1000, 500);
        $keys = $this->keysOf($descriptors);

        foreach (VariantFormat::preferenceOrder() as $format) {
            if (! $this->capability->supports('image/png', $format, 1000, 500)) {
                continue;
            }

            $this->assertContains($format->value.'@1000x500', $keys, '同寸の変換は従来どおり作る');
            $this->assertContains($format->value.'@640x320', $keys, '比率を保った 640px');
            $this->assertNotContains($format->value.'@1280x640', $keys, '原本より大きい幅は作らない');
            $this->assertNotContains($format->value.'@2400x1200', $keys, '原本より大きい幅は作らない');
        }

        foreach ($descriptors as $descriptor) {
            Storage::disk('public')->assertExists($descriptor['path']);

            $measured = getimagesize(Storage::disk('public')->path($descriptor['path']));
            $this->assertIsArray($measured);
            $this->assertSame(
                [$descriptor['width'], $descriptor['height']],
                [$measured[0], $measured[1]],
                '記録する寸法は実体の実寸であるべき',
            );
        }

        // 原本は無傷
        Storage::disk('public')->assertExists('media/sample.png');
    }

    public function test_a_transparent_png_keeps_its_transparency_when_shrunk(): void
    {
        $this->skipUnlessGenerationIsPossible();
        config()->set('agentcms.variants.widths', '640');
        $this->putTransparentPng('media/alpha.png', 1000, 500);

        $descriptors = $this->generator->generate('public', 'media/alpha.png', 'image/png', 1000, 500);
        $shrunk = array_values(array_filter($descriptors, static fn (array $d): bool => $d['width'] === 640));

        $this->assertNotSame([], $shrunk, '幅候補が 1 つも作られていない');

        foreach ($shrunk as $descriptor) {
            $absolute = Storage::disk('public')->path($descriptor['path']);
            $decoded = match ($descriptor['format']) {
                'webp' => imagecreatefromwebp($absolute),
                'avif' => imagecreatefromavif($absolute),
                default => false,
            };
            $this->assertNotFalse($decoded);

            $transparent = (imagecolorat($decoded, 630, 310) >> 24) & 0x7F;
            $opaque = (imagecolorat($decoded, 10, 10) >> 24) & 0x7F;
            imagedestroy($decoded);

            $this->assertSame(127, $transparent, "{$descriptor['format']} の透過が失われている");
            $this->assertSame(0, $opaque, "{$descriptor['format']} の不透明部分が透けている");
        }
    }

    /** WebP の原本から同寸の WebP は作らない（従来どおり）が、**縮小した WebP は作る** */
    public function test_a_webp_original_still_gets_smaller_webp_widths_but_no_same_size_copy(): void
    {
        if (! $this->capability->canDecode('image/webp') || ! $this->capability->canEncode(VariantFormat::Webp)) {
            $this->markTestSkipped('この環境では WebP を扱えない');
        }

        config()->set('agentcms.variants.widths', '640');

        $image = imagecreatetruecolor(1000, 500);
        ob_start();
        imagewebp($image, null, 80);
        Storage::disk('public')->put('media/photo.webp', (string) ob_get_clean());
        imagedestroy($image);

        $descriptors = $this->generator->generate('public', 'media/photo.webp', 'image/webp', 1000, 500);
        $keys = $this->keysOf($descriptors);

        $this->assertContains('webp@640x320', $keys);
        $this->assertNotContains('webp@1000x500', $keys, '同じ形式への同寸変換は作らない');
    }

    /** 作り直しは「無いものだけ」を渡す。渡していない target は作らない（既存の実体を上書きしない） */
    public function test_only_the_requested_targets_are_made(): void
    {
        $this->skipUnlessGenerationIsPossible();
        $this->putImage('media/sample.png', 'sample.png', 1000, 500);

        $format = null;

        foreach (VariantFormat::preferenceOrder() as $candidate) {
            if ($this->capability->supports('image/png', $candidate, 1000, 500)) {
                $format = $candidate;

                break;
            }
        }

        $this->assertNotNull($format);

        $descriptors = $this->generator->generate(
            'public', 'media/sample.png', 'image/png', 1000, 500,
            [new VariantTarget($format, 640)],
        );

        $this->assertSame([$format->value.'@640x320'], $this->keysOf($descriptors));
        $this->assertSame(
            [VariantGenerator::pathFor('media/sample.png', $format, 640)],
            Storage::disk('public')->allFiles('media/variants'),
        );
    }

    /** 明示的に頼まれても拡大はしない */
    public function test_an_upscaling_target_is_refused_even_when_asked_for(): void
    {
        $this->putImage('media/sample.png', 'sample.png', 40, 30);

        $descriptors = $this->generator->generate(
            'public', 'media/sample.png', 'image/png', 40, 30,
            [new VariantTarget(VariantFormat::Webp, 640), new VariantTarget(VariantFormat::Avif, 41)],
        );

        $this->assertSame([], $descriptors);
        $this->assertSame([], Storage::disk('public')->allFiles('media/variants'));
    }

    /** `.env` は文字列。数でないもの・範囲外・重複を静かに落とし、昇順に揃える */
    public function test_width_candidates_are_read_defensively_from_the_env_string(): void
    {
        config()->set('agentcms.variants.widths', '1280, 640,abc,640,0,99999,,-5');
        $this->assertSame([640, 1280], $this->capability->widths());

        config()->set('agentcms.variants.widths', '');
        $this->assertSame([], $this->capability->widths(), '空にすれば幅候補を作らない');

        config()->set('agentcms.variants.widths', [800, 400]);
        $this->assertSame([400, 800], $this->capability->widths());

        config()->set('agentcms.variants.widths', '100,200,300,400,500,600,700,800');
        $this->assertCount(6, $this->capability->widths(), '同期生成なので候補の数を bound する');

        config()->set('agentcms.variants.widths', 12345);
        $this->assertSame([640, 1280], $this->capability->widths(), '解釈できない型は既定へ');
    }

    /** 幅候補が無ければ従来どおり同寸の変換だけ。GIF / SVG は幅候補も作らない */
    public function test_widths_never_apply_to_gif_or_svg(): void
    {
        config()->set('agentcms.variants.widths', '20');

        $this->assertSame([], $this->capability->targetsFor('image/gif', 40, 30));
        $this->assertSame([], $this->capability->targetsFor('image/svg+xml', 40, 30));
    }
}
