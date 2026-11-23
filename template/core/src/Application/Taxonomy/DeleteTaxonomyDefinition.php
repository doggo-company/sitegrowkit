<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Support\Facades\DB;

/**
 * 一度も使っていない分類の定義だけを消す（Phase 9.7 / STAGE 20D・SPEC §H.4）。
 *
 * **これは purge ではない。** 消えるのは「作ったが 1 度も使わなかった定義」だけで、
 * 項目や割り当てを持つ分類はここを通らない（無効にするしかない）。
 *
 * 通す条件は 4 つで、**1 つでも欠けたら拒否する**。
 *
 * | 条件 | なぜ |
 * | --- | --- |
 * | 管理画面が作った定義であること | ファイルが正の定義を DB から消せない |
 * | 項目が 0 件 | 項目が在るなら誰かが使っている |
 * | **項目を作った履歴が 0 件** | 「作って消した」分類は「未使用」ではない。件数だけでは足りない |
 * | 投稿タイプとの関連付けが 0 件 | 付いている型があるなら使う気がある |
 *
 * 判定できないとき（監査表を読めない等）も**拒否する**。
 */
final class DeleteTaxonomyDefinition implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $key, int $expectedLockVersion, OperationContext $context): Result
    {
        $guard = TaxonomyDefinitionGuard::for($this, $this->policy, $this->outcome, $key, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $taxonomy = $guard->taxonomy;

        $blocked = $this->blockingReason($taxonomy->id, $key);

        if ($blocked !== null) {
            return $this->outcome->record($this, $context, Result::conflict($blocked),
                resourceType: 'taxonomy', resourceId: $key);
        }

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['key' => $key, 'lock_version' => $expectedLockVersion],
            "分類「{$taxonomy->label}」の定義を削除します",
            [
                'この分類は一度も使われていません（項目 0 件・作成履歴なし・関連付けなし）',
                '**元に戻せません。** 同じ分類がまた要るなら、作り直すことになります',
                '使ったことのある分類は、この操作では消せません（無効にしてください）',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation),
                resourceType: 'taxonomy', resourceId: $key);
        }

        // 確認から適用までの間に使われ始めていないか、もう一度見る
        $blocked = $this->blockingReason($taxonomy->id, $key);

        if ($blocked !== null) {
            return $this->outcome->record($this, $context, Result::conflict($blocked),
                resourceType: 'taxonomy', resourceId: $key);
        }

        if (! $this->writer->delete($taxonomy->id, $expectedLockVersion)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作でこの分類が更新されました。読み込み直してからやり直してください'),
                resourceType: 'taxonomy',
                resourceId: $key,
                metadata: ['expectedVersion' => $expectedLockVersion, 'actualVersion' => $this->writer->lockVersionOf($taxonomy->id)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['taxonomy' => ['key' => $key]]),
            resourceType: 'taxonomy',
            resourceId: $key,
        );
    }

    /** 消せない理由（消してよいなら null）。**判定できないときも理由を返す** */
    private function blockingReason(string $taxonomyId, string $key): ?string
    {
        $terms = TaxonomyTerm::query()->where('taxonomy_id', $taxonomyId)->count();

        if ($terms > 0) {
            return "この分類には {$terms} 件の項目があります。削除ではなく無効にしてください";
        }

        $associations = $this->writer->associationCount($taxonomyId);

        if ($associations > 0) {
            return "この分類は {$associations} 件の投稿タイプに関連付けられています。先に関連付けを外してください";
        }

        try {
            // 項目の作成は `term.create` として記録され、metadata に分類の key が入る
            // （`CreateTerm`）。**成功も失敗も数える** — 試した跡があるなら未使用ではない
            $created = DB::table('audit_logs')
                ->where('operation', 'term.create')
                ->where('metadata->taxonomy', $key)
                ->count();
        } catch (\Throwable) {
            // **確かめられなかったので通す、はしない**
            return '過去に項目が作られたかどうかを確認できませんでした。削除ではなく無効にしてください';
        }

        if ($created > 0) {
            return '過去にこの分類へ項目が作られています。削除ではなく無効にしてください';
        }

        return null;
    }
}
