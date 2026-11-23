<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

/**
 * ファイル宣言の上に **分類の関連付けを足すだけ**の口（Phase 9.7 / STAGE 20D・SPEC §H.3）。
 *
 * `SchemaFieldOverlay` と同じ形にしてある。違うのは足すものが
 * `fields[]` ではなく `taxonomies[]` だという点だけである。
 *
 * ## この契約でできないこと（意図的）
 *
 * | できないこと | 理由 |
 * | --- | --- |
 * | 関連付けを**外す** | ファイル宣言は DB から消せない。外すのは DB で足した分だけ |
 * | 既存 key の **multiple / required を変える** | 同じ key はファイルが勝つ（`SchemaRegistry` が捨てる） |
 * | **Content Type を増やす** | 返すのは宣言だけ。schema id の集合は overlay で変わらない |
 *
 * 3 つ目が効く。型の集合が変わらないので、route 登録とモデル登録は
 * overlay を読まなくてよい（= boot 中に DB へ触れない）。
 */
interface SchemaTaxonomyOverlay
{
    /**
     * 指定 Content Type へ **追加する** taxonomy 宣言。
     *
     * 返すのは `content/schemas/*.json` の `taxonomies[]` と同じ形
     * （`{key, multiple, required}`）で、`TaxonomyDeclaration` を通ることが検証済みであること。
     * 該当が無ければ空配列を返す（**その場合、既存の出力は 1 バイトも変わらない**）。
     *
     * @return list<array<string, mixed>>
     */
    public function taxonomiesFor(string $schemaId): array;
}
