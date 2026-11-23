<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

/**
 * 「これから作る Content Type」の検証済み下書き（Phase 9.4 / STAGE #17A）。
 *
 * DB にもファイルにも一切触らない **純粋な入力検証**である。
 * 衝突検査（既存 schema / 既存テーブル / route）は `ContentTypePlanner` の担当で、
 * ここは「そもそも受け付けてよい形か」だけを見る。
 *
 * 標準フィールド（title / slug / body / status / published_at）は
 * 管理画面が **初期提案**として送ってくるだけで、ここでは強制しない
 * （利用者は作成前に足し引きできる — SPEC §1B）。
 */
final readonly class ContentTypeDraft
{
    /**
     * 受け付けるトップレベルのキー。
     *
     * **未知のキーは黙って捨てず、エラーにする。** 送ったのに何も起きない状態は、
     * 利用者にも AI にも「効いた」と誤解させる（既存の Application Service と同じ方針）。
     *
     * @var list<string>
     */
    public const ACCEPTED_KEYS = ['id', 'label', 'fields', 'list', 'search'];

    /** 1 つの Content Type が持てるフィールド数 */
    private const FIELDS_MAX = 60;

    private const LABEL_MAX = 40;

    /**
     * 1 行あたりの推定バイト数の上限。
     *
     * MySQL の実上限は 65,535 だが、`id` / `timestamps` / 内部オーバーヘッド分の
     * 余白を残す。**実測ではなく決定的な見積り**なので、環境で結果が変わらない。
     */
    private const ROW_BYTES_BUDGET = 60000;

    /** @param  list<FieldDraft>  $fields */
    private function __construct(
        public ContentTypeIdentifier $identifier,
        public string $label,
        public array $fields,
        /** @var list<string> */
        public array $adminList,
        /** @var list<string> */
        public array $adminSearch,
    ) {}

    /**
     * 管理画面 / CLI の入力 → 検証済み下書き。
     *
     * @param  array<string, mixed>  $input
     * @return array{draft: self|null, errors: array<string, list<string>>}
     */
    public static function fromInput(array $input): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        $unknown = array_values(array_diff(array_keys($input), self::ACCEPTED_KEYS));

        if ($unknown !== []) {
            $errors['id'][] = '受け付けられない項目が含まれています（'.implode('、', $unknown).'）';

            return ['draft' => null, 'errors' => $errors];
        }

        $idErrors = [];
        $identifier = ContentTypeIdentifier::tryFrom($input['id'] ?? null, $idErrors);

        if ($idErrors !== []) {
            $errors['id'] = $idErrors;
        }

        $label = self::label($input['label'] ?? null);

        if ($label === null) {
            $errors['label'][] = '表示名は 1〜'.self::LABEL_MAX.' 文字で入力してください（改行・制御文字は使えません）';
        }

        $fields = self::fields($input['fields'] ?? null, $errors);

        if ($identifier === null || $label === null || $fields === null) {
            return ['draft' => null, 'errors' => $errors];
        }

        $keys = array_map(static fn (FieldDraft $f): string => $f->key, $fields);

        $adminList = self::columnSelection($input['list'] ?? null, $keys, 'list', $errors);
        $adminSearch = self::columnSelection($input['search'] ?? null, $keys, 'search', $errors);

        // 一覧列が空だと管理画面が「何も表示しない一覧」になる。先頭フィールドで補う
        if ($adminList === []) {
            $adminList = [$fields[0]->key];
        }

        /*
         * 検索対象は **1 つの集合として確定させる**。
         *
         * 入力には 2 つの経路がある（`search[]` と field の `searchable`）。
         * どちらか一方を黙って捨てると「チェックしたのに効かない」になり、
         * 片方だけ残すと schema の `admin.search` と `fields[].searchable` が
         * 食い違う。**和集合を取り、両方の表現を必ず一致させる**。
         */
        $adminSearch = array_values(array_unique([
            ...$adminSearch,
            ...array_map(
                static fn (FieldDraft $f): string => $f->key,
                array_values(array_filter($fields, static fn (FieldDraft $f): bool => $f->searchable)),
            ),
        ]));

        // 検索対象は searchable な型に限る（LIKE を張れない列を検索対象にしない）
        $searchable = array_map(
            static fn (FieldDraft $f): string => $f->key,
            array_values(array_filter($fields, static fn (FieldDraft $f): bool => $f->type->supportsSearchable())),
        );

        $rejected = array_values(array_diff($adminSearch, $searchable));

        if ($rejected !== []) {
            $errors['search'][] = '検索対象にできないフィールドが含まれています（'.implode('、', $rejected).'）';
        }

        self::assertRowFits($fields, $errors);

        if ($errors !== []) {
            return ['draft' => null, 'errors' => $errors];
        }

        $fields = array_map(
            static fn (FieldDraft $f): FieldDraft => $f->withSearchable(in_array($f->key, $adminSearch, true)),
            self::linkSlugSources($fields),
        );

        return [
            'draft' => new self($identifier, $label, $fields, $adminList, $adminSearch),
            'errors' => [],
        ];
    }

    /**
     * フィールドキー（プレビュー / 監査用）
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return array_map(static fn (FieldDraft $f): string => $f->key, $this->fields);
    }

    /** media を参照するフィールドがあるか（外部キーの要否） */
    public function referencesMedia(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->type === BuilderFieldType::Media) {
                return true;
            }
        }

        return false;
    }

    /**
     * 入力の同一性を表す指紋（確認トークンの束縛先）。
     *
     * **採番された field ID を含めない。** `FieldDraft` は生成のたびに
     * 新しい ULID を採るので、Schema 配列を指紋にすると
     * 「同じ内容を確認して適用した」だけで token が一致しなくなる。
     * ここが見るのは利用者が実際に入力した意味だけである。
     */
    public function semanticFingerprint(): string
    {
        return hash('sha256', json_encode([
            'id' => $this->identifier->value,
            'label' => $this->label,
            'fields' => array_map(static fn (FieldDraft $f): array => $f->toSemanticArray(), $this->fields),
            'list' => $this->adminList,
            'search' => $this->adminSearch,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * `content/schemas/<id>.json` に書く配列。
     *
     * `managedBy: "cms"` は **既存キー**（`ContentSchema::MANAGED_BY_CMS`）であり、
     * STAGE #17A で永続化フォーマットを増やしてはいない。
     *
     * @return array<string, mixed>
     */
    public function toSchemaArray(): array
    {
        return [
            'id' => $this->identifier->value,
            'label' => $this->label,
            'kind' => 'collection',
            'table' => $this->identifier->table(),
            'managedBy' => 'cms',
            'fields' => array_map(static fn (FieldDraft $f): array => $f->toSchemaArray(), $this->fields),
            'admin' => [
                'list' => $this->adminList,
                'search' => $this->adminSearch,
            ],
        ];
    }

    private static function label(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        if ($value === '' || mb_strlen($value) > self::LABEL_MAX) {
            return null;
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1 || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<FieldDraft>|null
     */
    private static function fields(mixed $raw, array &$errors): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            $errors['fields'][] = 'フィールドを 1 つ以上指定してください';

            return null;
        }

        if (count($raw) > self::FIELDS_MAX) {
            $errors['fields'][] = 'フィールドは '.self::FIELDS_MAX.' 件以内で指定してください';

            return null;
        }

        $fields = [];
        $seen = [];
        $failed = false;

        foreach ($raw as $index => $data) {
            $field = FieldDraft::fromInput($data, $index, $errors);

            if ($field === null) {
                $failed = true;

                continue;
            }

            if (isset($seen[$field->key])) {
                $errors["fields.{$index}.key"][] = "フィールドキー [{$field->key}] が重複しています";
                $failed = true;

                continue;
            }

            $seen[$field->key] = true;
            $fields[] = $field;
        }

        return $failed ? null : $fields;
    }

    /**
     * 一覧列 / 検索対象の選択。
     *
     * **存在しないキーを黙って捨てない。** フィールドキーを直したあとに
     * 古い選択が残っていると、利用者が意図した一覧と実際が食い違う。
     * 落とすのではなく検証エラーとして返し、画面で直してもらう。
     *
     * @param  list<string>  $keys
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private static function columnSelection(mixed $raw, array $keys, string $at, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors[$at][] = '選択内容の指定が不正です';

            return [];
        }

        $selected = [];
        $unknown = [];

        foreach ($raw as $value) {
            if (! is_string($value) || ! in_array($value, $keys, true)) {
                $unknown[] = is_string($value) ? $value : '(不正な値)';

                continue;
            }

            if (! in_array($value, $selected, true)) {
                $selected[] = $value;
            }
        }

        if ($unknown !== []) {
            $errors[$at][] = '存在しないフィールドが選ばれています（'
                .implode('、', array_values(array_unique($unknown))).'）';
        }

        return $selected;
    }

    /**
     * MySQL の行サイズ上限（65,535 バイト）に収まるか。
     *
     * `utf8mb4` では `varchar(255)` が 1KB を超えるので、
     * フィールドを増やしただけで CREATE TABLE が落ちうる。
     * **テーブルを作ろうとして初めて失敗する**のを避けるため、
     * 入力検証の段階で合算して落とす。
     *
     * @param  list<FieldDraft>  $fields
     * @param  array<string, list<string>>  $errors
     */
    private static function assertRowFits(array $fields, array &$errors): void
    {
        $bytes = 0;

        foreach ($fields as $field) {
            $bytes += $field->type->estimatedRowBytes($field);
        }

        if ($bytes > self::ROW_BYTES_BUDGET) {
            $errors['fields'][] = '項目の合計が大きすぎます（1 行あたりの推定 '
                .number_format($bytes).' バイトが上限 '.number_format(self::ROW_BYTES_BUDGET)
                .' バイトを超えています）。文字数上限を下げるか、長文は複数行テキストにしてください。';
        }
    }

    /**
     * slug の生成元を決める。
     *
     * 先頭の text フィールドを `from` にする（既存 news / works と同じ規約）。
     * text が無ければ `from` を書かない（管理画面が手入力を促す）。
     *
     * @param  list<FieldDraft>  $fields
     * @return list<FieldDraft>
     */
    private static function linkSlugSources(array $fields): array
    {
        $source = null;

        foreach ($fields as $field) {
            if ($field->type === BuilderFieldType::Text) {
                $source = $field->key;
                break;
            }
        }

        return array_map(
            static fn (FieldDraft $f): FieldDraft => $f->type === BuilderFieldType::Slug
                ? $f->withFrom($source)
                : $f,
            $fields,
        );
    }
}
