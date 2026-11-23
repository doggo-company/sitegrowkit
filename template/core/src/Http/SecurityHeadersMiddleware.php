<?php

declare(strict_types=1);

namespace AgentCms\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全レスポンス共通のセキュリティヘッダー（docs/SECURITY.md）。
 * CSP は公開面と管理画面で、それぞれ専用のmiddlewareが付与する。
 *
 * ## `Referrer-Policy` だけは上書きしない
 *
 * この middleware は `web` グループにあり、**応答の最後に**通る。
 * つまり controller が個別に付けたヘッダーを後から潰せる位置に居る。
 * 参照元の扱いは画面ごとに要求が違い、
 * たとえばパスワード設定 URL の受け渡し画面は
 * **参照元を一切送らない（`no-referrer`）**必要がある
 * （URL 自体が一時的な鍵なので、Referer で外へ出したくない）。
 *
 * そこで `Referrer-Policy` は **既に決まっていればそれを尊重する**。
 * ここが決めるのは「何も決まっていないときの既定」であって、
 * 個別の画面がより厳しくする判断を消してはいけない。
 *
 * ## `X-Frame-Options` は SAMEORIGIN への引き下げだけ認める
 *
 * 既定は `DENY` のまま。ただし Visual Page Editor の Canvas は、
 * **同じオリジンの下書きプレビューを iframe で読む**という作りなので、
 * その 1 画面だけ `SAMEORIGIN` を選べる必要がある（Phase 9.6 / P0-C2）。
 *
 * 認めるのは `SAMEORIGIN` **ただ 1 つ**で、それ以外の値は握り潰して `DENY` に戻す。
 * `ALLOW-FROM` も「ヘッダーを消す」も選べない。つまり
 * **他オリジンから枠に入れられる状態は、どの画面からも作れない**。
 */
final class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(
            'X-Frame-Options',
            $response->headers->get('X-Frame-Options') === 'SAMEORIGIN' ? 'SAMEORIGIN' : 'DENY',
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // 本番HTTPSでのみ再接続をHTTPSへ固定する。開発用HTTPや別サブドメインには強制しない。
        // isSecure()は信頼済みproxy設定に従うため、生のX-Forwarded-Protoを信用しない。
        if (app()->environment('production') && $request->isSecure()
            && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
