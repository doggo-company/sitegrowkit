<?php

declare(strict_types=1);

namespace Modules\Media\Application;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaUsageInspector;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Media\Models\Media;

/** メディア一覧（Phase 9.1B / STEP 9） */
final class ListMedia implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly MediaUsageInspector $inspector,
    ) {}

    public function operationId(): string
    {
        return 'media.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context, int $page = 1, int $perPage = 24, ?MediaKind $kind = null): Result
    {
        $paginator = $this->paginate($context, $perPage, $page, $kind);

        if ($paginator instanceof Result) {
            return $paginator;
        }

        return Result::success(
            ['items' => $this->withUsage($paginator)],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        );
    }

    /**
     * 失敗時は Result を返す。
     *
     * `$kind` を渡すとその種別だけを返す（タブ表示。null = すべて）。
     *
     * @return LengthAwarePaginator<int, Media>|Result
     */
    public function paginate(
        OperationContext $context,
        int $perPage = 24,
        ?int $page = null,
        ?MediaKind $kind = null,
    ): LengthAwarePaginator|Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        return Media::query()
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind?->value))
            // 派生物は 1 行ごとに引かない（一覧で N+1 にしない）
            ->with('variants')
            ->latest('id')
            ->paginate(
                max(1, min($perPage, self::MAX_PER_PAGE)),
                ['*'],
                'page',
                $page === null ? null : max(1, $page),
            );
    }

    /**
     * 一覧の各行に「使用中かどうか」を付ける。削除できるかを一覧で判断できるようにするため。
     *
     * @param  LengthAwarePaginator<int, Media>  $paginator
     * @return list<array<string, mixed>>
     */
    public function withUsage(LengthAwarePaginator $paginator): array
    {
        /** @var list<int> $ids */
        $ids = $paginator->getCollection()->pluck('id')->all();
        $usedMap = $this->inspector->usedMap($ids);

        return array_values($paginator->getCollection()
            ->map(fn (Media $m): array => [...$m->toPickerArray(), 'used' => $usedMap[$m->id] ?? false])
            ->all());
    }
}
