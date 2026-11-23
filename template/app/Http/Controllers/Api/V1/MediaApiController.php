<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Media\Application\GetMedia;
use Modules\Media\Application\ListMedia;

/** メディアの読み取り面（Phase 9.1B / STEP 10） */
final class MediaApiController
{
    public function index(Request $request, ListMedia $list): JsonResponse
    {
        $result = $list->execute(
            $this->context($request),
            (int) $request->query('page', 1),
            (int) $request->query('perPage', 24),
        );

        return ApiResponse::from($result, $list->operationId(), 'items');
    }

    public function show(Request $request, GetMedia $get, int $id): JsonResponse
    {
        $result = $get->execute($id, $this->context($request));

        return ApiResponse::from($result, $get->operationId(), 'media');
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name);
    }
}
