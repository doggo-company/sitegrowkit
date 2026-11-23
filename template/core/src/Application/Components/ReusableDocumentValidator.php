<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Components;

use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;

/**
 * 再利用 component の中身を、**ページと同じ物差しで**見る（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 検証を 2 つ持たない
 *
 * 実体の部分木にだけ効く検証を別に書くと、**ページでは通らないものが実体では通る**
 * （あるいは逆）ができる。それは「再利用 component が registry の allowlist を
 * 回避する経路にならない」という制約を、**別の形で破ること**である。
 *
 * だから**ページの起点で包んで、`DocumentValidator` にそのまま渡す**。
 * allowlist・props・style・binding・入れ子の実体まで、同じものが同じように効く。
 *
 * ## 起点は「置ける場所」で包んで見る
 *
 * 実体の一番外側を `page` の直下として見ると、**`container` を起点にした実体が作れない**。
 * すると「参照する入れ物の type は起点と同じ」という規則と噛み合わず、
 * **入れ子の実体が一切作れなくなる**（section は section の中に入れないため）。
 *
 * だから**その type が置ける場所まで包んでから**渡す。`page` の直下に置ける type
 * （`section`）はそのまま、そうでない type は `page` の直下に置ける親で 1 段包む。
 *
 * **置き場所の検査は、参照した場所で効く。** 入れ物は起点と同じ type を持つ普通の node
 * なので、実際に置かれたページの文脈で `allowedParents` が当たる。
 * ここで見たいのは「中身が registry の allowlist に収まっているか」である。
 */
final class ReusableDocumentValidator
{
    public function __construct(
        private readonly DocumentValidator $documents,
        private readonly ComponentRegistry $components,
    ) {}

    /**
     * 部分木の誤り。**無ければ空**。
     *
     * @param  array<string, mixed>  $subtree
     * @return array<string, list<string>>
     */
    public function errors(array $subtree): array
    {
        return $this->documents->validate($this->asDocument($subtree));
    }

    /**
     * 部分木を、ページの起点で包む。
     *
     * @param  array<string, mixed>  $subtree
     * @return array<string, mixed>
     */
    public function asDocument(array $subtree): array
    {
        $wrapped = $subtree;

        foreach ($this->wrappers($subtree) as $type) {
            $wrapped = [
                'id' => NodeId::generate(),
                'type' => $type,
                'props' => $this->components->has($type)
                    ? $this->defaults($type)
                    : [],
                'children' => [$wrapped],
            ];
        }

        return [
            'schemaVersion' => DocumentTree::SCHEMA_VERSION,
            'root' => [
                'id' => NodeId::generate(),
                'type' => 'page',
                'props' => [],
                'children' => [$wrapped],
            ],
        ];
    }

    /**
     * 起点を `page` の直下まで持ち上げるために挟む type（内側から外側の順）。
     *
     * `page` に置ける type は挟まない。置けない type は、**`page` に置ける親**で
     * 1 段だけ包む。**2 段以上は挟まない** — 深く包むほど、ここで見ている木が
     * 実際の使われ方から離れる。
     *
     * @param  array<string, mixed>  $subtree
     * @return list<string>
     */
    private function wrappers(array $subtree): array
    {
        $type = $subtree['type'] ?? null;

        if (! is_string($type) || ! $this->components->has($type)) {
            // 未登録なら包まない。`DocumentValidator` がその type を断る
            return [];
        }

        $definition = $this->components->get($type);

        if ($definition->acceptsParent('page')) {
            return [];
        }

        foreach ($definition->allowedParents as $parent) {
            if ($this->components->has($parent) && $this->components->get($parent)->acceptsParent('page')) {
                return [$parent];
            }
        }

        return [];
    }

    /**
     * 包むために作る node の既定 props。
     *
     * **required な prop を埋めないと、包んだ側の誤りが混ざる** —
     * 見たいのは実体の中身であって、包み紙の不備ではない。
     *
     * @return array<string, mixed>
     */
    private function defaults(string $type): array
    {
        $props = [];

        foreach ($this->components->get($type)->propsSchema as $key => $spec) {
            if (($spec['required'] ?? false) === true && array_key_exists('default', $spec)) {
                $props[$key] = $spec['default'];
            }
        }

        return $props;
    }
}
