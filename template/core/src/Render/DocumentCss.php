<?php

declare(strict_types=1);

namespace AgentCms\Core\Render;

use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;

/**
 * document が出しうるクラスの CSS（Phase 9.6 / P0-C4）。
 *
 * **「画面幅ごとに変えられる」と宣言したものには、必ず規則がある**状態を保つための 1 か所。
 * 宣言（theme.json と component registry）だけから作るので、
 * 案件がトークンを増やしても breakpoint を変えても、対応する規則がそのまま増える。
 *
 * ## どこから何が来るか
 *
 * | 種類 | 値の集合 | CSS |
 * | --- | --- | --- |
 * | style（`spaceTop` 等） | `StyleProperties` | 同上（token → CSS 変数 / enum → 対応表） |
 * | prop（`columns` / `layout`） | `propsSchema` の `values` または `min`〜`max` | `propsSchema.responsiveCss` |
 *
 * prop 側の CSS を registry に書かせているのは、**その意味を知っているのが component だから**。
 * ここに component の型名を書くと、component を増やすたびにこのクラスを触ることになる。
 *
 * ## 詳細度
 *
 * 生成した規則は **クラスを 3 回**書く（`.doc-x.doc-x.doc-x` = 0,3,0）。
 * この CSS は `document.css` より前に読み込まれるので、
 * `.doc-container--grid.doc-cols-2`（0,2,0）のような素の規則に**順番で負けないため**。
 */
final class DocumentCss
{
    public function __construct(
        private readonly ThemeTokens $theme,
        private readonly ComponentRegistry $components,
    ) {}

    /** `:root` のトークンと、document が出しうるクラスの規則 */
    public function build(): string
    {
        $out = $this->theme->toCss();

        // style は base も class で出す。inline は class より強く、
        // base を inline にすると画面幅ごとの上書きが原理的に効かない（§7.2）
        foreach (StyleProperties::classBasedKeys() as $key) {
            $out .= $this->rulesFor($key, null);
        }

        foreach ($this->theme->breakpointRanges() as $breakpoint => $condition) {
            $rules = '';

            foreach ($this->propertyKeys() as $key) {
                $rules .= $this->rulesFor($key, $breakpoint);
            }

            if ($rules !== '') {
                $out .= "@media {$condition} {\n".$rules."}\n";
            }
        }

        return $out;
    }

    /**
     * 宣言されているのに規則を出せないもの。**空でなければならない。**
     *
     * 空でなくなったら、それは「画面幅ごとに変えられます」と言いながら
     * 変えられない capability を増やしたということ。
     *
     * @return list<string> `component.key` の形
     */
    public function unsupported(): array
    {
        $out = [];

        foreach ($this->components->all() as $definition) {
            foreach ($definition->responsive as $key) {
                if ($this->valuesFor($key) === []) {
                    $out[] = "{$definition->type}.{$key}";
                }
            }
        }

        return $out;
    }

    /**
     * その key が画面幅ごとに取り得る値。
     *
     * @return list<string>
     */
    public function valuesFor(string $key): array
    {
        if (StyleProperties::isClassBased($key)) {
            return StyleProperties::allowedValues($key, $this->theme) ?? [];
        }

        $spec = $this->propSpec($key);

        if ($spec === null || ! is_array($spec['responsiveCss'] ?? null)) {
            return [];
        }

        if (is_array($spec['values'] ?? null)) {
            return array_map(strval(...), array_values($spec['values']));
        }

        if (is_numeric($spec['min'] ?? null) && is_numeric($spec['max'] ?? null)) {
            return array_map(strval(...), range((int) $spec['min'], (int) $spec['max']));
        }

        return [];
    }

    /**
     * 画面幅ごとに変えられると宣言された key を、registry 全体から集める。
     *
     * @return list<string>
     */
    private function propertyKeys(): array
    {
        $keys = [];

        foreach ($this->components->all() as $definition) {
            foreach ($definition->responsive as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * その key を宣言している component の `propsSchema`。
     *
     * 同じ key を複数の component が宣言していることはあるが、
     * **意味が違う CSS を持つ key を同じ名前で宣言しない**のが前提
     * （名前が同じなら意味も同じ。違うなら別の名前にする）。
     *
     * @return array<string, mixed>|null
     */
    private function propSpec(string $key): ?array
    {
        foreach ($this->components->all() as $definition) {
            $spec = $definition->propsSchema[$key] ?? null;

            if (is_array($spec) && is_array($spec['responsiveCss'] ?? null)) {
                return $spec;
            }
        }

        return null;
    }

    private function rulesFor(string $key, ?string $breakpoint): string
    {
        $indent = $breakpoint === null ? '' : '    ';
        $out = '';

        foreach ($this->valuesFor($key) as $value) {
            $declaration = $this->declarationFor($key, $value);

            if ($declaration === null) {
                continue;
            }

            $class = StyleProperties::className($key, $value, $breakpoint);

            $out .= "{$indent}.{$class}.{$class}.{$class} { {$declaration}; }\n";
        }

        return $out;
    }

    private function declarationFor(string $key, string $value): ?string
    {
        if (StyleProperties::isClassBased($key)) {
            return StyleProperties::declaration($key, $value, $this->theme);
        }

        $spec = $this->propSpec($key);

        /** @var array<string, mixed> $css */
        $css = is_array($spec['responsiveCss'] ?? null) ? $spec['responsiveCss'] : [];

        // 値ごとに宣言が違うもの（並べ方など）
        if (is_array($css['values'] ?? null)) {
            $declaration = $css['values'][$value] ?? null;

            return is_string($declaration) ? $declaration : null;
        }

        // 値を差し込むだけのもの（列数など）。差し込むのは検証済みの値だけ
        if (is_string($css['template'] ?? null)) {
            return str_replace('{value}', $value, $css['template']);
        }

        return null;
    }
}
