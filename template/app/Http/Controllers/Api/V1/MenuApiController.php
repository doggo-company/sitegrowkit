<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use AgentCms\Core\Application\Menus\GetMenu;
use AgentCms\Core\Application\Menus\ListMenus;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Menus\MenuTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * メニューの参照面（Phase 9.9 / STAGE 22C・SPEC §E.4）。
 *
 * 同一オリジン + セッションのまま。**公開ヘッドレス API 化しない** —
 * 未認証で辿れるサイト構造の一覧を作らないためである。
 * 公開ページが導線を出すのは Blade component（`<x-navigation>`）の仕事で、
 * この API を経由しない。
 *
 * ## 読みだけ
 *
 * 保存は管理画面から行う。API に書き込みを置かない（SPEC §E.3）。
 *
 * ## 返すのは **描ける形**
 *
 * 保存されているのは参照だけなので、ここで URL を作って返す（SPEC §B.3）。
 * ただし `current` は返さない — 「いま見ているページ」は API には無い（SPEC §D.3）。
 * 出せない項目（ゴミ箱・下書き・route が無い）は含まれない。
 */
final class MenuApiController
{
    public function index(Request $request, ListMenus $list): JsonResponse
    {
        return ApiResponse::from(
            $list->execute($this->context($request)),
            $list->operationId(),
            'menus',
        );
    }

    public function show(Request $request, GetMenu $get, MenuTree $tree, string $key): JsonResponse
    {
        // 権限と存在確認は operation に任せる（ここで判定を持たない）
        $result = $get->execute($key, $this->context($request));

        if (! $result->isSuccess()) {
            return ApiResponse::from($result, $get->operationId());
        }

        return ApiResponse::from(
            Result::success(
                ['menu' => $result->data['menu'], 'items' => self::renderable($tree->forKey($key))],
                $result->meta,
            ),
            $get->operationId(),
        );
    }

    /**
     * 画面の都合（`current` / `rel`）を落として返す。
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function renderable(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'title' => $item['title'],
            'url' => $item['url'],
            'target' => $item['target'],
            'children' => self::renderable($item['children']),
        ], $items);
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::api($user?->getKey(), $user === null ? '' : (string) $user->name);
    }
}
