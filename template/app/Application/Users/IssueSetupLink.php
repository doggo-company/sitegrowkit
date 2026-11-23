<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Support\SafeFailureLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * パスワード設定 URL を発行し直す（PHASE WP-BASELINE / STAGE #18B）。
 *
 * ## いつ要るか
 *
 * 手動で受け渡した URL は 1 度しか表示しない。
 * 渡す前に画面を閉じた・期限が切れた・渡した相手を間違えた —
 * どれも起こる。そのたびに利用者を作り直させないための操作。
 *
 * ## 発行し直すと前の URL は死ぬ
 *
 * Laravel のブローカーは新しい token を作る前に
 * **同じ利用者の古い token を消す**。つまりこの操作は
 * 「新しい URL を作る」と同時に「前に渡した URL を無効にする」。
 * 誤って外へ出した URL を止める手段にもなるので、
 * 画面にはその意味を書く。
 *
 * ## 使い始めた人には出さない
 *
 * 一度ログインした利用者へこの URL を出せるようにすると、
 * **利用者管理の権限がパスワード再設定の権限になってしまう**
 * （管理者が本人になりすませる入口）。
 * 既に使い始めている人のパスワード再設定は本人が
 * `/forgot-password` から行う。無効化された利用者にも出さない。
 */
final class IssueSetupLink implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly PasswordSetupLink $links,
        private readonly UserPresenter $presenter,
    ) {}

    public function operationId(): string
    {
        return 'user.setup_link.issue';
    }

    public function classification(): OperationClassification
    {
        /*
         * 外の世界へは何も出ない（メールを送らない）ので ExternalEffect にしない。
         * 一方で記録は必ず要るので Read でもない。
         */
        return OperationClassification::Write;
    }

    public function execute(int $id, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $user = User::query()->find($id);

        if ($user === null) {
            return $this->outcome->record($this, $context, Result::notFound('利用者が見つかりません'));
        }

        if (! $user->is_active) {
            return $this->outcome->record(
                $this, $context,
                Result::conflict('無効化された利用者にはパスワード設定 URL を発行できません'),
                'user', (string) $user->getKey(),
            );
        }

        if (! $user->isPendingInvitation()) {
            return $this->outcome->record(
                $this, $context,
                Result::conflict('この利用者はすでに利用を開始しています。パスワードの再設定は本人が行ってください。'),
                'user', (string) $user->getKey(),
            );
        }

        try {
            $url = DB::transaction(function () use ($user): string {
                $issued = $this->links->issueFor($user);

                // 「いつ渡す用の URL を出したか」は運用情報として残す。
                // **発行できたときだけ**進める（失敗を「渡した」と記録しない）
                $user->forceFill(['invited_at' => now()])->save();

                return $issued;
            });
        } catch (Throwable $e) {
            // 生の例外は SQL bindings（メールアドレス・ハッシュ化 token）を抱え得る
            SafeFailureLog::record('パスワード設定 URL を発行できませんでした（再発行）', $e);

            return $this->outcome->record(
                $this, $context,
                Result::conflict('パスワード設定 URL を発行できませんでした。時間をおいてもう一度お試しください。'),
                'user', (string) $user->getKey(),
                ['deliveryMethod' => InviteUser::DELIVERY_MANUAL, 'delivered' => false],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                [
                    'user' => $this->presenter->present($user->refresh(), $context),
                    // **URL は data にだけ**。meta にも metadata にも載せない
                    'setupUrl' => $url,
                    'expiresInMinutes' => $this->links->expiresInMinutes(),
                ],
                ['deliveryMethod' => InviteUser::DELIVERY_MANUAL, 'delivered' => true],
            ),
            resourceType: 'user',
            resourceId: (string) $user->getKey(),
            // URL・token・メールアドレス・名前は 1 文字も残さない
            metadata: ['deliveryMethod' => InviteUser::DELIVERY_MANUAL, 'delivered' => true],
        );
    }
}
