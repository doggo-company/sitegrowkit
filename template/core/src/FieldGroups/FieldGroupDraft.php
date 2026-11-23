<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\FieldStorage;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TaxonomyKey;
use Illuminate\Support\Str;
use InvalidArgumentException;
use TypeError;
use ValueError;

/**
 * Field Group の入力検証と正規化（Phase 9.5-B / ADR-0016）。
 *
 * **受理キーの判定・型の検証・拒否をここに集約する。** Controller / MCP / API が
 * 先回りしてキーを拾い直すと「送ったのに黙って捨てられた」が起きる。
 *
 * ## 受理するキー
 *
 * | キー | 内容 |
 * | --- | --- |
 * | `key` | 機械名。`^[a-z][a-z0-9_]*$` / 64 文字以内 / 束の中で一意 |
 * | `label` | 表示名。必須・100 文字以内 |
 * | `locations` | 割当先の list。`{kind, value}` のみ |
 * | `fields` | 項目宣言の list。読み込みと**同じ** `FieldShape` を通す |
 *
 * `status` / `sortOrder` / `id` は受け取らない。状態は archive / restore が、
 * 並び順は reorder が、識別子はサーバーが決める。
 *
 * ## 型の allowlist（9.5-B の範囲）
 *
 * 管理画面の入力 UI が描ける型だけを選ばせる。無い型を選べると
 * 「作れたのに値を入れられない」になる（`SchemaFieldInput.vue` — F-3）。
 * 9.5-C で `group` / `repeater` / `relation` / `relations` / `images` を開けた。
 * **`file` は入れない**（ADR-0017。Media 基盤が画像専用で一般ファイルを扱えない）。
 * `slug` / `status` は投稿タイプ固有の意味（一意性・公開状態）を持つので除く。
 */
final class FieldGroupDraft
{
    public const ALLOWED_KEYS = ['key', 'label', 'locations', 'fields'];

    public const MACHINE_NAME = '/^[a-z][a-z0-9_]*$/';

    /**
     * 割当先が**フォーム**のときの value（R-8A）。
     *
     * 指すのは `forms.key` そのもので、そちらは `/^[a-z][a-z0-9_-]{1,63}$/` —
     * **ハイフンが使える**。こちらだけ許さないと `contact-us` のようなフォームを
     * 指せない。**先に在る事実（フォームの key）に、後から来た制約のほうを合わせる。**
     *
     * 投稿タイプ側（`content_type`）は 1 バイトも変えない。あちらが指すのは schema の id で、
     * そちらにハイフンは無い。
     */
    public const FORM_TARGET_NAME = '/^[a-z][a-z0-9_-]*$/';

    public const KEY_MAX = 64;

    public const LABEL_MAX = 100;

    /**
     * 9.5-B で選べる型。
     *
     * @var list<string>
     */
    public const ALLOWED_TYPES = [
        'text', 'textarea', 'richtext', 'number', 'url', 'email', 'tel',
        'date', 'datetime', 'boolean',
        'select', 'radio', 'checkbox',
        'image', 'media',
        // Phase 9.5-D（ADR-0018）で `file` を開けた。Media が種別を持つようになり、
        // 一般ファイルを private disk に置いて controller 経由でだけ配れるようになったため。
        // multipart ではなく **Media の ID を選ぶ**ので、入れ子でも保存できる
        'file',
        'group', 'repeater', 'relation', 'relations', 'images',
    ];

    /** 子を必ず持つ型 */
    public const NESTED_TYPES = ['group', 'repeater'];

    /** 参照先の指定が要る型 */
    public const REFERENCE_TYPES = ['relation', 'relations'];

    /**
     * **トップレベルにしか置けない型**（Phase 9.5-C / ADR-0017）。
     *
     * `image` は値がアップロードそのもの（`<key>_file`）で、投稿フォームは
     * トップレベルのキーしか回収しない。入れ子に置くと入力欄は出るのに
     * **選んだファイルが保存されない** — 押せるものを出して嘘をつくことになる。
     * 入れ子で画像を持ちたい場合は、Media を参照する `media` を使う。
     *
     * @var list<string>
     */
    public const TOP_LEVEL_ONLY_TYPES = ['image'];

