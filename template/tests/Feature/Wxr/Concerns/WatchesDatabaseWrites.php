<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 「`plan` は DB を読むが書かない」を確かめるための道具（Phase 9.14 / SPEC §D.1）。
 *
 * ## 検査を二重に持たない
 *
 * 監視器と self-test が別々の実装を持つと、**self-test が緑のまま本番の監視器だけ
 * 壊れる**。壊れた監視器は「書き込みは無かった」と言い続けるので、
 * 検査があること自体が嘘になる。ここに 1 つだけ置いて、両方から呼ぶ。
 */
trait WatchesDatabaseWrites
{
    /**
     * 取り込みが触り得る表。**固定の一覧で持つ。**
     *
     * 「在る表を数える」形にすると、表ごと消えたときに一覧から静かに落ちて
     * 差が出なくなる。無い表は `absent` として残す。
     *
     * `taxonomy_content_types` は現行の migration に無いので入れない。
     *
     * @var list<string>
     */
    private const WATCHED_TABLES = [
        'import_batches',
        'import_records',
        'content_types',
        'taxonomies',
        'taxonomy_terms',
        'post',
        'news',
        'content_comments',
        'content_comment_settings',
        'content_entry_revisions',
        'taxonomy_term_assignments',
        'content_schema_manifests',
        // resolver は effective schema（Field Group overlay 込み）を読む
        'field_groups',
        'field_group_locations',
        'field_group_revisions',
        'audit_logs',
    ];

    /**
     * 書き込みの問い合わせかどうか。
     *
     * 先頭にコメントを置く、`WITH` を挟む、それだけで素通りする検査は
     * 「書いていない」ことの証明にならない。**コメントを剥いでから**見る。
     */
    public static function looksLikeWrite(string $sql): bool
    {
        /*
         * **コメントを先に消す。位置も種類も順番も決め打ちしない。**
         *
         * MySQL のコメントは 3 種（ブロック・`-- …`・`# …`）あり、
         * いくつでも、空白を挟んで、どの順でも並べられる。
         * 「先頭の 1 種類だけ」を剥ぐ検査は、並べ方を変えるだけで素通りする。
         *
         * 消すのは検査のためだけで、**消して短くなる方向にしか働かない**。
         * 文字列の中の `--` を消し過ぎても、先頭の動詞は消えないので
         * 書き込みを読み取りと見誤ることはない。
         */
        $sql = self::withoutComments($sql);

        // `WITH …` の後ろに書き込みが続く形（CTE DML）も捕まえる
        $write = '/^\s*(?:with\s+(?:recursive\s+)?.*?\)\s*)?'
            .'(insert|update|delete|replace|truncate|alter|drop|create|rename|lock|call)\b/is';

        return preg_match($write, $sql) === 1 || stripos($sql, 'for update') !== false;
    }

    /**
     * その書き込みが、**許した表だけを触っていると確かめられる**か。
     *
     * ## 見逃す側を狭くする
     *
     * 書き込みの段が来ると、書いてよい表がいくつか出てくる。
     * そこを「許していない表の名前が見えたら赤」にすると、
     * **解析を誤ったときに書き込みを見落とす**方へ倒れる。
     *
     * だから逆にする。**確かめられたときだけ見逃す。**
     * 判定できる形を狭く決め打ちし、当てはまらないものは全部赤にする。
     * join を含む更新も、CTE 付きの DML も、複数の表を触る文も、
     * 「この形ではない」ので赤になる。
     *
     * 許してよい表がそんな文を必要とするようになったら、
     * **解析を広げるのではなく、その書き込み自体を疑う。**
     */
    public static function touchesOnly(string $sql, array $allowed): bool
    {
        if ($allowed === []) {
            return false;
        }

        $sql = self::withoutComments($sql);

        // 動詞の**直後に来る 1 つの表名**だけを見る（Query Builder が出す形）
        $matched = preg_match(
            // `"main"."x"` のように**部分ごとに**引用符が付く形も拾う
            '/^\s*(?:insert\s+into|update|delete\s+from)\s+'
            .'(?:[`"\[]?[A-Za-z0-9_]+[`"\]]?\.)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?[\s(]/i',
            $sql.' ',
            $found,
        );

        if ($matched !== 1) {
            // **この形でなければ確かめられない。** 確かめられないものは見逃さない
            return false;
        }

        $table = $found[1];

        if (! in_array($table, $allowed, true)) {
            return false;
        }

        /*
         * **表名が他にも出ていないか確かめる。** `insert ... select ... from x` の形は
         * 動詞の直後だけ見ると通ってしまう。読む先も許した表であることまでは
         * 確かめられないので、素直に赤にする。
         */
        return preg_match('/\b(from|join)\b/i', substr($sql, (int) strpos($sql, $found[1]) + strlen($found[1]))) !== 1;
    }

