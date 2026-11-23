<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\Content\DuplicateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\Content\ReorderContent;
use AgentCms\Core\Application\Content\RestoreContent;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 同一オリジン + セッションの操作面（Phase 9.1B / STEP 10、owner 決定 G1 = Option A）。
 *
 * ヘッドレス CMS の公開 API ではない。Admin / MCP / WebMCP が共有する操作面。
 * **業務ロジックは持たない** — 入力の取り出しと Result の描画だけ。
 */
final class ContentApiController
{
    public function index(Request $request, ListContent $list, string $type): JsonResponse
    {
        $result = $list->execute(
            $type,
            $this->context($request),
            (string) $request->query('q', ''),
            (int) $request->query('page', 1),
            (int) $request->query('perPage', 20),
        );

        return ApiResponse::from($result, $list->operationId(), 'items', ['type' => $type]);
    }

    public function show(Request $request, GetContent $get, string $type, int $id): JsonResponse
    {
        $result = $get->execute($type, $id, $this->context($request));

        return ApiResponse::from($result, $get->operationId(), 'item', ['type' => $type]);
    }

    /**
     * v1 で唯一の書き込み。差分更新のみ（作成・削除は Admin / MCP 経由）。
     *
     * `taxonomies` は **3 状態を区別**して渡す（§K.2）。
     * 未指定 = 触らない / `null` = validation error / object = 全置換。
     * ここで `[]` へ正規化すると「不正値を黙って無視した」になる。
     */
    public function update(Request $request, UpdateContent $update, string $type, int $id): JsonResponse
    {
        $data = $request->input('data');

        $result = $update->execute(
            $type,
            $id,
            is_array($data) ? $data : [],
            $this->context($request),
            partial: true,
            taxonomies: TaxonomyPayload::fromInput($request->all()),
            lockVersion: $this->lockVersion($request),
        );

        return ApiResponse::from($result, $update->operationId(), 'item', ['type' => $type]);
    }

    /**
     * ゴミ箱へ移す（Phase 9.8 / STAGE 21C）。
     *
     * 確認は既存と同じ `X-Confirmation` ヘッダーで受ける。**面ごとに別の確認を作らない。**
     */
    public function trash(Request $request, TrashContent $trash, string $type, int $id): JsonResponse
    {
        $result = $trash->execute($type, $id, $this->context($request), $this->lockVersion($request));

        return ApiResponse::from($result, $trash->operationId(), null, ['type' => $type]);
    }

    /**
     * ゴミ箱から戻す（Phase 9.8 / STAGE 21C）。
     *
     * 確認は要らない。戻すこと自体は何も失わない（`RestorePage` と同じ作法）。
     */
    /** 元記事を変えず、新しい下書きへコピーする。 */
    public function duplicate(Request $request, DuplicateContent $duplicate, string $type, int $id): JsonResponse
    {
        $result = $duplicate->execute($type, $id, $this->context($request));

        return ApiResponse::from($result, $duplicate->operationId(), 'item', ['type' => $type]);
    }

    public function restore(Request $request, RestoreContent $restore, string $type, int $id): JsonResponse
    {
        $result = $restore->execute($type, $id, $this->context($request), $this->lockVersion($request));

        return ApiResponse::from($result, $restore->operationId(), null, ['type' => $type]);
    }

    /**
     * 掲載順を変える（P1 ②）。
     *
     * **確認は取らない。** 内容は 1 文字も変わらず、**また動かせる**
     * （`restore` と同じ作法）。取り消せない操作とは扱いを分ける。
     *
     * `afterId` を書かなければ先頭へ。**null と未指定を区別しない** —
     * どちらも「前に何も無い場所」を指しており、分ける意味が無い。
     */
    public function reorder(Request $request, ReorderContent $reorder, string $type, int $id): JsonResponse
    {
        /*
         * **生のまま渡す**（F-39）。ここで `(int)` に潰すと、`true` が 1 になって
         * 「id 1 の直後へ」に化ける。**読めるかどうかを決めるのは Application 層**で、
         * MCP から来た入力も同じ判定を通る（境界ごとに緩さが違う状態を作らない）。
         */
        $result = $reorder->execute(
            $type,
            $id,
            $request->input('afterId'),
            $this->context($request),
            $request->input('direction'),
        );

        return ApiResponse::from($result, $reorder->operationId(), 'order', ['type' => $type]);
    }

    /**
     * 版（楽観ロック）。**黙って捨てない。**
     *
     * 送られてきたのに見ていない、が起きると「上書きされていないつもり」で
     * 上書きされる。移行期間中は未指定を許すが、指定は必ず Application 層まで運ぶ。
     */
    private function lockVersion(Request $request): LockVersionInput
    {
        /*
         * **キーの有無で分ける。** 値が空かどうかではない。
         * 壊れた値を「送っていない」へ丸めると、送ったつもりの相手が素通りする。
         * 判断そのものは Application 層が行うので、ここは形を渡すだけである。
         */
        return LockVersionInput::fromRequest($request->all());
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->header('X-Confirmation');

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }
}
