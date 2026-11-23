<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\ContentTypeStatus;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 投稿タイプを無効にする（Phase 9.7 / STAGE 20C・SPEC §F.2）。
 *
 * **何も消さない。** 定義もテーブルも投稿も taxonomy 割り当ても media 参照も監査も残る。
 * 変わるのは「日常運用の導線から外れ、書けなくなる」ことだけで、復元すれば元に戻る。
 *
 * それでも Destructive として扱う。サイドバーから型が消え、投稿を保存できなくなるのは
 * 利用者にとって十分に大きな変化で、確認なしに起こしてよいことではない。
 */
final class ArchiveContentType implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly ContentTypeSettingsWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'content.type.archive';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $id, int $expectedLockVersion, OperationContext $context): Result
    {
        $guard = ContentTypeGuard::for($this, $this->policy, $this->outcome, $this->schemas, $this->resolver, $id, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $settings = $this->resolver->for($id);

        if ($settings->status === ContentTypeStatus::Archived) {
            return $this->outcome->record($this, $context, Result::conflict('この投稿タイプは既に無効です'),
                resourceType: 'content_type', resourceId: $id);
        }

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classification(),
            $context,
            // **期待版を指紋に含める**（SPEC §J.5）。確認してから適用するまでに
            // 他者が更新したら、トークンごと食い違って止まる
            ['id' => $id, 'lock_version' => $expectedLockVersion],
            "投稿タイプ「{$guard->label}」を無効にします",
            [
                '管理メニューから外れ、新しい投稿の作成と保存ができなくなります',
                '投稿・分類の割り当て・メディアの参照・操作履歴は削除されません',
                'いつでも元に戻せます',
            ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation),
                resourceType: 'content_type', resourceId: $id);
        }

        if (! $this->writer->save($id, ['status' => ContentTypeStatus::Archived->value], $expectedLockVersion)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作でこの投稿タイプが更新されました。読み込み直してからやり直してください'),
                resourceType: 'content_type',
                resourceId: $id,
                metadata: ['expectedVersion' => $expectedLockVersion, 'actualVersion' => $this->writer->currentLockVersion($id)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['contentType' => ['id' => $id, 'status' => ContentTypeStatus::Archived->value, 'lockVersion' => $expectedLockVersion + 1]]),
            resourceType: 'content_type',
            resourceId: $id,
            metadata: ['changed' => ['status']],
        );
    }
}
