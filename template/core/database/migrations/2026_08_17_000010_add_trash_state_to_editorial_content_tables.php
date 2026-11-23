<?php

declare(strict_types=1);

use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 投稿にゴミ箱の列を足す（Phase 9.8 / STAGE 21B・SPEC §C.2・§C.4.3・OG1 = (b)）。
 *
 * ## なぜ表を名前で並べないのか
 *
 * 同梱の `data` 列の migration は対象を `['news', 'works']` と書き、
 * 「実行時のファイル内容に依存させない」と説明している。**あちらは正しい** —
 * 対象が kit に固定された 2 つだったからである。
 *
 * ここは違う。投稿タイプは**サイトごとに違う**。利用者が管理画面で作った型も、
 * 生成直後の `post` も、同じ形にならなければ「型によってゴミ箱がある / ない」が
 * 生まれる。固定の一覧では、その site に何があるか書きようがない。
 * だから **実行時に投稿タイプを走査する**（SPEC §C.4.3 の (ii)）。
 *
 * 再現性は別の方法で守る。この migration は**べき等**で、
 * 何度実行しても「すべての投稿タイプが同じ 3 列を持つ」へ収束する。
 * ファイルを 1 つも書き出さないので、読み取り専用の環境でも動く。
 *
 * ## 何を対象にしないか
 *
 * `media` / `contact` / `site`、分類、Field Group、投稿タイプの定義そのものは
 * ゴミ箱の対象ではない（SPEC §A）。判定は 9.7 の `EditorialCollections` に任せ、
 * ここで型名を並べない。
 *
 * ## down は fail-closed
 *
 * `deleted_at` を落とすと、**ゴミ箱にあった投稿が公開へ戻る**。
 * `lock_version` を落とすと競合検出の痕跡が消える。守るものが 1 件でもあれば
 * **1 列も落とさずに停止**する（SPEC §C.4.4）。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->editorialTables() as $table) {
            $missing = array_values(array_filter(
                TrashColumns::ALL,
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ));

            if ($missing === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                // 既にある列は足さない（途中まで進んだ環境でも再実行できる）
                if (in_array(TrashColumns::DELETED_AT, $missing, true)) {
                    $blueprint->timestamp(TrashColumns::DELETED_AT)->nullable()->index();
                }

                if (in_array(TrashColumns::TRASHED_BY, $missing, true)) {
                    $blueprint->unsignedBigInteger(TrashColumns::TRASHED_BY)->nullable();
                }

                if (in_array(TrashColumns::LOCK_VERSION, $missing, true)) {
                    $blueprint->unsignedBigInteger(TrashColumns::LOCK_VERSION)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        $tables = $this->editorialTables();

        /*
         * **先に全部調べてから、1 つも問題が無いときだけ落とす。**
         *
         * 表ごとに「調べて落とす」を繰り返すと、3 つ目で止まったときに
         * 1 つ目と 2 つ目の列だけが消えた状態が残る。それは rollback ではない。
         */
        $blockers = $this->blockers($tables);

        if ($blockers !== []) {
            throw new RuntimeException(
                "ゴミ箱の列を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n列を落とすとゴミ箱の投稿が公開へ戻り、競合検出の記録も失われます。".
                "\n戻す場合は、先にゴミ箱を空にし、版の記録を片付けてから再実行してください。".
                "\n（この migration は 1 列も変更していません）",
            );
        }

        foreach ($tables as $table) {
            $present = array_values(array_filter(
                TrashColumns::ALL,
                static fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($present === []) {
                continue;
            }

            /*
             * index を先に外す。MySQL は列を落とせば index も消えるが、
             * SQLite は「index が参照している列を落とした」と言って止まる。
             * 落とす順番を明示しておけば、どちらでも同じ結果になる。
             */
            foreach ($this->indexesOn($table, TrashColumns::DELETED_AT) as $index) {
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }

            Schema::table($table, function (Blueprint $blueprint) use ($present): void {
                $blueprint->dropColumn($present);
            });
        }
    }

    /**
     * 投稿タイプのテーブル（実在するものだけ）。
     *
     * schema は**ファイルだけ**を読む（`declared()`）。ここで overlay を通すと
     * DB を読むことになり、migration の途中で DB の状態に依存してしまう。
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

            // 型の定義はあるが migration がまだの型は、その型の migration が作る
            if (Schema::hasTable($schema->table)) {
                $tables[] = $schema->table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * その列に張られている index の名前。
     *
     * @return list<string>
     */
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

    /**
     * down を止めるもの（SPEC §C.4.4）。
     *
     * 版の表（`content_entry_revisions` / `content_schema_manifests`）は **STAGE 21D** で
     * 作られる。この Stage の時点では存在しないので、**在るときだけ**見る。
     * 「在れば必ず守る」は満たしつつ、まだ無い表を要求しない（SPEC §C.4.4 の注記）。
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function blockers(array $tables): array
    {
        $blockers = [];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, TrashColumns::DELETED_AT)) {
                $trashed = DB::table($table)->whereNotNull(TrashColumns::DELETED_AT)->count();

                if ($trashed > 0) {
                    $blockers[] = "{$table}: ゴミ箱の投稿が {$trashed} 件（列を落とすと公開へ戻ります）";
                }
            }

            if (Schema::hasColumn($table, TrashColumns::LOCK_VERSION)) {
                $locked = DB::table($table)->where(TrashColumns::LOCK_VERSION, '>', 0)->count();

                if ($locked > 0) {
                    $blockers[] = "{$table}: 競合検出が働いた投稿が {$locked} 件（lock_version > 0）";
                }
            }
        }

        foreach (['content_entry_revisions', 'content_schema_manifests'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();

            if ($rows > 0) {
                $blockers[] = "{$table}: {$rows} 行（投稿と版の対応が失われます）";
            }
        }

        return $blockers;
    }
};
