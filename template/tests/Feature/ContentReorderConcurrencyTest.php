<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 並べ替えの書き込みが、**無関係な書き込みを待たせないか**（P1 ②）。
 *
 * ## なぜ測るのか
 *
 * 分類の割り当てで、**0 件しか当たらない `where … for update` が
 * index の隙間に gap lock を掛け**、同じ表への並行 INSERT を止めていた。
 * 並べ替えは `sort_order`（index つき）を書き直す操作なので、**同じ轍を踏み得る**。
 *
 * ## 測った結果（`ReorderContent` の書き方の根拠）
 *
 * | 書き方 | 無関係な INSERT | 意味 |
 * | --- | --- | --- |
 * | **主キーで書く**（実装） | **待たされない** | 掴むのは触る行だけ |
 * | `sort_order` の範囲で書く | **待たされる** | 隙間ごと掴む |
 *
 * だから `ReorderContent` は**新しい番号を PHP 側で決め、主キーで書く**。
 *
 * ## driver ごとの保証差（縮退を隠さない）
 *
 * SQLite は行ロックを持たない（書き込みは DB 全体で直列化される）ので、
 * **この差そのものが存在しない**。measurable なのは MySQL だけなので、
 * それ以外では**測っていないと言って飛ばす**（緑にしない）。
 */
final class ContentReorderConcurrencyTest extends TestCase
{
    private const PROBE = 'agentcms_reorder_probe';

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->isMysql()) {
            $this->markTestSkipped('行ロックの差は MySQL でしか測れない（SQLite は DB 全体で直列化する）');
        }

        Artisan::call('migrate:fresh');

        config([
            'database.connections.'.self::PROBE => config('database.connections.'.DB::getDefaultConnection()),
        ]);

        foreach (range(1, 5) as $i) {
            DB::table('news')->insert([
                'title' => "記事 {$i}", 'slug' => "c-{$i}", 'body' => '<p>本文</p>',
                'status' => 'published', 'published_at' => now()->subDays($i),
                'sort_order' => $i - 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->isMysql()) {
            DB::purge(self::PROBE);

            Schema::disableForeignKeyConstraints();

            foreach (['news', 'audit_logs'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            Schema::enableForeignKeyConstraints();
        }

        parent::tearDown();
    }

    /**
     * **主キーで書けば、無関係な追加を待たせない。**
     *
     * これが `ReorderContent` の書き方そのものである（新しい番号は PHP で決め、
     * `whereIn(id, …)` で書く）。
     */
    public function test_主キーで書けば無関係な追加を待たせない(): void
    {
        $ids = DB::table('news')->orderBy('id')->limit(2)->pluck('id')->all();

        $probe = DB::connection(self::PROBE);
        $probe->beginTransaction();
        $probe->table('news')->whereIn('id', $ids)->update(['sort_order' => 90]);

        try {
            $this->assertFalse($this->insertBlocked(), '主キーで書いたのに、無関係な追加が待たされた');
        } finally {
            $probe->rollBack();
        }
    }

    /**
     * **範囲で書くと、待たせる。**
     *
     * 「主キーで書く」を選んだ理由は、こちらが実際に起きるからである。
     * **比べる相手が緑のままなら、選んだ理由は測れていない。**
     */
    public function test_範囲で書くと無関係な追加を待たせる(): void
    {
        $probe = DB::connection(self::PROBE);
        $probe->beginTransaction();
        // 新しい行が入る位置（`sort_order = 0`）を含む範囲
        $probe->table('news')->where('sort_order', '>=', 0)->update(['sort_order' => 90]);

        try {
            $this->assertTrue($this->insertBlocked(), '範囲で書いても待たされなかった（この試験の前提が崩れている）');
        } finally {
            $probe->rollBack();
        }
    }

    /**
     * 別接続がロックを握っている状態で、**新しい記事を 1 件足せるか**。
     *
     * 待たされたか（`true`）／待たされなかったか（`false`）だけを返す。
     * **ロック以外の失敗は握りつぶさない** — そのまま投げる。
     */
    private function insertBlocked(): bool
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            DB::table('news')->insert([
                'title' => '無関係な追加', 'slug' => 'c-new', 'body' => '<p>本文</p>',
                'status' => 'published', 'published_at' => now(),
                'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return false;
        } catch (QueryException $e) {
            // 1205 = lock wait timeout / 1213 = deadlock。**それ以外は別の壊れ方**である
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                return true;
            }

            throw $e;
        }
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
}
