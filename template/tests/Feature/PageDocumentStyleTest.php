<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Render\DocumentCss;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C1: typed token による style・responsive の拡充。
 *
 * 守る線（`PHASE9_6_SPEC.md` §3 の I-3 / I-4）:
 * - style に入れられるのは **styleSchema の allowlist**、かつ **型が合う値**だけ
 * - 任意の CSS 文字列を保存できる欄を作らない
 * - breakpoint override は **クラス名だけ**。document に px も media query も書かせない
 */
final class PageDocumentStyleTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    private function page(string $slug = 'style'): Page
    {
        $result = app(CreatePage::class)->execute(['title' => 'スタイル', 'slug' => $slug], $this->admin());

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** @param array<string, mixed> ...$children */
    private function document(array ...$children): array
    {
        return [
            'schemaVersion' => 1,
            'root' => ['id' => NodeId::generate(), 'type' => 'page', 'props' => [], 'children' => $children],
        ];
    }

    /** @param array<string, mixed> $extra */
    private function node(string $type, array $extra = []): array
    {
        return array_merge(['id' => NodeId::generate(), 'type' => $type, 'props' => [], 'children' => []], $extra);
    }

    /**
     * section で包む。leaf component の `allowedParents` は section / container なので、
     * 検証を通すには必ず親が要る。
     *
     * @param  array<string, mixed>  $child
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function section(array $child, array $extra = []): array
    {
        return $this->node('section', array_merge($extra, ['children' => [$child]]));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, list<string>>
     */
    private function validate(array $document): array
    {
        return app(DocumentValidator::class)->validate($document);
    }

    /** @param array<string, mixed> $document */
    private function render(array $document): string
    {
        return (string) app(DocumentRenderer::class)
            ->render($document, new RenderContext($this->page(), []));
    }

    // ── token 型の prop ────────────────────────────────────────────────

    public function test_token_prop_rejects_a_value_that_is_not_a_design_token(): void
    {
        $errors = $this->validate($this->document(
            $this->node('section', ['props' => ['background' => 'red']]),
        ));

        $this->assertNotSame([], $errors, 'token 型の prop に生の CSS 値が通ってしまいました');
    }

    public function test_token_prop_is_rendered_as_a_css_variable(): void
    {
        $html = $this->render($this->document(
            $this->node('section', ['props' => ['background' => '{colors.primary}']]),
        ));

        $this->assertStringContainsString('background:var(--color-primary)', $html);
        $this->assertStringNotContainsString('{colors.primary}', $html);
    }

    public function test_section_padding_token_reaches_the_output(): void
    {
        $html = $this->render($this->document(
            $this->node('section', ['props' => ['padding' => '{spacing.section}']]),
        ));

        $this->assertStringContainsString('padding-block:var(--space-section)', $html);
    }

    public function test_container_gap_token_reaches_the_output(): void
    {
        $html = $this->render($this->document(
            $this->section($this->node('container', ['props' => ['gap' => '{spacing.gap}']])),
        ));

        $this->assertStringContainsString('gap:var(--space-gap)', $html);
    }

    // ── spacing の style key ───────────────────────────────────────────

    public function test_spacing_style_accepts_a_spacing_token_name(): void
    {
        $errors = $this->validate($this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'style' => ['spaceTop' => 'gap'],
            ])),
        ));

        $this->assertSame([], $errors);
    }

    public function test_spacing_style_is_emitted_as_a_class_not_inline_css(): void
    {
        $html = $this->render($this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'style' => ['spaceTop' => 'gap'],
            ])),
        ));

        $this->assertStringContainsString('doc-spaceTop-gap', $html);
        $this->assertStringNotContainsString('margin-block-start:', $html);
    }

    // ── breakpoint ごとの表示切り替え ──────────────────────────────────

    public function test_visibility_can_be_overridden_per_breakpoint(): void
    {
        $document = $this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'responsive' => ['mobile' => ['visibility' => 'hidden']],
            ])),
        );

        $this->assertSame([], $this->validate($document));
        $this->assertStringContainsString('doc-mobile-visibility-hidden', $this->render($document));
    }

    // ── theme から生成される CSS ───────────────────────────────────────

    public function test_theme_css_defines_a_rule_for_every_class_the_renderer_can_emit(): void
    {
        $css = app(DocumentCss::class)->build();

        // spacing token ごとの base クラス
        $this->assertStringContainsString('.doc-spaceTop-gap', $css);
        $this->assertStringContainsString('.doc-spaceBottom-section', $css);

        // breakpoint ごとの override
        $this->assertStringContainsString('.doc-mobile-visibility-hidden', $css);
        $this->assertStringContainsString('.doc-desktop-spaceTop-gap', $css);

        // px も breakpoint も theme.json 由来であること
        $this->assertStringContainsString('max-width: 767px', $css);
        $this->assertStringContainsString('min-width: 1200px', $css);
    }

    // ── 値の集合が閉じていることの確認（I-3） ──────────────────────────

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, list<string>>
     */
    private function headingStyleErrors(array $style): array
    {
        return $this->validate($this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'style' => $style,
            ])),
        ));
    }

    public function test_spacing_style_rejects_a_raw_css_length(): void
    {
        $this->assertNotSame([], $this->headingStyleErrors(['spaceTop' => '10px']));
    }

    public function test_spacing_style_rejects_a_token_from_another_group(): void
    {
        // 色のトークン名を余白へ入れられてはいけない
        $this->assertNotSame([], $this->headingStyleErrors(['spaceTop' => 'primary']));
    }

    public function test_spacing_style_rejects_a_token_reference_instead_of_a_token_name(): void
    {
        // class 名になるのは**トークン名**。`{spacing.gap}` の形は受けない
        $this->assertNotSame([], $this->headingStyleErrors(['spaceTop' => '{spacing.gap}']));
    }

    /**
     * `visible` を値として持たせない。
     *
     * ユーティリティは詳細度を上げてあるので、`display: revert` を当てると
     * `.doc-container { display: flex }` に勝ってしまい、**レイアウトが崩れる**。
     * 「表示する」は **キーを外すこと**で表す。
     */
    public function test_visibility_does_not_offer_a_value_that_would_break_layout(): void
    {
        $this->assertNotSame([], $this->headingStyleErrors(['visibility' => 'visible']));

        $this->assertStringNotContainsString('revert', app(DocumentCss::class)->build());
    }

    public function test_visibility_rejects_an_unknown_value(): void
    {
        $this->assertNotSame([], $this->headingStyleErrors(['visibility' => 'gone']));

        $errors = $this->validate($this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'responsive' => ['mobile' => ['visibility' => 'gone']],
            ])),
        ));

        $this->assertNotSame([], $errors, '画面幅ごとの値が検証されていません');
    }

    public function test_style_values_cannot_smuggle_css_into_the_output(): void
    {
        $payloads = [
            'gap; position: fixed',
            'url(https://evil.example/x.png)',
            'expression(alert(1))',
            "gap}\n.doc-section{display:none",
        ];

        foreach ($payloads as $payload) {
            $this->assertNotSame(
                [],
                $this->headingStyleErrors(['spaceTop' => $payload]),
                "任意の CSS が通ってしまいました: {$payload}",
            );
        }
    }

    // ── 既存の挙動を変えていないこと ───────────────────────────────────

    /**
     * `color` は値の集合が閉じていないので inline のまま。
     * `textAlign` は画面幅ごとに変えられると宣言しているので **class へ移した**
     * （P0-C4。inline のままだと class に勝ってしまい上書きが効かない）。
     */
    public function test_open_valued_styles_stay_inline_and_closed_ones_become_classes(): void
    {
        $document = $this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'style' => ['textAlign' => 'center', 'color' => '{colors.primary}'],
            ])),
        );

        $this->assertSame([], $this->validate($document));

        $html = $this->render($document);

        $this->assertStringContainsString('color:var(--color-primary)', $html);
        $this->assertStringContainsString('doc-textAlign-center', $html);
        $this->assertStringNotContainsString('text-align:center', $html);
    }

    public function test_an_undeclared_text_alignment_is_rejected(): void
    {
        $this->assertNotSame([], $this->headingStyleErrors(['textAlign' => 'justify']));
    }

    // ── 生成されるクラスに漏れが無いこと ───────────────────────────────

    public function test_every_class_the_renderer_can_emit_has_a_rule(): void
    {
        $theme = app(ThemeTokens::class);
        $css = app(DocumentCss::class)->build();
        $breakpoints = array_merge([null], array_keys($theme->breakpointRanges()));

        foreach (StyleProperties::classBasedKeys() as $key) {
            foreach (StyleProperties::allowedValues($key, $theme) ?? [] as $value) {
                foreach ($breakpoints as $breakpoint) {
                    $class = StyleProperties::className($key, $value, $breakpoint);

                    $this->assertStringContainsString(
                        ".{$class}.{$class}",
                        $css,
                        "宣言できるのに CSS が無い組み合わせです: {$class}",
                    );
                }
            }
        }
    }

    /**
     * ユーティリティは component の素のクラスより**強く**なければならない。
     *
     * `theme` の CSS は `document.css` より前に読み込まれる（preview / public とも）。
     * 詳細度が同じだと `.doc-container { display: flex }` が後勝ちして、
     * 「非表示にしたのに消えない」が起きる。
     */
    public function test_style_utilities_outrank_component_base_rules(): void
    {
        $css = app(DocumentCss::class)->build();

        preg_match_all('/^\s*(\.doc-[^{]+)\{/m', $css, $matches);

        $this->assertNotSame([], $matches[1], 'ユーティリティが 1 つも生成されていません');

        foreach ($matches[1] as $selector) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($selector, '.'),
                "詳細度が足りません（component の素のクラスに負けます）: {$selector}",
            );
        }
    }

    // ── prop の無い binding は描かない（SPEC §4.9 / 計画 D）──────────

    /**
     * **`prop` を省いた binding は、どの prop にも流れない。**
     *
     * かつては `propsSchema` の先頭 prop へ落としていた。当たっていれば偶然で、
     * 外れていれば「表示は出るが意味が壊れている」側になる（V-7）。
     * **保存は拒否、描画は無視**が安全側である。
     *
     * 保存の側は `DocumentValidator` が断るので、ここへ来るのは
     * **その検証より前に保存された document** だけである。
     */
    public function test_propの無いbindingは描かれない(): void
    {
        $html = $this->render($this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => 'その場で書いた見出し', 'level' => 2],
                'binding' => ['source' => 'page', 'field' => 'title'],
            ])),
        ));

        $this->assertStringContainsString('その場で書いた見出し', $html);
    }

    /** `prop` を書いた binding は、これまでどおり流れる */
    public function test_propを書いたbindingは描かれる(): void
    {
        $html = $this->render($this->document(
            $this->section($this->node('heading', [
                'props' => ['text' => 'その場で書いた見出し', 'level' => 2],
                'binding' => ['source' => 'page', 'field' => 'title', 'prop' => 'text'],
            ])),
        ));

        $this->assertStringNotContainsString('その場で書いた見出し', $html);
    }
}
