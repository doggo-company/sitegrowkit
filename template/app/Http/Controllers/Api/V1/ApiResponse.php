<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use Illuminate\Http\JsonResponse;

/**
 * Result → HTTP の唯一の変換規則（PHASE9_1A_SPEC B.3 の対応表）。
 *
 * アダプタ側で成功・失敗の解釈を作らないために、写像はここ 1 箇所に置く。
 */
final class ApiResponse
{
    /**
     * @param  string|null  $dataKey  Result.data から取り出すキー（null なら data 全体）
     * @param  array<string, mixed>  $extraMeta
     */
    public static function from(Result $result, string $operation, ?string $dataKey = null, array $extraMeta = []): JsonResponse
    {
        $meta = [...$result->meta, ...$extraMeta, 'operation' => $operation, 'interface' => 'api'];

        return match ($result->status) {
            OperationStatus::Success => response()->json([
                'data' => $dataKey === null ? $result->data : ($result->data[$dataKey] ?? null),
                'meta' => $meta,
            ]),
            OperationStatus::ValidationError => response()->json([
                'message' => $result->message,
                'errors' => $result->errors,
            ], 422),
            OperationStatus::NotFound => response()->json(['message' => $result->message], 404),
            OperationStatus::Forbidden => response()->json(['message' => $result->message], 403),
            OperationStatus::Conflict => response()->json([
                'message' => $result->message,
                'data' => $result->data,
            ], 409),
            OperationStatus::ConfirmationRequired => response()->json([
                'confirmation' => $result->confirmation?->toArray(),
                'meta' => $meta,
            ], 409),
        };
    }
}
