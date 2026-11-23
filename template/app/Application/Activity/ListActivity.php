<?php

declare(strict_types=1);

namespace App\Application\Activity;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Audit\AuditLog;
use App\Models\User;
use App\Support\Role;

/**
 * 行動履歴の一覧（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 全体履歴もユーザー別履歴も **同じ 1 つの操作**を通す
 * （ユーザー別は `actorId` を固定した絞り込みにすぎない）。
 * 権限を持たない利用者は、自分の分も含めて履歴を開けない。
 *
 * 一覧は必ずページングする。IP / User-Agent は **オーナーにだけ**返す。
 */
final class ListActivity implements Operation
{
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly ActivityPresenter $presenter,
    ) {}

    public function operationId(): string
    {
        return 'activity.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        ActivityFilter $filter,
        OperationContext $context,
        int $page = 1,
        int $perPage = 30,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $paginator = $filter->apply(AuditLog::query())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(1, min($perPage, self::PER_PAGE_MAX)),
                page: max(1, $page),
            );

        $includeNetwork = $this->maySeeNetworkContext($context);

        return Result::success(
            [
                'entries' => array_map(
                    fn (AuditLog $log): array => $this->presenter->present($log, $includeNetwork),
                    $paginator->items(),
                ),
                'filter' => $filter->toArray(),
            ],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'includesNetworkContext' => $includeNetwork,
            ],
        );
    }

    /** IP と User-Agent は個人情報なので、オーナーにだけ見せる */
    public function maySeeNetworkContext(OperationContext $context): bool
    {
        if ($context->actorId === null) {
            return false;
        }

        $user = User::query()->find($context->actorId);

        return $user !== null && $user->is_active && in_array($user->role(), [Role::Owner, Role::Developer], true);
    }
}
