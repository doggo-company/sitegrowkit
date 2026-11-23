<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\PageStatus;
use Illuminate\Support\Facades\DB;

/**
 * ページをゴミ箱へ入れる（Phase 9.1D・owner 決定 2）。
 *
 * **物理削除（purge）ではない。** 復元できることを前提に、
 * 公開から外して保管状態にする。purge は AI へ公開しない。
 */
final class TrashPage implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.trash';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(int $pageId, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            // 見つからない・権限が無いも結末として残す（Correction 2-5）
            return $this->outcome->record($this, $context, $page, resourceType: 'page', resourceId: (string) $pageId);
        }

        if ($page->status === PageStatus::Trashed) {
            return $this->outcome->record($this, $context, Result::conflict('このページはすでにゴミ箱にあります'));
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['pageId' => $page->id],
            summary: "「{$page->label()}」をゴミ箱へ移動します",
            impact: [
                '公開サイトから見えなくなります',
                ...self::menuImpact($page->id),
                'ゴミ箱からは元に戻せます',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation));
        }

        $page->forceFill([
            'status' => PageStatus::Trashed->value,
            'trashed_at' => now(),
        ])->save();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['page' => GetPage::present($page->refresh())]),
            resourceType: 'page',
            resourceId: (string) $page->id,
            metadata: ['slug' => $page->slug],
        );
    }

    /**
     * このページを指しているメニューの項目（UX 一周 13 周目 R13-3）。
     *
     * ゴミ箱のページを指す項目は公開サイトのメニューから**黙って落ちる**（MenuTree の OG4）。
     * 移したあとで公開サイトを見るまで気づかないので、移す前の確認に書く。
     *
     * @return list<string>
     */
    private static function menuImpact(int $pageId): array
    {
        $rows = DB::table('menu_items')
            ->join('menus', 'menus.id', '=', 'menu_items.menu_id')
            ->where('menu_items.page_id', $pageId)
            ->where('menu_items.link_type', 'page')
            ->orderBy('menus.id')
            ->pluck('menus.name')
            ->unique()
            ->values()
            ->all();

        if ($rows === []) {
            return [];
        }

        $names = implode('・', array_map(fn ($name): string => "「{$name}」", $rows));

        return ["サイトのメニュー {$names} に入っています。移すと、公開サイトのメニューからこの項目が消えます（代わりのページがあれば、メニューで差し替えてください）"];
    }
}
