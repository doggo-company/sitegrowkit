<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の書き込みと描き直しを、同じ session の中で順に処理させる（Laravel の session blocking）。
 *
 * ## なぜ要るのか（UX 一周 23 周目 #1 の根）
 *
 * Laravel の session は、リクエストの始めに読み、終わりに**丸ごと書き戻す**。同じ人のリクエストが
 * 同時に走ると、遅れて終わった方が、先に終わった方の書き込みを古い中身で上書きする。
 * ブロック編集で保存の直後に「公開する」を押すと、描き直しの GET と公開の POST が同時に走り、
 * 描き直しが「ページを公開しました」の知らせ（flash）を消していた。
 *
 * ## 何を順にするか
 *
 * - 管理画面・管理 API（`admin/*`・`api/v1/*`）の **GET 以外**（書き込み）
 * - 書き込みのすぐあとに走る**描き直し**（`*.preview` の GET）
 *
 * 読みの候補（`pages/slug-preview`・打つたびに走る）などの GET は順にしない（待たせない）。
 * ロックは session の store（`session.store`、無ければ既定の cache・既定は database）で取る。
 * database・redis・file はロックに対応している。
 *
 * StartSession より前に置く（StartSession が `$route->locksFor()` を見てロックを取る）。
 */
final class BlockConcurrentSessionWrites
{
    /** ロックを持つ長さ（秒）。重い保存でもこの間には終わる */
    public const LOCK_SECONDS = 10;

    /** 前のリクエストを待つ長さ（秒）。超えたら 500 ではなく順番待ちの失敗になる */
    public const WAIT_SECONDS = 10;

    /** 順にしない GET（打つたびに走る・session を書き換えない） */
    private const UNBLOCKED_GET_ROUTES = ['admin.pages.slug-preview'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route && self::shouldBlock($request, $route)) {
            $route->block(self::LOCK_SECONDS, self::WAIT_SECONDS);
        }

        return $next($request);
    }

    public static function shouldBlock(Request $request, Route $route): bool
    {
        if (! $request->is('admin', 'admin/*', 'api/v1/*')) {
            return false;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return true;
        }

        $name = (string) $route->getName();

        return str_ends_with($name, '.preview') && ! in_array($name, self::UNBLOCKED_GET_ROUTES, true);
    }
}
