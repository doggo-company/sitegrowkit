<?php

declare(strict_types=1);

namespace AgentCms\Core\Http;

use AgentCms\Core\Backups\RestoreJournal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 中断した復元が残っているあいだ、HTTP を止める（2026-09-09・release R09）。
 *
 * journal が在るということは、DB とメディアのどちらかが復元の途中で止まっている
 * （どちらかは DB の印でしか分からない）。その状態で
 *
 *   - 公開面を出せば、DB が指す画像が無い・消したはずの画像が出る
 *   - 管理画面で保存されれば、整える前の状態の上に変更が積まれる
 *   - もう一度復元や取得を始めれば、途中の状態を backup に写す
 *
 * のどれかになる。だから **全部の要求を 503** で止める。
 *
 * ## 訪問者には「整備中」だけを見せる
 *
 * ここは公開面にも出る。復元が止まっていること・整えるコマンド・置き場所は
 * サイトの内側の話であって、通りがかりの人に見せる情報ではない。
 * 手順は運用側（CLI の出力・`docs/release-20260909/restore-recovery.md`）が持つ。
 * 整えるのは web の中では行わない（`agentcms:backup-recover`）。
 *
 * 費用は毎リクエスト `is_file()` 1 回。
 */
final class RestorePendingMiddleware
{
    public const MESSAGE = 'ただいまサイトを整備しています。しばらくしてからもう一度お試しください';

    public function handle(Request $request, Closure $next): Response
    {
        if (! RestoreJournal::isPending()) {
            return $next($request);
        }

        $message = self::MESSAGE;
        $retryAfter = 60;

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message], 503, ['Retry-After' => (string) $retryAfter]);
        }

        return response($message, 503, [
            'Retry-After' => (string) $retryAfter,
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
