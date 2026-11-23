<?php

declare(strict_types=1);

namespace AgentCms\Core\Document;

use AgentCms\Core\Components\ComponentDefinition;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Components\ReusableGraph;
use AgentCms\Core\Components\ReusableReferences;
use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TaxonomyFilterIndex;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;

/**
 * PageDocument の検証（Phase 9.1D）。
 *
 * **Admin / API / MCP / import がすべてこの 1 つを通る。**
 * import だけ検証を迂回する経路は作らない（Phase 9.1C §25）。
 *
 * 返すのは `field => messages` の形で、Phase 9.1B の
 * `Result::validationError()` にそのまま渡せる。
 */
final class DocumentValidator
{
    /** 木の深さ上限（無制限にしない） */
    public const MAX_DEPTH = 12;

    /** 1 document のノード数上限 */
    public const MAX_NODES = 500;

    /**
     * taxonomy filter を解くための **この validate() 呼び出し限りの**索引（#22）。
     *
     * document ごとに冒頭で 1 度だけ作り、終わったら必ず捨てる。
     * インスタンスに残すと Octane のような長寿命プロセスで
     * 「削除済みの term がまだ有効に見える」stale cache になる。
     *
     * @var array{taxonomies: array<string, string>, terms: array<string, string>}|null
     */
    private ?array $taxonomyLookup = null;