    /**
     * 公開フォームへ割り当てる束で**選べない**型。
     *
     * 公開フォームは常に外部入力なので、XSS 面を増やす型を持ち込ませない
     * （owner 決定 G9-(b)。定義は共用するが、実行の強さは分ける）。
     *
     * @var list<string>
     */
    /**
     * 公開フォームで使える種類（R-12 / 受付の一本化の実測で見つけた食い違い）。
     *
     * 🔴 **正本は `FormFields::PUBLIC_TYPES` の 1 つだけ。**
     * ここに別の一覧を書くと、**選べるのに公開できない種類**が生まれる。
     * 実際そうなっていた（2026-08-30 実測）: 種類の一覧は 20 種を出すのに、
     * 公開の門は 12 種しか通さない。編集担当者が「ファイル」を選んで保存でき、
     * **公開しようとして初めて断られる**（しかも理由が画面に出ていなかった）。
     */
    public const FORM_ALLOWED_TYPES = FormFields::PUBLIC_TYPES;

    /**
     * 公開フォームでは選ばせない種類。
     *
     * **一覧を持たない。**「使えるもの」から引いて作るので、
     * 種類が増えても片方だけ古くなることがない。
     *
     * @return list<string>
     */
    public static function formForbiddenTypes(): array
    {
        return array_values(array_diff(self::ALLOWED_TYPES, self::FORM_ALLOWED_TYPES));
    }

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * 既に使った既存 field ID。
     *
     * **1 つの ID は 1 回しか使えない。** 同じ ID を 2 つの項目へ付けられると、
     * `SchemaMutationClassifier` が identity で突き合わせるときに片方が
     * もう片方を上書きし、**削除や改名を「変更なし」に見せられる**。
     * トップレベルと入れ子をまたいで数える。
     *
     * @var array<string, true>
     */
    private array $usedFieldIds = [];

    public string $key = '';

    /** 通し番号の接頭辞（`-` はキーに使えないので `_`） */
    private const NUMBERED_GROUP_PREFIX = 'field_group_';

    private const NUMBERED_FIELD_PREFIX = 'field_';

    public string $label = '';

    /** @var list<array{kind: string, value: string}> */
    public array $locations = [];

    /** @var list<array<string, mixed>> 保存する宣言（id 採番済み） */
    public array $fields = [];

