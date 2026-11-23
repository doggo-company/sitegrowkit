<?php

declare(strict_types=1);

namespace AgentCms\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面用 Content-Security-Policy（P1 ⑪）。
 *
 * ## 公開面のものと分けてある
 *
 * `PublicCspMiddleware` は公開サイト用で、外部の許可先（Turnstile 等）を持つ。
 * **管理画面は外部を 1 つも読まない**ので、そこへ相乗りさせない。
 * 許可先が増えるほど守りは薄くなる。**別の面には別の政策を当てる。**
 *
 * ## `unsafe-inline` を使わない
 *
 * 管理画面には消せないインラインが 3 つある:
 *
 * | 何 | なぜ消せないか |
 * | --- | --- |
 * | dark mode の即時適用（`<script>`） | 外部 file にすると**一瞬ちらつく**。それを避けるためのインライン |
 * | 背景色（`<style>`） | 同上 |
 * | `@font-face`（`<style>`） | `@fonts` が出す。こちらでは持たない |
 *
 * `'unsafe-inline'` を許せば全部通るが、**それは CSP を書かないのとほとんど同じ**である。
 * だから **nonce** を配る。`Vite::useCspNonce()` が `@vite` と `@fonts` の両方へ載せ、
 * 残る 2 つ（dark mode / 背景色）は view が `Vite::cspNonce()` を読んで付ける。
 *
 * ## Inertia の payload には nonce を付けない
 *
 * `<script data-page="app" type="application/json">` は **データブロック**で、
 * ブラウザは実行のために用意しない。だから `script-src` の対象にならない。
 * **付けられないから諦めた**のではなく、**要らない**。
 *
 * ## nonce は応答ごとに変える
 *
 * `useCspNonce()` は呼ぶたびに新しい値を作る。**使い回すと nonce の意味が無くなる**
 * （攻撃者が 1 度読めれば、次の応答でも通ってしまう）。
 */
final class AdminCspMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // **応答を作る前に**決める。view が読むより先でなければ載らない
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        /*
         * **既に決まっていれば触らない**（`SecurityHeadersMiddleware` の
         * `Referrer-Policy` と同じ作法）。
         *
         * この middleware は `web` グループに居るので**公開ページも通る**が、
         * 公開面は `PublicCspMiddleware` が route middleware として
         * **先に**自分の政策を置く。**外側が内側の判断を潰さない。**
         */
        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            // media の画像は同一オリジン。data: は favicon や inline SVG のため
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            // 管理画面は自分自身しか枠に入れない（Visual Page Editor の Canvas）
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        return $response;
    }
}
