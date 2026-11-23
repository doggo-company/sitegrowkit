<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use Illuminate\Database\QueryException;

/**
 * 投稿をゴミ箱へ移す（Phase 9.8 / STAGE 21C・Owner Decision OG2 = (c)）。
 *
 * **これが正式名である。** `content.delete` は同じことをする互換 wrapper で、
 * 呼ばれた名前のまま権限・確認・監査・応答を出す（SPEC §D.2.1 の (ii)）。
 *
 * 消さない。列を書き換えるだけなので、戻せる。
 * だから確認の文面も「元に戻せます」で、それは嘘ではない。
 */
final class TrashContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly GetContent $get,
        private readonly OperationPolicy $policy,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
        private readonly ContentTrashState $state,
    ) {}

    public function operationId(): string
    {
        return 'content.trash';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(
        string $type,
        int $id,
        OperationContext $context,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        return $this->run($this, $type, $id, $context, $lockVersion);
    }

    /**
     * 実体。**呼ばれた operation として**振る舞う。
     *
     * `content.delete` の wrapper がここを呼ぶとき、`$as` は wrapper 自身である。
     * 権限判定・確認トークンの指紋・監査・応答は、すべて `$as` の ID で出る。
     * 同じ service を 2 つの ID で登録する形（§D.2.1 の (i)）を採らないのは、
     * **どちらの名前で呼ばれたのかが後から読めなくなる**からである。
     */
    public function run(
        Operation $as,
        string $type,
        int $id,
        OperationContext $context,
        LockVersionInput|int|null $lockVersion,
    ): Result {
        $operationId = $as->operationId();
        $lock = LockVersionInput::normalize($lockVersion);

        if (! $this->policy->allows($operationId, $as->classification(), $context)) {
            return $this->outcome->record($as, $context, Result::forbidden(
                $this->policy->denialReason($operationId, $context),
            ));
        }

        /*
         * **ゴミ箱の中も含めて探す。**
         *
         * 既定除外のまま探すと、既にゴミ箱にある投稿は「見つかりません」になる。
         * それでは「もう捨ててある」と「そんなものは無い」の区別が付かない。
         * 見つけたうえで conflict を返すほうが、起きたことと応答が一致する。
         */
        $model = $this->get->findTrashed($type, $id, $context);

        if ($model instanceof Result) {
            // 見つからない・権限が無いも結末として残す
            return $this->outcome->record($as, $context, $model, resourceType: $type, resourceId: (string) $id);
        }

        if (($guard = $this->state->guard($type)) !== null) {
            return $this->outcome->record($as, $context, $guard, resourceType: $type, resourceId: (string) $id);
        }

        $schema = $this->resolver->schema($type);

        /*
         * 版の形が壊れているなら、**確認を出す前に**止める。
         * 壊れた指定のまま確認を取らせると、押した先で黙って素通りする。
         */
        if ($lock->rejected) {
            return $this->outcome->record($as, $context, Result::validationError(
                $lock->errors(), "{$schema->label}の入力内容を確認してください",
            ), resourceType: $type, resourceId: (string) $id);
        }

        $title = GetContent::titleOf($schema, $model->toArray());
        $current = EntryLockVersion::of($model);

        /*
         * 確認の指紋に **版**を混ぜる（SPEC §D.3）。
         *
         * 2 つ効く。1 つは、確認を取ってから実行するまでに誰かが更新していたら
         * その確認は使えなくなること。もう 1 つは、**物理削除だったころに
         * 取った確認**が、意味の変わった今の操作へ流れ込まないことである。
         */
        $confirmation = $this->confirmations->require(
            $operationId,
            $as->classification(),
            $context,
            ['type' => $type, 'id' => $id, 'lock_version' => $current],
            summary: "{$schema->label}「{$title}」をゴミ箱へ移動します",
            impact: [
                'ゴミ箱へ移動します。ゴミ箱からは元に戻せます',
                '公開ページからは表示されなくなります',
            ],
        );

        if ($confirmation !== null) {
            // 確認で止まったことも結末である。**何に対する確認か**まで残す
            return $this->outcome->record(
                $as,
                $context,
                Result::confirmationRequired($confirmation),
                resourceType: $type,
                resourceId: (string) $id,
            );
        }

        try {
            $written = $this->state->trash($type, $id, $lock->version, $context->actorId, $schema);
        } catch (ContentWriteAbort $abort) {
            return $this->outcome->record($as, $context, $abort->result, resourceType: $type, resourceId: (string) $id);
        } catch (QueryException $e) {
            // SQL・接続情報を利用者へ出さない（ログにだけ残す）
            report($e);

            return $this->outcome->record($as, $context, Result::conflict(
                'ほかの操作と重なったため処理できませんでした。もう一度お試しください。',
            ), resourceType: $type, resourceId: (string) $id);
        }

        return $this->outcome->record(
            $as,
            $context,
            Result::success(
                ['id' => $id, 'state' => 'trashed', 'lock_version' => $written['lockVersion']],
                ['type' => $type, 'title' => $title],
            ),
            resourceType: $type,
            resourceId: (string) $id,
            metadata: array_merge(
                ['title' => $title, 'state' => 'trashed'],
                EntryLockVersion::auditMetadata($lock->version, $written['lockVersion']),
            ),
        );
    }
}
