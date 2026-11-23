<?php

declare(strict_types=1);

namespace Tests\Unit;

use AgentCms\Core\Media\VariantCapability;
use AgentCms\Core\Media\VariantFormat;
use Tests\TestCase;

/**
 * variant を作ってよいかの判定（Phase 9.5-D / SPEC §4.1・§4.2）。
 *
 * **能力の有無そのものを合格条件にしない。** この環境に AVIF があるかは分からないので、
 * 「能力があるときだけ true になり得る」「能力に関係なく false になる条件」を固定する。
 */
final class VariantCapabilityTest extends TestCase
{
    private VariantCapability $capability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capability = new VariantCapability;
    }

    public function test_svg_is_never_converted(): void
    {
        // ベクタをラスタ化しない
        $this->assertFalse($this->capability->supports('image/svg+xml', VariantFormat::Webp, 100, 100));
        $this->assertFalse($this->capability->supports('image/svg+xml', VariantFormat::Avif, 100, 100));
    }

    public function test_gif_is_never_converted_so_animation_survives(): void
    {
        // GD で再エンコードするとアニメーションが失われる（D13）
        $this->assertFalse($this->capability->supports('image/gif', VariantFormat::Webp, 100, 100));
        $this->assertFalse($this->capability->supports('image/gif', VariantFormat::Avif, 100, 100));
    }

    public function test_same_format_is_not_regenerated(): void
    {
        $this->assertFalse($this->capability->supports('image/webp', VariantFormat::Webp, 100, 100));
        $this->assertFalse($this->capability->supports('image/avif', VariantFormat::Avif, 100, 100));
    }

    public function test_disabled_by_config_stops_every_format(): void
    {
        config()->set('agentcms.variants.enabled', false);

        $this->assertFalse($this->capability->supports('image/jpeg', VariantFormat::Webp, 10, 10));
    }

    public function test_pixel_limit_is_inclusive(): void
    {
        config()->set('agentcms.variants.max_pixels', 10_000);

        $this->assertTrue($this->capability->withinPixelLimit(100, 100));
        $this->assertFalse($this->capability->withinPixelLimit(101, 100));
    }

    public function test_zero_dimension_is_outside_the_pixel_limit(): void
    {
        $this->assertFalse($this->capability->withinPixelLimit(0, 100));
        $this->assertFalse($this->capability->withinPixelLimit(100, 0));
    }

    public function test_estimate_is_the_fixed_formula_not_a_measurement(): void
    {
        // width * height * 8 + 16MiB（SPEC §4.2）。実測に依存させない
        $this->assertSame(
            6000 * 6000 * 8 + 16 * 1024 * 1024,
            $this->capability->estimatedBytes(6000, 6000),
        );
    }

    public function test_oversized_image_is_skipped_even_when_the_format_is_supported(): void
    {
        config()->set('agentcms.variants.max_pixels', 1_000);

        $this->assertFalse($this->capability->supports('image/jpeg', VariantFormat::Webp, 2000, 2000));
    }

    public function test_an_image_that_would_not_fit_in_the_budget_is_refused(): void
    {
        $budget = $this->capability->memoryBudget();

        if ($budget === null) {
            $this->markTestSkipped('memory_limit が無制限の環境では画素数だけで判断する');
        }

        // budget をちょうど超える画素数（判定は実測ではなく固定式なので環境に依らない）
        $pixels = intdiv($budget, 8) + 1;

        $this->assertFalse($this->capability->withinMemoryBudget($pixels, 1));
        $this->assertTrue($this->capability->withinMemoryBudget(1, 1));
    }

    public function test_unlimited_memory_falls_back_to_the_pixel_limit_only(): void
    {
        if ($this->capability->memoryLimitBytes() !== null) {
            $this->markTestSkipped('memory_limit が設定されている環境');
        }

        $this->assertTrue($this->capability->withinMemoryBudget(4000, 4000));
    }

    public function test_decode_and_encode_are_judged_separately(): void
    {
        // 片方だけある環境が実在するので、組み合わせで判定する（SPEC §4.1）
        $decodable = $this->capability->canDecode('image/jpeg');
        $encodable = $this->capability->canEncode(VariantFormat::Avif);

        $this->assertSame(
            $decodable && $encodable,
            $this->capability->supports('image/jpeg', VariantFormat::Avif, 10, 10),
        );
    }

    /**
     * 設定は `.env`（= 文字列）から来る。数でない値・危険な値が入っても、
     * **upload を失敗させずに**安全な側へ丸める。
     */
    public function test_a_non_numeric_setting_falls_back_to_the_default(): void
    {
        config()->set('agentcms.variants.avif_quality', 'high');
        config()->set('agentcms.variants.max_pixels', '');

        $this->assertSame(50, $this->capability->quality(VariantFormat::Avif));
        $this->assertSame(20_000_000, $this->capability->maxPixels());
    }

    public function test_quality_is_clamped_into_the_usable_range(): void
    {
        config()->set('agentcms.variants.webp_quality', 0);
        $this->assertSame(1, $this->capability->quality(VariantFormat::Webp));

        config()->set('agentcms.variants.webp_quality', 500);
        $this->assertSame(100, $this->capability->quality(VariantFormat::Webp));
    }

    public function test_a_numeric_string_from_env_is_honoured(): void
    {
        config()->set('agentcms.variants.webp_quality', '65');

        $this->assertSame(65, $this->capability->quality(VariantFormat::Webp));
    }

    public function test_a_zero_memory_reserve_is_raised_to_a_safe_floor(): void
    {
        // 予約が 0 だと budget が膨らみ、生成中に memory_limit を踏む
        config()->set('agentcms.variants.memory_reserve_bytes', 0);

        $this->assertSame(8 * 1024 * 1024, $this->capability->memoryReserveBytes());
    }

    public function test_a_negative_pixel_limit_cannot_disable_the_guard(): void
    {
        config()->set('agentcms.variants.max_pixels', -1);

        $this->assertSame(1, $this->capability->maxPixels());
        $this->assertFalse($this->capability->withinPixelLimit(100, 100));
    }

    public function test_unknown_mime_has_no_decoder(): void
    {
        $this->assertNull($this->capability->decoderFor('application/pdf'));
        $this->assertFalse($this->capability->canDecode('application/pdf'));
    }
}
