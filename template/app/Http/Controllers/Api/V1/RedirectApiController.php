<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Redirects\GetRedirect;
use AgentCms\Core\Application\Redirects\ListRedirects;
use AgentCms\Core\Application\Redirects\SimulateRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 転送ルールの参照面（Phase 9.10 / STAGE 23C・SPEC §E.4）。
 *
 * 同一オリジン + セッションのまま。**公開ヘッドレス API 化しない**。
 * 保存と削除は管理画面から行う（API に書き込みを置かない — SPEC §E.2）。
 *
 * `simulate` は **何も変えない**。ヒット数も増やさない。
 * 301 はブラウザが強く覚えるので、確かめるために踏ませてはいけない。
 */
final class RedirectApiController
{
    public function index(Request $request, ListRedirects $list): JsonResponse
    {
        return ApiResponse::from(
            $list->execute(
                $this->context($request),
                (string) $request->query('q', ''),
                $request->has('enabled') ? $request->boolean('enabled') : null,
                (int) $request->query('page', 1),
                (int) $request->query('perPage', 50),
            ),
            $list->operationId(),
            'rules',
        );
    }

    public function show(Request $request, GetRedirect $get, string $id): JsonResponse
    {
        return ApiResponse::from(
            $get->execute((int) $id, $this->context($request)),
            $get->operationId(),
            'rule',
        );
    }

    /** **何も変えない**ので GET（api/v1 の POST は書き込み、という約束を崩さない） */
    public function simulate(Request $request, SimulateRedirect $simulate): JsonResponse
    {
        return ApiResponse::from(
            $simulate->execute((string) $request->query('url', ''), $this->context($request)),
            $simulate->operationId(),
        );
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name);
    }
}
