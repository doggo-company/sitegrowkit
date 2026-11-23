<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

/**
 * 一覧取得に **ページングより前**でかける絞り込み（Phase 9.3B / #22）。
 *
 * ## なぜ必要か
 *
 * 絞り込みを PHP 側でやると、順番が「取ってから絞る」になる。
 * 「新着 3 件」に分類の条件を足したとき、新しい 3 件がどれも別の分類だと
 * **該当する記事があるのに 0 件**になる。件数を増やして読んでも、
 * 母集団が上限を超えた瞬間また取りこぼす。
 *
 * 意味として正しいのは **母集団 → 絞り込み → 並び替え → 件数で切る**。
 * それを成立させるには、条件を SQL 側へ渡すしかない。
 *
 * ## 何を渡さないか
 *
 * **テーブル名もクラス名も条件式も渡さない。** 渡すのは
 *
 * - `termGroups`: term ID の並びの並び（外側 AND / 内側 OR）
 * - `publishedOnly`: 公開済みだけに限るか
 *
 * だけ。参照先のテーブルは `TaxonomyTermAssignment` 固定、値はすべて束縛変数で渡る。
 * 利用者の入力が SQL の構造を決めることはない。
 *
 * ## 誰が使うか
 *
 * 公開ページの描画（`BindingResolver`）だけ。API / MCP / Admin は渡さないので、
 * **既存の応答は 1 バイトも変わらない**。
 */
final readonly class ContentQueryRestriction
{
    /**
     * @param  list<list<string>>  $termGroups  外側が AND、内側が OR の term ID
     * @param  bool  $publishedOnly  status を持つ型だけに効く（持たない型は素通し）
     */
    public function __construct(
        public array $termGroups = [],
        public bool $publishedOnly = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->termGroups === [] && ! $this->publishedOnly;
    }
}
