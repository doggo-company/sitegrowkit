<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

use AgentCms\Core\Ordering\SortOrderColumn;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Wxr\Status\WxrStatusColumn;
use InvalidArgumentException;

/**
 * 1 コンテンツタイプの Schema（immutable）。
 * 管理画面のフォーム・一覧・バリデーションはすべてここから導出される（Schema First）。
 */
final readonly class ContentSchema
{
    private const KINDS = ['collection', 'settings', 'submissions'];

    /** schema 構造の所有者（owner 決定 G7）。データの所有者ではない */
    public const MANAGED_BY_CODE = 'code';

    public const MANAGED_BY_CMS = 'cms';

    /**
     * 分類の payload / read response が使うキー（Phase 9.3B）。
     *
     * **このキーの単一情報源。** payload（`{"data": …, "taxonomies": …}`）も
     * read response（`item.taxonomies`）も予約 field key もここを参照する。
     */
    public const TAXONOMIES_KEY = 'taxonomies';

    /**
     * **トップレベル field key として使えない予約キー**（STAGE 3B CORRECTION / F-3B-1）。
     *
     * `taxonomies` は分類割り当て専用である。通常 field に同名を許すと:
     *
     * - Admin controller が「分類 payload」とみなして field 値を落とす
     * - read response で taxonomy block が field 値を上書きする
     * - taxonomy 宣言が無い schema でも field 値が空 list に置き換わる
     *
     * 同梱 schema を検査するだけでは利用者 project を守れないため、
     * **ContentSchema の contract 自体で拒否する**（値を黙って上書きしない）。
     *
     * **入れ子（group / repeater）の子 key は対象外。** 衝突するのは
     * トップレベルの表現だけであり、入れ子は `data` JSON の中で完結する。
     *
     * @var list<string>
     */
    public const RESERVED_FIELD_KEYS = [self::TAXONOMIES_KEY];

    /**
     * field として宣言してはいけない**列**の key。
     *
     * ここに挙げた列は CMS が管理していて、`ContentValues::split()` の
     * 書き込み対象に入らないことが前提になっている。field として宣言すると
     * **編集フォームの書き込み対象に変わり、人が投稿を編集するたびに消える**。
     *
     * 失うものは列ごとに違う。
     *
     * - `wp_status` … 取り込み元の公開状態。**出自が消える**（§C.8.3）
     * - `deleted_at` … ゴミ箱の状態。保存のたびに**入れ替わり、復元できなくなる**
     * - `trashed_by` … ゴミ箱へ入れた人。**誰が捨てたかが消える**
     * - `lock_version` … 楽観ロックの版（OG11）。**同時編集の検出が黙って効かなくなる**
     *   — 落ちないので、上書きが起きていることに誰も気づかない
     *
     * `RESERVED_FIELD_KEYS` とは**断る理由が違う**ので分けてある。
     * あちらは「分類の割り当て用に予約」で、直す先は taxonomies への書き換えである。
     * こちらは「CMS が管理する列」で、直す先は別の名前を選ぶことである。
     *
     * @var list<string>
     */
    public const RESERVED_COLUMN_KEYS = [WxrStatusColumn::NAME, SortOrderColumn::NAME, ...TrashColumns::ALL];

    /** 参照先テーブル名（省略時は id。MediaUsageInspector 等が使用） */
    public readonly string $table;

    /**
     * @param  list<Field>  $fields
     * @param  list<string>  $adminList
     * @param  list<string>  $searchable  **面を問わず**「何で探せるか」（P1 ④）
     * @param  list<TaxonomyDeclaration>  $taxonomies  **fields とは独立した構造**（§J.1）
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public array $fields,
        public array $adminList = [],
        public array $searchable = [],
        ?string $table = null,
        /**
         * `code` … リポジトリのファイルが SSoT。構造変更は code workflow で行う
         * `cms`  … 将来の Admin Content Type Builder から変更できる（Phase 9.4）
         * どちらでも **エントリの編集は Admin / MCP から通常どおり可能**。
         */
        public string $managedBy = self::MANAGED_BY_CODE,
        public array $taxonomies = [],
    ) {
        $this->table = $table ?? $id;
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown schema kind [{$kind}] for [{$id}]");
        }

        $keys = array_map(fn (Field $f) => $f->key, $fields);
        if ($keys !== array_unique($keys)) {
            throw new InvalidArgumentException("Schema [{$id}] has duplicate field keys");
        }

        // 予約キーは **トップレベル field だけ**を見る（入れ子の子 key は許す）
        foreach (array_intersect($keys, self::RESERVED_FIELD_KEYS) as $reserved) {
            throw new InvalidArgumentException(
                "Schema [{$id}]: field key [{$reserved}] は分類の割り当て用に予約されています。"
                .'通常のフィールドには使用できません（別の名前へ変更してください）。'
                .'分類を宣言する場合は fields ではなく taxonomies に書きます。',
            );
        }

        foreach (array_intersect($keys, self::RESERVED_COLUMN_KEYS) as $reserved) {
            throw new InvalidArgumentException(
                "Schema [{$id}]: field key [{$reserved}] は CMS が管理する列の名前です。"
                .'field として宣言すると編集フォームの書き込み対象になり、'
                .'保存のたびにその列の値が失われます（別の名前へ変更してください）。',
            );
        }

        // 同一 ContentSchema 内で taxonomy key が重複してはならない
        $taxonomyKeys = array_map(fn (TaxonomyDeclaration $t) => $t->key, $taxonomies);
        if ($taxonomyKeys !== array_unique($taxonomyKeys)) {
            throw new InvalidArgumentException("Schema [{$id}] has duplicate taxonomy keys");
        }

        foreach ([...$adminList, ...$searchable] as $key) {
            if (! in_array($key, $keys, true)) {
                throw new InvalidArgumentException("Schema [{$id}] admin config references unknown field [{$key}]");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? throw new InvalidArgumentException('Schema is missing "id"'));

        return new self(
            id: $id,
            label: (string) ($data['label'] ?? $id),
            kind: (string) ($data['kind'] ?? 'collection'),
            fields: array_map(Field::fromArray(...), array_values($data['fields'] ?? [])),
            adminList: array_values($data['admin']['list'] ?? []),
            /*
             * **「何で探せるか」は面ごとの都合ではない。**
             *
             * 元は `admin.search` にしか無かったが、公開側でも同じ列を探す
             * （P1 ④）。名前が `admin` のままだと**実態と合わない**ので、
             * **`search` を直下へ出した**。
             *
             * `admin.search` は**後方互換で読み続ける** — 既に配った schema が
             * それで書かれている。**直下が在ればそちらが勝つ。**
             */
            searchable: array_values($data['search'] ?? $data['admin']['search'] ?? []),
            table: isset($data['table']) ? (string) $data['table'] : null,
            managedBy: in_array($data['managedBy'] ?? null, [self::MANAGED_BY_CODE, self::MANAGED_BY_CMS], true)
                ? (string) $data['managedBy']
                : self::MANAGED_BY_CODE,
            taxonomies: self::parseTaxonomies($data, $id),
        );
    }

    /**
     * `taxonomies` の読み取り。
     *
     * **「キーが無い」と「キーはあるが null」を同一視しない**（STAGE 3A CORRECTION）。
     *
     * | 入力 | 扱い |
     * | --- | --- |
     * | `taxonomies` キー自体が無い | 有効。**空 list** |
     * | `taxonomies: null` | **無効**。`null` に「削除」「無効化」「全消去」のいずれの意味も与えない |
     * | `taxonomies: [...]` | **JSON list のみ**許可 |
     * | associative object / 文字列 / 数値 / boolean | 無効 |
     *
     * associative object を配列として受け入れない
     * （`{"0": {...}}` のような形を黙って通すと、順序と重複検査が壊れる）。
     *
     * @param  array<string, mixed>  $data
     * @return list<TaxonomyDeclaration>
     */
    private static function parseTaxonomies(array $data, string $id): array
    {
        // キー自体が無い場合だけが「宣言なし」
        if (! array_key_exists('taxonomies', $data)) {
            return [];
        }

        $raw = $data['taxonomies'];

        // null もここで弾く。**空 list へ変換しない**
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException(
                "Schema [{$id}]: taxonomies は配列で指定してください"
                .'（未指定にする場合はキーごと省略してください。null は使えません）',
            );
        }

        return array_map(
            static fn (mixed $entry): TaxonomyDeclaration => TaxonomyDeclaration::fromArray(
                $entry,
                "Schema [{$id}]",
            ),
            $raw,
        );
    }

    public function field(string $key): Field
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        throw new InvalidArgumentException("Schema [{$this->id}] has no field [{$key}]");
    }

    public function hasField(string $key): bool
    {
        return in_array($key, $this->fieldKeys(), true);
    }

    /** 宣言されている taxonomy を key で引く */
    public function taxonomy(string $key): TaxonomyDeclaration
    {
        foreach ($this->taxonomies as $taxonomy) {
            if ($taxonomy->key === $key) {
                return $taxonomy;
            }
        }

        throw new InvalidArgumentException("Schema [{$this->id}] has no taxonomy [{$key}]");
    }

    public function hasTaxonomy(string $key): bool
    {
        return in_array($key, $this->taxonomyKeys(), true);
    }

    /** @return list<string> */
    public function taxonomyKeys(): array
    {
        return array_map(fn (TaxonomyDeclaration $t) => $t->key, $this->taxonomies);
    }

    /** @return list<string> */
    public function fieldKeys(): array
    {
        return array_map(fn (Field $f) => $f->key, $this->fields);
    }

    /**
     * カラムに保存されるフィールド（Phase 9.2B / G2 = Hybrid）。
     * `SchemaTableParityTest` はこれだけをカラムと突き合わせる。
     *
     * @return list<Field>
     */
    public function columnFields(): array
    {
        return array_values(array_filter($this->fields, fn (Field $f) => $f->isColumnBacked()));
    }

    /**
     * `data` JSON へ保存されるフィールド（group / repeater / images / relations）。
     *
     * @return list<Field>
     */
    public function jsonFields(): array
    {
        return array_values(array_filter($this->fields, fn (Field $f) => ! $f->isColumnBacked()));
    }

    /** data JSON カラムが必要か */
    public function needsDataColumn(): bool
    {
        return $this->jsonFields() !== [];
    }

    /**
     * immutable field ID から探す（rename されても同じフィールドだと分かる）。
     * 入れ子（group / repeater）の中も辿る。
     */
    public function fieldById(string $id): ?Field
    {
        return self::searchById($this->fields, $id);
    }

    /** @param list<Field> $fields */
    private static function searchById(array $fields, string $id): ?Field
    {
        foreach ($fields as $field) {
            if ($field->id === $id) {
                return $field;
            }

            $nested = self::searchById($field->fields, $id);

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * ドット記法のパスでフィールドを辿る（例 `specs.weight` / `features.heading`）。
     * PageDocument binding が入れ子を参照するために使う。
     */
    public function fieldByPath(string $path): ?Field
    {
        $segments = explode('.', $path);
        $fields = $this->fields;
        $found = null;

        foreach ($segments as $segment) {
            $found = null;

            foreach ($fields as $field) {
                if ($field->key === $segment) {
                    $found = $field;
                    break;
                }
            }

            if ($found === null) {
                return null;
            }

            $fields = $found->fields;
        }

        return $found;
    }

    /** @return array<string, string> Eloquent casts */
    public function casts(): array
    {
        $casts = [];
        foreach ($this->fields as $field) {
            if ($cast = $field->cast()) {
                $casts[$field->key] = $cast;
            }
        }

        return $casts;
    }

    /**
     * 管理画面へ渡す表現（Inertia props）
     *
     * @return array<string, mixed>
     */
    public function toAdminSpec(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind,
            'fields' => array_map(fn (Field $f) => $f->toFormSpec(), $this->fields),
            // fields とは**別のキー**で返す。宣言（key / multiple / required）だけで、
            // taxonomy ID や term 一覧は混ぜない（runtime 解決は Resolver の責務 — §J.3）。
            // 宣言が無い schema でも決定的に空 list を返す
            'taxonomies' => array_map(fn (TaxonomyDeclaration $t) => $t->toArray(), $this->taxonomies),
            'list' => $this->adminList,
            'search' => $this->searchable,
            'titleKey' => $this->titleKey(),
            'managedBy' => $this->managedBy,
        ];
    }

    /**
     * 一覧で「その行を代表する」列。管理画面はこの列を編集画面へのリンクにする
     * （Phase 8.5-F / F-UX2）。News・Works 等を hardcode せず schema から決める。
     *
     * text 型の先頭列を優先し、無ければ一覧の先頭列を使う。
     */
    public function titleKey(): ?string
    {
        foreach ($this->adminList as $key) {
            foreach ($this->fields as $field) {
                if ($field->key === $key && $field->type === 'text') {
                    return $key;
                }
            }
        }

        return $this->adminList[0] ?? null;
    }
}
