<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Render\DocumentCss;
use AgentCms\Core\Theme\ThemeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C4: 画面幅ごとの上書きが **本当に効く**こと。
 *
 * `capabilities.responsive` は「この prop / style は画面幅ごとに変えられる」という約束。
 * クラスは出るのに CSS が無い、という状態はその約束を破っている
 * （宣言はできるが描かれない — `PHASE9_6_SPEC.md` V-6）。
 *
 * ここで固定するのは **宣言と CSS が 1 対 1 で対応していること**。
 */
final class PageDocumentResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function css(): DocumentCss
    {
        return app(DocumentCss::class);
    }

    /**
     * **宣言されているのに CSS を出せないものが 1 つも無いこと。**
     *
     * ここが空でなくなったら、それは「画面幅ごとに変えられます」と言いながら
     * 変えられない capability を増やしたということ。
     */
    public function test_every_responsive_capability_can_be_expressed_in_css(): void
    {
        $this->assertSame(
            [],
            $this->css()->unsupported(),
            '宣言だけで CSS を出せない responsive capability があります',
        );
    }

    /** 宣言されたキーの、取り得る値すべてに規則があること */
    public function test_every_declared_value_has_a_rule_at_every_breakpoint(): void
    {
        $css = $this->css()->build();
        $breakpoints = array_keys(app(ThemeTokens::class)->breakpointRanges());
        $registry = app(ComponentRegistry::class);

        $checked = 0;

        foreach ($registry->all() as $definition) {
            foreach ($definition->responsive as $key) {
                foreach ($this->css()->valuesFor($key) as $value) {
                    foreach ($breakpoints as $breakpoint) {
                        $class = "doc-{$breakpoint}-{$key}-{$value}";

                        $this->assertStringContainsString(
                            ".{$class}.{$class}",
                            $css,
                            "宣言できるのに CSS が無い組み合わせです: {$definition->type} / {$class}",
                        );

                        $checked++;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked, '検査対象が 1 つもありません');
    }

    /**
     * 画面幅の範囲は **theme.json 由来**で、重ならないこと。
     *
     * 重なると「tablet で隠すと mobile でも隠れる」になり、
     * 画面幅ごとの上書きという約束が守れない。
     */
    public function test_breakpoint_ranges_come_from_the_theme_and_do_not_overlap(): void
    {
        $ranges = app(ThemeTokens::class)->breakpointRanges();

        $this->assertSame(
            [
                'mobile' => '(max-width: 767px)',
                'tablet' => '(min-width: 768px) and (max-width: 1199px)',
                'desktop' => '(min-width: 1200px)',
            ],
            $ranges,
        );
    }

    /** 列数のような数値も、宣言された範囲すべてに規則があること */
    public function test_numeric_ranges_are_covered_end_to_end(): void
    {
        $css = $this->css()->build();

        foreach (range(1, 6) as $columns) {
            $this->assertStringContainsString(
                ".doc-mobile-columns-{$columns}.doc-mobile-columns-{$columns}",
                $css,
            );
        }
    }

    // ── 画面幅ごとの値も、base と同じ契約で検証されること ──────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, list<string>>
     */
    private function containerErrors(array $overrides): array
    {
        $document = [
            'schemaVersion' => 1,
            'root' => [
                'id' => NodeId::generate(),
                'type' => 'page',
                'props' => [],
                'children' => [[
                    'id' => NodeId::generate(),
                    'type' => 'section',
                    'props' => [],
                    'children' => [[
                        'id' => NodeId::generate(),
                        'type' => 'container',
                        'props' => [],
                        'responsive' => ['mobile' => $overrides],
                        'children' => [],
                    ]],
                ]],
            ],
        ];

        return app(DocumentValidator::class)->validate($document);
    }

    public function test_a_declared_value_is_accepted_per_breakpoint(): void
    {
        $this->assertSame([], $this->containerErrors(['columns' => 3, 'layout' => 'grid']));
    }

    /**
     * **画面幅ごとの値にも `propsSchema` の契約がかかること。**
     *
     * base の prop は範囲も選択肢も検証されるのに、画面幅ごとだけ素通しでは
     * 「宣言に無い値は保存できない」という約束がその面だけ破れる。
     */
    public function test_a_number_outside_the_declared_range_is_rejected_per_breakpoint(): void
    {
        foreach ([0, 7, 999, -1] as $columns) {
            $this->assertNotSame(
                [],
                $this->containerErrors(['columns' => $columns]),
                "宣言の範囲外が画面幅ごとに保存できました: {$columns}",
            );
        }
    }

    public function test_a_value_of_the_wrong_type_is_rejected_per_breakpoint(): void
    {
        $this->assertNotSame([], $this->containerErrors(['columns' => 'いくつか']));
    }

    public function test_a_choice_that_is_not_declared_is_rejected_per_breakpoint(): void
    {
        $this->assertNotSame([], $this->containerErrors(['layout' => 'not-a-layout']));
    }

    /** 文字寄せは画面幅ごとに変えられると宣言している以上、規則が要る */
    public function test_text_alignment_can_be_overridden_per_breakpoint(): void
    {
        $css = $this->css()->build();

        foreach (['left', 'center', 'right'] as $value) {
            $this->assertStringContainsString(
                ".doc-tablet-textAlign-{$value}.doc-tablet-textAlign-{$value}",
                $css,
            );
        }
    }
}
