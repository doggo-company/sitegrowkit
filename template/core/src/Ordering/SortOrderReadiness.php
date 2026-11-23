<?php

declare(strict_types=1);

namespace AgentCms\Core\Ordering;

use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Throwable;

/**
 * その表が手動並べ替えに対応しているか（P1 ②）。
 *
 * ## なぜ判定が要るのか
 *
 * 更新は **code が先に入り、migration が後から当たる**。その間、
 * `sort_order` を持たない表が残る。一覧の読みは毎回この列で並べるので、
 * 判定を持たないと**その期間だけ全一覧が 500 になる**。
 *
 * ## ゴミ箱より弱く扱ってよい理由
 *
 * `TrashReadinessResolver` は「分からない」を通さない — 通すと
 * **捨てた投稿が公開へ出る**からである。こちらは分からないまま進んでも
 * **並びが今までのままになるだけ**で、出てはいけないものは 1 件も出ない。
 * だから read は落とさず、**書き（並べ替えそのもの）だけを断る**。
 *
 * ## 1 要求 1 回
 *
 * 表ごとに `hasColumn` を 1 回だけ投げる（要求のあいだ持つ）。
 * boot では読まない — route を組んでいる最中に DB を読むと、
 * DB が落ちているだけで全 URL が消える。
 */
final class SortOrderReadiness
{
    /** @var array<string, bool> 表ごとの判定。要求のあいだだけ持つ */
    private array $resolved = [];

    /** 検査を何回投げたか（「1 要求 1 回」を測るためだけに持つ） */
    private int $lookups = 0;

    public function __construct(private readonly SchemaBuilder $schema) {}

    public function supports(string $table): bool
    {
        return $this->resolved[$table] ??= $this->detect($table);
    }

    public function lookupCount(): int
    {
        return $this->lookups;
    }

    private function detect(string $table): bool
    {
        $this->lookups++;

        try {
            return $this->schema->hasColumn($table, SortOrderColumn::NAME);
        } catch (Throwable) {
            /*
             * **分からないときは「まだ無い」に倒す。**
             *
             * 倒した先で起きるのは「並びが今までのまま」だけである。
             * ここで例外を投げると、**DB が一時的に読めないだけで一覧が消える**。
             */
            return false;
        }
    }
}
