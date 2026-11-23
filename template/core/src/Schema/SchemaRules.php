<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;

/**
 * Schema → Laravel validation rules の導出（Schema First: 手書きルールとの二重管理禁止）。
 *
 * Phase 9.2B: 入れ子（group / repeater）と参照（relation / relations）へ対応。
 * **Admin / API / MCP / Import がすべてこの 1 箇所から導かれたルールで検証される。**
 *
 * 利用者が任意の正規表現を書ける `pattern` は採用しない（ReDoS）。
 * 名前付きパターン（`slug` / `postal` / `hex`）だけを許す。
 */
final class SchemaRules
{
    /** validation.pattern に指定できる名前付きパターン */
    private const NAMED_PATTERNS = [
        'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        'postal' => '/^[0-9]{3}-?[0-9]{4}$/',
        'hex' => '/^#?[0-9a-fA-F]{3,8}$/',
        'alphanumeric' => '/^[A-Za-z0-9]+$/',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public static function for(ContentSchema $schema, string $table, ?int $ignoreId = null): array
    {
        $rules = [];

        foreach ($schema->fields as $field) {
            $rules = [...$rules, ...self::rulesForField($field, $field->key, $table, $ignoreId)];
        }

        return $rules;
    }

    /**
     * 1 フィールド分のルール。入れ子は `parent.*.child` の形で展開する。
     *
     * @return array<string, list<mixed>>
     */
    private static function rulesForField(Field $field, string $path, string $table, ?int $ignoreId): array
    {
        $rules = [$path => self::baseRules($field, $table, $ignoreId)];

        if ($field->type === 'group') {
            foreach ($field->fields as $child) {
                $rules = [...$rules, ...self::rulesForField($child, "{$path}.{$child->key}", $table, null)];
            }

            return $rules;
        }

        if ($field->type === 'repeater') {
            // 行の identity。サーバーが採番するので入力側では任意
            $rules["{$path}.*._id"] = ['sometimes', 'string'];

            foreach ($field->fields as $child) {
                $rules = [...$rules, ...self::rulesForField($child, "{$path}.*.{$child->key}", $table, null)];
            }

            return $rules;
        }

        if ($field->type === 'images') {
            // 同じ画像を 2 回並べない
            $rules["{$path}.*"] = ['integer', 'distinct', self::mediaOfKind('image')];

            return $rules;
        }

        if ($field->type === 'relations') {
            // 参照先の実在確認は ContentReferences が schema から解決する。
            // 重複は V1 の既定で許可しない（owner 決定）
            $rules["{$path}.*"] = ['integer', 'distinct'];

            return $rules;
        }

        if ($field->type === 'checkbox') {
            $rules["{$path}.*"] = [Rule::in($field->choices)];

            return $rules;
        }

        return $rules;
    }

    /**
     * その種別の Media を指しているか。
     *
     * **存在と種別を 1 本のクエリで見る。** 「存在するが種別が違う」を別経路で
     * 判定すると、片方だけ通る窓ができる（SPEC §6）。
     */
    private static function mediaOfKind(string $kind): Exists
    {
        return Rule::exists('media', 'id')->where('kind', $kind);
    }

    /**
     * いま公開されているページを指しているか。
     *
     * `Page::isPublicallyVisible()` と**同じ 3 条件**（公開状態・公開版がある・ゴミ箱でない）を
     * 1 本のクエリで見る。片方だけ通る窓を作らないためで、`mediaOfKind()` と同じ作法である。
     *
     * ## 使う側が、さらに狭められる
     *
     * `page` 型そのものは**広いまま**にしてある — ページを指す欄が、常に
     * 「ビジュアルエディタで編集されたページ」だけを指すとは限らない。
     * **狭めたい欄だけが `validation.managedBy` で宣言する**（site.json の `front_page_id`）。
     *
     * 宣言を規則側で受けるのは、**MCP / CLI から「保存は成功したのに何も変わらない」を
     * 作らせない**ためである。画面の候補だけで絞ると、画面を通らない経路が素通りする。
     */
    private static function publishedPage(Field $field): Exists
    {
        $rule = Rule::exists('pages', 'id')
            ->where('status', 'published')
            ->whereNotNull('published_version_id')
            ->whereNull('trashed_at');

        $managedBy = $field->validation['managedBy'] ?? null;

        if (is_string($managedBy) && $managedBy !== '') {
            $rule = $rule->where('managed_by', $managedBy);
        }

        return $rule;
    }

    /**
     * 検証メッセージの中で欄を何と呼ぶか。**schema の label をそのまま使う**。
     *
     * 持たせないと `front_page_id` のような**内部の名前が利用者に出る**。
     * lang ファイルへ欄ごとに書き写すと二重管理になる（label は既に schema にある）。
     *
     * @return array<string, string>
     */
    public static function attributes(ContentSchema $schema): array
    {
        $attributes = [];

        foreach ($schema->fields as $field) {
            $attributes[$field->key] = $field->label;
        }

        return $attributes;
    }

    /**
     * 型ごとの言い回し。**欄の名前では引かない**（型で引く）。
     *
     * `exists` の既定は「選択された値は正しくありません」で、
     * **なぜ選べないのかが分からない**。`page` 型は理由が 1 つに決まっているので、
     * ここで言い切る。
     *
     * @return array<string, string>
     */
    public static function messages(ContentSchema $schema): array
    {
        $messages = [];

        foreach ($schema->fields as $field) {
            if ($field->type !== 'page') {
                continue;
            }

            /*
             * **断った理由と、実際に見た条件を一致させる**（R-5 再監査 指摘 8）。
             *
             * `validation.managedBy` を宣言した欄は「公開中」だけでなく
             * **ビジュアルエディタで編集されたページか**も見ている。
             * それを言わずに「公開中のページだけ」と出すと、**公開中のページを選んだのに
             * 断られた人が、事実と食い違う説明を読む**ことになる（F-26 型）。
             */
            $messages["{$field->key}.exists"] = isset($field->validation['managedBy'])
                ? ':attributeには、ビジュアルエディタで編集された、公開中のページだけを指定できます。'
                : ':attributeには、公開中のページだけを指定できます。';
        }

        return $messages;
    }

    /** @return list<mixed> */
    private static function baseRules(Field $field, string $table, ?int $ignoreId): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];

