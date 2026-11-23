<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\DuplicatePage;
use AgentCms\Core\Application\Pages\GetPage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\ListPages;
use AgentCms\Core\Application\Pages\ListPageVersions;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Pages\RestorePage;
use AgentCms\Core\Application\Pages\RestorePageVersion;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Application\Pages\UnpublishPage;
use AgentCms\Core\Application\Pages\UpdatePageMetadata;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ComponentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PageDocument の操作面（Phase 9.1D・owner 決定 5）。
 *
 * 同一オリジン + セッションのまま。**public headless API 化しない**。
 * bearer token / Sanctum は追加しない。
 */
final class PageApiController
{
    public function index(Request $request, ListPages $list): JsonResponse
    {
        $result = $list->execute(
            $this->context($request),
            (string) $request->query('status', ''),
            $request->boolean('includeTrashed'),
        );

        return ApiResponse::from($result, $list->operationId(), 'items');
    }

    public function show(Request $request, GetPage $get, int $id): JsonResponse
    {
        return ApiResponse::from($get->execute($id, $this->context($request)), $get->operationId(), 'page');
    }

    public function store(Request $request, CreatePage $create): JsonResponse
    {
        return ApiResponse::from(
            $create->execute($request->all(), $this->context($request)),
            $create->operationId(),
            'page',
        );
    }

    public function update(Request $request, UpdatePageMetadata $update, int $id): JsonResponse
    {
        return ApiResponse::from(
            $update->execute($id, $request->all(), $this->context($request)),
            $update->operationId(),
            'page',
        );
    }

    public function document(Request $request, GetPageDocument $get, int $id): JsonResponse
    {
        $result = $get->execute(
            $id,
            $this->context($request),
            $request->query('from') !== null ? (string) $request->query('from') : null,
            $request->query('depth') !== null ? (int) $request->query('depth') : null,
            $request->boolean('published'),
        );

        return ApiResponse::from($result, $get->operationId(), 'document');
    }

    /** semantic command を 1 つ受け取る（内部で JSON Patch へ落ちる） */
    public function patch(Request $request, PatchPageDocument $patch, int $id): JsonResponse
    {
        $command = $request->input('command');

        $result = $patch->execute($id, is_array($command) ? $command : [], $this->context($request));

        return ApiResponse::from($result, $patch->operationId(), 'document');
    }

    /** 版の一覧。**中身は返さない**（§4.12 — 大きさと、本文が応答へ流れること） */
    public function versions(Request $request, ListPageVersions $list, int $id): JsonResponse
    {
        $result = $list->execute(
            $id,
            $this->context($request),
            $request->query('perPage') !== null
                ? (int) $request->query('perPage')
                : ListPageVersions::MAX_PER_PAGE,
            // 続きは revision で辿る（件数で飛ばさない — 下で行が消える一覧だから）
            $request->query('before') !== null ? (int) $request->query('before') : null,
        );

        return ApiResponse::from($result, $list->operationId(), 'versions');
    }

    /**
     * 過去の版へ戻す。**新しい draft を作るだけ**で、公開面も過去の版も変わらない。
     *
     * 確認は `X-Confirmation` で通る（`page.document.patch` の削除と同じ経路）。
     */
    public function restoreVersion(Request $request, RestorePageVersion $restore, int $id): JsonResponse
    {
        $result = $restore->execute($id, (int) $request->input('revision'), $this->context($request));

        return ApiResponse::from($result, $restore->operationId(), 'document');
    }

    /** 管理画面と同じ複製処理を利用する。 */
    public function duplicate(Request $request, DuplicatePage $duplicate, int $id): JsonResponse
    {
        return ApiResponse::from($duplicate->execute($id, $this->context($request)), $duplicate->operationId(), 'page');
    }

    public function publish(Request $request, PublishPage $publish, int $id): JsonResponse
    {
        return ApiResponse::from(
            $publish->execute($id, $this->context($request), $request->input(PublishPage::INPUT_KEY)),
            $publish->operationId(),
            'page',
        );
    }

    /** 公開の取り消しも API では確認ゲートを通す。 */
    public function unpublish(Request $request, UnpublishPage $unpublish, int $id): JsonResponse
    {
        return ApiResponse::from($unpublish->execute($id, $this->context($request)), $unpublish->operationId(), 'page');
    }

    public function trash(Request $request, TrashPage $trash, int $id): JsonResponse
    {
        return ApiResponse::from($trash->execute($id, $this->context($request)), $trash->operationId(), 'page');
    }

    public function restore(Request $request, RestorePage $restore, int $id): JsonResponse
    {
        return ApiResponse::from($restore->execute($id, $this->context($request)), $restore->operationId(), 'page');
    }

    /** 使える component の一覧。**型を焼き込ませないための実行時取得口** */
    public function components(Request $request, ComponentRegistry $registry): JsonResponse
    {
        return ApiResponse::from(
            Result::success(['components' => $registry->specs()]),
            'component.types.list',
            'components',
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
