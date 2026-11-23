<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Menus;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use AgentCms\Core\Menus\MenuPresenter;

/**
 * メニュー 1 本と、その木（Phase 9.9 / STAGE 22B・SPEC §E.1）。
 *
 * **URL は返さない**（SPEC §B.3）。返すのは保存されている参照そのもので、
 * URL を作るのは公開面の仕事である。
 */
final class GetMenu implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'menu.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $key, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $menu = self::find($key);

        if ($menu === null) {
            return Result::notFound("メニュー [{$key}] は存在しません");
        }

        // 1 回で全項目を引き、木は memory で組む（親ごとに問い合わせない）
        $items = MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Result::success(
            ['menu' => MenuPresenter::menu($menu), 'items' => MenuPresenter::tree($items)],
            ['key' => $menu->key, 'total' => $items->count()],
        );
    }

    public static function find(string $key): ?Menu
    {
        return Menu::query()->where('key', $key)->first();
    }
}