    /**
     * それが**行ロックだけ**か（`select … for update`）。
     *
     * ## 行を掴むのはデータの書き込みではない
     *
     * `looksLikeWrite()` は `for update` を書き込みとして数える。1 行も書かないことを
     * 証明する場面ではそれが正しい — `plan` がロックを取るのは異常だからである。
     * だが**書き込みの段**では、許した表の行を掴むのは正常な動作で、
     * `touchesOnly()` は `insert` / `update` / `delete` の形しか確かめられないので、
     * そこで赤くなる。
     *
     * sqlite は `lockForUpdate()` を何にもコンパイルしないので、この食い違いは
     * **MySQL で走らせて初めて見えた**（27F）。
     *
     * ## 許した表が空のときは、見逃さない
     *
     * `$allowed === []` は「1 行も書かない」の証明である。そこでロックを見逃すと、
     * **`plan` が行を掴んでも緑のまま**になる。非対称はここが理由で、
     * 書く段だと申告した人だけがロックを見逃せる。
     *
     * ## どの表を掴んだかは問わない
     *
     * 許した表の一覧は「**書いてよい表**」であって「掴んでよい表」ではない。
     * 実測でその差が出た（27F）— 取り込みは `taxonomies` の行を掴むが、
     * `taxonomies` へは 1 行も書かない（`CreateTerm` の lock 順序が
     * Taxonomy 行 → Term 行）。掴んだ表を許可の一覧と突き合わせると、
     * **書いてよい表に `taxonomies` を足す**しかなくなり、
     * そこへの本物の書き込みまで見逃すことになる。
     *
     * ロックは**データを 1 行も変えない**ので、変わったかどうかを見張るこの道具の
     * 対象ではない。確かめるのは「ロックだけだと**言い切れる形か**」の方である。
     */
    public static function locksOnly(string $sql, array $allowed): bool
    {
        if ($allowed === []) {
            return false;
        }

        $sql = self::withoutComments($sql);

        if (stripos($sql, 'for update') === false) {
            return false;
        }

        /*
         * **`select` で始まる形だけ。** CTE や他の動詞が付いたものは確かめられない。
         *
         * ここを通った時点で、文は `select … for update` である。**書き込みの動詞が
         * 混じっていないかは見ない** — 見ても、この検査より先へ進めるのは
         * 引用符や文字列の中に動詞が入っている場合だけで、それでも文は
         * 依然としてロックだからである（副問い合わせは下の `from` の数で落ちる）。
         */
        if (preg_match('/^\s*select\b/i', $sql) !== 1) {
            return false;
        }

        // **`from` の直後の 1 つの表名だけ**を見る（`touchesOnly()` と同じ狭さ）
        $matched = preg_match(
            '/\bfrom\s+(?:[`"\[]?[A-Za-z0-9_]+[`"\]]?\.)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?\b/i',
            $sql,
            $found,
        );

        if ($matched !== 1) {
            return false;
        }

        // 表が 2 つ以上見えたら確かめられない
        if (preg_match_all('/\b(from|join)\b/i', $sql) !== 1) {
            return false;
        }

        // ここまで来れば「1 つの表の行を掴んだだけ」と言い切れる
        return $found[1] !== '';
    }