    /** 自動で埋めた項目キー（入れ子をまたいで重ならないように控える） */
    /** @var list<string> */
    private array $fieldKeysInUse = [];

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $currentFieldIds  更新時に既に存在する field id => key
     */
    public function __construct(
        array $input,
        private readonly SchemaRegistry $schemas,
        private readonly FieldGroupRepository $repository,
        private readonly ContentTypeResolver $types,
        private readonly ?string $ignoreGroupId = null,
        array $currentFieldIds = [],
    ) {
        $this->rejectUnknownKeys($input);

        $this->label = $this->text($input['label'] ?? null, 'label', '表示名', self::LABEL_MAX);

        /*
         * **名前だけで作れるようにする**（WP 一周 10 周目 2026-09-04 / 効くもの ③）。
         *
         * 6 周目に分類へ入れた手当て（`CreateTaxonomyDefinition`）が、
         * ACF 相当のこちらには来ていなかった。表示名に「診療メニュー」、
         * 項目に「料金」とだけ入れて作成を押すと、**束と項目の両方**で止まっていた。
         * WordPress から来た人が最初に触る場所である。
         *
         * 空のときだけ埋める。**自分で書いたキーには触らない** —
         * 形が違うときも黙って直さない（直すと「書いたものと違うキーで作られた」になる）。
         */
        $this->key = $this->machineNameOrDerived(
            $input['key'] ?? null,
            'key',
            'キー',
            $this->label,
            fn (string $candidate): bool => $this->groupKeyTaken($candidate),
            self::NUMBERED_GROUP_PREFIX,
        );
        $this->locations = $this->readLocations($input['locations'] ?? []);
        $this->fields = $this->readFields($input['fields'] ?? [], $currentFieldIds);

        $this->assertKeyIsFree();
        $this->assertFieldKeysAreFree();
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 危険度分類・検証に使う Field。
     *
     * **保存先は overlay が付けるものと同じ `json` にする。** ここを揃えないと、
     * 読み込んだ既存の項目（json）と入力の項目（型から導出 = column）で
     * 保存先が違うと判定され、無害な変更まで「移行が必要」になる。
     *
     * @return list<Field>
     */
    public function fieldObjects(): array
    {
        return array_map(
            static fn (array $declaration): Field => Field::fromArray(
                [...$declaration, 'storage' => FieldStorage::Json->value],
            ),
            $this->fields,
        );
    }

    /** 危険度分類へ渡すための、この束だけの schema 表現 */
    public function asSchema(): ContentSchema
    {
        return new ContentSchema(
            id: 'field_group.'.($this->key === '' ? 'draft' : $this->key),
            label: $this->label,
            kind: 'collection',
            fields: $this->fieldObjects(),
        );
    }

    /** @param  array<string, mixed>  $input */
    private function rejectUnknownKeys(array $input): void
    {
        $unknown = array_diff(array_keys($input), self::ALLOWED_KEYS);

        foreach ($unknown as $key) {
            $this->error((string) $key, "[{$key}] は指定できません");
        }
    }

    /** 割当先がフォームのときの value（ハイフンを許す。理由は `FORM_TARGET_NAME`） */
    private function formTarget(mixed $value, string $field): string
    {
        $text = $this->text($value, $field, '割当先', self::KEY_MAX);

        if ($text !== '' && preg_match(self::FORM_TARGET_NAME, $text) !== 1) {
            $this->error($field, '割当先は英小文字で始まり、英小文字・数字・アンダースコア・ハイフンだけが使えます');

            return '';
        }

        return $text;
    }

    /**
     * 空なら表示名から作る。書いてあればそのまま検証する。
     *
     * @param  callable(string): bool  $taken
     */
    private function machineNameOrDerived(
        mixed $value,
        string $field,
        string $label,
        string $source,
        callable $taken,
        string $prefix,
    ): string {
        $written = is_string($value) ? trim($value) : '';

        if ($written !== '' || $source === '') {
            // 書いてあるもの・名前も無いものは、今までどおり検証する
            return $this->machineName($value, $field, $label);
        }

        return $this->freeKey($source, $prefix, $taken);
    }

    /**
     * 表示名から、まだ使われていないキーを作る。
     *
     * 英字の名前は名前どおりに。日本語だけの名前からは作れないので通し番号へ倒す
     * （分類と同じ形 — `CreateTaxonomyDefinition::deriveKey()`）。
     *
     * @param  callable(string): bool  $taken
     */
    private function freeKey(string $source, string $prefix, callable $taken): string
    {
        $fromLabel = TaxonomyKey::suggestFrom($source);

        if ($fromLabel !== '' && ! $taken($fromLabel)) {
            return $fromLabel;
        }

        for ($n = 1; $n <= 1000; $n++) {
            $candidate = $prefix.$n;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // ここまで来たら形の検証で弾かれる（推測で作らない）
        return '';
    }

    /**
     * 項目のキーが空なら、その項目の表示名から作って差し込む。
     *
     * 書いてあるものには触らない（形が違うときも黙って直さない）。
     * 表示名も無ければ触らない — 今までどおり「項目のキーを入力してください」で止める。
     *
     * @param  array<string, bool>  $seen  同じ階層で既に使われた key
     */
    private function withDerivedFieldKey(mixed $entry, array $seen): mixed
    {
        if (! is_array($entry) || array_is_list($entry)) {
            return $entry;
        }

        $written = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';

        if ($written !== '') {
            return $entry;
        }

        $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';

        if ($label === '') {
            return $entry;
        }

        $entry['key'] = $this->freeKey(
            $label,
            self::NUMBERED_FIELD_PREFIX,
            function (string $candidate) use ($seen): bool {
                if (isset($seen[$candidate])) {
                    return true;
                }

                foreach ($this->fieldKeysInUse as $used) {
                    if ($used === $candidate) {
                        return true;
                    }
                }

                return false;
            },
        );

        $this->fieldKeysInUse[] = $entry['key'];

        return $entry;
    }

    /** 既にある束が使っているキーか（作成でも更新でも、自分自身は除く） */
    private function groupKeyTaken(string $key): bool
    {
        foreach ($this->repository->all() as $group) {
            if ($group->key === $key && $group->id !== $this->ignoreGroupId) {
                return true;
            }
        }

        return false;
    }

    private function machineName(mixed $value, string $field, string $label): string
    {
        $text = $this->text($value, $field, $label, self::KEY_MAX);

        if ($text !== '' && preg_match(self::MACHINE_NAME, $text) !== 1) {
            $this->error($field, "{$label}は英小文字で始まり、英小文字・数字・アンダースコアだけが使えます");

            return '';
        }

        return $text;
    }

    private function text(mixed $value, string $field, string $label, int $max): string
    {
        if (! is_string($value)) {
            $this->error($field, "{$label}を入力してください");

            return '';
        }

        $text = trim($value);

        if ($text === '') {
            $this->error($field, "{$label}を入力してください");

            return '';
        }

        if (mb_strlen($text) > $max) {
            $this->error($field, "{$label}は{$max}文字以内で入力してください");

            return '';
        }

        return $text;
    }

    /**
     * @return list<array{kind: string, value: string}>
     */
    private function readLocations(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->error('locations', '割当先は配列で指定してください');

            return [];
        }

        $locations = [];
        $seen = [];

        foreach ($raw as $index => $entry) {
            $path = "locations.{$index}";

            if (! is_array($entry) || array_is_list($entry)) {
                $this->error($path, '割当先の形が正しくありません');

                continue;
            }

            $unknown = array_diff(array_keys($entry), ['kind', 'value']);

            foreach ($unknown as $key) {
                $this->error($path, "[{$key}] は指定できません");
            }

            $kind = is_string($entry['kind'] ?? null) ? FieldGroupLocationKind::tryFrom($entry['kind']) : null;

            if ($kind === null) {
                // 未対応の kind（page_template を含む）は受け取らない。
                // 予約値であって実行値ではない（owner 決定 G11-(a)）
                $this->error($path.'.kind', '割当先の種類が正しくありません');

                continue;
            }

            $value = $kind === FieldGroupLocationKind::Form
                ? $this->formTarget($entry['value'] ?? null, $path.'.value')
                : $this->machineName($entry['value'] ?? null, $path.'.value', '割当先');

            if ($value === '') {
                continue;
            }

            if ($kind === FieldGroupLocationKind::ContentType && ! isset($this->schemas->declared()[$value])) {
                $this->error($path.'.value', "投稿タイプ [{$value}] は存在しません");

                continue;
            }

            $signature = $kind->value.':'.$value;

            if (isset($seen[$signature])) {
                $this->error($path, '同じ割当先が重複しています');

                continue;
            }

            $seen[$signature] = true;
            $locations[] = ['kind' => $kind->value, 'value' => $value];
        }

        return $locations;
    }

    /**
     * @param  array<string, mixed>  $currentFieldIds
     * @return list<array<string, mixed>>
     */
    private function readFields(mixed $raw, array $currentFieldIds): array
    {
        $hasForm = false;

        foreach ($this->locations as $location) {
            $hasForm = $hasForm || $location['kind'] === FieldGroupLocationKind::Form->value;
        }

        $fields = $this->readFieldList($raw, 'fields', $currentFieldIds, $hasForm, 1);

        // 総数は **入れ子を含めて**数える（Phase 9.5-C / ADR-0017）
        $total = FieldGroupLimits::countFields($fields);

        if ($total > FieldGroupLimits::MAX_FIELDS) {
            $this->error('fields', FieldGroupLimits::fieldCountMessage($total));
        }

        return $fields;
    }

    /**
     * 1 階層分の宣言を読む。
     *
     * **入れ子も同じ関数で辿る。** トップレベルだけ厳しくして子を素通りさせると、
     * crafted request で allowlist も予約 key も回避できてしまう（ADR-0017）。
     *
     * @param  array<string, mixed>  $currentFieldIds
     * @return list<array<string, mixed>>
     */
    private function readFieldList(
        mixed $raw,
        string $prefix,
        array $currentFieldIds,
        bool $hasForm,
        int $depth,
    ): array {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->error($prefix, '項目は配列で指定してください');

            return [];
        }

        if ($raw === []) {
            $this->error($prefix, '項目を 1 つ以上追加してください');

            return [];
        }

        if ($depth > FieldGroupLimits::MAX_DEPTH) {
            $this->error($prefix, FieldGroupLimits::depthMessage($prefix));

            return [];
        }

        $fields = [];
        $seen = [];

        foreach ($raw as $index => $entry) {
            $path = "{$prefix}.{$index}";
            $declaration = $this->readField($entry, $path, $currentFieldIds, $hasForm, $depth, $seen);

            if ($declaration === null) {
                continue;
            }

            $seen[(string) $declaration['key']] = true;
            $fields[] = $declaration;
        }

        return $fields;
    }

