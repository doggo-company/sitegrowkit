<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * content/schemas/*.json を読み込む Schema レジストリ（Single Source of Truth）。
 * 管理画面・バリデーション・モデル設定はここを経由してのみ Schema を参照する。
 *
 * Project Scope Override（ADR-0010 Rev.1）:
 * content/schemas/overrides/<id>.json が存在する場合、フィールド属性を上書きマージする。
 * kit 標準 schema（Core 配布物）を書き換えずに、プロジェクト固有の要件
 * （例: import 元デザインに存在しない category を optional 化）を表現するための仕組み。
 *
 * Field Group Overlay（Phase 9.5-A / ADR-0015）:
 * `SchemaFieldOverlay` を渡すと、ファイル宣言の**後ろに項目を足す**ことができる。
 * overlay が空（または未指定）なら、出力は 1 バイトも変わらない。
 *
 * ## 2 つの入口
 *
 * | メソッド | 中身 | 使う場所 |
 * | --- | --- | --- |
 * | `declared()` | ファイル宣言だけ。**DB を読まない** | route 登録 / モデル登録 |
 * | `all()` / `get()` / `has()` | overlay 込みの effective schema | 画面 / 検証 / API |
 *
 * overlay は **schema id を増やさない**ので、「どんな Content Type があるか」の
 * 答えは両者で同じである。分けているのは、MySQL が落ちているときに
 * route 登録が例外を投げて `/health` まで巻き込むのを防ぐため（ADR-0015）。
 */
final class SchemaRegistry
{
    /** overlay 込みの effective schema */
    /** @var array<string, ContentSchema>|null */
    private ?array $schemas = null;

    /** ファイル宣言だけの schema */
    /** @var array<string, ContentSchema>|null */
    private ?array $declared = null;

    /** ファイル宣言の生データ（base + overrides） */
    /** @var array<string, array<string, mixed>>|null */
    private ?array $raw = null;

    public function __construct(
        private readonly string $schemaPath,
        private readonly ?SchemaFieldOverlay $overlay = null,
        private readonly ?SchemaTaxonomyOverlay $taxonomyOverlay = null,
    ) {}

