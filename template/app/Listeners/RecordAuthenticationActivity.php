<?php

declare(strict_types=1);

namespace App\Listeners;

use AgentCms\Core\Application\AuditRecorder;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;

/**
 * 認証まわりのセキュリティイベントを履歴へ残す（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ## 生のメールアドレスを残さない
 *
 * 存在しないメールアドレスへのログイン失敗は「攻撃者が入力した文字列」であり、
 * そのまま保存すると **こちらの履歴が個人情報の入れ物になる**。
 * 既知の利用者なら actor_id で十分に特定できるので、
 * 未知の場合は一方向ハッシュの先頭だけを残して同一性の追跡に使う。
 *
 * ## IP と User-Agent
 *
 * 認証イベントに限って記録する（誰がどこから入ったかは事故調査に要る）。
 * 表示はオーナー限定で、`agentcms:audit-prune` が期限後に匿名化する。
 */
final class RecordAuthenticationActivity
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            // 最終ログイン日時は一覧の運用情報。fillable を広げずに書く
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        $this->record('auth.login', $this->contextFor($user), Result::success(), [
            'guard' => $event->guard,
            'remember' => $event->remember,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user;

        $this->record(
            'auth.login.failed',
            $this->contextFor($user),
            Result::forbidden('認証に失敗しました'),
            $user instanceof User ? [] : ['identifierHash' => $this->identifierHash($event->credentials)],
        );
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('auth.logout', $this->contextFor($event->user), Result::success());
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record('auth.password.reset', $this->contextFor($event->user), Result::success());
    }

    /*
     * 以下はアカウント保護の設定変更（Correction 5）。
     * **秘密そのものは 1 つも記録しない** — 何が有効化 / 無効化されたかだけ。
     * リカバリーコード・パスキーの資格情報・二要素の secret は metadata に載せない。
     */

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $this->record('auth.password.updated', $this->contextFor($event->user), Result::success());
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->record('auth.two_factor.enabled', $this->contextFor($event->user), Result::success());
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record('auth.two_factor.confirmed', $this->contextFor($event->user), Result::success());
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record('auth.two_factor.disabled', $this->contextFor($event->user), Result::success());
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        // **コードそのものは記録しない**。作り直したという事実だけ
        $this->record('auth.recovery_codes.generated', $this->contextFor($event->user), Result::success());
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->recordPasskey('auth.passkey.registered', $event->user, $event->passkey);
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->recordPasskey('auth.passkey.deleted', $event->user, $event->passkey);
    }

    /**
     * パスキーの増減を記録する（Correction 2-6）。
     *
     * **資格情報は 1 バイトも残さない。** `credential` / `credential_id` は
     * 認証そのものに使える値なので、監査ログへ複製した時点で
     * ログを読める人が別の攻撃面を手に入れる。
     * 追跡に要るのは「どの鍵が増えた / 減ったか」だけなので、
     * 行 ID だけを残す。利用者が付けた名前も載せない
     * （自由入力なので個人情報が混ざり得る）。
     */
    private function recordPasskey(string $operationId, Authenticatable $user, Passkey $passkey): void
    {
        $this->record($operationId, $this->contextFor($user), Result::success(), [
            'passkeyId' => $passkey->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $operationId, OperationContext $context, Result $result, array $metadata = []): void
    {
        $this->audit->record(
            $operationId,
            // 認証イベントは外部影響として扱い、必ず記録対象にする
            OperationClassification::ExternalEffect,
            $context,
            $result,
            resourceType: 'user',
            resourceId: $context->actorId !== null ? (string) $context->actorId : null,
            metadata: $metadata,
        );
    }

    /**
     * 認証イベントの actor 文脈。
     *
     * **`OperationContext::admin()` を通す**（Correction 2-3）。直接 `new` すると
     * リクエスト相関 ID が入らず、同じリクエスト内の他の監査行と結びつかなくなる。
     */
    private function contextFor(mixed $user): OperationContext
    {
        return OperationContext::admin(
            $user instanceof User ? (int) $user->getKey() : null,
            $user instanceof User ? (string) $user->name : '不明な利用者',
        );
    }

    /**
     * 入力された識別子の一方向ハッシュ（先頭 16 文字）。
     *
     * 元のメールアドレスへ戻せないが、同じ相手からの連続失敗は追える。
     *
     * @param  array<string, mixed>  $credentials
     */
    private function identifierHash(array $credentials): string
    {
        $identifier = $credentials['email'] ?? $credentials['username'] ?? '';

        return substr(hash('sha256', is_string($identifier) ? mb_strtolower($identifier) : ''), 0, 16);
    }
}
