<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use Illuminate\Database\QueryException;

/**
 * ゴミ箱から戻す（Phase 9.8 / STAGE 21C・Owner Decision OG3 = (a)・OG6 = (a)）。
 *
 * **確認は取らない。** 戻すこと自体は何も失わないからで、これはページの
 * `RestorePage` と同じ作法である。取り返しがつかない操作にだけ確認を要求する、
 * という線をここでも動かさない。
 *
 * **AI へは公開しない。** `OperationCatalog` に載せず、管理画面と同一オリジンの
 * 管理 API からだけ呼べる。まずは人の操作に留める（後から出すのは容易い）。
 *
 * 「過去の版へ戻す」（`content.revision.restore`）とは別の操作である（SPEC §D.2.2）。
 * こちらが変えるのは**保管状態**だけで、内容には触れない。
 */
final class RestoreContent implements Operation
{
    public function __construct(
        private readonly ContentTypeResolver $resolver,
        private readonly GetContent $get,
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ContentTrashState $state,
    ) {}

    public function operationId(): string
    {
        return 'content.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(
        string $type,
        int $id,
        OperationContext $context,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        $lock = LockVersionInput::normalize($lockVersion);

        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        /*
         * **ゴミ箱の中を探す。** 既定の read はゴミ箱を除くので、
         * 素直に `find()` すると戻したい相手が「見つかりません」になる。
         */
        $model = $this->get->findTrashed($type, $id, $context);

        if ($model instanceof Result) {
            return $this->outcome->record($this, $context, $model, resourceType: $type, resourceId: (string) $id);
        }

        if (($guard = $this->state->guard($type)) !== null) {
            return $this->outcome->record($this, $context, $guard, resourceType: $type, resourceId: (string) $id);
        }

        $schema = $this->resolver->schema($type);

        // 版の形が壊れているなら、状態を変える前に止める
        if ($lock->rejected) {
            return $this->outcome->record($this, $context, Result::validationError(
                $lock->errors(), "{$schema->label}の入力内容を確認してください",
            ), resourceType: $type, resourceId: (string) $id);
        }

        $title = GetContent::titleOf($schema, $model->toArray());

        try {
            $written = $this->state->restore($type, $id, $lock->version, $schema, $context);
        } catch (ContentWriteAbort $abort) {
            return $this->outcome->record($this, $context, $abort->result, resourceType: $type, resourceId: (string) $id);
        } catch (QueryException $e) {
            report($e);

            return $this->outcome->record($this, $context, Result::conflict(
                'ほかの操作と重なったため処理できませんでした。もう一度お試しください。',
            ), resourceType: $type, resourceId: (string) $id);
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                array_filter([
                    'id' => $id,
                    'state' => 'active',
                    'lock_version' => $written['lockVersion'],
                    // 公開状態を持つ型は下書きへ戻る。呼び出し側がそれを読めるようにする
                    'status' => $written['status'],
                ], static fn (mixed $value): bool => $value !== null),
                ['type' => $type, 'title' => $title],
            ),
            resourceType: $type,
            resourceId: (string) $id,
            metadata: array_merge(
                ['title' => $title, 'state' => 'active'],
                EntryLockVersion::auditMetadata($lock->version, $written['lockVersion']),
            ),
        );
    }
}
