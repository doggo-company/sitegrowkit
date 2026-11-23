<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Application\ActorType;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * ロールを見る認可（PHASE WP-BASELINE / STAGE #18A）。
 *
 * `DefaultOperationPolicy`（認証済みなら全部許可）の置き換え。
 * **core の `OperationPolicy` 境界はそのまま**で、実装だけを project 側へ差し替える。
 *
 * ## 判定は Application 層が authoritative
 *
 * 画面で項目を隠すのは補助でしかない。Admin も API も MCP も
 * この 1 か所を通るので、URL 直打ちでも API 直叩きでも同じ結果になる。
 *
 * ## AI を人より強くしない
 *
 * agent（MCP / WebMCP）の上限は **管理者ロールの権限**とする。
 * 既存の公開範囲（`OperationCatalog` の 29 操作）はすべて管理者の権限内なので
 * 現在の契約は 1 つも変わらないが、将来 operation を足したときに
 * 「人には出していないのに AI からは実行できる」状態が生まれない。
 *
 * ## 記録はここでしない
 *
 * 拒否も監査に残すが、**書くのは Application Service の `OperationAudit`** である
 * （Correction 7）。policy と service の両方が書くと二重記録になるため、
 * ここは判定だけに徹する。
 */
final class RoleOperationPolicy implements OperationPolicy
{
    public function allows(string $operationId, OperationClassification $classification, OperationContext $context): bool
    {
        return $this->decide($operationId, $context);
    }

    public function denialReason(string $operationId, OperationContext $context): string
    {
        if ($context->actorId === null && $context->actorType === ActorType::User) {
            return "この操作にはログインが必要です（{$operationId}）";
        }

        $user = $this->user($context);

        if ($user !== null && ! $user->is_active) {
            return 'このアカウントは無効化されています。管理者にお問い合わせください。';
        }

        $capability = OperationCapabilities::for($operationId);

        if ($capability === null) {
            return "この操作は許可されていません（{$operationId}）";
        }

        return "この操作には「{$capability->label()}」の権限が必要です（{$operationId}）";
    }

    /**
     * 画面へ渡す「今の利用者ができること」
     *
     * @return list<string>
     */
    public function capabilitiesFor(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        return array_map(
            static fn (Capability $capability): string => $capability->value,
            $user->role()->capabilities(),
        );
    }

    private function decide(string $operationId, OperationContext $context): bool
    {
        return match ($context->interface) {
            // サーバー管理者が実行する経路。既存契約を変えない
            OperationInterface::Cli, OperationInterface::System => true,
            OperationInterface::Admin, OperationInterface::Api => $this->userAllows($operationId, $context),
            // agent の上限は管理者ロール（詳細はクラス docblock）
            OperationInterface::Mcp, OperationInterface::WebMcp => $this->roleAllows(Role::Administrator, $operationId),
        };
    }

    private function userAllows(string $operationId, OperationContext $context): bool
    {
        $user = $this->user($context);

        if ($user === null || ! $user->is_active) {
            return false;
        }

        return $this->roleAllows($user->role(), $operationId);
    }

    private function roleAllows(Role $role, string $operationId): bool
    {
        $capability = OperationCapabilities::for($operationId);

        // 表に無い operation は拒否する（default deny）
        return $capability !== null && $role->can($capability);
    }

    /**
     * 操作者を解決する。
     *
     * HTTP 経路では **すでに認証済みの利用者**がメモリにあるので、そのまま使う
     * （毎操作で `users` を引き直さない）。それ以外は毎回 DB から読む。
     *
     * **インスタンスへキャッシュしない**（Correction 3）。この policy は singleton なので、
     * 長命なワーカーではロール変更後も古い権限を持ち続けてしまう。
     */
    private function user(OperationContext $context): ?User
    {
        if ($context->actorId === null) {
            return null;
        }

        $current = Auth::user();

        if ($current instanceof User && (int) $current->getKey() === $context->actorId) {
            return $current;
        }

        return User::query()->find($context->actorId);
    }
}
