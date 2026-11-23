<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

/**
 * ファイル宣言の上に **項目を足すだけ**の口（Phase 9.5-A / ADR-0015 G1）。
 *
 * `SchemaRegistry` が `overrides/*.json` を重ねるのと同じ場所に、もう 1 段だけ足す。
 *
 * ## この契約でできないこと（意図的）
 *
 * | できないこと | 理由 |
 * | --- | --- |
 * | 項目を**削除**する | 削除を表す構文を作らない。ファイル側の定義は DB から消せない |
 * | 既存 key の**型や属性を変える** | 同じ key はファイルが勝つ（`SchemaRegistry` が捨てる） |
 * | **Content Type を増やす** | 返すのは field 宣言だけ。schema id の集合は overlay で変わらない |
 *
 * 3 つ目が効く。型の集合が変わらないので、route 登録とモデル登録は
 * overlay を読まなくてよい（= boot 中に DB へ触れない — ADR-0015）。
 */
interface SchemaFieldOverlay
{
    /**
     * 指定 Content Type へ **追加する** field 宣言。
     *
     * 返すのは `content/schemas/*.json` の `fields[]` と同じ形の生の配列で、
     * `Field::fromArray()` を通ることが検証済みであること。
     * 該当が無ければ空配列を返す（**その場合、既存の出力は 1 バイトも変わらない**）。
     *
     * @return list<array<string, mixed>>
     */
    public function fieldsFor(string $schemaId): array;
}