    /**
     * 1 件の宣言を読む。読めなければ null（理由は `$errors` へ積む）。
     *
     * @param  array<string, mixed>  $currentFieldIds
     * @param  array<string, bool>  $seen  同じ階層で既に使われた key
     * @return array<string, mixed>|null
     */
    private function readField(
        mixed $entry,
        string $path,
        array $currentFieldIds,
        bool $hasForm,
        int $depth,
        array $seen,
    ): ?array {
        /*
         * **キーを先に埋める**（WP 一周 10 周目 2026-09-04 / 効くもの ③）。
         *
         * `FieldShape::assert()` は `key` が在ることを求める — 保存する宣言の契約なので
         * 緩めない。埋めるのはその**手前**である。こうすれば「読み込みと同じ検証を通す」
         * という約束を壊さずに、名前だけで作れるようになる。
         */
        $entry = $this->withDerivedFieldKey($entry, $seen);

        // 読み込みと **同じ** 検証を通す（書けたのに読めない、を作らない）
        try {
            FieldShape::assert($entry, $path, $depth);
        } catch (InvalidArgumentException $e) {
            $this->error($path, $e->getMessage());

            return null;
        }

        /** @var array<string, mixed> $entry */
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            $this->error($path.'.type', "この項目の種類はまだ選べません（{$type}）");

            return null;
        }

