<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の応答をブラウザに残させない（UX 一周 13 周目 R13-1・R13-2）。
 *
 * ブラウザの「戻る」で、ブラウザが手元に残した古い画面（bfcache・HTTP キャッシュ）を出すと、
 * 置いたブロックが無い・対応済みが未対応のまま、と「消えた」ように見える。
 * `no-store` の応答は bfcache にも HTTP キャッシュにも入らないので、戻ると必ずサーバーへ取りに来る。
 * （Inertia の画面の中の「戻る」は `resources/js/lib/history-refresh.ts` が読み直す）
 *
 * 公開サイトには付けない（公開面のキャッシュの方針は公開面の middleware が決める）。
 * ダウンロード（書き出し・バックアップ）は触らない。
 * 付けるのは画面を返した応答（2xx）だけ。404 などの断りは今までどおり
 * （断りの見た目で「在る・無い」を見分けさせない — MediaDownloadTest）。
 * 既に no-store を決めている応答（プレビューの `max-age=0` 等）はそのまま。
 */
final class AdminNoStore
{
    /** 管理画面の path（`routes/admin.php` の admin/、ダッシュボード、settings/） */
    private const PATHS = ['admin', 'admin/*', 'dashboard', 'dashboard/*', 'settings', 'settings/*'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (
            ! $request->is(...self::PATHS)
            || ! $response->isSuccessful()
            || $response->headers->has('Content-Disposition')
            || str_contains((string) $response->headers->get('Cache-Control'), 'no-store')
        ) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
