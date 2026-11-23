<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tests\TestCase;

/**
 * PWA の 3 route は **セッションを持たない**（Phase 9.3B / #23）。
 *
 * ## なぜ固定するのか
 *
 * manifest / sw.js / offline は Service Worker が **いつでも勝手に**取りに来る。
 * ここでセッションが始まると、その裏の取得が flash を読んで消してしまい、
 * 直後に利用者が開いた画面から確認ダイアログや toast が消える。
 * 実際に「削除の確認ダイアログが出ない」という形で表面化し、
 * ブラウザの Service Worker を止めると再現しなくなることで原因が確定した。
 *
 * 症状が **タイミング次第**なので、応答だけを見るテストでは再発を防げない。
 * middleware から外れていること自体をここで固定する。
 */
final class PwaStatelessRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ROUTES = ['admin.manifest', 'admin.sw', 'admin.offline'];

    /** セッションに触れる middleware は 1 つも通さない */
    public function test_セッション関連のmiddlewareを通さない(): void
    {
        $excluded = [
            StartSession::class,
            ShareErrorsFromSession::class,
            HandleInertiaRequests::class,
            // XSRF cookie をセッションから作るため、これも通すと落ちる
            PreventRequestForgery::class,
        ];

        foreach (self::ROUTES as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "route [{$name}] がありません");

            $middleware = app('router')->gatherRouteMiddleware($route);

            foreach ($excluded as $class) {
                $this->assertNotContains(
                    $class,
                    $middleware,
                    "{$name} が {$class} を通しています（Service Worker の取得で flash が消えます）",
                );
            }
        }
    }

    /** 実際に叩いても 200 で、セッション cookie を 1 つも発行しない */
    public function test_応答は200でセッションcookieを発行しない(): void
    {
        foreach (['/admin/manifest.webmanifest', '/admin/sw.js', '/admin/offline'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();

            $names = array_map(
                static fn ($cookie): string => $cookie->getName(),
                $response->headers->getCookies(),
            );

            $this->assertNotContains(config('session.cookie'), $names, "{$uri}");
            $this->assertNotContains('XSRF-TOKEN', $names, "{$uri}");
        }
    }

    /** 認証済みの利用者が取得しても、セッションを作り直さない */
    public function test_ログイン中の取得でもセッションを触らない(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

        foreach (['/admin/manifest.webmanifest', '/admin/sw.js', '/admin/offline'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();
            $this->assertSame([], $response->headers->getCookies(), "{$uri} が cookie を発行しています");
        }
    }
}