        if ($hasForm && ! in_array($type, self::FORM_ALLOWED_TYPES, true)) {
            $this->error(
                $path.'.type',
                'この種類は公開フォームでは使えません（選べる種類だけを一覧に出しています）',
            );

            return null;
        }

        if ($depth > 1 && in_array($type, self::TOP_LEVEL_ONLY_TYPES, true)) {
            $this->error(
                $path.'.type',
                'この項目の種類は入れ子の中では使えません（画像を入れ子で持つ場合はメディアを選んでください）',
            );

            return null;
        }

        $key = $this->machineName($entry['key'] ?? null, $path.'.key', '項目のキー');

        if ($key === '') {
            return null;
        }

        if (in_array($key, ContentSchema::RESERVED_FIELD_KEYS, true)) {
            $this->error($path.'.key', "[{$key}] は分類の割り当て用に予約されています");

            return null;
        }

        if (isset($seen[$key])) {
            $this->error($path.'.key', "項目のキー [{$key}] が重複しています");

            return null;
        }

        $id = $this->fieldId($entry, $currentFieldIds, $path);

        if ($id === null) {
            return null;
        }

        if (in_array($type, self::REFERENCE_TYPES, true) && ! $this->assertTarget($entry, $path)) {
            return null;
        }

        $declaration = [...$entry, 'id' => $id];

        // 子は **同じ関数**で辿る。深さは 1 段深く数える
        if (in_array($type, self::NESTED_TYPES, true)) {
            $declaration['fields'] = $this->readFieldList(
                $entry['fields'] ?? [],
                $path.'.fields',
                $currentFieldIds,
                $hasForm,
                $depth + 1,
            );

            if ($declaration['fields'] === []) {
                return null;
            }
        }

        try {
            $declaration = FieldGroupLimits::withRepeaterBounds($declaration, $path);
        } catch (InvalidArgumentException $e) {
            $this->error($path.'.max', $e->getMessage());

            return null;
        }

        // **意味**の検証は Field::fromArray に委ねる（規則を 2 か所に書かない）
        try {
            // 保存先は overlay が付けるものと同じにして、書けたのに読めないを作らない
            Field::fromArray([...$declaration, 'storage' => FieldStorage::Json->value]);
        } catch (InvalidArgumentException|TypeError|ValueError $e) {
            $this->error($path, $e->getMessage());

            return null;
        }

