<?php

declare(strict_types=1);

namespace AgentCms\Core\Render;

use AgentCms\Core\Components\ComponentDefinition;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Components\ReusableReferences;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * PageDocument → HTML（Phase 9.1D・owner 決定 G19 / N）。
 *
 * - **サーバー側 Blade で描画する。公開ページに Vue runtime を要求しない**
 * - 描画先は **Registry 経由でのみ**決まる。`type` から任意のパス・クラスを解決しない
 * - 未登録 type / 未知 props は **破棄せず**、プレースホルダを出して
 *   ページ全体を壊さない（Phase 9.1C §41）
 */
final class DocumentRenderer
{
    public function __construct(
        private readonly ComponentRegistry $components,
        private readonly ThemeTokens $theme,
        private readonly BindingResolver $bindings,
        private readonly ViewFactory $views,
    ) {}

    /**
     * 描画で展開する node の上限。
     *
     * ## 保存時の上限では、将来を保証できない
     *
     * `DocumentValidator` はページを保存する時点で展開後の大きさを見る。しかし
     * **参照は生き物**である（§4.5）。500 node で保存したページ P が参照している
     * 実体 X を、後から 20 万 node に育てると、**誰も P を触っていないのに
     * P の描画が 20 万 node を展開する**。
     *
     * だから描く側にも上限を置く。保存時の上限（`DocumentValidator::MAX_NODES` = 500）より
     * 十分に大きくしてあるので、普通の使い方では当たらない。
     *
     * ## publish の側で止める案は採らなかった
     *
     * 「実体を公開するとき、参照している全ページの展開を試して止める」形も考えられる。
     * 採らないのは、**参照を歩くものはページの一覧しか返さない**（§4.5）ため、
     * 各ページの document を読んで展開を試すことになり、**publish が参照の数だけ重くなる**
     * からである。しかも育つのは実体の側なので、**止める場所が増え続ける**。
     * 描く側で 1 か所見る方が、費用も置き場所も少ない。
     */
    private const MAX_EXPANDED_NODES = 5000;

    /**
     * 参照している再利用 component。**描画の前に 1 回だけ引く。**
     *
     * @var array<string, ReusableComponent>|null
     */
    private ?array $reusables = null;

    /** この描画で展開した node の数（`MAX_EXPANDED_NODES` の判定に使う） */
    private int $expanded = 0;

    /** @param array<string, mixed> $document */
    public function render(array $document, RenderContext $context): HtmlString
    {
        $root = $document['root'] ?? null;

        if (! is_array($root)) {
            return new HtmlString('');
        }

        /*
         * **node ごとに引かない。** 木を 1 度歩いて `instanceOf` を集め、
         * 1 回の `whereIn` で読む（taxonomy filter と同じ作法）。
         * 同じ実体が 50 か所から参照されても、問い合わせは増えない。
         */
        $this->reusables = $this->loadReusables($document);
        $this->expanded = 0;

        try {
            return new HtmlString($this->renderChildren($root, $context));
        } finally {
            // 例外で抜けても必ず捨てる（次の render() へ持ち越さない）
            $this->reusables = null;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, ReusableComponent>
     */
    private function loadReusables(array $document): array
    {
        $ids = ReusableReferences::instanceIds($document);

        if ($ids === []) {
            return [];
        }

        return ReusableComponent::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * 参照が指している中身。**出せなければ null**（fail closed）。
     *
     * 出さないのは 3 つの場合である:
     *
     * - 実体が居ない（DB を直接操作された・別の環境から来た document）
     * - **まだ公開していない**（公開側で `published_document` が無い）
     * - しまわれている
     *
     * どれも「間違って出すより出さない」側へ倒す。**ページ全体は壊さない** —
     * その node だけが空になる（未登録 component と同じ扱い）。
     *
     * ## 公開と下書きで読む先が違う
     *
     * 公開 URL は `published_document`、**preview は `draft_document`** を読む。
     * ページが published / draft を持つのと同じ向きで、preview が常に draft を
     * 描くという既存の作法に揃えている。
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function instanceDocument(array $node, RenderContext $context): ?array
    {
        $instanceOf = $node['instanceOf'] ?? null;

        if (! is_string($instanceOf) || $instanceOf === '') {
            return null;
        }

        $component = ($this->reusables ?? [])[$instanceOf] ?? null;

        if (! $component instanceof ReusableComponent || ! $component->isActive()) {
            return null;
        }

        $subtree = $context->preview ? $component->draft_document : $component->publicDocument();

        if ($subtree === null) {
            return null;
        }

        $nodes = DocumentTree::countNodes($subtree);

        if ($this->expanded + $nodes > self::MAX_EXPANDED_NODES) {
            /*
             * **落ちるのではなく、この node だけ描かない。**
             *
             * 描いている最中に例外を投げると**ページごと消える**。参照が育ったせいで
             * 全部が見えなくなるより、その部分が欠ける方が実害が小さい。
             * 画面に出ない不具合になるので、理由は log へ残す。
             */
            Log::warning('再利用 component の展開が上限を超えました', [
                'reusable_component_id' => $component->id,
                'nodes' => $nodes,
                'expanded' => $this->expanded,
                'limit' => self::MAX_EXPANDED_NODES,
            ]);

            return null;
        }

        $this->expanded += $nodes;

        return $subtree;
    }

    /** @param array<string, mixed> $node */
    private function renderNode(array $node, RenderContext $context): string
    {
        if (array_key_exists('instanceOf', $node)) {
            $document = $this->instanceDocument($node, $context);

            if ($document === null) {
                // **出せないものは出さない**（fail closed）。ページ全体は壊さない
                return '';
            }

            /*
             * **入れ物の type は実体の起点と同じ**（`DocumentValidator` がそれを求める）。
             * だから props と children を差し替えるだけで、木の形は変わらない。
             * id は入れ物のものを使う — 描画した先の id が実体側と重複しないため。
             */
            $node['props'] = is_array($document['props'] ?? null) ? $document['props'] : [];
            $node['children'] = is_array($document['children'] ?? null) ? $document['children'] : [];
            unset($node['instanceOf']);
        }

        $type = (string) ($node['type'] ?? '');

        if (! $this->components->has($type)) {
            // 登録が外れた component。編集内容は document に残したまま、描画だけ退避する
            return $this->views->make('components.document.unknown', [
                'type' => $type,
                'nodeId' => (string) ($node['id'] ?? ''),
            ])->render();
        }

        $definition = $this->components->get($type);
        $props = $this->resolveProps($node, $definition->propsSchema, $context);

        return $this->views->make($definition->view, [
            'node' => $node,
            'nodeId' => (string) ($node['id'] ?? ''),
            'props' => $props,
            'style' => $this->styleAttribute($node, $definition, $props),
            'classes' => $this->classAttribute($node),
            'children' => new HtmlString($this->renderChildren($node, $context)),
            // **宣言した component にだけ渡す。**型の名前で決めない
            'items' => $definition->collection
                ? $this->bindings->collection((array) ($node['binding'] ?? []), $props)
                : [],
            'context' => $context,
        ])->render();
    }

    /** @param array<string, mixed> $node */
    private function renderChildren(array $node, RenderContext $context): string
    {
        $out = '';

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= $this->renderNode($child, $context);
            }
        }

        return $out;
    }

    /**
     * props を確定する。binding があれば **binding の値が勝つ**。
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    private function resolveProps(array $node, array $schema, RenderContext $context): array
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $out = [];

        foreach ($schema as $key => $spec) {
            $out[$key] = $props[$key] ?? ($spec['default'] ?? null);
        }

        $binding = $node['binding'] ?? null;

        /*
         * **`prop` を省いた binding は、どの prop にも流さない**（SPEC §4.9 / P0-C5）。
         *
         * かつては `propsSchema` の先頭 prop へ落としていた。宣言順を変えただけで
         * 流し先が黙って変わるうえ、**当たっていれば偶然・外れていれば
         * 「表示は出るが意味が壊れている」**（V-7）である。
         *
         * `DocumentValidator` が保存を断るので、ここへ来るのは**その検証より前に
         * 保存された document** だけである。そこは描かない — 間違って出るより
         * 出ない方が安全側で、直す道は編集画面が持つ。
         */
        if (is_array($binding) && is_string($binding['field'] ?? null) && is_string($binding['prop'] ?? null)) {
            $target = (string) $binding['prop'];
            $value = $this->bindings->value($binding, $context);

            if ($value !== null && array_key_exists($target, $out)) {
                $out[$target] = $value;
            }
        }

        return $out;
    }

