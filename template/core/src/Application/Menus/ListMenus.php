<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Menus;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuPresenter;

/**
 * メニューの一覧（Phase 9.9 / STAGE 22B・SPEC §E.1）。
 *
 * 項目は返さない。一覧で要るのは「どの本があるか」だけで、
 * 木まで返すと本の数だけ木を引くことになる。中身は `GetMenu` で取る。
 */
final class ListMenus implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'menu.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $menus = Menu::query()
            ->withCount('items')
            ->orderBy('key')
            ->get();

        $items = $menus
            ->map(static fn (Menu $menu): array => MenuPresenter::menu($menu) + [
                'item_count' => (int) $menu->getAttribute('items_count'),
            ])
            ->values()
            ->all();

        return Result::success(['menus' => $items], ['count' => count($items)]);
    }
}
