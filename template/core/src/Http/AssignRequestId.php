<?php

declare(strict_types=1);

namespace AgentCms\Core\Http;

use AgentCms\Core\Application\RequestCorrelation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP リクエストごとに相関 ID を 1 個だけ採番する（STAGE #18A / Correction 2-3）。
 *
 * **受信ヘッダーは読まない。** `X-Request-ID` を無検証で採用すると、
 * 送信側が他人の試行と同じ ID を名乗って監査ログを混ぜられる。
 * 採番はサーバー側の ULID だけで行い、結果を応答ヘッダーへ出して
 * 利用者が問い合わせのときに提示できるようにする。
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestCorrelation $correlation) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 同じ container を使い回す環境（Octane / テスト）でも
        // リクエストごとに必ず新しい ID になるよう、毎回振り直す
        $id = $this->correlation->assign();

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
