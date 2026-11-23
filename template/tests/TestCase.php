<?php

namespace Tests;

use App\Models\User;
use App\Support\SessionInvalidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * **テストは front-end の build 成果物に依存しない**（Phase 9.7 / STAGE 20F）。
     *
     * 画面を描くテストは `app.blade.php` の `@vite` を通る。build がまだ無いと
     * そこで `Vite manifest not found` になり、**アプリは正しいのにテストだけ落ちる**。
     *
     * これは生成したばかりのサイトで必ず起きる。`public/build` は生成物に含めない
     * （各サイトで作るもの）ので、`agentcms verify` の 1 回目は build ゲートより
     * 先に走るテストゲートが落ちていた。2 回目からは build が在るので通ってしまい、
     * 「たまに落ちる」ようにしか見えなかった。
     *
     * build 自体は専用のゲートが見ている。ここで見たいのは**中身**なので、
     * 資産の有無から切り離す。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * `actingAs` を **本番のログインと同じ状態**にそろえる（Correction 2-2）。
     *
     * 本番では `Login` event を受けた `StampSessionEpoch` が session 世代を刻み、
     * `EnsureUserIsActive` は「記録の無い session は信用しない」で動く。
     * テストの `actingAs()` は event を起こさないので、ここで同じ刻みを入れる。
     *
     * **弱めているのではない**。刻むのは *その時点の* 世代なので、
     * `actingAs` のあとに権限が変われば次のリクエストは正しく弾かれる。
     * 世代の不一致を検証したいテストは、変更後に `actingAs` を呼び直さないこと
     * （呼び直すのは「入り直した」と同じ意味になる）。
     *
     * @param  string|null  $guard
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if ($user instanceof User) {
            $this->app['session.store']->put(
                SessionInvalidator::SESSION_KEY,
                (int) $user->session_epoch,
            );
        }

        return $this;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
