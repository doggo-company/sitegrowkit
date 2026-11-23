<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

use Illuminate\Support\Collection;

/**
 * 保存されている形を、そのまま外へ出す（Phase 9.9 / STAGE 22B）。
 *
 * **URL は出さない。** ここが返すのは「何を指しているか」であって
 * 「どの URL になるか」ではない。URL の導出は公開面の仕事で、STAGE 22C の
 * `MenuTree` が行う（SPEC §B.3・§D.2）。
 *
 * 分けているのは手抜きではない。管理画面は**参照そのもの**を編集する必要があり、
 * URL を混ぜると「どちらを直せばよいか」が分からなくなる。
 */
final class MenuPresenter
{
    /** @return array<string, mixed> */
    public static function menu(Menu $menu): array
    {
        return [
            'id' => (int) $menu->getKey(),
            'key' => $menu->key,
            'name' => $menu->name,
            'description' => $menu->description,
            'managed_by' => $menu->managed_by,
            // テーマが持っている本かどうかは、画面が導線を消すのに要る
            'code_managed' => $menu->isCodeManaged(),
            'lock_version' => (int) $menu->lock_version,
        ];
    }

    /**
     * 項目を木にして返す。
     *
     * 1 回引いた collection から組み立てる（親ごとに問い合わせない）。
     *
     * @param  Collection<int, MenuItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function tree(Collection $items): array
    {
        $byParent = [];

        foreach ($items as $item) {
            $byParent[$item->parent_id ?? 0][] = $item;
        }

        return self::branch($byParent, 0);
    }

    /**
     * @param  array<int, list<MenuItem>>  $byParent
     * @return list<array<string, mixed>>
     */
    private static function branch(array $byParent, int $parentId): array
    {
        $rows = $byParent[$parentId] ?? [];

        usort($rows, static function (MenuItem $a, MenuItem $b): int {
            // 並び順が同値のときは id 昇順（安定した順序にする）
            return [$a->sort_order, (int) $a->getKey()] <=> [$b->sort_order, (int) $b->getKey()];
        });

        return array_map(
            static fn (MenuItem $item): array => self::item($item)
                + ['children' => self::branch($byParent, (int) $item->getKey())],
            $rows,
        );
    }

    /** @return array<string, mixed> */
    public static function item(MenuItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'sort_order' => $item->sort_order,
            'title' => $item->title,
            'link_type' => $item->link_type,
            'link_url' => $item->link_url,
            'link_route' => $item->link_route,
            'page_id' => $item->page_id,
            'content_type' => $item->content_type,
            'content_id' => $item->content_id,
            'target' => $item->target,
        ];
    }
}
