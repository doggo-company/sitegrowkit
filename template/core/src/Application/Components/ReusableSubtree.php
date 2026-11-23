<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Components;

use AgentCms\Core\Document\NodeId;

/**
 * 送られてきた部分木を、保存できる形に整える（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## id はサーバーが採番する
 *
 * ページの追加（`PatchPageDocument::prepareNode()`）と同じ作法である。
 * **画面に採番させない** — 画面ごとに作り方が分かれるうえ、重複や不正な形を
 * 送られたときに断る側が持つ責任が増える。
 *
 * **既にある id は残す。** 更新のときは画面が同じ木を送り返してくるので、
 * 全部作り直すと**選んでいた節点が毎回別のものになる**。
 */
final class ReusableSubtree
{
    /**
     * 足りない id を埋めた木を返す（**元の木は変えない**）。
     *
     * @param  array<string, mixed>  $subtree
     * @return array<string, mixed>
     */
    public static function withIds(array $subtree): array
    {
        $id = $subtree['id'] ?? null;

        if (! is_string($id) || ! NodeId::isValid($id)) {
            $subtree['id'] = NodeId::generate();
        }

        $children = is_array($subtree['children'] ?? null) ? $subtree['children'] : [];
        $prepared = [];

        foreach ($children as $child) {
            if (is_array($child)) {
                $prepared[] = self::withIds($child);
            }
        }

        $subtree['children'] = $prepared;

        return $subtree;
    }
}
