<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Support\Role;
use Illuminate\Database\Eloquent\Builder;

/**
 * 利用者の一覧（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 検索・ロール・状態で絞り込み、必ずページングする
 * （利用者が増えても 1 画面で全件を読み込まない）。
 */
final class ListUsers implements Operation
{
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly UserPresenter $presenter,
    ) {}

    public function operationId(): string
    {
        return 'user.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(
        OperationContext $context,
        string $search = '',
        string $role = '',
        string $status = '',
        int $page = 1,
        int $perPage = 20,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $paginator = $this->query($search, $role, $status)
            ->orderByRaw("case when role = 'owner' then 0 else 1 end")
            ->orderBy('name')
            ->paginate(
                perPage: max(1, min($perPage, self::PER_PAGE_MAX)),
                page: max(1, $page),
            );

        return Result::success(
            [
                'users' => array_map(
                    fn (User $user): array => $this->presenter->present($user, $context),
                    $paginator->items(),
                ),
            ],
            [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        );
    }

    /** @return Builder<User> */
    private function query(string $search, string $role, string $status): Builder
    {
        return User::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                // LIKE のワイルドカードを入力からエスケープする
                $escaped = addcslashes($search, '%_\\');

                $query->where(function (Builder $inner) use ($escaped): void {
                    $inner->where('name', 'like', "%{$escaped}%")
                        ->orWhere('email', 'like', "%{$escaped}%");
                });
            })
            ->when(Role::tryFrom($role) !== null, fn (Builder $query) => $query->where('role', $role))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($status === 'invited', fn (Builder $query) => $query
                ->whereNotNull('invited_at')
                ->whereNull('last_login_at'));
    }
}
