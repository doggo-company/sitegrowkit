<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\Content\GetContentSchema;
use AgentCms\Core\Application\Content\ListContentTypes;
use AgentCms\Core\Application\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content Type と schema の実行時取得（Phase 9.1B / STEP 10）。
 * ツール定義に型を焼き込まないための入口（PHASE9_1A_SPEC K）。
 */
final class ContentTypeApiController
{
    public function index(Request $request, ListContentTypes $types): JsonResponse
    {
        $result = $types->execute($this->context($request));

        return ApiResponse::from($result, $types->operationId(), 'types');
    }

    public function show(Request $request, GetContentSchema $schema, string $type): JsonResponse
    {
        $result = $schema->execute($type, $this->context($request));

        return ApiResponse::from($result, $schema->operationId(), 'schema');
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name);
    }
}
