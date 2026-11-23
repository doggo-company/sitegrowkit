<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `/healthz`（P1 ⑩）。
 *
 * ## これは監視ダッシュボードではない
 *
 * `deploy.ts:319-325` が **自動切り戻しの引き金**として読む。
 * 答えるべき問いは 1 つだけ — **「この release でアプリが動くか」**。
 *
 * だから接続数・遅延・容量は**足さない**。それらは「運用が健全か」の話で、
 * 混ぜると **FAIL の意味が濁る**（遅いから切り戻す、は普通おかしい）し、
 * **無認証のエンドポイントから DB の規模と負荷が読める**ようになる。
 *
 * ## 応答は真偽だけ
 *
 * 版番号や表の名前を返さない。**外に出す情報を増やさない。**
 */
final class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_健全なら200(): void
    {
        $this->get('/healthz')
            ->assertOk()
            ->assertJson(['ok' => true, 'db' => true, 'storage' => true]);
    }

    /**
     * **繋がるだけでは足りない。**
     *
     * `select 1` は migration が 1 つも当たっていない DB でも通る。
     * deploy 直後に**表が無い**状態で `ok` を返すと、
     * **切り戻しが働かないまま壊れたサイトが公開される**。
     */
    public function test_表が無ければokにしない(): void
    {
        Schema::dropIfExists('migrations');

        $this->get('/healthz')
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'db' => false]);
    }

    /**
     * **前の release の表が在るだけでは足りない**（F-31）。
     *
     * 現実に起きるのは「表が 1 つも無い」ではなく、
     * **前の release の表は在るが、今回の migration が失敗した / 走っていない**である。
     * `migrations` に 1 行でも在れば通す判定は、**その事故をそのまま素通りする**。
     */
    public function test_未適用のmigrationが残っていればokにしない(): void
    {
        // 最後の 1 件だけ「まだ当てていない」状態にする
        $last = DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $last)->delete();

        $this->get('/healthz')
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'db' => false]);
    }

    /**
     * **落ちたら 503。**
     *
     * ここが 200 を返すと、**DB が死んでいるのに deploy が通る**。
     * いままで誰も確かめていなかった。
     */
    public function test_dbが落ちていれば503(): void
    {
        DB::listen(static function (): void {
            throw new QueryException('mysql', 'select 1', [], new \RuntimeException('落ちた（試験）'));
        });

        $this->get('/healthz')
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'db' => false]);
    }

    /** **応答に真偽以外を混ぜない**（無認証なので、増やすと外へ漏れる） */
    public function test_応答は真偽だけ(): void
    {
        $body = $this->get('/healthz')->assertOk()->json();

        $this->assertSame(['db', 'ok', 'storage'], collect(array_keys($body))->sort()->values()->all());

        foreach ($body as $key => $value) {
            $this->assertIsBool($value, "{$key} が真偽ではない");
        }
    }

    public function test_キャッシュに残さない(): void
    {
        $this->get('/healthz')->assertHeader('Cache-Control', 'no-store, private');
    }
}