    /** コメントを消す。**位置も種類も順番も決め打ちしない** */
    private static function withoutComments(string $sql): string
    {
        $sql = (string) preg_replace('#/\*.*?\*/#s', ' ', $sql);

        return (string) preg_replace('#(?:--|\#)[^\n]*#', ' ', $sql);
    }

    /**
     * **書き込みの見つけ方そのものを固定する。**
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function sqlShapes(): array
    {
        return [
            'そのままの更新' => ['update content_types set menu_order = 1', true],
            'コメント付きの更新' => ['/* import */ update content_types set menu_order = 1', true],
            'コメント付きの挿入' => ['-- import'."\n".'insert into content_types (id) values (1)', true],
            '井桁コメント付きの挿入' => ['# import'."\n".'insert into content_types (id) values (1)', true],
            '行コメントを重ねた削除' => ['-- a'."\n".'-- b'."\n".'delete from content_types', true],
            '種類を混ぜて空白で挟んだ更新' => ['/* a */  -- b'."\n".'  # c'."\n".' update content_types set menu_order = 1', true],
            '逆順に並べた削除' => ['-- b'."\n".' /* a */ delete from content_types', true],
            'CTE の更新' => ['with x as (select 1) update content_types set menu_order = 1', true],
            'CTE の前後にコメントを置いた更新' => ['/* a */ with x as (select 1) /* b */ update content_types set menu_order = 1', true],
            '再帰 CTE の削除' => ['WITH RECURSIVE x AS (SELECT 1) DELETE FROM content_types', true],
            '行を掴む読み取り' => ['select * from content_types for update', true],
            'ただの読み取り' => ['select * from content_types', false],
            'コメント付きの読み取り' => ['/* plan */ select * from content_types', false],
            'CTE の読み取り' => ['with x as (select 1) select * from x', false],
        ];
    }

    #[DataProvider('sqlShapes')]
    public function test_書き込みの見つけ方(string $sql, bool $isWrite): void
    {
        $this->assertSame($isWrite, self::looksLikeWrite($sql), $sql);
    }

    /**
     * **許した表だけを触っていると確かめられる形**を固定する。
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function allowedShapes(): array
    {
        return [
            '許した表への挿入' => ['insert into "import_batches" ("id") values (?)', true],
            '許した表への更新' => ['update "import_batches" set "status" = ? where "id" = ?', true],
            '引用符なし' => ['insert into import_batches (id) values (?)', true],
            'バッククォート' => ['update `import_batches` set `status` = ?', true],
            'スキーマ修飾' => ['update "main"."import_batches" set "status" = ?', true],
            '許していない表' => ['insert into "news" ("id") values (?)', false],
            '許した表を含む join' => ['update "import_batches" join "news" on 1 = 1 set "status" = ?', false],
            '許した表への CTE' => ['with x as (select 1) update "import_batches" set "status" = ?', false],
            '許した表へ読み取りから入れる' => ['insert into "import_batches" select * from "news"', false],
            '許した表を消す' => ['truncate table "import_batches"', false],
        ];
    }

    #[DataProvider('allowedShapes')]
    public function test_許した表だけを触っていると確かめられる形(string $sql, bool $allowed): void
    {
        $this->assertSame($allowed, self::touchesOnly($sql, ['import_batches', 'audit_logs']), $sql);
    }

    /**
     * **行ロックを見逃してよい形**を固定する。
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function lockShapes(): array
    {
        return [
            '許した表を掴む' => ['select * from "import_batches" where "id" = ? limit 1 for update', true],
            'バッククォート' => ['select * from `import_batches` for update', true],
            'スキーマ修飾' => ['select * from "main"."import_batches" for update', true],
            'コメント付き' => ['/* rollback */ select * from "import_batches" for update', true],
            // **掴んだ表は問わない**（許可の一覧は「書いてよい表」であって「掴んでよい表」ではない）
            '書く先ではない表を掴む' => ['select * from "taxonomies" where "id" = ? limit 1 for update', true],
            'join を含む' => ['select * from "import_batches" join "news" on 1 = 1 for update', false],
            'CTE 付き' => ['with x as (select 1) select * from "import_batches" for update', false],
            'ロックでない読み取り' => ['select * from "import_batches"', false],
            // **`select` で始まらない**（`for update` を含んでいても、これは書き込みである）
            '書き込みで始まる' => ['delete from "import_batches" where id in (select id from "import_batches" for update)', false],
        ];
    }

    #[DataProvider('lockShapes')]
    public function test_行を掴んだだけと確かめられる形(string $sql, bool $ignored): void
    {
        $this->assertSame($ignored, self::locksOnly($sql, ['import_batches', 'audit_logs']), $sql);
    }

    /**
     * **1 行も書かない証明の側では、掴んだだけでも赤い。**
     *
     * `plan` が行を掴むのは異常なので、ここは見逃してはいけない。
     */
    public function test_許した表が無ければ掴んだだけでも見逃さない(): void
    {
        $this->assertFalse(self::locksOnly('select * from "import_batches" for update', []));
    }

    public function test_許した表が無ければ何も見逃さない(): void
    {
        // **既定は空。**これまでの「1 行も書かない」の証明は 1 文字も変わらない
        $this->assertFalse(self::touchesOnly('insert into "import_batches" ("id") values (?)', []));
    }

    /**
     * 書き込みの問い合わせを見張る。**呼ぶと、それまでに見えた分を返す。**
     *
     * @param  list<string>  $allowed  書いてよい表。**既定は空**（1 行も書かない）
     * @return \Closure(): list<string>
     */
    private function watchWrites(array $allowed = []): \Closure
    {
        $writes = [];

        DB::listen(function ($query) use (&$writes, $allowed): void {
            $sql = (string) $query->sql;

            if (! self::looksLikeWrite($sql)) {
                return;
            }

            // **確かめられたときだけ見逃す**（`touchesOnly()` の docblock）
            if (self::touchesOnly($sql, $allowed)) {
                return;
            }

            // 許した表の**行を掴んだだけ**なら、データは動いていない（`locksOnly()`）
            if (self::locksOnly($sql, $allowed)) {
                return;
            }

            $writes[] = $sql;
        });

        /*
         * **参照で返す。**
         *
         * arrow function は使わない。`fn () => $writes` は**作った時点の値**を
         * 写し取るので、後から listener が足した分が入らない。
         * それでは「書き込みは無かった」と言い続ける監視器になる。
         */
        return function () use (&$writes): array {
            return $writes;
        };
    }

    /**
     * 取り込みが触り得る表の**中身そのもの**。
     *
     * **件数では足りない。** 1 行を書き換えても、1 行消して 1 行足しても
     * 件数は変わらない。全行を決まった順で読んで畳む。
     *
     * @param  list<string>  $allowed  変わってよい表。**既定は空**（1 行も変わらない）
     * @return array<string, string>
     */
    private function snapshot(array $allowed = []): array
    {
        $digests = [];

        foreach (self::WATCHED_TABLES as $table) {
            if (in_array($table, $allowed, true)) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                // 「見ていない」と「無い」を混ぜない
                $digests[$table] = 'absent';

                continue;
            }

            // 並びが揺れると「変わった」に見えるので、決まった順で読む
            $rows = DB::table($table)->orderBy(Schema::getColumnListing($table)[0])->get();

            $digests[$table] = hash('sha256', (string) $rows->toJson());
        }

        return $digests;
    }
}
