<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * 取り込みの記録を DB が本当に守るか（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## SQLite では証明にならない
 *
 * 複合 unique と外部キーの効き方は driver で違う。既定のテストは in-memory の
 * SQLite なので、「MySQL でも同じ」とは言えない。**mock で「検証済み」にしない。**
 *
 * 使い捨ての MySQL 8.4 を別に立て、接続情報を環境変数で渡したときだけ走る。
 * 既存の runtime / volume / network には触れない。
 *
 * ```bash
 * # 名前は毎回変える（固定名だと前回の残骸と衝突する）
 * NAME="agentcms-27c-mysql-$$"
 *
 * # port は固定しない。127.0.0.1 にだけ publish し、空き port を docker に選ばせる
 * # （固定 port は他の MySQL と衝突し、0.0.0.0 だと LAN へ晒す）
 * docker run -d --name "$NAME" -p 127.0.0.1::3306 \
 *   -e MYSQL_ROOT_PASSWORD=import -e MYSQL_DATABASE=import mysql:8.4
 *
 * # 後片付けを先に予約する（テストが落ちても消える）
 * trap 'docker rm -f "$NAME" >/dev/null 2>&1' EXIT
 *
 * # 選ばれた port を取り出す
 * PORT="$(docker port "$NAME" 3306/tcp | head -1 | sed 's/.*://')"
 *
 * # 起動を待つ。**必ず TCP で確かめる。**
 * # 公式イメージは初期化のあいだ socket 専用の一時サーバーを動かす。既定の socket
 * # へ ping するとその一時サーバーが ready と答えてしまい、本サーバーの再起動中に
 * # host の TCP へ繋いで SQLSTATE[HY000] [2006] MySQL server has gone away になる。
 * # TCP に答えるのは最後の本サーバーだけなので、そこだけを待つ。
 * until docker exec "$NAME" \
 *   mysqladmin ping --protocol=tcp -h127.0.0.1 -uroot -pimport --silent 2>/dev/null; do
 *   sleep 1
 * done
 *
 * IMPORT_DB_HOST=127.0.0.1 IMPORT_DB_PORT="$PORT" IMPORT_DB_DATABASE=import \
 * IMPORT_DB_USERNAME=root IMPORT_DB_PASSWORD=import \
 *   php artisan test --filter=ImportLedgerMysqlTest
 * ```
 *
 * `--rm` は付けない。付けると落ちた原因を `docker logs` で読む前に消える。
 * 代わりに `trap` で確実に片付ける。
 *
 * 環境変数が無ければ skip する。**skip を合格と読ませない**ため、
 * `verify` の一部ではなく明示的な検証手順として実行する。
 */
final class ImportLedgerMysqlTest extends TestCase
{
    private const BATCHES = 'import_batches';

    private const RECORDS = 'import_records';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutIsolatedMysql();
        $this->useIsolatedMysql();
        $this->migrateIsolatedDatabase();

