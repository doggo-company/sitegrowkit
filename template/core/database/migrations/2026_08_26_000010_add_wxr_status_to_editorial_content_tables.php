<?php

declare(strict_types=1);

use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Wxr\Status\WxrStatusColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 取り込み元の公開状態を残す列を足す（Phase 9.14 / STAGE 27C・SPEC §C.8.3）。
 *
 * ## なぜ投稿の表に足すのか
 *
 * WordPress は 8 つ以上の公開状態を持ち、こちらは 2 つしかない（§C.8.2）。
 * 畳んだ後の値だけを持つと、十年分の中から**もともと非公開だったのか、
 * ゴミ箱だったのか**が永久に読めなくなる。
 *
 * 台帳（`import_records`）に持てば取り消しで行ごと消えるのに、
 * §D.3 / OG8 により**投稿はゴミ箱に残る** — 記事はあるのに出自が読めない。
 * `wp_meta`（§C.7）に持てば、受け皿の無い型で元の状態ごと落ちる。
 * **投稿と同じ寿命が、3 つの中で最も長い。**
 *
 * ## 型の名前を並べない
 *
 * 投稿タイプはサイトごとに違う。管理画面から作った型も、生成直後の `post` も、
 * 同じ形にならなければ「型によって出自が残る / 残らない」が生まれる。
 * 判定は 9.7 の `EditorialCollections` に任せ、ここで型名を書かない
 * （9.8 の trash 列の migration と同じ作法）。
 *
 * **べき等**である。何度実行しても「すべての投稿タイプが同じ列を持つ」へ収束し、
 * ファイルを 1 つも書き出さないので読み取り専用の環境でも動く。
 *
 * ## down は fail-closed
 *
 * 列を落とすと、取り込んだ記事の**出自が二度と戻らない**。
 * 残っているものが 1 件でもあれば、**1 列も落とさずに停止**する。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->editorialTables() as $table) {
            if (Schema::hasColumn($table, WxrStatusColumn::NAME)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                WxrStatusColumn::define($blueprint);
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
                "取り込み元の公開状態の列を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n列を落とすと、取り込んだ記事が元は非公開だったのかゴミ箱だったのかが二度と読めなくなります。".
                "\n戻す場合は、先に取り込みを取り消してから再実行してください。".
                "\n（この migration は 1 列も変更していません）",
            );
        }

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, WxrStatusColumn::NAME)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(WxrStatusColumn::NAME);
            });
        }
    }

    /**
     * 出自が残っている表。**1 件でもあれば 1 列も落とさない。**
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function blockers(array $tables): array
    {
        $blockers = [];

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, WxrStatusColumn::NAME)) {
                continue;
            }

            $rows = DB::table($table)->whereNotNull(WxrStatusColumn::NAME)->count();

            if ($rows > 0) {
                $blockers[] = "{$table}: 取り込み元の公開状態が {$rows} 件";
            }
        }

        return $blockers;
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

        return $tables;
    }
};
