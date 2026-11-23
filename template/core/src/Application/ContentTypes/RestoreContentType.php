<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

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
 * 無効にした投稿タイプを元に戻す（Phase 9.7 / STAGE 20C・SPEC §F.2）。
 *
 * **同じ ID・同じテーブル・同じデータへ戻る。** archive が何も消していないので、
 * 復元は状態を 1 つ書き戻すだけで済む。
 *
 * 確認を要らないのは、**値を失う操作ではない**からである。
 * 取り返しのつく操作に、取り返しのつかない操作と同じ手数を要求しない。
 */
final class RestoreContentType implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly ContentTypeSettingsWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'content.type.restore';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(string $id, int $expectedLockVersion, OperationContext $context): Result
    {
        $guard = ContentTypeGuard::for($this, $this->policy, $this->outcome, $this->schemas, $this->resolver, $id, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $settings = $this->resolver->for($id);

        if ($settings->status === ContentTypeStatus::Active) {
            return $this->outcome->record($this, $context, Result::conflict('この投稿タイプは無効になっていません'),
                resourceType: 'content_type', resourceId: $id);
        }

        if (! $this->writer->save($id, ['status' => ContentTypeStatus::Active->value], $expectedLockVersion)) {
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
            Result::success(['contentType' => ['id' => $id, 'status' => ContentTypeStatus::Active->value, 'lockVersion' => $expectedLockVersion + 1]]),
            resourceType: 'content_type',
            resourceId: $id,
            metadata: ['changed' => ['status']],
        );
    }
}
