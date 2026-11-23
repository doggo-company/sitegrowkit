<?php

declare(strict_types=1);

namespace AgentCms\Core\Components;

use AgentCms\Core\Document\BindingTypes;

/**
 * Component Registry の 1 件（Phase 9.1D・owner 決定 G19）。
 *
 * これは **contract かつ allowlist** である。`type` から任意のクラスやパスを
 * 解決させないため、描画先は必ずこの定義を経由して決まる。
 */
final readonly class ComponentDefinition
{
    /**
     * @param  array<string, array<string, mixed>>  $propsSchema
     * @param  list<string>  $styleSchema  style で指定できるキー
     * @param  list<string>  $bindable  binding で差し替えられる prop
     * @param  list<string>  $allowedChildren  '*' は任意の component を許可
     * @param  list<string>  $allowedParents
     * @param  list<string>  $responsive  breakpoint override できる prop
     * @param  array<string, mixed>  $a11y  アクセシビリティ検査のための宣言（PHASE9_1C_SPEC §37）
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $category,
        public int $since,
        public array $propsSchema,
        public array $styleSchema,
        public array $bindable,
        public array $allowedChildren,
        public array $allowedParents,
        public string $view,
        public array $responsive,
        public bool $container,
        /**
         * 一覧を描く component（`binding` から中身を受け取る）。
         *
         * 🔴 **型の名前で決めない。**`type === 'collection'` と書くと、
         * **取り込んだ意匠の一覧**（`imported_gallery` 等）が中身を受け取れない。
         * 同じ判断が描画側と検証側の 2 か所にあったので、宣言 1 つに寄せた（B-53 の族）。
         */
        public bool $collection,
        public array $a11y = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        /** @var array<string, array<string, mixed>> $props */
        $props = is_array($raw['propsSchema'] ?? null) ? $raw['propsSchema'] : [];

        return new self(
            type: (string) ($raw['type'] ?? ''),
            label: (string) ($raw['label'] ?? ($raw['type'] ?? '')),
            category: (string) ($raw['category'] ?? 'basic'),
            since: (int) ($raw['since'] ?? 1),
            propsSchema: $props,
            styleSchema: self::strings($raw['styleSchema'] ?? []),
            bindable: self::strings($raw['bindable'] ?? []),
            allowedChildren: self::strings($raw['allowedChildren'] ?? []),
            allowedParents: self::strings($raw['allowedParents'] ?? []),
            view: (string) ($raw['renderer']['view'] ?? ''),
            responsive: self::strings($raw['capabilities']['responsive'] ?? []),
            container: (bool) ($raw['capabilities']['container'] ?? false),
            collection: (bool) ($raw['capabilities']['collection'] ?? false),
            a11y: is_array($raw['a11y'] ?? null) ? $raw['a11y'] : [],
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    public function acceptsChild(string $childType): bool
    {
        return in_array('*', $this->allowedChildren, true)
            || in_array($childType, $this->allowedChildren, true);
    }

    public function acceptsParent(string $parentType): bool
    {
        return $this->allowedParents === []
            || in_array('*', $this->allowedParents, true)
            || in_array($parentType, $this->allowedParents, true);
    }

    /**
     * bindable な prop ごとに、**流せる Field 型**（`BindingTypes` から導出）。
     *
     * ## 解いた結果だけを渡す
     *
     * 表は Core にしかない（`BindingTypes`）。画面へ表そのものを持たせると
     * **2 つ目の表**になり、片方だけ直したときに「UI では選べるのに保存できない」
     * （あるいはその逆）が生まれる。だから**導出値をわざと送る**。
     *
     * ## 限界
     *
     * `enum` の prop は「Field の選択肢が prop の値集合の**部分集合**か」まで見ないと
     * 決まらないので、ここは**型までの近似**である。最終判断は
     * `DocumentValidator` に残る（Editor は事前・validator は最後の砦）。
     *
     * @return array<string, list<string>>
     */
    private function accepts(): array
    {
        $accepts = [];

        foreach ($this->bindable as $prop) {
            $spec = $this->propsSchema[$prop] ?? null;

            if (! is_array($spec)) {
                continue;
            }

            $accepts[$prop] = array_values(array_filter(
                BindingTypes::knownTypes(),
                static fn (string $type): bool => BindingTypes::acceptsType($type, $spec),
            ));
        }

        return $accepts;
    }

    /**
     * 管理画面 / AI へ渡す仕様（Inspector と tool schema の材料）。
     *
     * @return array<string, mixed>
     */
    public function toSpec(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'category' => $this->category,
            'since' => $this->since,
            'propsSchema' => $this->propsSchema,
            'styleSchema' => $this->styleSchema,
            'bindable' => $this->bindable,
            'accepts' => $this->accepts(),
            'allowedChildren' => $this->allowedChildren,
            'allowedParents' => $this->allowedParents,
            'responsive' => $this->responsive,
            'container' => $this->container,
            'collection' => $this->collection,
            'a11y' => $this->a11y,
        ];
    }
}
