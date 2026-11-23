<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\PageRepository;
use AgentCms\Core\Document\AccessibilityCheck;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C8: 編集中のアクセシビリティ検査。
 *
 * 基準は `web-a11y-check`（デジタル庁ガイドブック / JIS X 8341-3:2016 / WCAG AA）と
 * `PHASE9_1C_SPEC.md` §37。**Registry が宣言したことだけを見る**ので、
 * component の型名はこの検査にも画面にも出てこない。
 *
 * 出すのは **警告**であって、保存も公開も止めない（Owner 決定）。
 * そして **判定できないものを「合格」と言わない** — 分からないものは「要確認」にする。
 */
final class PageDocumentAccessibilityTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function page(array $children): array
    {
        return [
            'schemaVersion' => 1,
            'root' => [
                'id' => NodeId::generate(),
                'type' => 'page',
                'props' => [],
                'children' => [[
                    'id' => 'SECTION',
                    'type' => 'section',
                    'props' => [],
                    'children' => $children,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function node(string $id, string $type, array $extra = []): array
    {
        return array_merge(
            ['id' => $id, 'type' => $type, 'props' => [], 'children' => []],
            $extra,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function inspect(array $document): array
    {
        return app(AccessibilityCheck::class)->inspect($document);
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    private function rules(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'rule')));
    }

    // ── 何も問題が無ければ何も出さない ────────────────────────────────

    public function test_a_well_formed_page_produces_no_findings(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('H1', 'heading', ['props' => ['text' => '大見出し', 'level' => 1]]),
            $this->node('H2', 'heading', ['props' => ['text' => '中見出し', 'level' => 2]]),
            $this->node('IMG', 'image', ['props' => ['mediaId' => 1, 'alt' => '猫の写真']]),
            $this->node('BTN', 'button', ['props' => ['label' => '料金表を見る', 'href' => '/pricing']]),
        ]));

        $this->assertSame([], $findings, print_r($findings, true));
    }

    // ── 見出しの順序 ──────────────────────────────────────────────────

    public function test_skipping_a_heading_level_is_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('H1', 'heading', ['props' => ['text' => '大見出し', 'level' => 1]]),
            $this->node('H3', 'heading', ['props' => ['text' => '飛ばした見出し', 'level' => 3]]),
        ]));

        $this->assertContains('heading-order', $this->rules($findings));
        $this->assertSame('H3', $findings[0]['nodeId']);
        $this->assertStringContainsString('見出し', (string) $findings[0]['message']);
    }

    /**
     * 自分で書く見出しが空なら、公開面に描かない（UX 一周 3 周目 N3）ので、気づきも出さない。
     * 置いた直後の見出しは空になった。そこで注意を出すと、最初の 1 手で「要修正」が出る。
     * 描かないことは `UxRelease20261105Round3Test` が公開面で見る。
     */
    public function test_an_empty_heading_is_not_reported_because_it_is_not_rendered(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('H1', 'heading', ['props' => ['text' => '', 'level' => 1]]),
            $this->node('H2', 'heading', ['props' => ['text' => '   ', 'level' => 2]]),
        ]));

        $this->assertNotContains('heading-empty', $this->rules($findings));
    }

    // ── 画像の代替テキスト ────────────────────────────────────────────

    public function test_an_image_without_alternative_text_is_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('IMG', 'image', ['props' => ['mediaId' => 1]]),
        ]));

        $this->assertContains('image-alt', $this->rules($findings));
    }

    /** 装飾だと明示してあれば、空の代替テキストで良い（§37） */
    public function test_a_decorative_image_without_alternative_text_is_accepted(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('IMG', 'image', ['props' => ['mediaId' => 1, 'decorative' => true]]),
        ]));

        $this->assertNotContains('image-alt', $this->rules($findings));
    }

    // ── 操作できるものの名前と行き先 ──────────────────────────────────

    public function test_a_control_without_a_name_is_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('BTN', 'button', ['props' => ['label' => '', 'href' => '/x']]),
        ]));

        $this->assertContains('control-name', $this->rules($findings));
    }

    /** 「こちら」だけではリンク先が分からない（web-a11y ②） */
    public function test_a_vague_link_label_is_reported(): void
    {
        foreach (['こちら', 'ここをクリック', 'click here', '詳しくはこちら'] as $label) {
            $findings = $this->inspect($this->page([
                $this->node('BTN', 'button', ['props' => ['label' => $label, 'href' => '/x']]),
            ]));

            $this->assertContains(
                'link-purpose',
                $this->rules($findings),
                "リンク文言 [{$label}] が見逃されました",
            );
        }
    }

    /** 行き先の無い操作先へキーボードが止まる（web-a11y ②） */
    public function test_a_control_that_goes_nowhere_is_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('BTN', 'button', ['props' => ['label' => '申し込む', 'href' => '#']]),
        ]));

        $this->assertContains('control-destination', $this->rules($findings));
    }

    // ── 文字色と背景の組み合わせ ──────────────────────────────────────

    public function test_low_contrast_text_is_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('T', 'text', [
                'props' => ['text' => '読みにくい文章'],
                // 薄い文字色を、明るい背景の上に置く
                'style' => ['color' => '{colors.bgAlt}'],
            ]),
        ]));

        $this->assertContains('contrast', $this->rules($findings));
    }

    public function test_sufficient_contrast_is_not_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('T', 'text', [
                'props' => ['text' => '読める文章'],
                'style' => ['color' => '{colors.text}'],
            ]),
        ]));

        $this->assertNotContains('contrast', $this->rules($findings));
    }

    // ── 判定できないものを「合格」と言わない ──────────────────────────

    /** 値がコンテンツ側から来る場合、ここでは中身が分からない */
    public function test_a_bound_value_is_reported_as_needing_a_look(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('IMG', 'image', [
                'props' => ['mediaId' => 1],
                'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'alt_text', 'prop' => 'alt'],
            ]),
        ]));

        $this->assertSame(['image-alt'], $this->rules($findings));
        $this->assertSame(['unknown'], array_values(array_unique(array_column($findings, 'level'))));
    }

    /** 画像そのものがコンテンツ由来でも、**その場で書く代替テキスト**は空なら空 */
    public function test_a_bound_image_still_needs_its_own_alternative_text(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('IMG', 'image', [
                'props' => ['mediaId' => 1],
                'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'main_image', 'prop' => 'mediaId'],
            ]),
        ]));

        $this->assertSame(['image-alt'], $this->rules($findings));
        $this->assertSame('warning', $findings[0]['level']);
    }

    /**
     * **`prop` の無い binding は「供給されている」と見なさない。**
     *
     * かつては `prop` を省くと `propsSchema` の先頭 prop を指したことにしていた。
     * 当たっていれば偶然、外れていれば「表示は出るが意味が壊れている」側で、
     * **間違って出るより出ない方が安全**である（SPEC §4.9 / 計画 D）。
     *
     * ここは `button.label`（先頭 prop）を空にして見る。先頭 prop へ落ちる読みが
     * 残っているかぎり「中身が分からない（unknown）」になり、**空という事実が隠れる**。
     */
    public function test_prop_の無い_binding_は供給と見なさない(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('BTN', 'button', [
                'props' => ['label' => '', 'href' => 'https://example.com'],
                'binding' => ['source' => 'page', 'field' => 'title'],
            ]),
        ]));

        $this->assertSame(['control-name'], $this->rules($findings));
        $this->assertSame(
            'warning',
            $findings[0]['level'],
            'prop の無い binding が「中身が分からない」に化けて、空のラベルが隠れました',
        );
    }

    /** 記事から代替テキストを流したものは、ここでは中身が分からない */
    public function test_altへのbindingは要確認になる(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('IMG', 'image', [
                'props' => ['mediaId' => 1, 'alt' => ''],
                'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'alt'],
            ]),
        ]));

        $this->assertSame(['image-alt'], $this->rules($findings));
        $this->assertSame('unknown', $findings[0]['level']);
    }

    /** テーマのトークンでない色は、実際の値が分からない */
    public function test_a_colour_that_is_not_a_token_is_reported_as_needing_a_look(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('T', 'text', [
                'props' => ['text' => '文章'],
                'style' => ['color' => 'rebeccapurple'],
            ]),
        ]));

        $contrast = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['rule'] === 'contrast',
        ));

        $this->assertNotSame([], $contrast);
        $this->assertSame('unknown', $contrast[0]['level']);
    }

    // ── 一部だけ取り出したときも、判断は文書全体を見て行う ────────────

    /**
     * 一部だけ取り出した document を返す。
     *
     * @param  array<string, mixed>  $document
     * @return array{array<string, mixed>, list<array<string, mixed>>}
     */
    private function fetch(array $document, ?string $from = null, ?int $depth = null): array
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '検査', 'slug' => 'a11y-'.bin2hex(random_bytes(4))],
            OperationContext::admin($this->operatorId(), 'tester'),
        );

        $page = Page::query()->findOrFail($result->data['page']['id']);

        app(PageRepository::class)->createVersion($page, $document, 'admin', null);

        $fetched = app(GetPageDocument::class)->execute(
            $page->id,
            OperationContext::admin($this->operatorId(), 'tester'),
            $from,
            $depth,
        );

        return [$fetched->data['document'], $fetched->meta['accessibility']];
    }

    /**
     * **見出しの順序は文書全体の話**（`PHASE9_1C_SPEC.md` §37）。
     *
     * 途中から取り出しただけで「一番大きい見出しが無い」と言ってはいけない。
     * 前に 1 段目があるなら、それは事実ではない。
     */
    public function test_fetching_a_subtree_does_not_invent_a_heading_problem(): void
    {
        $document = [
            'schemaVersion' => 1,
            'root' => [
                'id' => 'ROOT', 'type' => 'page', 'props' => [], 'children' => [
                    $this->node('S1', 'section', ['children' => [
                        $this->node('H1', 'heading', ['props' => ['text' => '大見出し', 'level' => 1]]),
                    ]]),
                    $this->node('S2', 'section', ['children' => [
                        $this->node('H2', 'heading', ['props' => ['text' => '中見出し', 'level' => 2]]),
                    ]]),
                ],
            ],
        ];

        [, $whole] = $this->fetch($document);
        $this->assertSame([], $whole, '文書全体では問題が無いはず');

        [, $subtree] = $this->fetch($document, 'S2');

        $this->assertSame([], $subtree, '一部を取り出しただけで見出しの指摘が生まれています');
    }

    /** 祖先の背景は、その祖先が返らなくても判断に使われること */
    public function test_a_subtree_keeps_the_background_of_its_ancestors(): void
    {
        $document = [
            'schemaVersion' => 1,
            'root' => [
                'id' => 'ROOT', 'type' => 'page', 'props' => [], 'children' => [
                    $this->node('S', 'section', [
                        // 濃い背景の上に、白い文字を置く（これは読める）
                        'props' => ['background' => '{colors.primary}'],
                        'children' => [
                            $this->node('T', 'text', [
                                'props' => ['text' => '白い文字'],
                                'style' => ['color' => '{colors.primaryContrast}'],
                            ]),
                        ],
                    ]),
                ],
            ],
        ];

        [, $whole] = $this->fetch($document);
        $this->assertSame([], $whole);

        // 文字だけを取り出しても、背景は濃いままとして判断される
        [, $subtree] = $this->fetch($document, 'T');

        $this->assertSame([], $subtree, '祖先の背景が失われ、読めない扱いになっています');
    }

    /** 深さで切った先の指摘を、返っていない節点について出さない */
    public function test_pruning_by_depth_does_not_report_nodes_that_were_not_returned(): void
    {
        $document = [
            'schemaVersion' => 1,
            'root' => [
                'id' => 'ROOT', 'type' => 'page', 'props' => [], 'children' => [
                    $this->node('S', 'section', ['children' => [
                        // 代替テキストが無い（本来は指摘される）
                        $this->node('IMG', 'image', ['props' => ['mediaId' => 1]]),
                    ]]),
                ],
            ],
        ];

        [, $whole] = $this->fetch($document);
        $this->assertSame(['image-alt'], $this->rules($whole));

        // 深さ 1 では画像が返らない。返っていないものの指摘は出さない
        [$pruned, $findings] = $this->fetch($document, null, 1);

        $this->assertSame([], $pruned['root']['children'][0]['children'] ?? []);
        $this->assertSame([], $findings, '返っていない節点の指摘が出ています');
    }

    // ── 壊れた入力で落ちない ──────────────────────────────────────────

    public function test_an_unknown_component_is_skipped_without_failing(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('X', 'not-a-component', ['props' => ['whatever' => 1]]),
        ]));

        $this->assertSame([], $findings);
    }

    public function test_dangerous_looking_text_does_not_break_the_check(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('H', 'heading', [
                'props' => ['text' => '<script>alert(1)</script>', 'level' => 1],
            ]),
        ]));

        // 見出しとしては中身があるので、順序の問題も空の問題も出ない
        $this->assertSame([], $findings);
    }

    public function test_several_problems_are_all_reported(): void
    {
        $findings = $this->inspect($this->page([
            $this->node('H3', 'heading', ['props' => ['text' => '見出し', 'level' => 3]]),
            $this->node('IMG', 'image', ['props' => ['mediaId' => 1]]),
            $this->node('BTN', 'button', ['props' => ['label' => 'こちら', 'href' => '#']]),
        ]));

        $rules = $this->rules($findings);

        $this->assertContains('heading-order', $rules);
        $this->assertContains('image-alt', $rules);
        $this->assertContains('link-purpose', $rules);
        $this->assertContains('control-destination', $rules);
    }
}