        return $declaration;
    }

    /**
     * `relation` / `relations` の参照先。
     *
     * **投稿タイプ名を書き写さない。** 実際に CRUD できる collection かどうかは
     * `ContentTypeResolver` が決める（画面・API・MCP と同じ母集団）。
     *
     * @param  array<string, mixed>  $entry
     */
    private function assertTarget(array $entry, string $path): bool
    {
        $target = $entry['target'] ?? null;

        if (! is_string($target) || $target === '') {
            $this->error($path.'.target', '参照先の投稿タイプを選んでください');

            return false;
        }

        if (! in_array($target, $this->referenceTargets(), true)) {
            $this->error($path.'.target', "投稿タイプ [{$target}] は参照先に選べません");

            return false;
        }

        return true;
    }

    /**
     * 参照先に選べる投稿タイプ。
     *
     * @return list<string>
     */
    public function referenceTargets(): array
    {
        $targets = [];

        foreach ($this->types->availableTypes() as $id) {
            if ($this->types->schema($id)->kind === 'collection') {
                $targets[] = $id;
            }
        }

        return $targets;
    }

    /**
     * 項目の immutable ID。
     *
     * 既存の ID は **そのまま保つ**（rename しても binding が切れない — owner 決定 G10-(b)）。
     * 未知の ID を持ち込ませない（他の束の項目を横取りできてしまう）。
     * 入れ子の子にも同じ規則を当てる。
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $currentFieldIds
     */
    private function fieldId(array $entry, array $currentFieldIds, string $path): ?string
    {
        $id = $entry['id'] ?? null;

        if ($id === null || $id === '') {
            /*
             * 🔴 **ID を持たない既存の項目は、キーで同じ項目だと分かる**（UX 一周 2026-09-28）。
             *
             * 同梱のお問い合わせフォームの項目は ID 無しで入っている。画面は ID の無い行に
             * ID を付けて送らないので、ここで毎回新しい ULID を振ると、比べる側
             * （`SchemaMutationClassifier`、`Field::identity()` = ID か、無ければキー）が
             * **全項目を「消して足した」と読み**、選択肢を 1 つ変えただけで
             * 「この項目は画面に出なくなります」が 5 件並ぶ確認が出た。
             * キーをそのまま ID にすれば、identity は保存の前後で変わらない。
             */
            $key = is_string($entry['key'] ?? null) ? $entry['key'] : '';

            if ($key !== '' && ($currentFieldIds[$key] ?? null) === $key && ! isset($this->usedFieldIds[$key])) {
                $this->usedFieldIds[$key] = true;

                return $key;
            }

            return (string) Str::ulid();
        }

        if (! is_string($id) || ! isset($currentFieldIds[$id])) {
            $this->error($path.'.id', 'この項目は編集できません');

            return null;
        }

        if (isset($this->usedFieldIds[$id])) {
            $this->error($path.'.id', 'この項目の識別子が重複しています');

            return null;
        }

        $this->usedFieldIds[$id] = true;

        return $id;
    }

    /** key は全 Field Group で一意（DB の unique と同じ規則を先に伝える） */
    private function assertKeyIsFree(): void
    {
        if ($this->key === '') {
            return;
        }

        foreach ($this->repository->all() as $group) {
            if ($group->key === $this->key && $group->id !== $this->ignoreGroupId) {
                $this->error('key', "キー [{$this->key}] は既に使われています");

                return;
            }
        }
    }

    /**
     * 項目のキーが割当先で衝突していないか。
     *
     * - **ファイル宣言と同じ key は拒否**する。読み込み時はファイルが勝って
     *   overlay 側が捨てられるので、作れてしまうと「作ったのに出ない」になる
     * - **他の束と同じ key も拒否**する。先に読まれたほうだけが効くため
     */
    private function assertFieldKeysAreFree(): void
    {
        $keys = [];

        foreach ($this->fields as $field) {
            $keys[] = (string) ($field['key'] ?? '');
        }

        if ($keys === []) {
            return;
        }

        foreach ($this->locations as $location) {
            if ($location['kind'] !== FieldGroupLocationKind::ContentType->value) {
                continue;
            }

            $schema = $this->schemas->declared()[$location['value']] ?? null;

            if ($schema instanceof ContentSchema) {
                foreach (array_intersect($keys, $schema->fieldKeys()) as $conflict) {
                    $this->error('fields', "[{$conflict}] は投稿タイプ [{$location['value']}] に既にある項目です");
                }
            }

            foreach ($this->repository->all() as $group) {
                if ($group->id === $this->ignoreGroupId || ! $group->contributesFields()) {
                    continue;
                }

                $targets = false;

                foreach ($group->locations as $other) {
                    $targets = $targets || $other->matchesContentType($location['value']);
                }

                if (! $targets) {
                    continue;
                }

                $otherKeys = array_map(static fn (Field $f): string => $f->key, $group->fields);

                foreach (array_intersect($keys, $otherKeys) as $conflict) {
                    $this->error('fields', "[{$conflict}] は別の束「{$group->label}」が既に使っています");
                }
            }
        }
    }

    private function error(string $key, string $message): void
    {
        if (! in_array($message, $this->errors[$key] ?? [], true)) {
            $this->errors[$key][] = $message;
        }
    }
}
