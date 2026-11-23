<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

/**
 * Content Type Builder が **管理画面から選ばせてよい** フィールド型（Phase 9.4 / STAGE #17A）。
 *
 * `Schema\Field::TYPES` の部分集合である。Builder は
 *
 * - 生成した migration のカラム定義が決定的に書けて
 * - 追加の参照整合（relation / media 以外）や入れ子を必要としない
 *
 * 型だけを扱う。`image` / `images` / `file` / `relation` / `relations` /
 * `group` / `repeater` は **STAGE #17A の対象外**（schema を手で書けば従来どおり使える）。
 *
 * 各 case が「その型のカラム定義」の単一情報源であり、
 * migration 生成と Schema JSON 生成が別々の対応表を持つことはない。
 */
enum BuilderFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Richtext = 'richtext';
    case Slug = 'slug';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case Datetime = 'datetime';
    case Email = 'email';
    case Url = 'url';
    case Tel = 'tel';
    case Media = 'media';
    case Status = 'status';

    /** 管理画面の選択肢（表示順は宣言順に固定する） */
    public function label(): string
    {
        return match ($this) {
            self::Text => '1 行テキスト',
            self::Textarea => '複数行テキスト',
            self::Richtext => 'リッチテキスト（本文）',
            self::Slug => 'スラッグ',
            self::Number => '数値',
            self::Boolean => 'チェック（オン / オフ）',
            self::Select => 'プルダウン選択',
            self::Radio => 'ラジオ選択',
            self::Checkbox => '複数選択',
            self::Date => '日付',
            self::Datetime => '日時',
            self::Email => 'メールアドレス',
            self::Url => 'URL',
            self::Tel => '電話番号',
            self::Media => 'メディア（1 件）',
            self::Status => '公開ステータス',
        };
    }

    /** 選択肢が必須の型（`Schema\Field` の NEEDS_CHOICES と同じ集合） */
    public function needsChoices(): bool
    {
        return in_array($this, [self::Select, self::Radio, self::Checkbox], true);
    }

    /** `max`（文字数上限）を設定できる型 */
    public function supportsMax(): bool
    {
        return in_array($this, [self::Text, self::Textarea, self::Slug], true);
    }

    /** 既定の文字数上限。`supportsMax()` が false の型では使わない */
    public function defaultMax(): int
    {
        return match ($this) {
            self::Textarea => 500,
            default => 120,
        };
    }

    /** `max` に許される上限（これを超えると `string` カラムに収まらない） */
    public function maxLimit(): int
    {
        return match ($this) {
            self::Textarea => 2000,
            default => 255,
        };
    }

    /** 全文検索（LIKE）の対象にできる型 */
    public function supportsSearchable(): bool
    {
        return in_array($this, [self::Text, self::Textarea, self::Richtext, self::Slug, self::Email, self::Url], true);
    }

    /**
     * 一覧の絞り込み / 並び替えに使える型（index を張れるもの）。
     *
     * `richtext` / `textarea` は TEXT 系カラムなので index を張れない
     * （MySQL は prefix 長の指定が要る）。`checkbox` は JSON なので対象外。
     */
    public function supportsIndex(): bool
    {
        return ! in_array($this, [self::Richtext, self::Textarea, self::Checkbox], true);
    }

    /**
     * 1 行あたりの概算バイト数（MySQL の行サイズ上限 65,535 の見積り）。
     *
     * `utf8mb4` は 1 文字最大 4 バイトなので、`varchar(255)` だけでも
     * 1KB 強を消費する。フィールドを増やしすぎた schema が
     * **CREATE TABLE で初めて落ちる**のを避けるため、計画段階で合算する。
     * TEXT / JSON / LONGTEXT は本体行にポインタしか置かない。
     */
    public function estimatedRowBytes(FieldDraft $field): int
    {
        $max = $field->max ?? $this->defaultMax();

        return match ($this) {
            self::Text, self::Slug => $max * 4 + 2,
            self::Select, self::Radio => 191 * 4 + 2,
            self::Email, self::Url => 255 * 4 + 2,
            self::Tel => 32 * 4 + 2,
            self::Status => 20 * 4 + 2,
            self::Number => 8,
            self::Boolean => 1,
            self::Date => 3,
            self::Datetime => 8,
            self::Media => 8,
            // TEXT / LONGTEXT / JSON は off-page 保存でポインタのみ
            self::Textarea, self::Richtext, self::Checkbox => 20,
        };
    }

    /**
     * migration の 1 行（`$table->` に続く式）。
     *
     * **ここが生成カラムの単一情報源**。値は識別子検証済みの
     * key と数値だけで組み立てるので、文字列連結による注入が成立しない。
     */
    public function columnExpression(FieldDraft $field): string
    {
        $key = $field->key;
        $max = $field->max ?? $this->defaultMax();

        $expression = match ($this) {
            self::Text, self::Slug => "string('{$key}', {$max})",
            // 複数行は varchar にしない。utf8mb4 の index 長超過と
            // 行サイズ超過（65,535 バイト）を構造的に避ける
            self::Textarea => "text('{$key}')",
            self::Richtext => "longText('{$key}')",
            self::Number => "decimal('{$key}', 14, 4)",
            self::Boolean => "boolean('{$key}')",
            self::Select, self::Radio => "string('{$key}', 191)",
            self::Checkbox => "json('{$key}')",
            self::Date => "date('{$key}')",
            self::Datetime => "dateTime('{$key}')",
            self::Email, self::Url => "string('{$key}', 255)",
            self::Tel => "string('{$key}', 32)",
            self::Media => "unsignedBigInteger('{$key}')",
            self::Status => "string('{$key}', 20)",
        };

        // status は既定値を持つので nullable にしない（既存 news / works と同じ形）
        if ($this === self::Status) {
            return $expression."->default('draft')->index()";
        }

        if ($this === self::Boolean) {
            $expression .= '->default(false)';
        } elseif (! $field->required) {
            $expression .= '->nullable()';
        }

        if ($field->unique) {
            $expression .= '->unique()';
        } elseif ($field->indexed() && $this->supportsIndex()) {
            $expression .= '->index()';
        }

        return $expression;
    }

    /**
     * そのカラムに必要な外部キー制約（不要なら null）。
     *
     * **必須項目へ SET NULL を張らない。** 必須メディアのカラムは
     * NOT NULL なので、参照先が消えたときに NULL を書き戻すと
     * DB がその時点で壊れる。必須なら RESTRICT にして
     * 「使用中のメディアは消せない」側で守る（既存の削除保護と同じ既定）。
     */
    public function foreignKeyExpression(FieldDraft $field): ?string
    {
        if ($this !== self::Media) {
            return null;
        }

        $onDelete = $field->required ? 'restrictOnDelete()' : 'nullOnDelete()';

        return "foreign('{$field->key}')->references('id')->on('media')->{$onDelete}";
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * 管理画面へ渡す選択肢。
     *
     * @return list<array<string, mixed>>
     */
    public static function toFormOptions(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'needsChoices' => $type->needsChoices(),
            'supportsMax' => $type->supportsMax(),
            'defaultMax' => $type->defaultMax(),
            'maxLimit' => $type->maxLimit(),
            'supportsSearchable' => $type->supportsSearchable(),
            'supportsIndex' => $type->supportsIndex(),
        ], self::cases());
    }
}
