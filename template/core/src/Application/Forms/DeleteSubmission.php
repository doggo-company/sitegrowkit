<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;

/**
 * 受信を 1 件消す（R-12 / 2026-08-30）。
 *
 * ## なぜ要るのか
 *
 * 🔴 **受信は個人情報である。** 名前・メールアドレス・本文がそのまま入っている。
 * 「消してほしい」と言われたときに応じられない状態で配るわけにいかない。
 *
 * 2026-08-30 の実測では、受信に対して**読む・書き出す・状態を変える**はあったが、
 * **消す道が route ごと無かった**。管理画面にも API にも無い＝
 * **削除依頼に応じるには DB を直接触るしかない**。編集担当者にはできない。
 *
 * ## ゴミ箱にしない
 *
 * 投稿は `content.trash` でゴミ箱へ入るが、**受信はそのまま消す**。
 * 消す理由が「本人が消してと言ったから」なので、
 * **戻せる場所に置いたままにすると依頼に応えたことにならない。**
 *
 * ## だから確認を挟む
 *
 * 戻せない操作なので `ConfirmationGate` を通す（`DeleteForm` と同じ作法）。
 * **押した人が何を失うかを読んでから消す。**
 *
 * ## 権限の軸を増やさない
 *
 * 「対応できる人」は `contact.write` で定義済み（`UpdateSubmissionStatus` と同じ）。
 * 受信専用の軸を足すと、同じ役割に 2 か所で権限を与えることになる。
 */
final class DeleteSubmission implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $confirmations,
    ) {}

    public function operationId(): string
    {
        return 'form.submission.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $submission = GetSubmission::find($id);

        if ($submission === null) {
            return Result::notFound("受信 [{$id}] は存在しません");
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['id' => $id],
            'この受信を完全に削除します',
            [
                '入力された内容・お名前・メールアドレスがすべて消えます',
                'ゴミ箱には入りません。元に戻せません',
                '残しておきたい場合は、先に CSV で書き出してください',
            ],
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation);
        }

        $submission->delete();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(null, ['id' => $id]),
            resourceType: 'form_submission',
            resourceId: (string) $id,
        );
    }
}