        // 子から先に片付ける（RESTRICT を自分で踏まない）
        DB::table(self::RECORDS)->delete();
        DB::table(self::BATCHES)->delete();
    }

    public function test_必要な制約が実際に作られている(): void
    {
        $this->assertTrue(Schema::hasTable(self::BATCHES));
        $this->assertTrue(Schema::hasTable(self::RECORDS));

        $indexes = collect(Schema::getIndexes(self::RECORDS))
            ->mapWithKeys(static fn (array $i): array => [(string) $i['name'] => $i])
            ->all();

        $this->assertArrayHasKey('uq_import_external', $indexes);
        $this->assertTrue($indexes['uq_import_external']['unique']);
        $this->assertSame(
            ['kind', 'target_type', 'external_id'],
            array_values((array) $indexes['uq_import_external']['columns']),
        );

        /*
         * **対応先を変えて実行し直したときに気づくための索引。**
         *
         * 一意制約は `target_type` を含むので、`--type` を変えると
         * 同じ記事が 2 つの型へ入る。制約は通ってしまうので、
         * `(kind, external_id)` で先に引けることが要る。
         * 索引が無いと十年分の台帳を毎回なめることになる。
         */
        $this->assertArrayHasKey('idx_import_kind_external', $indexes);
        $this->assertFalse($indexes['idx_import_kind_external']['unique']);
        $this->assertSame(
            ['kind', 'external_id'],
            array_values((array) $indexes['idx_import_kind_external']['columns']),
        );
    }

    public function test_所有権の既定は他人のものになる(): void
    {
        /*
         * **決め忘れた記録は消さない方へ倒す。**
         *
         * 取り消しは自分が作ったものだけを消す。既定が true だと、
         * 元から在った項目へ記事を結び付けただけの記録まで「自分のもの」になり、
         * **取り消しで他人の分類項目が消える**。既定は false でなければならない。
         */
        $batch = $this->batch();

        // 列を書かずに入れる（apply がうっかり省いた場合と同じ）
        DB::table(self::RECORDS)->insert([
            'batch_id' => $batch,
            'external_id' => '101',
            'kind' => 'term',
            'target_type' => 'news_category',
            'target_id' => '1',
            'created_at' => now(),
        ]);

        $this->assertSame(
            0,
            (int) DB::table(self::RECORDS)->where('external_id', '101')->value('created_by_import'),
        );
    }

    public function test_所有権は種類と別の軸で持つ(): void
    {
        /*
         * 同じ `kind` の中に、作ったものと使い回したものが並ぶ。
         * `kind` を増やして表そうとすると一意制約の意味まで変わるので、
         * **列を分けて直交させる**。
         */
        $batch = $this->batch();

        $this->record($batch, kind: 'term', targetType: 'news_category', externalId: '1', owned: true);
        $this->record($batch, kind: 'term', targetType: 'news_category', externalId: '2', owned: false);

        $owned = DB::table(self::RECORDS)->where('created_by_import', true)->pluck('external_id');

        $this->assertSame(['1'], $owned->all());
    }

    public function test_重なった定義は同じ対象を指すが所有はしない(): void
    {
        /*
         * 別の `wp:term_id` が同じ項目を指すことがある（`term_definition_repeated`）。
         * 台帳は id ごとに 1 行を持ち、`target_id` は共有する。
         * **所有するのは代表の 1 行だけ。** 両方が所有すると、
         * 片方を取り消した時点で、もう片方が指している対象が消える。
         */
        $batch = $this->batch();

        $this->record($batch, kind: 'term', targetType: 'news_category', externalId: '1', owned: true, targetId: 'T1');
        $this->record($batch, kind: 'term', targetType: 'news_category', externalId: '2', owned: false, targetId: 'T1');

        $this->assertSame(
            1,
            DB::table(self::RECORDS)->where('target_id', 'T1')->where('created_by_import', true)->count(),
        );
    }

    public function test_台帳の列が仕様どおり揃っている(): void
    {
        /*
         * 列が 1 つ欠けても、欠けたまま動いてしまう経路がある
         * （`failed_at` が無ければ「失敗して取り消した」が記録されないだけで、
         * 取り込み自体は通る）。**DDL をここで閉じる。**
         */
        $this->assertSame(
            [
                'counts', 'failed_at', 'file_hash', 'finished_at', 'id', 'options',
                'plan_hash', 'rolled_back_at', 'source', 'started_at', 'status',
            ],
            $this->sortedColumns(self::BATCHES),
        );

        $this->assertSame(
            [
                'batch_id', 'created_at', 'created_by_import', 'external_id',
                'id', 'kind', 'target_id', 'target_type',
            ],
            $this->sortedColumns(self::RECORDS),
        );
    }

    public function test_外部キーの削除規則がrestrictである(): void
    {
        // 「例外が出たから RESTRICT のはず」ではなく、宣言そのものを読む
        $foreignKeys = collect(Schema::getForeignKeys(self::RECORDS))
            ->firstWhere('name', 'fk_import_record_batch');

        $this->assertNotNull($foreignKeys, 'fk_import_record_batch が無い');
        $this->assertSame(['batch_id'], array_values((array) $foreignKeys['columns']));
        $this->assertSame(self::BATCHES, $foreignKeys['foreign_table']);
        $this->assertSame(['id'], array_values((array) $foreignKeys['foreign_columns']));
        $this->assertSame('restrict', strtolower((string) $foreignKeys['on_delete']));
    }

    public function test_同じ外部idを二重に記録できない(): void
    {
        /*
         * **これが冪等性の鍵**（SPEC §D.2）。同じ WXR をもう一度 apply しても
         * 二重に作らないことを、アプリではなく **DB に守らせる**。
         * アプリ側の検査だけだと、検査してから書くまでの間に
         * もう 1 本が同じ検査を通り、二重に入る。
         */
        $first = $this->batch();
        $second = $this->batch();

        $this->record($first, kind: 'content', targetType: 'post', externalId: '101');

        $this->expectException(QueryException::class);

        // batch が違っても、同じ (kind, target_type, external_id) は 1 行だけ
        $this->record($second, kind: 'content', targetType: 'post', externalId: '101');
    }

    public function test_種類や型が違えば同じ外部idを記録できる(): void
    {
        $batch = $this->batch();

        $this->record($batch, kind: 'content', targetType: 'post', externalId: '101');
        $this->record($batch, kind: 'content', targetType: 'news', externalId: '101');
        $this->record($batch, kind: 'comment', targetType: 'comment', externalId: '101');

        // WordPress の id は種類ごとに振られる。跨いで衝突させない
        $this->assertSame(3, DB::table(self::RECORDS)->count());
    }

    public function test_記録の残る_batchを消せない(): void
    {
        /*
         * **RESTRICT**（SPEC §D.2）。batch だけ消えると
         * 「何を入れたか分からない記録」が残り、rollback できなくなる。
         */
        $batch = $this->batch();
        $this->record($batch, kind: 'content', targetType: 'post', externalId: '101');

        $this->expectException(QueryException::class);

        DB::table(self::BATCHES)->where('id', $batch)->delete();
    }

    public function test_記録を片付ければbatchを消せる(): void
    {
        $batch = $this->batch();
        $this->record($batch, kind: 'content', targetType: 'post', externalId: '101');

        DB::table(self::RECORDS)->where('batch_id', $batch)->delete();
        DB::table(self::BATCHES)->where('id', $batch)->delete();

        $this->assertSame(0, DB::table(self::BATCHES)->count());
    }

    public function test_取り込みの記録が残っていればdownは何も変更せずに止まる(): void
    {
        /*
         * **fail-closed。** 取り込みの記録を落とすと、
         * 「入れたものを取り消す」手段そのものが消える。
         * 1 行でも在れば、表を 1 つも変更せずに止まる。
         */
        $batch = $this->batch();
        $this->record($batch, kind: 'content', targetType: 'post', externalId: '101');

        try {
            $this->migration()->down();
            $this->fail('記録が残っているのに down が通りました');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('取り消す', $e->getMessage());
        }

        // **止まったあとも、表と行がそのまま在ること**を確かめる
        $this->assertTrue(Schema::hasTable(self::BATCHES));
        $this->assertTrue(Schema::hasTable(self::RECORDS));
        $this->assertSame(1, DB::table(self::RECORDS)->count());
        $this->assertSame(1, DB::table(self::BATCHES)->count());
    }

    public function test_batchだけの台帳でもdownは何も変更せずに止まる(): void
    {
        /*
         * **`import_records` が空でも台帳は空ではない。**
         * 走って途中で落ちた batch、rollback 済みの batch は、記録が 0 件でも
         * `import_batches` に残る。**「何をしたか」の履歴はそこにしか無い。**
         * 先に見る表が空だったからと通してしまうと、その履歴ごと落ちる。
         */
        $this->batch(status: 'rolled_back');

        $this->assertSame(0, DB::table(self::RECORDS)->count());

        try {
            $this->migration()->down();
            $this->fail('batch が残っているのに down が通りました');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('取り消す', $e->getMessage());
            // どちらの表で止まったかを言う（利用者が片付ける先が分かる）
            $this->assertStringContainsString(self::BATCHES, $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable(self::BATCHES));
        $this->assertTrue(Schema::hasTable(self::RECORDS));
        $this->assertSame(1, DB::table(self::BATCHES)->count());
    }

    public function test_記録が無ければdownは通る(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable(self::RECORDS));
        $this->assertFalse(Schema::hasTable(self::BATCHES));

        // 次の test のために戻す
        $this->migration()->up();
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** batch を 1 つ作り、その id を返す */
    private function batch(string $status = 'completed'): string
    {
        $id = strtoupper(bin2hex(random_bytes(13)));

        DB::table(self::BATCHES)->insert([
            'id' => $id,
            // **パスは持たない**（SPEC §D.2 / §G）
            'source' => 'export.xml',
            'file_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'options' => '{}',
            'status' => $status,
            'counts' => '{}',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $id;
    }

    private function record(
        string $batch,
        string $kind,
        string $targetType,
        string $externalId,
        bool $owned = false,
        string $targetId = '1',
    ): void {
        DB::table(self::RECORDS)->insert([
            'batch_id' => $batch,
            'external_id' => $externalId,
            'kind' => $kind,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by_import' => $owned,
            'created_at' => now(),
        ]);
    }

    /** @return list<string> 列名を並べ替えて返す（宣言順に依存しない比較にする） */
    private function sortedColumns(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        return array_values($columns);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'core/database/migrations/2026_08_25_000010_create_import_tables.php',
        );

        return $migration;
    }

    private function skipWithoutIsolatedMysql(): void
    {
        if ((string) env('IMPORT_DB_HOST', '') === '') {
            $this->markTestSkipped(
                '隔離 MySQL の接続情報（IMPORT_DB_*）が無いため skip。'
                .'クラス docblock の手順で使い捨ての MySQL 8.4 を立てて実行すること。',
            );
        }
    }

    private function useIsolatedMysql(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            ...(array) config('database.connections.mysql'),
            'driver' => 'mysql',
            'host' => (string) env('IMPORT_DB_HOST'),
            'port' => (string) env('IMPORT_DB_PORT', '3306'),
            'database' => (string) env('IMPORT_DB_DATABASE'),
            'username' => (string) env('IMPORT_DB_USERNAME'),
            'password' => (string) env('IMPORT_DB_PASSWORD'),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        app()->forgetInstance('cache');
    }

    private function migrateIsolatedDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $this->assertTrue(
            Schema::hasTable(self::RECORDS),
            'migrate が通っていない: '.Artisan::output(),
        );
    }
}
