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
use AgentCms\Core\Spam\SpamBlocks;
use AgentCms\Core\Spam\SpamSubject;

/**
 * フォームを消す（Phase 9.12 / STAGE 25B・SPEC §D.4）。
 *
 * **受信が 1 件でも残っていれば消せない。** DB の FK も RESTRICT だが、
 * ここで先に断って、**何をすればよいか**を返す。
 * 「外部キー制約違反」とだけ言われても、利用者は次の手が分からない。
 *
 * 受け付けを止めたいだけなら「受付終了」にすればよい。その導線も文面に書く。
 */
final class DeleteForm implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly SpamBlocks $blocks,
    ) {}

    public function operationId(): string
    {
        return 'form.delete';
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

        $form = GetForm::find($key);

        if ($form === null) {
            return Result::notFound("フォーム [{$key}] は存在しません");
        }

        $submissions = (int) $form->submissions()->count();

        if ($submissions > 0) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict(
                    "このフォームには受信が {$submissions} 件あるため削除できません。"
                    .'先に受信を書き出して片付けるか、「受付終了」にしてください。',
                ),
                resourceType: 'form',
                resourceId: $key,
            );
        }

        $confirmation = $this->confirmations->require(
            $this->operationId(),
            $this->classification(),
            $context,
            ['key' => $key],
            "フォーム「{$form->name}」を削除します",
            [
                "公開 URL（/forms/{$key}）が無くなります",
                '受け付けを止めるだけなら、削除せず「受付終了」にできます',
                '入力項目の束（カスタムフィールド）は残ります',
            ],
        );

        if ($confirmation !== null) {
            return Result::confirmationRequired($confirmation);
        }

        /*
         * 弾いた件数は**明示的に片付ける**。
         *
         * 9.13 で集計表を書き込み口ごとに共用したため FK が張れなくなった
         * （`subject_id` はフォームの id のことも投稿タイプの id のこともある）。
         * DB に任せられない後始末は、消す側が自分で行う。
         */
        $this->blocks->forget(new SpamSubject(kind: 'form', id: $form->getKey()));

        $form->delete();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(null, ['key' => $key]),
            resourceType: 'form',
            resourceId: $key,
        );
    }
}
