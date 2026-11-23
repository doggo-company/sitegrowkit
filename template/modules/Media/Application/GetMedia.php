<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\MediaUsageInspector;
use Modules\Media\Models\Media;

/** メディア 1 件の取得（Phase 9.1B / STEP 9） */
final class GetMedia implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly MediaUsageInspector $inspector,
    ) {}

    public function operationId(): string
    {
        return 'media.get';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        $media = $this->find($id, $context);

        if ($media instanceof Result) {
            return $media;
        }

        return Result::success(['media' => $this->present($media)]);
    }

    /** 失敗時は Result を返す（他の Media 操作から再利用する） */
    public function find(int $id, OperationContext $context): Media|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $media = Media::query()->find($id);

        return $media ?? Result::notFound("メディア（ID: {$id}）は見つかりません");
    }

    /**
     * 使用箇所つきの表示用データ。削除可否の判断材料を必ず添える（ADR-0006）。
     *
     * @return array<string, mixed>
     */
    public function present(Media $media): array
    {
        $usages = $this->inspector->usages($media->id);

        return [
            ...$media->toPickerArray(),
            'has_webp' => $media->has_webp,
            'used' => $usages !== [],
            'usages' => $usages,
        ];
    }
}
