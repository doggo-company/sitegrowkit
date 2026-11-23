<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Forms;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Forms\FormPresenter;
use AgentCms\Core\Forms\FormSubmission;

/**
 * 受信の一覧（Phase 9.12 / STAGE 25B・SPEC §D.2）。
 *
 * **一覧に本文を全部積まない。** 最初の項目の頭だけを出す（`summary`）。
 * 個人情報を、必要のない画面へ広く展開しない。
 *
 * 検索は `data` の中の**全文一致**に限る。JSON で持っている以上それ以上はできず、
 * できないことは画面で正直に書く（SPEC §D.2）。
 */
final class ListSubmissions implements Operation
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly OperationPolicy $policy) {}

    public function operationId(): string
    {
        return 'form.submission.list';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    /**
     * @param  bool  $onlyUndelivered  通知メールが届かなかったものだけに絞る
     *                                 （WP 一周 10 周目 2026-09-04）。
     *                                 サイドバーの赤い数から来た人が、**どれか**をすぐ見られるように。
     */
    public function execute(
        string $key,
        OperationContext $context,
        string $search = '',
        int $page = 1,
        int $perPage = 50,
        bool $onlyUndelivered = false,
    ): Result {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $form = GetForm::find($key);

        if ($form === null) {
            return Result::notFound("フォーム [{$key}] は存在しません");
        }

        $search = trim($search);

        $paginator = FormSubmission::query()
            ->where('form_id', $form->getKey())
            ->when($search !== '', static fn ($q) => $q->where('data', 'like', "%{$search}%"))
            ->when($onlyUndelivered, static fn ($q) => $q
                ->whereNull('admin_notified_at')
                ->whereNotNull('admin_notification_error'))
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, self::MAX_PER_PAGE)), ['*'], 'page', max(1, $page));

        return Result::success(
            [
                'submissions' => array_map(
                    static fn (FormSubmission $s): array => FormPresenter::submissionSummary($s),
                    $paginator->items(),
                ),
            ],
            [
                'key' => $form->key,
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'search' => $search,
                'delivery' => $onlyUndelivered ? 'failed' : 'all',
            ],
        );
    }
}
