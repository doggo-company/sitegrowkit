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
use AgentCms\Core\Pages\PageVersion;

/**
 * 過去の版へ戻す（Phase 9.6 / P0-C6c・SPEC §4.12）。
 *
 * ## 過去を書き換えない。**新しい版を作る**
 *
 * 復元は「その版の中身で、**新しい draft を作る**」である。
 * 公開中の版も、戻す元の版も、1 バイトも変わらない。
 * 過去を書き換える形にすると、**戻したあとに「本当は何だったか」を誰も言えなくなる**。
 *
 * ## 自動保存ではない
 *
 * `createVersion()` の既定（`autosave = false`）のまま作る。**間引きの対象にしない**
 * （§4.11 — 利用者が意図して作った版だからで、自動で増えた版とは違う）。
 *
 * ## 確認を求める
 *
 * 公開面は変わらないので破壊的ではない。**が、編集中の内容は上書きされる**。
 * 打ちかけのものが消えるので、実行の前に確認する（§4.12）。
 *
 * ## AI へは出さない
 *
 * `OperationCatalog` に足さない（§E.3 の `wxr.rollback` と同じ系譜）。
 * 復元は**人が編集している draft を上書きする**操作で、エージェントには
 * **誰かが編集中かを知る手段が無い** — 取り消しも復元も `expectedVersion` を
 * 持たないからである（§4.10 の前提注記）。「AI が押せる復元」は、
 * **人の編集を黙って巻き戻せる**ことを意味する。
 */
final class RestorePageVersion implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly PageRepository $pages,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.document.restore';
    }

    /**
     * 公開面も過去の版も変わらないが、**編集中の内容は上書きされる**ので `Write` である。
     * 失われるものがあるなら、それは分類に出す。
     */
    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(int $pageId, int $revision, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $page;
        }

        $source = PageVersion::query()
            ->where('page_id', $page->id)
            ->where('revision', $revision)
            ->first();

        if (! $source instanceof PageVersion) {
            return $this->outcome->record(
                $this,
                $context,
                Result::notFound("バージョン {$revision} は見つかりません"),
                resourceType: 'page',
                resourceId: (string) $page->id,
            );
        }

        /*
         * **分類ではなく、この操作の都合で確認を求める**（`requireAlways()`）。
         *
         * 分類は `Write` である（公開面も過去も変わらない）。しかし
         * **打ちかけの編集は置き換わる**ので、分類に任せると確認が出ない。
         * 失われるものがある操作は、分類が何であれ訊く。
         */
        $confirmation = $this->confirmations->requireAlways(
            $this->operationId(),
            $context,
            ['pageId' => $page->id, 'revision' => $revision],
            summary: "「{$page->label()}」をバージョン {$revision} の内容に戻します",
            impact: [
                '編集中の内容は、この操作で置き換わります',
                '公開中のページと過去のバージョンは変わりません',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record(
                $this,
                $context,
                Result::confirmationRequired($confirmation),
                resourceType: 'page',
                resourceId: (string) $page->id,
            );
        }

        /*
         * **`autosave` を渡さない**（既定の `false` のまま）。
         * 意図して作った版なので、間引きの対象にしない（§4.11）。
         */
        $version = $this->pages->createVersion(
            $page,
            $source->document,
            $context->interface->value,
            $context->actorId,
        );

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['document' => $version->document],
                ['pageId' => $page->id, 'version' => $version->revision, 'restoredFrom' => $revision],
            ),
            resourceType: 'page',
            resourceId: (string) $page->id,
            // **中身は載せない。** 監査が運ぶのは「どの版から戻したか」だけ（§4.12）
            metadata: ['restoredFrom' => $revision, 'created' => $version->revision],
        );
    }
}