    public function __construct(
        private readonly ComponentRegistry $components,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeRegistry $types,
        private readonly ThemeTokens $theme,
        private readonly TaxonomyFilterIndex $taxonomies,
        private readonly ReusableGraph $reusable = new ReusableGraph,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, list<string>> 空なら妥当
     */
    public function validate(array $document): array
    {
        $errors = [];

        $version = $document['schemaVersion'] ?? null;

        if ($version !== DocumentTree::SCHEMA_VERSION) {
            $errors['schemaVersion'][] = 'このバージョンのドキュメントは扱えません';

            return $errors;
        }

        $root = $document['root'] ?? null;

        if (! is_array($root) || ($root['type'] ?? null) !== 'page') {
            $errors['root'][] = 'ドキュメントの起点が page ではありません';

            return $errors;
        }

        $seenIds = [];
        $seenKeys = [];
        $count = 0;

        // taxonomy filter は node ごとに引かず、**document 単位でまとめて 1 度だけ**解決する。
        // 同じ term が 500 node に現れてもクエリは増えない（#22-8）
        $this->taxonomyLookup = $this->resolveTaxonomyFilters($root);

        try {
            $this->walk($root, 'page', 0, $errors, $seenIds, $seenKeys, $count);
        } finally {
            // 例外で抜けても必ず捨てる（次の validate() へ持ち越さない）
            $this->taxonomyLookup = null;
        }

        if ($count > self::MAX_NODES) {
            $errors['root'][] = 'ブロック数が上限（'.self::MAX_NODES.'）を超えています';
        }

        $this->validateInstances($root, $errors);

        return $errors;
    }

    /**
     * 再利用 component への参照（Phase 9.6 / P0-C7・SPEC §4.5）。
     *
     * **document 単位でまとめて 1 度だけ**見る。node ごとに引くと、参照の数だけ
     * 問い合わせが増える（taxonomy filter と同じ作法）。
     *
     * 見るのは 4 つで、**それぞれ別の壊れ方**である:
     *
     * 1. 指している実体が**居る**か
     * 2. **循環**していないか（推移閉包まで）
     * 3. **展開後**の大きさが上限に収まるか（循環していなくても超え得る）
     * 4. 実体の中が **registered component だけ**か（allowlist を回避する経路にしない）
     *
     * @param  array<string, mixed>  $root
     * @param  array<string, list<string>>  $errors
     */
    private function validateInstances(array $root, array &$errors): void
    {
        $ids = ReusableReferences::instanceIds($root);

        if ($ids === []) {
            return;
        }

        $loaded = $this->reusable->load($ids);

        foreach ($ids as $id) {
            if (! array_key_exists($id, $loaded)) {
                $errors['root'][] = "再利用 component [{$id}] は見つかりません";
            }
        }

        /*
         * **循環を先に潰す。** 残っていると展開が終わらない
         * （`expandedSize()` は保険で打ち切るが、上限超過として報せることになる）。
         */
        $cycles = $this->reusable->cycleMembers($ids, $loaded);

        if ($cycles !== []) {
            $errors['root'][] = '再利用 component が自分自身を含んでいます（'.implode(', ', $cycles).'）';

            return;
        }

        /*
         * **参照する node の type は、実体の起点と同じでなければならない。**
         *
         * 参照は「実体で中身を差し替える入れ物」である。入れ物の type が違うと、
         * **置き場所の規則（`allowedParents` / `allowedChildren`）が成り立たない** —
         * 起点が `section` の実体を `container` の入れ物で参照すると、
         * 展開したときに section が section の中へ入る形になる。
         *
         * type を揃えておけば、入れ物はただの node として既存の規則で検証され、
         * 切り離しても形が変わらない。
         */
        DocumentTree::walk(['root' => $root], static function (array $node) use ($loaded, &$errors): void {
            $instanceOf = $node['instanceOf'] ?? null;
            $component = is_string($instanceOf) ? ($loaded[$instanceOf] ?? null) : null;

            if (! $component instanceof ReusableComponent) {
                return;
            }

            $expected = $component->draft_document['type'] ?? null;
            $actual = $node['type'] ?? null;

            if (is_string($expected) && $actual !== $expected) {
                $id = is_string($node['id'] ?? null) ? $node['id'] : 'root';
                $errors[$id][] = "再利用 component を参照するブロックは [{$expected}] でなければなりません";
            }
        });

        // **実体の中も allowlist の中だけ**（§4.5 の制約）
        foreach ($loaded as $id => $component) {
            foreach ($this->unregisteredTypes($component->draft_document) as $type) {
                $errors['root'][] = "再利用 component [{$id}] に未登録のブロック [{$type}] があります";
            }
        }

        $size = $this->reusable->expandedSize($root, $loaded);

        if ($size['nodes'] > self::MAX_NODES) {
            $errors['root'][] = '再利用 component を展開すると、ブロック数が上限（'.self::MAX_NODES.'）を超えます';
        }

        if ($size['depth'] > self::MAX_DEPTH) {
            $errors['root'][] = '再利用 component を展開すると、入れ子が上限（'.self::MAX_DEPTH.'）より深くなります';
        }
    }

    /**
     * その木に含まれる**未登録の type**（`page` は registry を持たない起点なので除く）。
     *
     * @return list<string>
     */
    private function unregisteredTypes(mixed $document): array
    {
        if (! is_array($document)) {
            return [];
        }

        $unregistered = [];

        $wrapped = isset($document['root']) ? $document : ['root' => $document];

        DocumentTree::walk($wrapped, function (array $node) use (&$unregistered): void {
            $type = $node['type'] ?? null;

            if (! is_string($type) || $type === 'page' || $this->components->has($type)) {
                return;
            }

            $unregistered[$type] = true;
        });

        return array_keys($unregistered);
    }

    /**
     * **公開時だけ**の検証（Phase 9.3B / #18・SPEC §V.2-(7) PB-1〜PB-8）。
     *
     * 通常の `validate()` へ「空はだめ」を足さない。`CreatePage` は
     * `DocumentTree::empty()` を作るため、足すと**ページを作れなくなる**（PB-3）。
     *
     * ## renderable node の定義（PB-7）
     *
     * **root（`page`）の子孫に、Component Registry で解決できる node が 1 つ以上あること。**
     * 登録済みなら `section` / `container` も 1 件として数える。**root 自身は数えない。**
     *
     * 構造エラーがあるときは**それを先に返す**。未登録・壊れた node を
     * 「何かある＝renderable」と数えて構造検証を迂回させない（PB-4）。
     *
     * 使うのは `PublishPage` だけ（PB-2）。Admin / API / MCP は同じ Application Service を
     * 通るので、経路によらず同じ結論になる（PB-5）。UI のボタン無効化は補助であって
     * 検証の実体ではない（PB-6）。
     *
     * @param  array<string, mixed>  $document
     * @return array<string, list<string>> 空なら公開してよい
     */
    public function validateForPublish(array $document): array
    {
        $errors = $this->validate($document);

        if ($errors !== []) {
            return $errors;
        }

        $root = $document['root'] ?? null;

        if (! is_array($root) || ! $this->hasRenderableDescendant($root)) {
            $errors['root'][] = '公開するには少なくとも 1 つブロックが必要です。このページはまだ空です。';
        }

        return $errors;
    }

    /**
     * 子孫に **Component Registry で解決できる node** があるか。
     *
     * 登録されていない type は数えない（`validate()` が既にエラーにしているが、
     * ここでも「解決できるもの」だけを renderable として扱う）。
     *
     * @param  array<string, mixed>  $node
     */
    private function hasRenderableDescendant(array $node): bool
    {
        $children = $node['children'] ?? [];

        if (! is_array($children)) {
            return false;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $type = $child['type'] ?? null;

            if (is_string($type) && $this->components->has($type)) {
                return true;
            }

            if ($this->hasRenderableDescendant($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $errors
     * @param  array<string, true>  $seenIds
     * @param  array<string, true>  $seenKeys
     */
    private function walk(
        array $node,
        string $parentType,
        int $depth,
        array &$errors,
        array &$seenIds,
        array &$seenKeys,
        int &$count,
    ): void {
        $count++;
        $id = $node['id'] ?? null;
        $type = $node['type'] ?? null;
        $label = is_string($id) ? $id : '(id なし)';

        if (! is_string($id) || ! NodeId::isValid($id)) {
            $errors['id'][] = 'ブロックの ID が不正です';

            return;
        }

        if (isset($seenIds[$id])) {
            $errors[$id][] = 'ブロック ID が重複しています';

            return;
        }

        $seenIds[$id] = true;

        // key は identity ではないが、重複と形式は検査する（AI と人が指すため）
        $key = $node['key'] ?? null;

        if ($key !== null && $key !== '') {
            if (! is_string($key) || ! NodeId::isValidKey($key)) {
                $errors[$id][] = 'キーの形式が不正です（英小文字・数字・ハイフン）';
            } elseif (isset($seenKeys[$key])) {
                $errors[$id][] = "キー [{$key}] が重複しています";
            } else {
                $seenKeys[$key] = true;
            }
        }

        if ($depth > self::MAX_DEPTH) {
            $errors[$id][] = '入れ子が深すぎます';

            return;
        }

        if (! is_string($type)) {
            $errors[$id][] = 'ブロックの種類が指定されていません';

            return;
        }

        // page は registry を持たない特別なルート。子だけ検証する
        if ($type === 'page') {
            if ($depth !== 0) {
                $errors[$id][] = 'page はドキュメントの起点にしか置けません';

                return;
            }

            $this->walkChildren($node, $type, $depth, $errors, $seenIds, $seenKeys, $count);

            return;
        }

        if (! $this->components->has($type)) {
            $errors[$id][] = "登録されていないブロック種別です: {$type}";

            return;
        }

        $definition = $this->components->get($type);

        if (! $definition->acceptsParent($parentType)) {
            $errors[$id][] = "{$definition->label}は{$parentType}の中に置けません";
        }

        $this->validateProps($id, $definition, $node, $errors);
        $this->validateStyle($id, $definition, $node, $errors);
        $this->validateResponsive($id, $definition, $node, $errors);
        $this->validateBinding($id, $definition, $node, $errors);

        $children = $node['children'] ?? [];

        if (is_array($children) && $children !== [] && $definition->allowedChildren === []) {
            $errors[$id][] = "{$definition->label}の中にブロックは置けません";

            return;
        }

        $this->walkChildren($node, $type, $depth, $errors, $seenIds, $seenKeys, $count, $definition);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $errors
     * @param  array<string, true>  $seenIds
     * @param  array<string, true>  $seenKeys
     */
    private function walkChildren(
        array $node,
        string $type,
        int $depth,
        array &$errors,
        array &$seenIds,
        array &$seenKeys,
        int &$count,
        ?ComponentDefinition $definition = null,
    ): void {
        $children = $node['children'] ?? [];

        if (! is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                $errors[(string) $node['id']][] = '子ブロックの形式が不正です';

                continue;
            }

            $childType = $child['type'] ?? null;

            if ($definition !== null && is_string($childType) && ! $definition->acceptsChild($childType)) {
                $errors[(string) $node['id']][] = "{$definition->label}の中に {$childType} は置けません";
            }

            $this->walk($child, $type, $depth + 1, $errors, $seenIds, $seenKeys, $count);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $errors
     */
    private function validateProps(string $id, ComponentDefinition $definition, array $node, array &$errors): void
    {
        $props = $node['props'] ?? [];

        if (! is_array($props)) {
            $errors[$id][] = '設定値の形式が不正です';

            return;
        }

        $bound = is_array($node['binding'] ?? null) ? (string) ($node['binding']['field'] ?? '') : null;

        foreach ($definition->propsSchema as $key => $spec) {
            $value = $props[$key] ?? null;
            $required = (bool) ($spec['required'] ?? false);

            // binding で供給される prop は未設定でよい
            if ($value === null || $value === '') {
                if ($required && $bound === null && ! in_array($key, $definition->bindable, true)) {
                    $errors[$id][] = "「{$this->propLabel($key, $spec)}」は必須です";
                }

                continue;
            }

            $message = $this->checkPropValue($value, $spec);

            if ($message !== null) {
                $errors[$id][] = "「{$this->propLabel($key, $spec)}」{$message}";
            }
        }

        foreach (array_keys($props) as $key) {
            if (! array_key_exists((string) $key, $definition->propsSchema)) {
                $errors[$id][] = "{$definition->label}に設定項目 [{$key}] はありません";
            }
        }
    }

    /** @param array<string, mixed> $spec */
    private function propLabel(string $key, array $spec): string
    {
        return is_string($spec['label'] ?? null) ? $spec['label'] : $key;
    }

    /** @param array<string, mixed> $spec */
    private function checkPropValue(mixed $value, array $spec): ?string
    {
        return match ((string) ($spec['type'] ?? 'string')) {
            'string' => match (true) {
                ! is_string($value) => 'は文字列で指定してください',
                isset($spec['max']) && mb_strlen($value) > (int) $spec['max'] => 'が長すぎます',
                default => null,
            },
            'number' => match (true) {
                ! is_numeric($value) => 'は数値で指定してください',
                isset($spec['min']) && $value < $spec['min'] => 'が小さすぎます',
                isset($spec['max']) && $value > $spec['max'] => 'が大きすぎます',
                default => null,
            },
            'boolean' => is_bool($value) ? null : 'は true / false で指定してください',
            'enum' => in_array($value, (array) ($spec['values'] ?? []), true) ? null : 'に使えない値です',
            'media' => is_int($value) || ctype_digit((string) $value) ? null : 'は画像 ID で指定してください',
            'url' => $this->checkUrl((string) $value),
            // token 型は **theme に実在する参照だけ**を受ける。
            // 生の CSS 値を通すと、prop が任意 CSS の入口になる（Phase 9.6 / P0-C1）
            'token' => is_string($value) && $this->tokenReferenceIsValid($value, $spec)
                ? null
                : 'はデザイントークンで指定してください',
            default => null,
        };
    }

    /**
     * `{spacing.gap}` の形で、宣言された group に実在するか。
     *
     * @param  array<string, mixed>  $spec
     */
    private function tokenReferenceIsValid(string $value, array $spec): bool
    {
        if ($this->theme->resolve($value) === null) {
            return false;
        }

        $group = $spec['group'] ?? null;

        return ! is_string($group) || str_starts_with($value, '{'.$group.'.');
    }

    /** 危険なスキームを弾く（Phase 9.1C §24） */
    private function checkUrl(string $value): ?string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $value)) {
            return 'に使えないリンク先です';
        }
        // `//evil.example` は protocol-relative で外へ出る。root 相対（`/path`）とは別物
        if (str_starts_with($value, '//') || str_starts_with($value, '/\\')) {
            return 'に使えないリンク先です';
        }

        if (str_starts_with($value, '/') || str_starts_with($value, '#')) {
            return null;
        }

        // parse_urlはtel:0120をホストとポートと解釈する場合がある。URIのschemeを直接読む。
        preg_match('/\A([a-z][a-z0-9+.-]*):/i', $value, $match);
        $scheme = strtolower($match[1] ?? '');

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)
            ? null
            : 'に使えないリンク先です';
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $errors
     */
    private function validateStyle(string $id, ComponentDefinition $definition, array $node, array &$errors): void
    {
        $style = $node['style'] ?? [];

        if ($style === []) {
            return;
        }

        if (! is_array($style)) {
            $errors[$id][] = 'スタイルの形式が不正です';

            return;
        }

        foreach ($style as $key => $value) {
            if (! in_array((string) $key, $definition->styleSchema, true)) {
                $errors[$id][] = "{$definition->label}にスタイル [{$key}] は指定できません";

                continue;
            }

            // 値の可否は StyleProperties が 1 か所で決める（renderer / CSS 生成と同じ表）
            $message = StyleProperties::rejection((string) $key, $value, $this->theme);

            if ($message !== null) {
                $errors[$id][] = "スタイル [{$key}] {$message}";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $errors
     */
    private function validateResponsive(string $id, ComponentDefinition $definition, array $node, array &$errors): void
    {
        $responsive = $node['responsive'] ?? [];

        if ($responsive === []) {
            return;
        }

        if (! is_array($responsive)) {
            $errors[$id][] = '画面幅ごとの設定の形式が不正です';

            return;
        }

        $breakpoints = $this->theme->breakpoints();

        foreach ($responsive as $breakpoint => $overrides) {
            if (! array_key_exists((string) $breakpoint, $breakpoints)) {
                $errors[$id][] = "画面幅 [{$breakpoint}] はテーマに定義されていません";

                continue;
            }

            if (! is_array($overrides)) {
                $errors[$id][] = "画面幅 [{$breakpoint}] の設定が不正です";

                continue;
            }

            foreach ($overrides as $key => $value) {
                if (! in_array((string) $key, $definition->responsive, true)) {
                    $errors[$id][] = "[{$key}] は画面幅ごとに変えられません";

                    continue;
                }

                // **画面幅ごとの値にも base と同じ契約をかける。**
                // style は StyleProperties、prop は `propsSchema` が決める。
                // どちらの表にも無いなら、それは検証できない宣言なので通さない
                $message = match (true) {
                    StyleProperties::isClassBased((string) $key) => StyleProperties::rejection((string) $key, $value, $this->theme),
                    isset($definition->propsSchema[(string) $key]) => $this->checkPropValue($value, $definition->propsSchema[(string) $key]),
                    default => 'は画面幅ごとに検証できません',
                };

                if ($message !== null) {
                    $errors[$id][] = "画面幅 [{$breakpoint}] の [{$key}] {$message}";
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $errors
     */
    private function validateBinding(string $id, ComponentDefinition $definition, array $node, array &$errors): void
    {
        $binding = $node['binding'] ?? null;

        if ($binding === null) {
            // 一覧は binding が無いと描画できない。**型の名前で決めない**
            if ($definition->collection) {
                $errors[$id][] = '一覧に表示するコンテンツが指定されていません';
            }

            return;
        }

        if (! is_array($binding)) {
            $errors[$id][] = 'コンテンツ参照の形式が不正です';

            return;
        }

        $source = (string) ($binding['source'] ?? '');

        if (! in_array($source, ['collection', 'entry', 'page', 'settings'], true)) {
            $errors[$id][] = "コンテンツ参照の種類が不正です: {$source}";

            return;
        }

        if (in_array($source, ['collection', 'entry'], true)) {
            $type = (string) ($binding['type'] ?? '');

            if (! $this->types->has($type) || ! $this->schemas->has($type)) {
                $errors[$id][] = "コンテンツタイプ [{$type}] は存在しません";

                return;
            }

            $field = $binding['field'] ?? null;

            if ($source === 'entry' && (! is_string($field) || ! $this->hasField($type, $field))) {
                $errors[$id][] = "[{$type}] にフィールド [{$field}] はありません";
            }

            $sortSpecs = $binding['sort'] ?? [];

            // filters と同じ扱い。読み取れない並び順を黙って無視しない
            if (! is_array($sortSpecs) || ! array_is_list($sortSpecs)) {
                $errors[$id][] = '並び順の形式が不正です';
                $sortSpecs = [];
            }

            foreach ($sortSpecs as $sort) {
                $sortField = is_array($sort) ? (string) ($sort['field'] ?? '') : '';

                if (! $this->hasField($type, $sortField)) {
                    $errors[$id][] = "並び順に使えないフィールドです: {$sortField}";
                }
            }

            $filters = $binding['filters'] ?? [];

            // 黙って無視すると「絞り込んだつもりで全件出る」になる（fail closed）。
            // checked-in content にも CLI にも連想配列の filters を作る場所は無いので、
            // ここで list を要求しても既存の document は壊れない
            if (! is_array($filters) || ! array_is_list($filters)) {
                $errors[$id][] = '絞り込み条件の形式が不正です';
                $filters = [];
            }

            foreach ($filters as $filter) {
                // taxonomy filter は **通常 field 検証へ落とさない**（§O / #22-2）。
                // 落とすと `field` が無いので必ず「使えないフィールド」になり、
                // 分類での絞り込みが一切保存できなくなる
                if (is_array($filter) && array_key_exists('taxonomy', $filter)) {
                    $this->validateTaxonomyFilter($id, $filter, $errors);

                    continue;
                }

                $filterField = is_array($filter) ? (string) ($filter['field'] ?? '') : '';

                if (! $this->hasField($type, $filterField)) {
                    $errors[$id][] = "絞り込みに使えないフィールドです: {$filterField}";
                }
            }
        }

        if ($source === 'settings' || $source === 'page') {
            $field = $binding['field'] ?? null;

            if (! is_string($field) || $field === '') {
                $errors[$id][] = '参照するフィールドが指定されていません';
            } elseif ($source === 'page' && ! isset(BindingTypes::PAGE_FIELDS[$field])) {
                /*
                 * **`BindingResolver` が解決しないものは、保存させない。**
                 *
                 * あちらは `page` の `title` / `slug` しか解決せず、他は黙って null を返す。
                 * 通すと「保存はできるが何も出ない binding」が作れてしまい、
                 * 「読み取れないときは fail closed」という §4.9 の方針と噛み合わない。
                 */
                $errors[$id][] = "ページの項目に [{$field}] はありません";
            }
        }

        $this->validateBindingProp($id, $definition, $source, $binding, $errors);
    }

    /**
     * 流し先の prop（Phase 9.6 / P0-C5・SPEC §4.9）。
     *
     * ここまでの検証は**どこから持ってくるか**だけを見ていた。ここで初めて
     * **どこへ流すか**を見る。表は `BindingTypes` が正本である。
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, list<string>>  $errors
     */
    private function validateBindingProp(
        string $id,
        ComponentDefinition $definition,
        string $source,
        array $binding,
        array &$errors,
    ): void {
        if ($source === 'collection') {
            /*
             * **一覧に流し先の prop は無い。** まるごと流し込むので、
             * 書けるようにすると「一覧をテキスト欄へ流す」が表現できてしまう。
             */
            if (array_key_exists('prop', $binding)) {
                $errors[$id][] = '一覧の binding に流し先の項目は指定できません';
            }

            return;
        }

        $prop = $binding['prop'] ?? null;

        /*
         * **単一値の binding では `prop` が要る。** 省略を許して先頭の prop へ落とすと、
         * `propsSchema` の宣言順を変えただけで**流し先が黙って変わる**。
         */
        if (! is_string($prop) || $prop === '') {
            $errors[$id][] = '流し先の項目が指定されていません';

            return;
        }

        if (! in_array($prop, $definition->bindable, true)) {
            $errors[$id][] = "[{$prop}] はコンテンツを流せる項目ではありません";

            return;
        }

        $propSpec = $definition->propsSchema[$prop] ?? null;

        if (! is_array($propSpec)) {
            // bindable に居るのに宣言が無い = registry 側の食い違い
            $errors[$id][] = "[{$prop}] は宣言されていません";

            return;
        }

        [$fieldType, $choices] = $this->bindingFieldType($source, $binding);

        /*
         * **型が引けないときの扱いは、わざと非対称にしてある**（§4.9・BindingTypes の docblock）。
         *
         * `format: "html"` の prop は**閉じる** — richtext だと分かっているものしか通さない。
         * それ以外は**開く** — 引けなければ検査を飛ばす。
         *
         * html を受ける prop は、生成 project 側が clean せずに raw で出す component を
         * 書けてしまう場所で、防御の重ねの最後の環にあたる。他の prop で起きるのは
         * 意味の壊れまでで、そこまで閉じると settings からの binding が全滅する。
         */
        if (BindingTypes::isHtmlProp($propSpec)) {
            if ($fieldType !== 'richtext') {
                $errors[$id][] = "[{$prop}] には richtext のフィールドしか流せません";
            }

            return;
        }

        if ($fieldType === null || ! BindingTypes::knows($fieldType)) {
            return;
        }

        if (! BindingTypes::accepts($fieldType, $propSpec, $choices)) {
            $errors[$id][] = "[{$fieldType}] のフィールドは [{$prop}] へ流せません";
        }
    }

    /**
     * binding が指す Field の型と選択肢。**引けなければ `[null, []]`**。
     *
     * `settings` は schema を持たないので引けない。`page` は
     * `BindingResolver` が解決する 2 つだけ引ける。
     *
     * @param  array<string, mixed>  $binding
     * @return array{0: ?string, 1: list<string>}
     */
    private function bindingFieldType(string $source, array $binding): array
    {
        $name = is_string($binding['field'] ?? null) ? $binding['field'] : '';

        if ($source === 'page') {
            return [BindingTypes::PAGE_FIELDS[$name]['type'] ?? null, []];
        }

        if ($source !== 'entry') {
            return [null, []];
        }

        $type = (string) ($binding['type'] ?? '');

        if ($name === '' || ! $this->schemas->has($type)) {
            return [null, []];
        }

        $schema = $this->schemas->get($type);
        $field = $schema->fieldById($name) ?? $schema->fieldByPath($name);

        return $field === null ? [null, []] : [$field->type, $field->choices];
    }

    /**
     * taxonomy filter の検証（Phase 9.3B / #22・SPEC §O）。
     *
     * ## 形
     *
     * ```json
     * { "taxonomy": "news_category", "terms": ["01KZ…"] }
     * ```
     *
     * **`terms[]` は immutable term ID が正**。slug / name では解決しない。
     * 名前を変えても filter が壊れないのが ID を正にしている理由なので、
     * ここで slug を受け付けると設計が崩れる。
     *
     * ## 判定
     *
     * - 型の検証（`taxonomy` が文字列 / `terms` が文字列の配列）は
     *   Reference Inspector と **同じ語彙**を使う。参照調査と保存時検証で
     *   「何が壊れているか」の言い方をずらさない。
     * - ただし **空の `terms` と連想配列の `terms` はここだけが弾く**。
     *   Inspector にとってそれらは「一致しない」だけで壊れてはいないので malformed にしない。
     *   保存時検証は「後から直せない形を残さない」のが仕事なので、より厳しくてよい。
     *   この差は Inspector が数える参照の定義を変えない。
     * - taxonomy は key で引くが、**所属の判定は ID で行う**。
     *   key が合っているだけで term の所属を信じない（別 taxonomy の term ID は error）。
     * - **archived な term は有効**（AR-5）。archive は「新規に付けられない」であって、
     *   既にある filter を壊す操作ではない。
     * - **空の `terms` は error**。何にも当たらない filter を黙って許すと
     *   「絞り込んだのに 0 件」なのか「書き間違い」なのか画面から区別できない。
     * - 重複した term ID は許す（OR なので結果が変わらない）。
     *
     * @param  array<string, mixed>  $filter
     * @param  array<string, list<string>>  $errors
     */
    private function validateTaxonomyFilter(string $id, array $filter, array &$errors): void
    {
        $key = $filter['taxonomy'];

        if (! is_string($key) || $key === '') {
            $errors[$id][] = '絞り込み条件の分類名が文字列ではありません';

            return;
        }

        if (! array_key_exists('terms', $filter)) {
            $errors[$id][] = '絞り込み条件に terms がありません';

            return;
        }

        $terms = $filter['terms'];

        if (! is_array($terms)) {
            $errors[$id][] = '絞り込み条件の terms が配列ではありません';

            return;
        }

        // contract は list<string>。`{"a": "01K…"}` のような連想配列は受け取らない
        // （key に意味を持たせた別の形を、黙って並びとして解釈しない）
        if (! array_is_list($terms)) {
            $errors[$id][] = '絞り込み条件の terms は並びではありません';

            return;
        }

        foreach ($terms as $term) {
            if (! is_string($term)) {
                $errors[$id][] = '絞り込み条件の terms に文字列でない要素があります';

                return;
            }
        }

        if ($terms === []) {
            $errors[$id][] = "分類 [{$key}] の絞り込みに項目が 1 つも指定されていません";

            return;
        }

        $lookup = $this->taxonomyLookup ?? ['taxonomies' => [], 'terms' => []];
        $taxonomyId = $lookup['taxonomies'][$key] ?? null;

        if ($taxonomyId === null) {
            $errors[$id][] = "分類 [{$key}] は存在しません";

            return;
        }

        foreach ($terms as $term) {
            $owner = $lookup['terms'][$term] ?? null;

            if ($owner === null) {
                $errors[$id][] = "分類項目 [{$term}] は存在しません";

                continue;
            }

            if ($owner !== $taxonomyId) {
                $errors[$id][] = "分類項目 [{$term}] は分類 [{$key}] のものではありません";
            }
        }
    }

    /**
     * document 全体の taxonomy filter を **1 度だけ**まとめて解決する（#22-8）。
     *
     * node ごとに引くと、同じ分類を 500 回参照する document で 500 クエリになる。
     * ここで key と term ID を集め、**多くても 2 クエリ**で索引を作る。
     * taxonomy filter が 1 つも無ければ **DB へ触らない**。
     *
     * 走査は検証より前に走るので、壊れた形（非配列・非文字列）は
     * ここでは黙って読み飛ばす。**エラーにするのは検証側の仕事**で、
     * ここで例外を投げると「壊れた document を検証すらできない」になる。
     *
     * @param  array<string, mixed>  $root
     * @return array{taxonomies: array<string, string>, terms: array<string, string>}
     */
    private function resolveTaxonomyFilters(array $root): array
    {
        $keys = [];
        $termIds = [];

        $this->collectTaxonomyFilters($root, $keys, $termIds);

        if ($keys === []) {
            return ['taxonomies' => [], 'terms' => []];
        }

        // 索引の作り方は描画側と同じものを使う（保存できるのに描画されない、を作らない）
        return $this->taxonomies->build(array_keys($keys), array_keys($termIds));
    }

    /**
     * @param  array<string, mixed>|mixed  $node
     * @param  array<string, true>  $keys
     * @param  array<string, true>  $termIds
     */
    private function collectTaxonomyFilters(mixed $node, array &$keys, array &$termIds): void
    {
        if (! is_array($node)) {
            return;
        }

        $binding = $node['binding'] ?? null;
        $filters = is_array($binding) ? ($binding['filters'] ?? null) : null;

        if (is_array($filters)) {
            foreach ($filters as $filter) {
                if (! is_array($filter) || ! is_string($filter['taxonomy'] ?? null)) {
                    continue;
                }

                $keys[$filter['taxonomy']] = true;

                foreach ((array) ($filter['terms'] ?? []) as $term) {
                    if (is_string($term)) {
                        $termIds[$term] = true;
                    }
                }
            }
        }

        $children = $node['children'] ?? null;

        if (! is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            $this->collectTaxonomyFilters($child, $keys, $termIds);
        }
    }

    /**
     * binding の field 参照を解決する（Phase 9.2B）。
     *
     * 受け付ける形は 3 つ:
     * - **immutable field ID**（第一候補。rename されても壊れない）
     * - key（人が読める形）
     * - `specs.weight` のようなドット記法（group / repeater の中を指す）
     */
    private function hasField(string $type, string $field): bool
    {
        if ($field === '' || ! $this->schemas->has($type)) {
            return false;
        }

        $schema = $this->schemas->get($type);

        if ($schema->fieldById($field) !== null) {
            return true;
        }

        if ($schema->fieldByPath($field) !== null) {
            return true;
        }

        // published_at / created_at は schema に無いが並び順に使える
        return in_array($field, ['id', 'created_at', 'updated_at'], true);
    }
}
