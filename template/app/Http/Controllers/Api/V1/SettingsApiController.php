<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Settings\GetSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** サイト設定の読み取り面（Phase 9.1B / STEP 10） */
final class SettingsApiController
{
    public function show(Request $request, GetSettings $get): JsonResponse
    {
        $user = $request->user();

        $result = $get->execute(
            OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name),
        );

        return ApiResponse::from($result, $get->operationId());
    }
}
