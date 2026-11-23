<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\Components\ArchiveReusableComponent;
use AgentCms\Core\Application\Components\CreateReusableComponent;
use AgentCms\Core\Application\Components\ListReusableComponents;
use AgentCms\Core\Application\Components\PublishReusableComponent;
use AgentCms\Core\Application\Components\UpdateReusableComponent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ReusableComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 再利用 component の操作面（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * **同一オリジンの操作面**である（公開ヘッドレス API ではない）。
 * 認可・検証・確認・監査はすべて Application Service 側で行われる。
 *
 * ## 切り離しの口は無い
 *
 * detach はページの document を変える操作なので、`page.document.patch` の
 * command（`component.detach`）として入る。**document を変える口を 2 本にしない。**
 *
 * ## AI へは出さない
 *
 * `OperationCatalog` に足さない（Owner Gate V11）。再利用 component の変更は
 * **自分が開いていないページまで変える**ので、影響範囲が広すぎる。
 */
final class ReusableComponentApiController
{
    public function index(Request $request, ListReusableComponents $list): JsonResponse
    {
        return ApiResponse::from(
            $list->execute($this->context($request), $request->boolean('archived')),
            $list->operationId(),
            'components',
        );
    }

    public function store(Request $request, CreateReusableComponent $create): JsonResponse
    {
        return ApiResponse::from(
            $create->execute((array) $request->all(), $this->context($request)),
            $create->operationId(),
            'component',
        );
    }

    /** 1 件の中身。**下書きを返す**（編集画面が読むもの） */
    public function show(Request $request, ListReusableComponents $list, string $id): JsonResponse
    {
        $component = ReusableComponent::query()->find($id);

        $result = $component instanceof ReusableComponent
            ? Result::success(['component' => [
                'id' => $component->id,
                'key' => $component->key,
                'label' => $component->label,
                'document' => $component->draft_document,
                'published' => $component->isPublished(),
                'archived' => ! $component->isActive(),
            ]])
            : Result::notFound('再利用 component が見つかりません');

        return ApiResponse::from($result, $list->operationId(), 'component');
    }

    public function update(Request $request, UpdateReusableComponent $update, string $id): JsonResponse
    {
        return ApiResponse::from(
            $update->execute($id, (array) $request->all(), $this->context($request)),
            $update->operationId(),
            'component',
        );
    }

    public function publish(Request $request, PublishReusableComponent $publish, string $id): JsonResponse
    {
        return ApiResponse::from(
            $publish->execute($id, $this->context($request)),
            $publish->operationId(),
            'component',
        );
    }

    public function archive(Request $request, ArchiveReusableComponent $archive, string $id): JsonResponse
    {
        return ApiResponse::from(
            $archive->execute($id, $this->context($request)),
            $archive->operationId(),
            'component',
        );
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->header('X-Confirmation');

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }
}
