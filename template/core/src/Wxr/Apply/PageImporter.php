<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Apply;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PageRepository;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Ledger\PageSnapshot;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use AgentCms\Core\Wxr\Plan\PageFields;
use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\WxrItem;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ページを入れる（release-20260909 / wxr-pages）。
 *
 * ## 既存の Application Service を通す
 *
 * Page の作成は `CreatePage` を通る（validation・policy・監査・slug の下書き）。
 * 本文の版は `PageRepository::createVersion()`（Admin の保存と同じ口）で入れる。
 * **取り込み専用の書き込み経路を作らない** — `ContentImporter` と同じ理由。
 *
 * ## 公開も `PublishPage` を通す
 *
 * 公開の検証（PB 規則・予約日時の読み方・監査）を 2 か所に持たない。
 * `PublishPage` は CLI からだと確認 token を要求するので、1 度目の返事の token を
 * そのまま渡して 2 度目で通す。人の確認は plan（hash 照合・`--i-have-a-backup`）で
 * 済んでいる — MCP が確認を返す経路と同じ形で、専用の書き込み口は作らない。
 *
 * ## 台帳に無いページを作らない
 *
 * Page・版・台帳は**同じ transaction**。分けると、その間に落ちたときに
 * 台帳に無いページが残り、取り消しから見えなくなる。
 */
final class PageImporter
{
    public function __construct(
        private readonly ItemTarget $targets,
        private readonly LedgerReconciler $ledger,
        private readonly CreatePage $create,
        private readonly PublishPage $publish,
        private readonly PageRepository $pages,
        private readonly PageFields $fields,
    ) {}

    /**
     * 入れて、**作った件数**を返す（plan の予定ではない）。
     *
     * @param  array<string, string>  $mediaUrls  元の URL => こちら側の URL（取り込めたものだけ）
     * @return array<string, int>
     */
    public function import(
        WxrReader $reader,
        ImportOptions $options,
        string $batchId,
        OperationContext $context,
        array $mediaUrls = [],
    ): array {
        $counts = [];

        foreach ($reader->items() as $item) {
            $target = $this->targets->of($item, $options);

            if ($target === null || $target['kind'] !== ImportKind::Page) {
                continue;
            }

            $action = $this->actionFor($item);

            $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;

            if ($action === PlanAction::Create) {
                $this->createOne($item, $batchId, $context, $mediaUrls);
            }
        }

        return $counts;
    }

    /** plan と同じ道（`LedgerReconciler`）で行動を決める。理由は plan が出し済みなので捨てる */
    private function actionFor(WxrItem $item): PlanAction
    {
        $key = new LedgerKey(ImportKind::Page, ItemTarget::PAGE_TARGET, $item->postId);

        return $this->ledger->reconcile([$key], new PlanFindings)[0]->action(false);
    }

    /**
     * @param  array<string, string>  $mediaUrls
     */
    private function createOne(WxrItem $item, string $batchId, OperationContext $context, array $mediaUrls): void
    {
        DB::transaction(function () use ($item, $batchId, $context, $mediaUrls): void {
            $result = $this->create->execute(
                ['title' => PageFields::title($item), 'slug' => $this->fields->slug($item)],
                $context,
            );

            if ($result->status !== OperationStatus::Success) {
                // **黙って飛ばさない。** 外来の値は文面へ入れない
                throw new RuntimeException(
                    "wp:post_id {$item->postId} のページを入れられませんでした（{$result->status->value}）",
                );
            }

            $id = (int) ($result->data['page']['id'] ?? 0);

            if ($id === 0) {
                throw new RuntimeException("wp:post_id {$item->postId} のページの作成結果を確認できませんでした");
            }

            /** @var Page $page */
            $page = Page::query()->findOrFail($id);

            $document = $this->fields->document($item, $mediaUrls);

            // 本文は **人が編集画面で保存するのと同じ口**で版にする（`CreatePage` の空の版の次の版）
            $version = $this->pages->createVersion($page, $document, $context->interface->value, $context->actorId);

            $seo = PageFields::seo($item);

            if ($seo !== null) {
                $page->forceFill(['seo' => $seo])->save();
            }

            if (PageFields::publishes($item)) {
                $this->publish($page->id, $item, $context);
            }

            DB::table('import_records')->insert([
                'batch_id' => $batchId,
                'external_id' => $item->postId,
                'kind' => ImportKind::Page->value,
                'target_type' => ItemTarget::PAGE_TARGET,
                'target_id' => (string) $id,
                'created_by_import' => true,
                /*
                 * **取り込んだ直後の姿を残す。** 取り消しは時刻ではなくこれと比べる —
                 * 同じ秒の編集でも一致しなくなるので、人が触ったものを消さない。
                 * 行の値と版の id/revision/本文 hash だけ（本文の複製は持たない）。
                 */
                'snapshot' => PageSnapshot::of($id),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * `PublishPage` を通して公開する。**確認は 1 度目の返事の token で通す。**
     *
     * 予約（`future`）は `publishedAt` に元の日時を渡す — 未来かどうかの判定も
     * `PublishPage` が行う。空本文・表せない本文は plan が止めているので、
     * ここで validation error が返るのは組み立ての誤りである。
     */
    private function publish(int $pageId, WxrItem $item, OperationContext $context): void
    {
        $at = PageFields::publishedAt($item);

        if ($item->status === PageFields::SCHEDULED && $at === null) {
            throw new RuntimeException("wp:post_id {$item->postId} の予約日時を読めませんでした");
        }

        $publishedAt = $at?->toIso8601String();

        $result = $this->publish->execute($pageId, $context, $publishedAt);

        if ($result->status === OperationStatus::ConfirmationRequired && $result->confirmation !== null) {
            $result = $this->publish->execute(
                $pageId,
                $context->withConfirmationToken($result->confirmation->token),
                $publishedAt,
            );
        }

        if ($result->status !== OperationStatus::Success) {
            throw new RuntimeException(
                "wp:post_id {$item->postId} のページを公開できませんでした（{$result->status->value}）",
            );
        }
    }
}
