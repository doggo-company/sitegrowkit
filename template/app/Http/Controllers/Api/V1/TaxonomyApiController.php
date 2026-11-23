<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Application\Taxonomy\GetTaxonomy;
use AgentCms\Core\Application\Taxonomy\ListTaxonomies;
use AgentCms\Core\Application\Taxonomy\ListTerms;
use AgentCms\Core\Application\Taxonomy\ReorderTerms;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 分類の操作面（Phase 9.3B / SPEC §Q.1）。
 *
 * 同一オリジン + セッションのまま。**公開ヘッドレス API 化しない**。
 * bearer token / Sanctum は追加しない。
 *
 * ## 責務
 *
 * request からの取り出しと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・認可・確認・監査・transaction はすべて Application Service 側にある
 * （STAGE 2B の 7 操作をそのまま呼ぶ）。Model を直接触らない。
 *
 * ## 公開範囲
 *
 * **taxonomy 自体の作成・更新・削除は公開しない**（§P.2 — Phase 9.4 へ延期）。
 * ここにあるのは **taxonomy の read と term の操作**だけ。
 *
 * URL には **taxonomy key と term の immutable ID** を使う。
 * taxonomy の ULID は露出させない（§Q.1）。
 * 破壊的操作は `DELETE` 動詞ではなく `POST .../delete` + `X-Confirmation` に揃える
 * （既存 `api/v1` に DELETE 動詞が 1 本も無いため）。
 */
final class TaxonomyApiController
{
    public function index(Request $request, ListTaxonomies $list): JsonResponse
    {
        return ApiResponse::from(
            $list->execute($this->context($request)),
            $list->operationId(),
            'taxonomies',
        );
    }

    public function show(Request $request, GetTaxonomy $get, string $key): JsonResponse
    {
        return ApiResponse::from(
            $get->execute($key, $this->context($request)),
            $get->operationId(),
            'taxonomy',
        );
    }

    /** `includeArchived=true` で archived も含む（AR-2 / HR-3） */
    public function terms(Request $request, ListTerms $list, string $key): JsonResponse
    {
        return ApiResponse::from(
            $list->execute($key, $this->context($request), $request->boolean('includeArchived')),
            $list->operationId(),
            'terms',
        );
    }

    /**
     * URL の `{key}` が **taxonomy の唯一の指定手段**。
     *
     * body に `taxonomyKey` が来ても URL が優先される（REST の慣習どおり）。
     * 実際にどの分類へ作ったかは response の `meta.taxonomyKey` で確認できる。
     */
    public function storeTerm(Request $request, CreateTerm $create, string $key): JsonResponse
    {
        $input = $request->all();
        $input['taxonomyKey'] = $key;

        return ApiResponse::from(
            $create->execute($input, $this->context($request)),
            $create->operationId(),
            'term',
        );
    }

    /** archive / unarchive も `archived` で行う（専用 route を作らない — §I.5） */
    public function updateTerm(Request $request, UpdateTerm $update, string $id): JsonResponse
    {
        return ApiResponse::from(
            $update->execute($id, $request->all(), $this->context($request)),
            $update->operationId(),
            'term',
        );
    }

    public function reorderTerms(Request $request, ReorderTerms $reorder, string $key): JsonResponse
    {
        $parentId = $request->input('parentId');

        $result = $reorder->execute(
            $key,
            is_string($parentId) ? $parentId : null,
            $this->orderedTermIds($request),
            $this->context($request),
        );

        return ApiResponse::from($result, $reorder->operationId(), 'order');
    }

    /**
     * **破壊的操作**。1 回目は確認内容（409）を返し、`X-Confirmation` 付きの
     * 2 回目で実行する。参照が残っていれば confirmation を発行せず conflict になる。
     */
    public function deleteTerm(Request $request, DeleteTerm $delete, string $id): JsonResponse
    {
        return ApiResponse::from(
            $delete->execute($id, $this->context($request)),
            $delete->operationId(),
            'id',
        );
    }

    /**
     * 並び順の入力。**型の検証は Application Service が行う**ので、
     * ここでは配列かどうかだけを見て素通しする（要素を勝手に文字列化しない）。
     *
     * @return array<array-key, mixed>
     */
    private function orderedTermIds(Request $request): array
    {
        $ordered = $request->input('orderedTermIds');

        return is_array($ordered) ? $ordered : [];
    }

    /** 誰が・どの経路で操作しているか（確認トークンがあれば同梱） */
    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->header('X-Confirmation');

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }
}
