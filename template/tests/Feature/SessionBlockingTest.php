<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\BlockConcurrentSessionWrites;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * 同時のリクエストが session を古い中身で書き戻さないよう、管理画面の書き込みと描き直しを順に処理する
 * （UX 一周 23 周目 #1 の根・BlockConcurrentSessionWrites）。
 */
final class SessionBlockingTest extends TestCase
{
    public function test_管理画面の書き込みと描き直しは順に_読みの候補は順にしない(): void
    {
        $this->assertTrue($this->blocks('POST', '/admin/pages/1/publish'));
        $this->assertTrue($this->blocks('PATCH', '/api/v1/pages/1/document'));
        $this->assertTrue($this->blocks('GET', '/admin/pages/1/preview?canvas=1'));

        $this->assertFalse($this->blocks('GET', '/admin/pages/slug-preview?title=x'));
        $this->assertFalse($this->blocks('GET', '/admin/pages/1/editor'));
        $this->assertFalse($this->blocks('POST', '/contact'));
    }

    public function test_session_の前にロックを決める(): void
    {
        $kernel = app(Kernel::class);
        $this->assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        $web = $kernel->getMiddlewareGroups()['web'];
        $block = array_search(BlockConcurrentSessionWrites::class, $web, true);
        $start = array_search(StartSession::class, $web, true);

        $this->assertIsInt($block, 'web の middleware に入っていない');
        $this->assertIsInt($start);
        $this->assertLessThan($start, $block, 'StartSession より後ではロックが効かない');
    }

    public function test_session_の_store_はロックに対応している(): void
    {
        $store = config('session.store') ?? config('cache.default');

        $this->assertContains(config("cache.stores.{$store}.driver"), ['database', 'redis', 'file', 'memcached', 'dynamodb', 'array']);
        $this->assertInstanceOf(LockProvider::class, Cache::store($store)->getStore());
    }

    private function blocks(string $method, string $uri): bool
    {
        $request = Request::create($uri, $method);
        $route = RouteFacade::getRoutes()->match($request);
        $request->setRouteResolver(static fn (): Route => $route);

        (new BlockConcurrentSessionWrites)->handle($request, static fn () => response('ok'));

        return $route->locksFor() !== null;
    }
}
