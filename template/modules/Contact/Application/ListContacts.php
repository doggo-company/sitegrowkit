<?php

declare(strict_types=1);

namespace Modules\Contact\Application;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Contact\Models\ContactStatus;
use Modules\Contact\Models\ContactSubmission;

/** 問い合わせ一覧（Phase 9.1B / STEP 9） */
final class ListContacts implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'contact.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(OperationContext $context, string $status = '', int $page = 1, int $perPage = 20): Result
    {
        $paginator = $this->paginate($context, $status, $perPage, $page);

        if ($paginator instanceof Result) {
            return $paginator;
        }

        return Result::success(
            ['items' => $paginator->getCollection()->map(static fn ($i) => $i->toArray())->all()],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'status' => $status,
            ],
        );
    }

    /**
     * 失敗時は Result を返す。
     *
     * @return LengthAwarePaginator<int, ContactSubmission>|Result
     */
    public function paginate(OperationContext $context, string $status = '', int $perPage = 20, ?int $page = null): LengthAwarePaginator|Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $query = ContactSubmission::query()->latest('id');

        // 未知の status は「絞り込みなし」として扱う（不正値でエラーにしない既存挙動）
        if ($status !== '' && in_array($status, array_column(ContactStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        return $query->paginate(
            max(1, min($perPage, self::MAX_PER_PAGE)),
            ['*'],
            'page',
            $page === null ? null : max(1, $page),
        );
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return array_map(
            static fn (ContactStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
            ContactStatus::cases(),
        );
    }
}
