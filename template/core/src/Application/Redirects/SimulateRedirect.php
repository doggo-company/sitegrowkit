<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Redirects;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectPath;
use AgentCms\Core\Redirects\RedirectPresenter;
use AgentCms\Core\Redirects\RedirectResolver;

/**
 * 踏まずに結果を見る（Phase 9.10 / STAGE 23B・SPEC §F.3）。
 *
 * **301 はブラウザが強く覚える。** 試しに踏んで確かめると、
 * ルールを直したあとも自分のブラウザだけ古い転送先へ飛び続ける。
 * だから「踏まずに分かる」手段を必ず用意する。
 *
 * 実際の転送と**同じ `RedirectResolver`** を使う。見せた結果と起きることは、
 * 2 つが同じものを見ているからこそ一致する。
 *
 * 何も変えないので **AI へ公開してよい**（OG8）。ヒット数も増やさない。
 */
final class SimulateRedirect implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly RedirectResolver $resolver,
    ) {}

    public function operationId(): string
    {
        return 'redirect.simulate';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Read;
    }

    public function execute(string $url, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return Result::forbidden($this->policy->denialReason($this->operationId(), $context));
        }

        $path = RedirectPath::normalize($url);

        if ($path === null) {
            return Result::validationError(['url' => ['パスとして読めません']]);
        }

        // query は転送先の組み立てに効くので、入力から取り出して渡す
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');

        if (RedirectResolver::isProtected($path)) {
            return Result::success(
                RedirectPresenter::decision(null, $path) + [
                    'protected' => true,
                    'reason' => '管理画面や API のパスは、ルールがあっても転送されません',
                ],
            );
        }

        return Result::success(
            RedirectPresenter::decision($this->resolver->resolve($path, $query), $path)
                + ['protected' => false],
        );
    }
}