    /**
     * style 属性。値は validator を通ったものだけが来る。
     *
     * 出すのは 2 つ:
     *
     * 1. `type: token` かつ `css` を宣言した prop（宣言に無いものは出さない = allowlist）
     * 2. `StyleProperties` が inline と決めたキー（textAlign / color）
     *
     * **class で出すキーはここに来ない。** inline は class より強く、
     * breakpoint override が効かなくなるため（`PHASE9_6_SPEC.md` §4.4）。
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $props
     */
    private function styleAttribute(array $node, ComponentDefinition $definition, array $props): string
    {
        $declarations = [];

        foreach ($definition->propsSchema as $key => $spec) {
            $property = ($spec['type'] ?? null) === 'token' && is_string($spec['css'] ?? null)
                ? (string) $spec['css']
                : null;

            if ($property === null || ! is_string($props[$key] ?? null)) {
                continue;
            }

            $css = $this->theme->toCssValue($props[$key]);

            if ($this->isSafeCssValue($css)) {
                $declarations[] = "{$property}:{$css}";
            }
        }

        $style = $node['style'] ?? [];

        foreach (is_array($style) ? $style : [] as $key => $value) {
            $property = StyleProperties::inlineCssProperty((string) $key);

            if ($property === null || ! is_string($value)) {
                continue;
            }

            $css = $this->theme->toCssValue($value);

            if ($this->isSafeCssValue($css)) {
                $declarations[] = "{$property}:{$css}";
            }
        }

        return implode(';', $declarations);
    }

    /** 宣言を組み立てる前の最後の関門。`;` や `:` を含む値はここで落ちる */
    private function isSafeCssValue(?string $css): bool
    {
        return $css !== null && preg_match('/^[A-Za-z0-9 ,.#()%_\-]+$/', $css) === 1;
    }

    /**
     * class 属性。**document に px も media query も書かせないため、クラス名だけを出す**
     * （Phase 9.1C §10）。base と breakpoint override を同じ形で出し、
     * CSS 側の記述順（base → media query）で後勝ちにする。
     *
     * @param  array<string, mixed>  $node
     */
    private function classAttribute(array $node): string
    {
        $classes = [];
        $style = $node['style'] ?? [];

        foreach (is_array($style) ? $style : [] as $key => $value) {
            if (is_scalar($value) && StyleProperties::isClassBased((string) $key)) {
                $classes[] = StyleProperties::className((string) $key, (string) $value);
            }
        }

        $responsive = $node['responsive'] ?? [];

        foreach (is_array($responsive) ? $responsive : [] as $breakpoint => $overrides) {
            if (! is_array($overrides)) {
                continue;
            }

            foreach ($overrides as $key => $value) {
                if (is_scalar($value)) {
                    $classes[] = StyleProperties::className(
                        (string) $key,
                        (string) $value,
                        (string) $breakpoint,
                    );
                }
            }
        }

        return implode(' ', $classes);
    }
}