    public function get(string $id): ContentSchema
    {
        $schemas = $this->all();

        return $schemas[$id] ?? throw new InvalidArgumentException(
            "Content schema [{$id}] not found in {$this->schemaPath}. Available: ".implode(', ', array_keys($schemas)),
        );
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * overlay 込みの effective schema。
     *
     * @return array<string, ContentSchema>
     */
    public function all(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        $raw = $this->rawDeclarations();

        if ($this->overlay !== null) {
            $raw = $this->applyOverlay($raw);
        }

        if ($this->taxonomyOverlay !== null) {
            $raw = $this->applyTaxonomyOverlay($raw);
        }

        return $this->schemas = $this->build($raw);
    }

    /**
     * **ファイル宣言だけ**の schema（DB を読まない）。
     *
     * route 登録・モデル登録のように「boot 中に DB へ触れてはいけない」場面専用。
     * overlay は Content Type を増やさないので、型の集合は `all()` と同じである。
     *
     * @return array<string, ContentSchema>
     */
    public function declared(): array
    {
        return $this->declared ??= $this->build($this->rawDeclarations());
    }

    /**
     * ファイル宣言に overlay の項目を足す。
     *
     * | 規則 | 内容 |
     * | --- | --- |
     * | 追加のみ | overlay は key を足すことしかできない |
     * | ファイル優先 | 同じ key があれば**ファイル側をそのまま残す**（overlay 側を捨てる） |
     * | 順序 | ファイルの順序を保ち、**その後ろに**足す |
     * | 型は増えない | 既に宣言されている schema にしか重ねない |
     *
     * @param  array<string, array<string, mixed>>  $raw
     * @return array<string, array<string, mixed>>
     */
    private function applyOverlay(array $raw): array
    {
        foreach ($raw as $id => $data) {
            $additions = $this->overlay?->fieldsFor($id) ?? [];

            if ($additions === []) {
                continue;
            }

            $taken = [];
            foreach ($data['fields'] ?? [] as $field) {
                if (is_array($field)) {
                    $taken[(string) ($field['key'] ?? '')] = true;
                }
            }

            foreach ($additions as $field) {
                $key = (string) ($field['key'] ?? '');

                // key が無い / 既にファイル側にある宣言は**足さない**。
                // 上書きでも削除でもなく、単に無かったことにする
                if ($key === '' || isset($taken[$key])) {
                    continue;
                }

                $taken[$key] = true;
                $raw[$id]['fields'][] = $field;
            }
        }

        return $raw;
    }

    /**
     * ファイル宣言に overlay の **分類の関連付け**を足す。
     *
     * 規則は field の overlay と同じ（追加のみ / ファイル優先 / 順序はファイルの後ろ）。
     * 同じ key がファイル側にあるときに**上書きしない**のが要点である。
     * ファイルの `multiple` / `required` を DB から変えられるようにすると、
     * git を見ても実行時の制約が分からなくなる。
     *
     * @param  array<string, array<string, mixed>>  $raw
     * @return array<string, array<string, mixed>>
     */
    private function applyTaxonomyOverlay(array $raw): array
    {
        foreach ($raw as $id => $data) {
            $additions = $this->taxonomyOverlay?->taxonomiesFor($id) ?? [];

            if ($additions === []) {
                continue;
            }

            $taken = [];
            foreach ($data['taxonomies'] ?? [] as $declaration) {
                if (is_array($declaration)) {
                    $taken[(string) ($declaration['key'] ?? '')] = true;
                }
            }

            foreach ($additions as $declaration) {
                $key = (string) ($declaration['key'] ?? '');

                if ($key === '' || isset($taken[$key])) {
                    continue;
                }

                $taken[$key] = true;
                $raw[$id]['taxonomies'][] = $declaration;
            }
        }

        return $raw;
    }

    /**
     * @param  array<string, array<string, mixed>>  $raw
     * @return array<string, ContentSchema>
     */
    private function build(array $raw): array
    {
        $schemas = [];

        foreach ($raw as $id => $data) {
            $schemas[$id] = ContentSchema::fromArray($data);
        }

        return $schemas;
    }

    /**
     * ファイル宣言（base + overrides）を読む。
     *
     * @return array<string, array<string, mixed>>
     */
    private function rawDeclarations(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        if (! is_dir($this->schemaPath)) {
            throw new RuntimeException("Schema directory not found: {$this->schemaPath}");
        }

        $raw = [];
        foreach (glob($this->schemaPath.'/*.json') ?: [] as $file) {
            $data = $this->decode($file);
            $id = (string) ($data['id'] ?? '');
            if ($id === '') {
                throw new RuntimeException("Schema file {$file} is missing \"id\"");
            }
            if (isset($raw[$id])) {
                throw new RuntimeException("Duplicate schema id [{$id}] in {$file}");
            }
            $raw[$id] = $data;
        }

        // Project Scope Override: overrides/<id>.json のフィールド属性をマージする
        foreach (glob($this->schemaPath.'/overrides/*.json') ?: [] as $file) {
            $override = $this->decode($file);
            $id = (string) ($override['id'] ?? pathinfo($file, PATHINFO_FILENAME));
            if (! isset($raw[$id])) {
                throw new RuntimeException("Schema override {$file} targets unknown schema [{$id}]");
            }
            $rawPatches = $override['fields'] ?? [];

            // **JSON の配列を黙って無視しない。** `"fields": []` は
            // 連想配列と同じ空配列になるため、object かどうかを元の JSON で確かめる
            $this->assertOverrideFieldsIsObject($file);

            if (! is_array($rawPatches)) {
                throw new RuntimeException("Schema override {$file}: fields はオブジェクトで指定してください");
            }

            /** @var array<string, array<string, mixed>> $fieldPatches */
            $fieldPatches = $rawPatches;
            foreach ($fieldPatches as $key => $patch) {
                $found = false;
                foreach ($raw[$id]['fields'] ?? [] as $i => $fieldData) {
                    if (($fieldData['key'] ?? null) === $key) {
                        $raw[$id]['fields'][$i] = array_merge($fieldData, $patch);
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw new RuntimeException("Schema override {$file} targets unknown field [{$key}] in schema [{$id}]");
                }
            }

            if (array_key_exists('taxonomies', $override)) {
                // **「キーが無い」と「キーはあるが null」を区別する**。
                // `?? []` では両者を同一視してしまい、base 側の null を空 list へ
                // 黙って変換してしまう（STAGE 3A CORRECTION）
                $raw[$id]['taxonomies'] = $this->mergeTaxonomies(
                    array_key_exists('taxonomies', $raw[$id]) ? $raw[$id]['taxonomies'] : [],
                    $override['taxonomies'],
                    $file,
                    $id,
                );
            }
        }

        return $this->raw = $raw;
    }

    /**
     * taxonomy 宣言の override 統合（Phase 9.3B / STAGE 3A）。
     *
     * **key を識別子にする。** field patch と違い、配列 index で重ねない
     * （`array_replace_recursive` は宣言の順序が変わるだけで別 taxonomy を壊す）。
     *
     * | 規則 |
     * | --- |
     * | base を出発点にする |
     * | override の宣言を **key 単位で追加または置換**する |
     * | override に無い base 宣言は**維持**する |
     * | 同じ key があれば **override の完全な宣言で置換**する（部分マージしない） |
     * | override から新しい key を追加できる |
     * | override 側にも `key` / `multiple` / `required` の完全な 3 項目を要求する |
     * | **削除する構文は V1 では作らない**（null / remove / deleted / disabled 等は未承認） |
     * | base 内・override 内の重複はどちらも拒否する |
     *
     * 順序は「base の順 → override で追加された分」で決定的になる。
     *
     * **base と override はそれぞれ単独で完全検証してから統合する**
     * （STAGE 3A CORRECTION）。統合後にだけ検証すると、
     * 「base の不正宣言を同じ key の正常な override が上書きして隠す」経路ができる。
     * effective schema が正常でも、**base / override のどちらかが不正なら FAIL** させる。
     *
     * @return list<array<string, mixed>>
     */
    private function mergeTaxonomies(mixed $base, mixed $override, string $file, string $id): array
    {
        // 統合**前**に、それぞれを単独で完全検証する
        $baseList = $this->validatedDeclarations($base, "Schema [{$id}]");
        $overrideList = $this->validatedDeclarations($override, "Schema override {$file}");

        $merged = [];

        foreach ($baseList as $declaration) {
            $merged[$declaration->key] = $declaration;
        }

        foreach ($overrideList as $declaration) {
            // 同じ key は **完全な宣言で置換**する（部分マージしない）
            $merged[$declaration->key] = $declaration;
        }

        return array_values(array_map(
            static fn (TaxonomyDeclaration $d): array => $d->toArray(),
            $merged,
        ));
    }

    /**
     * taxonomies を **1 件ずつ完全検証**して list を作る。
     *
     * 型・未知キー・boolean・key 文法の検証は `TaxonomyDeclaration::fromArray()` に委ねる
     * （検証規則を 2 箇所へ書かないため）。ここでは **形と重複**を見る。
     *
     * `null` は **空 list へ変換しない**。`taxonomies: null` に
     * 「削除」「無効化」「全消去」のいずれの意味も承認されていないため、
     * no-op として受理せず FAIL させる。
     *
     * @return list<TaxonomyDeclaration>
     */
    private function validatedDeclarations(mixed $raw, string $context): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new RuntimeException(
                "{$context}: taxonomies は配列で指定してください"
                .'（未指定にする場合はキーごと省略してください。null は使えません）',
            );
        }

        $keys = [];
        $list = [];

        foreach ($raw as $entry) {
            // 統合前に厳格な検証を通す
            $declaration = TaxonomyDeclaration::fromArray($entry, $context);

            if (isset($keys[$declaration->key])) {
                throw new RuntimeException("{$context}: taxonomy key [{$declaration->key}] が重複しています");
            }

            $keys[$declaration->key] = true;
            $list[] = $declaration;
        }

        return $list;
    }

