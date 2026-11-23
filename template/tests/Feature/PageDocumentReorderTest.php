<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Pages\Page;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C2: Layers の並べ替えが乗る `component.move` の意味。
 *
 * Layers は「上へ / 下へ」を `component.move` へ落とす。**同じ親の中で動かすとき**、
 * サーバーは patch を「取り出してから入れ直す」で実現するので、
 * 入れる位置は**取り出したあとの並び**で決まらなければならない。
 * ここが 1 つずれると、下へ動かしたときだけ 2 つ飛ぶ。
 */
final class PageDocumentReorderTest extends TestCase
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

    private function page(): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '並べ替え', 'slug' => 'reorder'],
            $this->admin(),
        );

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** @param array<string, mixed> $command */
    private function command(Page $page, array $command): array
    {
        $result = app(PatchPageDocument::class)->execute($page->id, $command, $this->admin());

        $this->assertTrue(
            $result->isSuccess(),
            (string) $result->message.' '.json_encode($result->errors, JSON_UNESCAPED_UNICODE),
        );

        return $result->data['document'];
    }

    /** @return array<string, mixed> */
    private function document(Page $page): array
    {
        return app(GetPageDocument::class)->execute($page->id, $this->admin())->data['document'];
    }

    /**
     * section 1 つと、その中に見出しを 3 つ作る。
     *
     * @return array{Page, string, list<string>} page / section id / 見出しの id
     */
    private function pageWithThreeHeadings(): array
    {
        $page = $this->page();

        $document = $this->command($page, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ]);

        $sectionId = (string) $document['root']['children'][0]['id'];

        foreach (['A', 'B', 'C'] as $text) {
            $this->command($page, [
                'operation' => 'component.insert',
                'parent' => $sectionId,
                'component' => ['type' => 'heading', 'props' => ['text' => $text, 'level' => 2]],
            ]);
        }

        return [$page, $sectionId, $this->headingTexts($this->document($page))];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function headingTexts(array $document): array
    {
        $section = $document['root']['children'][0] ?? ['children' => []];

        return array_values(array_map(
            static fn (array $node): string => (string) ($node['props']['text'] ?? ''),
            $section['children'] ?? [],
        ));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function headingIds(array $document): array
    {
        $section = $document['root']['children'][0] ?? ['children' => []];

        return array_values(array_map(
            static fn (array $node): string => (string) $node['id'],
            $section['children'] ?? [],
        ));
    }

    public function test_building_the_document_keeps_insertion_order(): void
    {
        [, , $texts] = $this->pageWithThreeHeadings();

        $this->assertSame(['A', 'B', 'C'], $texts);
    }

    public function test_moving_a_block_up_puts_it_before_its_previous_sibling(): void
    {
        [$page, $sectionId] = $this->pageWithThreeHeadings();
        $ids = $this->headingIds($this->document($page));

        // C を B の前へ（= A の直後へ）
        $document = $this->command($page, [
            'operation' => 'component.move',
            'target' => $ids[2],
            'parent' => $sectionId,
            'after' => $ids[0],
        ]);

        $this->assertSame(['A', 'C', 'B'], $this->headingTexts($document));
    }

    public function test_moving_a_block_down_puts_it_after_its_next_sibling(): void
    {
        [$page, $sectionId] = $this->pageWithThreeHeadings();
        $ids = $this->headingIds($this->document($page));

        // A を B の直後へ
        $document = $this->command($page, [
            'operation' => 'component.move',
            'target' => $ids[0],
            'parent' => $sectionId,
            'after' => $ids[1],
        ]);

        $this->assertSame(['B', 'A', 'C'], $this->headingTexts($document));
    }

    public function test_moving_a_block_to_the_end_leaves_the_others_in_order(): void
    {
        [$page, $sectionId] = $this->pageWithThreeHeadings();
        $ids = $this->headingIds($this->document($page));

        // A を C の直後（末尾）へ
        $document = $this->command($page, [
            'operation' => 'component.move',
            'target' => $ids[0],
            'parent' => $sectionId,
            'after' => $ids[2],
        ]);

        $this->assertSame(['B', 'C', 'A'], $this->headingTexts($document));
    }

    public function test_moving_a_block_without_a_sibling_puts_it_first(): void
    {
        [$page, $sectionId] = $this->pageWithThreeHeadings();
        $ids = $this->headingIds($this->document($page));

        $document = $this->command($page, [
            'operation' => 'component.move',
            'target' => $ids[2],
            'parent' => $sectionId,
            'after' => null,
        ]);

        $this->assertSame(['C', 'A', 'B'], $this->headingTexts($document));
    }

    public function test_moving_a_block_into_another_parent(): void
    {
        [$page, $sectionId] = $this->pageWithThreeHeadings();

        $document = $this->command($page, [
            'operation' => 'component.insert',
            'parent' => $sectionId,
            'component' => ['type' => 'container', 'props' => []],
        ]);

        $containerId = (string) $document['root']['children'][0]['children'][3]['id'];
        $ids = $this->headingIds($this->document($page));

        $document = $this->command($page, [
            'operation' => 'component.move',
            'target' => $ids[0],
            'parent' => $containerId,
            'after' => null,
        ]);

        $section = $document['root']['children'][0];

        // section に残るのは B / C と、末尾の container（container は見出しを持たない）
        $this->assertSame(
            ['heading', 'heading', 'container'],
            array_column($section['children'], 'type'),
        );
        $this->assertSame(['B', 'C', ''], $this->headingTexts($document));
        $this->assertSame(
            'A',
            (string) ($section['children'][2]['children'][0]['props']['text'] ?? ''),
            'container の中へ移っていません',
        );
    }
}
