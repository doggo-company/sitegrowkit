<?php

declare(strict_types=1);

use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Ordering\SortOrderColumn;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 投稿に掲載順の列を足す（P1 ②）。
 *
 * ## 表を名前で並べない
 *
 * 投稿タイプは**サイトごとに違う**。管理画面で作った型も同じ形にならなければ、
 * 「型によって並べ替えられる / られない」が生まれる。
 * だから**実行時に投稿タイプを走査する**（ゴミ箱の列の migration と同じ作法）。
 *
 * この migration は**べき等**で、何度実行しても
 * 「すべての投稿タイプが `sort_order` を持つ」へ収束する。
 *
 * ## 足しただけでは並びは変わらない
 *
 * 既定は 0 で、全行が同じ値になる。読みの第二キー（公開日の新しい順）が
 * そのまま効くので、**当てた瞬間に既存サイトの並びが変わることはない**。
 *
 * ## down は fail-closed
 *
 * `sort_order` を落とすと、**手で決めた掲載順が丸ごと消える**。
 * 元に戻す手段は無い（版にも backup にも「並び」は入っていない）。
 * 並べた形跡が 1 つでもあれば **1 列も落とさずに停止**する。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->editorialTables() as $table) {
            if (Schema::hasColumn($table, SortOrderColumn::NAME)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                SortOrderColumn::define($blueprint);
            });
        }
    }

    public function down(): void
    {
        $tables = $this->editorialTables();
        $blockers = [];

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, SortOrderColumn::NAME)) {
                continue;
            }

            $arranged = DB::table($table)->where(SortOrderColumn::NAME, '>', 0)->count();

            if ($arranged > 0) {
                $blockers[] = "{$table}: 手で並べた投稿が {$arranged} 件";
            }
        }

        /*
         * **先に全部調べてから、1 つも問題が無いときだけ落とす。**
         * 表ごとに「調べて落とす」を繰り返すと、途中で止まったときに
         * 一部の表だけ列が消えた状態が残る。それは rollback ではない。
         */
        if ($blockers !== []) {
            throw new RuntimeException(
                "掲載順の列を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n列を落とすと、手で決めた掲載順が丸ごと消えます（版にも backup にも並びは入っていません）。".
                "\n（この migration は 1 列も変更していません）",
            );
        }

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, SortOrderColumn::NAME)) {
                continue;
            }

            /*
             * index を先に外す。MySQL は列を落とせば index も消えるが、
             * SQLite は「index が参照している列を落とした」と言って止まる。
             */
            foreach ($this->indexesOn($table, SortOrderColumn::NAME) as $index) {
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(SortOrderColumn::NAME);
            });
        }
    }

    /**
     * 投稿タイプのテーブル（実在するものだけ）。
     *
     * schema は**ファイルだけ**を読む（`declared()`）。overlay を通すと DB を読むことになり、
     * migration の途中で DB の状態に依存してしまう。
     *
     * @return list<string>
     */
    private function editorialTables(): array
    {
        $tables = [];

        foreach (app(SchemaRegistry::class)->declared() as $schema) {
            if (! EditorialCollections::isEditorial($schema)) {
                continue;
            }

            if (Schema::hasTable($schema->table)) {
                $tables[] = $schema->table;
            }
        }

        return array_values(array_unique($tables));
    }

    /** @return list<string> */
    private function indexesOn(string $table, string $column): array
    {
        $names = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (in_array($column, $index['columns'] ?? [], true) && ! ($index['primary'] ?? false)) {
                $names[] = (string) $index['name'];
            }
        }

        return $names;
    }
};