    /** @return array<string, mixed> */
    private function decode(string $file): array
    {
        $raw = (string) file_get_contents($file);

        try {
            $data = (array) json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in schema file {$file}: {$e->getMessage()}", previous: $e);
        }

        $this->assertTaxonomiesIsArray($raw, $file);

        return $data;
    }

    /**
     * override の `fields` が JSON の **オブジェクト**であることを確かめる。
     *
     * `json_decode($raw, true)` は `[]` も `{}` も同じ空配列にする。
     * 配列で書かれた `fields` は「上書きするものが無い」と解釈されて
     * **黙って無視される**ので、書いた人は override が効いていると誤解する。
     */
    private function assertOverrideFieldsIsObject(string $file): void
    {
        try {
            $object = json_decode((string) file_get_contents($file), false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return; // JSON 自体の問題は decode() が報告している
        }

        if (! $object instanceof \stdClass || ! property_exists($object, 'fields')) {
            return;
        }

        if (! $object->fields instanceof \stdClass) {
            throw new RuntimeException(
                "Schema override {$file}: fields はオブジェクトで指定してください"
                .'（配列で書くと上書きが無視されます）',
            );
        }
    }

    /**
     * `taxonomies` が JSON の **配列**であることを、連想配列へ変換する前に確かめる。
     *
     * `json_decode($raw, true)` は `{}` を `[]` にする。つまり
     * **オブジェクトで書かれた `taxonomies` が空の配列として通ってしまう**。
     * 「宣言が無い」と「オブジェクトで書かれている」は別のことなので、
     * ここで区別する（Template Import 側は配列でないものを拒否しており、
     * 片方だけ受理すると「CMS は起動しないのに import は通る」がおきる）。
     */
    private function assertTaxonomiesIsArray(string $raw, string $file): void
    {
        try {
            // 連想配列化しないで読むと、object は stdClass のまま残る
            $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return; // 形式の問題は上の decode が既に報告している
        }

        if (! $object instanceof \stdClass || ! property_exists($object, 'taxonomies')) {
            return;
        }

        if ($object->taxonomies !== null && ! is_array($object->taxonomies)) {
            throw new RuntimeException(
                "Schema file {$file}: taxonomies は配列で指定してください"
                .'（未指定にする場合はキーごと省略してください）',
            );
        }
    }
}
