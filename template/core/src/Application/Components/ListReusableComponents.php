<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Components;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ReusableComponent;

/**
 * 再利用 component の一覧（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * **部分木は返さない。** 一覧が答えるのは「どれが在るか・公開されているか」で、
 * 中身は 1 件ずつ引く（版の一覧が document を返さないのと同じ理由 — 大きいうえ、
 * 一覧の応答へ本文がそのまま流れる）。
 *
 * **しまったものは既定で出さない。** 出すと「使えるもの」と混ざる。
 */
final class ListReusableComponents implements Operation
{
    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'component.reusable.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context, bool $includeArchived = false): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $query = ReusableComponent::query();

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        $components = $query
            ->orderByDesc('published_at')
            ->orderBy('key')
            ->get()
            ->map(static fn (ReusableComponent $component): array => [
                'id' => $component->id,
                'key' => $component->key,
                'label' => $component->label,
                /*
                 * **起点の type。** 参照する入れ物は同じ type でなければならないので
                 * （§4.5）、置く側がこれを知らないと**置けない参照**を作ってしまう。
                 * 中身は返さないが、この 1 つだけは要る。
                 */
                'rootType' => is_string($component->draft_document['type'] ?? null)
                    ? $component->draft_document['type']
                    : null,
                // `status` は持たないので、ここで導出して見せる
                'published' => $component->isPublished(),
                'publishedAt' => $component->published_at?->toIso8601String(),
                'archived' => ! $component->isActive(),
            ])
            ->all();

        return Result::success(['components' => array_values($components)]);
    }
}