        $rules = [...$rules, ...match ($field->type) {
            'text', 'textarea', 'richtext' => ['string'],
            'email' => ['string', 'email'],
            'url' => ['string', 'url'],
            'tel' => ['string', 'regex:/^[0-9+\-() ]{6,20}$/'],
            'number' => ['numeric'],
            'slug' => ['string', 'regex:'.self::NAMED_PATTERNS['slug']],
            'select', 'radio' => [Rule::in($field->choices)],
            'checkbox' => ['array'],
            'status' => [Rule::in(['draft', 'published'])],
            'date', 'datetime' => ['date'],
            'boolean' => ['boolean'],
            // image はアップロード済みパス文字列を受ける（アップロード自体は UploadValidator が検証）
            'image' => ['string'],
            // media / file は Media Entity への参照（ADR-0006）。
            // **種別まで見る**（Phase 9.5-D / SPEC §6）
            'media' => ['integer', self::mediaOfKind('image')],
            'file' => ['integer', self::mediaOfKind('file')],
            'images', 'relations' => ['array'],
            'relation' => ['integer'],
            /*
             * ページへの参照。**指せるのは「いま公開されているページ」だけ**。
             *
             * 下書き・ゴミ箱を保存できてしまうと、**保存は成功したのに公開面では何も変わらない**
             * という黙った空振りになる（画面からも API からも成功に見える）。
             * 候補の出し方（`MenuAdminController::choices()`）と同じ条件をここでも見る。
             *
             * **選んだ後に状態が変わる**（公開を取り消す・ゴミ箱へ入れる）のは別の話で、
             * そちらは描画側が既定へ戻して受ける — 保存時に断ることと矛盾しない。
             */
            'page' => ['integer', self::publishedPage($field)],
            'group' => ['array'],
            'repeater' => ['array'],
            default => throw new InvalidArgumentException("Unknown field type [{$field->type}]"),
        }];

        if ($field->max !== null) {
            $rules[] = "max:{$field->max}";
        }

        $rules = [...$rules, ...self::declaredRules($field)];

        if ($field->unique) {
            $unique = Rule::unique($table, $field->key);

            if ($ignoreId !== null) {
                $unique = $unique->ignore($ignoreId);
            }

            $rules[] = $unique;
        }

        return $rules;
    }

    /**
     * schema の `validation` ブロックから導く追加ルール。
     *
     * @return list<mixed>
     */
    private static function declaredRules(Field $field): array
    {
        $rules = [];
        $v = $field->validation;

        if (isset($v['minLength'])) {
            $rules[] = 'min:'.(int) $v['minLength'];
        }

        if (isset($v['maxLength'])) {
            $rules[] = 'max:'.(int) $v['maxLength'];
        }

        if (isset($v['min']) && ! $field->isMultiValue()) {
            $rules[] = 'min:'.$v['min'];
        }

        if (isset($v['max']) && ! $field->isMultiValue()) {
            $rules[] = 'max:'.$v['max'];
        }

        if (isset($v['minItems'])) {
            $rules[] = 'min:'.(int) $v['minItems'];
        }

        if (isset($v['maxItems'])) {
            $rules[] = 'max:'.(int) $v['maxItems'];
        }

        if (isset($v['in']) && is_array($v['in'])) {
            $rules[] = Rule::in($v['in']);
        }

        if (isset($v['pattern']) && is_string($v['pattern'])) {
            $pattern = self::NAMED_PATTERNS[$v['pattern']]
                ?? throw new InvalidArgumentException(
                    "Field [{$field->key}] uses unknown validation pattern [{$v['pattern']}]",
                );
            $rules[] = 'regex:'.$pattern;
        }

        if (isset($v['after']) && is_string($v['after'])) {
            $rules[] = 'after:'.$v['after'];
        }

        if (isset($v['before']) && is_string($v['before'])) {
            $rules[] = 'before:'.$v['before'];
        }

        return $rules;
    }
}
