<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use Illuminate\Support\Str;

/**
 * Content Type Builder が受け取る 1 フィールドの下書き（Phase 9.4 / STAGE #17A）。
 *
 * **検証済みの値しか持たない**。`fromInput()` を通らずに構築できないので、
 * 生成器（Schema JSON / migration / model）は再検証しなくてよい。
 *
 * `label` / `help` は自由入力だが、行き先は **JSON だけ**である。
 * PHP コードにも SQL にも埋め込まないので、`json_encode` の
 * escape を超えて危険になる経路がない（管理画面は Vue が既定で escape する）。
 */
final readonly class FieldDraft
{
    /** label / help の長さ上限（表示が壊れる長さを永続化しない） */
    private const LABEL_MAX = 60;

    private const HELP_MAX = 200;

    /**
     * 受け付けるフィールドのキー。未知のキーは**黙って捨てずに**拒否する。
     *
     * @var list<string>
     */
    public const ACCEPTED_KEYS = [
        'key', 'type', 'label', 'required', 'max', 'help',
        'searchable', 'filterable', 'sortable', 'choices',
    ];

    /** 選択肢の個数と 1 件の長さ */
    private const CHOICES_MAX = 50;

    private const CHOICE_MAX_LENGTH = 60;

    /** @param  list<string>  $choices */
    private function __construct(
        public string $id,
        public string $key,
        public BuilderFieldType $type,
        public string $label,
        public bool $required,
        public ?int $max,
        public array $choices,
        public string $help,
        public bool $searchable,
        public bool $filterable,
        public bool $sortable,
        public bool $unique,
        public ?string $from,
    ) {}

    /**
     * 管理画面の入力 1 件 → 検証済み下書き。
     *
     * @param  array<string, list<string>>  $errors  `fields.<index>.<attr>` 形式で追記する
     */
    public static function fromInput(mixed $data, int $index, array &$errors): ?self
    {
        $at = "fields.{$index}";

        if (! is_array($data)) {
            $errors[$at][] = 'フィールドの指定が不正です';

            return null;
        }

        $unknown = array_values(array_diff(array_keys($data), self::ACCEPTED_KEYS));

        if ($unknown !== []) {
            $errors[$at][] = '受け付けられない項目が含まれています（'.implode('、', array_map(strval(...), $unknown)).'）';

            return null;
        }

        $key = $data['key'] ?? null;

        if (! is_string($key) || ! ContentTypeIdentifier::isValidFieldKey($key)) {
            $errors["{$at}.key"][] = 'フィールドキーは英小文字で始まり、英小文字・数字・アンダースコアだけを使う'
                .'2〜40 文字で指定してください（taxonomies / id / data などの予約語は使えません）';

            return null;
        }

        $type = is_string($data['type'] ?? null) ? BuilderFieldType::tryFrom($data['type']) : null;

        if ($type === null) {
            $errors["{$at}.type"][] = 'この管理画面から作成できるフィールド型ではありません';

            return null;
        }

        $label = self::text($data['label'] ?? null, self::LABEL_MAX);

        if ($label === null || $label === '') {
            $errors["{$at}.label"][] = '表示名は 1〜'.self::LABEL_MAX.' 文字で入力してください（改行・制御文字は使えません）';

            return null;
        }

        $help = self::text($data['help'] ?? null, self::HELP_MAX);

        if ($help === null) {
            $errors["{$at}.help"][] = '補足説明は '.self::HELP_MAX.' 文字以内で入力してください（改行・制御文字は使えません）';

            return null;
        }

        $required = self::flag($data['required'] ?? null);
        $choices = self::choices($type, $data['choices'] ?? null, $at, $errors);

        if ($choices === null) {
            return null;
        }

        $max = self::max($type, $data['max'] ?? null, $at, $errors);

        if ($max === false) {
            return null;
        }

        $searchable = self::flag($data['searchable'] ?? null) && $type->supportsSearchable();
        $filterable = self::flag($data['filterable'] ?? null) && $type->supportsIndex();
        $sortable = self::flag($data['sortable'] ?? null) && $type->supportsIndex();

        return new self(
            id: (string) Str::ulid(),
            key: $key,
            type: $type,
            label: $label,
            // status は既定値 draft を持つので、必須かどうかに関わらず常に値がある
            required: $type === BuilderFieldType::Status ? true : $required,
            max: $max,
            choices: $choices,
            help: $help,
            searchable: $searchable,
            filterable: $filterable,
            sortable: $sortable,
            // スラッグは URL の identity なので常に一意
            unique: $type === BuilderFieldType::Slug,
            from: null,
        );
    }

    /** slug の生成元フィールドを後から束ねる（title が決まってから解決するため） */
    public function withFrom(?string $from): self
    {
        return new self(
            $this->id, $this->key, $this->type, $this->label, $this->required, $this->max,
            $this->choices, $this->help, $this->searchable, $this->filterable, $this->sortable,
            $this->unique, $from,
        );
    }

    /**
     * 検索対象かどうかを確定させる。
     *
     * `admin.search` と `fields[].searchable` は同じことの 2 つの表現なので、
     * 集合が決まったあとに **必ず一致させる**（`ContentTypeDraft` が呼ぶ）。
     */
    public function withSearchable(bool $searchable): self
    {
        return new self(
            $this->id, $this->key, $this->type, $this->label, $this->required, $this->max,
            $this->choices, $this->help, $searchable, $this->filterable, $this->sortable,
            $this->unique, $this->from,
        );
    }

    /** 一覧の絞り込み / 並び替えに使う = index を張る */
    public function indexed(): bool
    {
        return $this->filterable || $this->sortable;
    }

    /**
     * `content/schemas/<id>.json` に書く表現。
     *
     * **`Schema\Field::fromArray()` が読める形と 1:1**。値のないキーは書かない
     * （生成物に意味のない `null` を残さない）。
     *
     * @return array<string, mixed>
     */
    public function toSchemaArray(): array
    {
        $field = [
            'id' => $this->id,
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
        ];

        if ($this->required) {
            $field['required'] = true;
        }

        if ($this->max !== null) {
            $field['max'] = $this->max;
        }

        if ($this->unique) {
            $field['unique'] = true;
        }

        if ($this->from !== null) {
            $field['from'] = $this->from;
        }

        if ($this->choices !== []) {
            $field['choices'] = $this->choices;
        }

        if ($this->help !== '') {
            $field['help'] = $this->help;
        }

        foreach (['searchable' => $this->searchable, 'filterable' => $this->filterable, 'sortable' => $this->sortable] as $flag => $value) {
            if ($value) {
                $field[$flag] = true;
            }
        }

        return $field;
    }

    /**
     * **採番された ID を含まない**、入力そのものの表現。
     *
     * 確認トークンはこれに束縛する。`id` は `fromInput()` のたびに
     * 新しい ULID になるため、Schema 配列を指紋にすると
     * Preview と Apply で必ず食い違う（同じ入力なのに確認が通らない）。
     *
     * @return array<string, mixed>
     */
    public function toSemanticArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
            'required' => $this->required,
            'max' => $this->max,
            'choices' => $this->choices,
            'help' => $this->help,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
        ];
    }

    /**
     * 確認画面へ出す 1 行
     *
     * @return array<string, mixed>
     */
    public function toPreviewArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'label' => $this->label,
            'required' => $this->required,
            'choices' => $this->choices,
            'searchable' => $this->searchable,
            'column' => $this->type->columnExpression($this),
        ];
    }

    /** 制御文字・改行を含まない自由入力。長すぎる / 型違いは null */
    private static function text(mixed $raw, int $max): ?string
    {
        if ($raw === null) {
            return '';
        }

        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        // 制御文字（NUL・改行・タブ含む）は表示にも保存にも要らない
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }

        // 不正な UTF-8 を JSON へ書かない
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return mb_strlen($value) > $max ? null : $value;
    }

    private static function flag(mixed $raw): bool
    {
        return $raw === true || $raw === 1 || $raw === '1' || $raw === 'true' || $raw === 'on';
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<string>|null
     */
    private static function choices(BuilderFieldType $type, mixed $raw, string $at, array &$errors): ?array
    {
        if (! $type->needsChoices()) {
            return [];
        }

        $list = is_array($raw) ? array_values($raw) : [];
        $choices = [];

        foreach ($list as $choice) {
            $value = self::text($choice, self::CHOICE_MAX_LENGTH);

            if ($value === null || $value === '') {
                continue;
            }

            $choices[] = $value;
        }

        $choices = array_values(array_unique($choices));

        if ($choices === []) {
            $errors["{$at}.choices"][] = "{$type->label()}には選択肢が 1 つ以上必要です";

            return null;
        }

        if (count($choices) > self::CHOICES_MAX) {
            $errors["{$at}.choices"][] = '選択肢は '.self::CHOICES_MAX.' 件以内で指定してください';

            return null;
        }

        return $choices;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return int|null|false false = 検証エラー
     */
    private static function max(BuilderFieldType $type, mixed $raw, string $at, array &$errors): int|null|false
    {
        if (! $type->supportsMax()) {
            return null;
        }

        if ($raw === null || $raw === '') {
            return $type->defaultMax();
        }

        if (! is_int($raw) && ! (is_string($raw) && preg_match('/\A[0-9]{1,5}\z/', $raw) === 1)) {
            $errors["{$at}.max"][] = '文字数上限は整数で指定してください';

            return false;
        }

        $max = (int) $raw;

        if ($max < 1 || $max > $type->maxLimit()) {
            $errors["{$at}.max"][] = "文字数上限は 1〜{$type->maxLimit()} の範囲で指定してください";

            return false;
        }

        return $max;
    }
}
