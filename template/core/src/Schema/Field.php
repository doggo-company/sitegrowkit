<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

use InvalidArgumentException;

/**
 * Content Schema の 1 フィールド定義（immutable value object）。
 *
 * Phase 9.2B（owner 決定 G2 / G4 / G6）で拡張:
 * - `id`      … **immutable な機械 ID**（ULID）。rename しても label / type を変えても不変。
 *                PageDocument binding・audit・schema history はこれで同じフィールドだと分かる
 * - `key`     … 人が読む名前。API / MCP の payload 名であり **rename できる**
 * - `fieldset`… 管理画面の表示上のまとまり（旧 `group`）。**入れ子データではない**
 * - `fields`  … group / repeater の入れ子定義
 * - `target`  … relation / relations の参照先 Content Type
 *
 * 形式は docs/adr/0004-content-schema-format.md（Phase 9.2B で改訂）。
 */
final readonly class Field
{
    /** 使える型（Phase 9.2B の目標セット） */
    private const TYPES = [
        // 文字・数値
        'text', 'textarea', 'richtext', 'number', 'url', 'email', 'tel',
        // 日付・真偽
        'date', 'datetime', 'boolean',
        // 選択
        'select', 'radio', 'checkbox',
        // メディア
        'image', 'images', 'file', 'media',
        // 参照
        'relation', 'relations',
        // ページを 1 つ指す（値は `pages.id`。`relation` は content type しか指せない）
        'page',
        // 入れ子
        'group', 'repeater',
        // 本製品固有
        'slug', 'status',
    ];

    /**
     * 使える Field 型の一覧。
     *
     * **外から数えられるようにしてある。** 型を足したときに、それを見ている側
     * （`BindingTypes` の型整合表など）が置いていかれていないことを試験で固定するため。
     * 定数のままだと「増えたのに誰も気づかない」が起きる（F-18）。
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** 選択肢が必須の型 */
    private const NEEDS_CHOICES = ['select', 'radio', 'checkbox'];

    /** 入れ子定義が必須の型 */
    private const NEEDS_FIELDS = ['group', 'repeater'];

    /** 参照先が必須の型 */
    private const NEEDS_TARGET = ['relation', 'relations'];

    /**
     * @param  list<string>  $choices
     * @param  list<Field>  $fields  group / repeater の子フィールド
     * @param  array<string, mixed>  $validation
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public bool $required = false,
        public ?int $max = null,
        public array $choices = [],
        public ?string $from = null,
        public bool $unique = false,
        public ?string $fieldset = null,
        public ?string $id = null,
        public mixed $default = null,
        public string $help = '',
        public array $validation = [],
        public array $fields = [],
        public ?string $target = null,
        public bool $searchable = false,
        public bool $filterable = false,
        public bool $sortable = false,
        /**
         * 保存先の**明示指定**（Phase 9.5-B / ADR-0004 Rev.2）。
         *
         * 既定は `null` = 従来どおり **型から導出**する。既存の schema ファイルは
         * このキーを書かないので、出力は 1 バイトも変わらない。
         *
         * 明示が要るのは「同じ型でも保存先が違う」場合だけである。
         * Field Group が DB overlay で足す項目は、対象テーブルにカラムが無いため
         * 型に関わらず `data` JSON へ入れる（owner 決定 G2-(a)）。
         * 型からは導出できないので、宣言で持つ。
         */
        public ?FieldStorage $declaredStorage = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown field type [{$type}] for field [{$key}]");
        }

        if (in_array($type, self::NEEDS_CHOICES, true) && $choices === []) {
            throw new InvalidArgumentException("Field [{$key}] of type {$type} requires non-empty choices");
        }

        if (in_array($type, self::NEEDS_FIELDS, true) && $fields === []) {
            throw new InvalidArgumentException("Field [{$key}] of type {$type} requires nested fields");
        }

        if (in_array($type, self::NEEDS_TARGET, true) && ($target === null || $target === '')) {
            throw new InvalidArgumentException("Field [{$key}] of type {$type} requires a target content type");
        }

        $childKeys = array_map(static fn (self $f): string => $f->key, $fields);

        if ($childKeys !== array_unique($childKeys)) {
            throw new InvalidArgumentException("Field [{$key}] has duplicate nested field keys");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = (string) ($data['key'] ?? throw new InvalidArgumentException('Field is missing "key"'));

        // `group` は Phase 9.2B で入れ子型になったため、表示のまとまりは `fieldset` へ移した。
        // 旧 schema（"group": "seo"）は読み込み時だけ別名として受ける（新規生成はしない）
        $fieldset = $data['fieldset'] ?? (is_string($data['group'] ?? null) ? $data['group'] : null);

        return new self(
            key: $key,
            type: (string) ($data['type'] ?? throw new InvalidArgumentException("Field [{$key}] is missing \"type\"")),
            label: (string) ($data['label'] ?? $key),
            required: (bool) ($data['required'] ?? false),
            max: isset($data['max']) ? (int) $data['max'] : null,
            choices: array_values(array_map(strval(...), $data['choices'] ?? [])),
            from: isset($data['from']) ? (string) $data['from'] : null,
            unique: (bool) ($data['unique'] ?? false),
            fieldset: $fieldset === null ? null : (string) $fieldset,
            id: isset($data['id']) ? (string) $data['id'] : null,
            default: $data['default'] ?? null,
            help: (string) ($data['help'] ?? ''),
            validation: is_array($data['validation'] ?? null) ? $data['validation'] : [],
            fields: array_map(self::fromArray(...), array_values($data['fields'] ?? [])),
            target: isset($data['target']) ? (string) $data['target'] : null,
            searchable: (bool) ($data['searchable'] ?? false),
            filterable: (bool) ($data['filterable'] ?? false),
            sortable: (bool) ($data['sortable'] ?? false),
            declaredStorage: self::readStorage($data, $key),
        );
    }

    /**
     * `storage` の読み取り。**キーが無ければ null**（= 型から導出）。
     *
     * 未知の値は黙って型導出へ落とさない。落とすと「書いたのに効かない」になる。
     *
     * @param  array<string, mixed>  $data
     */
    private static function readStorage(array $data, string $key): ?FieldStorage
    {
        if (! array_key_exists('storage', $data) || $data['storage'] === null) {
            return null;
        }

        $raw = $data['storage'];

        return (is_string($raw) ? FieldStorage::tryFrom($raw) : null)
            ?? throw new InvalidArgumentException(
                "Field [{$key}] has an unknown storage. Use \"column\" or \"json\".",
            );
    }

    /** 永続化方式（カラムか data JSON か）。宣言があればそれ、無ければ型から導出 */
    public function storage(): FieldStorage
    {
        return $this->declaredStorage ?? FieldStorage::forType($this->type);
    }

    public function isColumnBacked(): bool
    {
        return $this->storage() === FieldStorage::Column;
    }

    /** 入れ子を持つ型か */
    public function isNested(): bool
    {
        return $this->fields !== [];
    }

    /** Media を参照する型か（削除保護の走査対象） */
    public function referencesMedia(): bool
    {
        return in_array($this->type, ['image', 'images', 'file', 'media'], true);
    }

    /** Content を参照する型か */
    public function referencesContent(): bool
    {
        return in_array($this->type, ['relation', 'relations'], true);
    }

    /** 値が配列になる型か */
    public function isMultiValue(): bool
    {
        return in_array($this->type, ['images', 'relations', 'repeater', 'checkbox'], true);
    }

    /** 内部参照に使う identity。id があればそれ、無ければ key（後方互換） */
    public function identity(): string
    {
        return $this->id ?? $this->key;
    }

    /** Eloquent の casts に必要な型（不要なら null） */
    public function cast(): ?string
    {
        return match ($this->type) {
            'date' => 'date',
            'datetime' => 'datetime',
            'boolean' => 'boolean',
            'number' => 'float',
            // 複数選択はカラムに保存するが値は配列（json カラム）
            'checkbox' => 'array',
            default => null,
        };
    }

    /**
     * 管理画面フォーム / AI へ渡す表現。
     *
     * @return array<string, mixed>
     */
    public function toFormSpec(): array
    {
        // 既存の管理画面フォームが読むキーは **常に存在させる**（false や [] を落とさない）。
        // Phase 9.2B で増えたキーだけ、値があるときに追加する
        $spec = [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'required' => $this->required,
            'max' => $this->max,
            'choices' => $this->choices,
            'from' => $this->from,
            // 旧 `group`（表示のまとまり）は `fieldset` へ移した。既存フォームとの互換で両方返す
            'group' => $this->fieldset,
            'fieldset' => $this->fieldset,
            'storage' => $this->storage()->value,
        ];

        if ($this->id !== null) {
            $spec['id'] = $this->id;
        }

        if ($this->default !== null) {
            $spec['default'] = $this->default;
        }

        if ($this->help !== '') {
            $spec['help'] = $this->help;
        }

        if ($this->validation !== []) {
            $spec['validation'] = $this->validation;
        }

        if ($this->target !== null) {
            $spec['target'] = $this->target;
        }

        if ($this->fields !== []) {
            $spec['fields'] = array_map(static fn (self $f): array => $f->toFormSpec(), $this->fields);
        }

        foreach (['searchable' => $this->searchable, 'filterable' => $this->filterable, 'sortable' => $this->sortable] as $key => $value) {
            if ($value) {
                $spec[$key] = true;
            }
        }

        return $spec;
    }
}
