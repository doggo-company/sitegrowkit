<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 一覧を描く component は、**型の名前ではなく宣言**で決まる（NEW-2 / collection）。
 *
 * ここで固定するのは 3 つ:
 *
 * 1. **宣言した component は binding が無いと通らない**
 * 2. **宣言していない component は binding が無くても通る**
 * 3. **同梱の `collection` は宣言している**
 *
 * ▷ 元は `type === 'collection'` という**文字列比較が描画側と検証側の 2 か所**にあった。
 *   それだと**取り込んだ意匠の一覧**（`imported_gallery` 等）が中身を受け取れず、
 *   一覧なのに空で出る。**画面からは「まだ登録がありません」と区別が付かない**。
 *
 * ▷ この試験が無いとき、検証側を `if (false)` に潰しても
 *   **3,794 件が全部緑のままだった**（2026-08-28 実測）。
 */
final class CollectionCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_同梱の一覧は宣言している(): void
    {
        $this->assertTrue(app(ComponentRegistry::class)->get('collection')->collection);
    }

    public function test_一覧でないものは宣言していない(): void
    {
        foreach (['heading', 'text', 'section', 'container'] as $type) {
            $this->assertFalse(
                app(ComponentRegistry::class)->get($type)->collection,
                "[{$type}] が一覧として宣言されている",
            );
        }
    }

    public function test_宣言した_component_は_binding_が無いと通らない(): void
    {
        $id = NodeId::generate();

        $errors = app(DocumentValidator::class)->validate($this->document([
            'id' => $id, 'key' => 'c', 'type' => 'collection', 'props' => [], 'children' => [],
        ]));

        $this->assertArrayHasKey($id, $errors);
        $this->assertSame(['一覧に表示するコンテンツが指定されていません'], $errors[$id]);
    }

    public function test_宣言していない_component_は_binding_が無くても通る(): void
    {
        $errors = app(DocumentValidator::class)->validate($this->document([
            'id' => NodeId::generate(), 'key' => 'h', 'type' => 'heading',
            'props' => ['text' => 'あ', 'level' => 2], 'children' => [],
        ]));

        $this->assertSame([], $errors);
    }

    /**
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private function document(array $child): array
    {
        return [
            'schemaVersion' => 1,
            'root' => [
                'id' => NodeId::generate(), 'key' => 'page', 'type' => 'page', 'props' => [],
                'children' => [[
                    'id' => NodeId::generate(), 'key' => 's', 'type' => 'section', 'props' => [],
                    'children' => [$child],
                ]],
            ],
        ];
    }
}
