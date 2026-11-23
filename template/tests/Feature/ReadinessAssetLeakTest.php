<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

/**
 * 公開前チェックが、**公開側の資材を管理画面へ持ち込まない**
 * （WP 一周 6 周目 2026-09-03）。
 *
 * ## 何が出ていたか
 *
 * `/admin/readiness` を開くと、ブラウザの console に CSP 違反が 4 件出る。
 * 応答の頭に原因がそのまま出ている:
 *
 * ```
 * Link: <http://localhost/build/assets/public-DGNmsnoG.css>; rel="preload"; as="style"…
 * ```
 *
 * **ポートが無い。** 検査は `Request::create('/about')` で公開ページを描くので、
 * その中の `asset()` が `http://localhost/build/…` を作る。
 * `AddLinkHeadersForPreloadedAssets` がそれを**外側の応答**へ付け、
 * ブラウザが先読みしようとして CSP に弾かれる。
 *
 * `fetch()` は 2026-08-30 に「元の request へ必ず戻す」を入れている
 *（それで 46 件が 4 件まで減った）。残っていたのは **Vite の preload 台帳**だった。
 *
 * ## 🔴 この試験は、直す前でも緑だった
 *
 * **試験環境では `Link` ヘッダが空**で、公開ページの `@vite` が
 * container の台帳を引かない（`Link` を出力させて確かめた）。
 * つまり**症状そのものを試験から起こせない**。
 *
 * ここに残すのは、**入れた仕組みが後で崩れないため**の 1 本である —
 * 使い捨てへ差し替えたまま戻し忘れると落ちる。
 * 症状のほうは、実物の応答ヘッダで直す前と後を見て確かめた（PR 本文）。
 */
final class ReadinessAssetLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 検査のあと、外側は**同じ台帳**を持っている。
     *
     * 空にする（`flush()`）のではなく差し替えて戻すのは、
     * 空にすると**この検査より前に積まれた外側の資材まで落とす**ためである。
     */
    public function test_検査のあとに元の台帳が戻っている(): void
    {
        $before = app(Vite::class);

        app(PublicationReadiness::class)->check();

        $this->assertSame($before, app(Vite::class), '差し替えた台帳が戻っていない');
    }

    /** 台帳の入れ替えは、検査の結果を変えない */
    public function test_検査は動いたまま(): void
    {
        $this->assertIsArray(app(PublicationReadiness::class)->check());
    }
}
