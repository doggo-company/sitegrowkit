<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Menus;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use Illuminate\Support\Facades\DB;

/**
 * メニューを削除する（Phase 9.9 / STAGE 22B・SPEC §E.1・§E.5）。
 *
 * **DESTRUCTIVE。しかも戻せない。** メニューには版もゴミ箱も無い（OG9）ので、
 * 消えたら組み直すしかない。だから確認では**何件消えるか**を必ず出す。
 *
 * ## テーマが持っている本は消せない
 *
 * `managed_by = 'code'` の本は、テーマの Blade が名前で参照している（OG1）。
 * 消すと公開ページの `<x-navigation>` が指す先が無くなる。
 * **確認を出す前に**止める — 確認を出してしまうと「押せば消える」と読める。
 *
 * ## 項目は FK の cascade で消える
 *
 * `menu_items.menu_id` は cascade delete なので、本を消せば項目も消える。
 * ここで手で消さないのは、**木の一部だけ残る中間状態を作らない**ためである。
 */
final class DeleteMenu implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'menu.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $key, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $menu = GetMenu::find($key);

        if ($menu === null) {
            return Result::notFound("メニュー [{$key}] は存在しません");
        }

        if ($menu->isCodeManaged()) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict(
                    'このメニューはテーマが使っているため削除できません。名前と中身は編集できます。',
                ),
                resourceType: 'menu',
                resourceId: $menu->key,
            );
        }

        $items = MenuItem::query()->where('menu_id', $menu->getKey())->count();

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['key' => $menu->key],
            "メニュー「{$menu->name}」を削除します",
            [
                "このメニューの項目 {$items} 件も一緒に消えます",
                'メニューに履歴はありません。元に戻せません',
                'このメニューを使っている場所からは導線が消えます',
            ],
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation);
        }

        DB::transaction(static function () use ($menu): void {
            // 項目は FK の cascade で落ちる
            Menu::query()->whereKey($menu->getKey())->delete();
        });

        return $this->outcome->record(
            $this,
            $context,
            Result::success(null, ['key' => $menu->key, 'items' => $items]),
            resourceType: 'menu',
            resourceId: $menu->key,
        );
    }
}
