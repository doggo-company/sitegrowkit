<?php

declare(strict_types=1);

namespace App\Application\Users;

use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use App\Support\Role;

/**
 * 利用者を画面 / API へ返す形（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **allowlist 方式**にする。`$user->toArray()` を返すと、
 * 将来 users へ列が増えたときに黙って外へ出てしまう
 * （`Hidden` 属性に頼るだけの守りにしない）。
 * パスワード・2FA の秘密・remember_token は**そもそも組み立てない**。
 */
final class UserPresenter
{
    public function __construct(private readonly UserGuard $guard) {}

    /** @return array<string, mixed> */
    public function present(User $user, OperationContext $context): array
    {
        $role = $user->role();

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $role->value,
            'roleLabel' => $role->label(),
            'isActive' => (bool) $user->is_active,
            // 「有効 / 無効」を色だけで表さないため、文字も一緒に返す
            'statusLabel' => $this->statusLabel($user),
            'emailVerified' => $user->email_verified_at !== null,
            'invitedAt' => $user->invited_at?->toIso8601String(),
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'pendingInvitation' => $user->isPendingInvitation(),
            'createdAt' => $user->created_at?->toIso8601String(),
            ...$this->guard->abilitiesFor($user, $context),
        ];
    }

    private function statusLabel(User $user): string
    {
        if (! $user->is_active) {
            return '無効';
        }

        return $user->isPendingInvitation() ? '招待中' : '有効';
    }

    /**
     * 画面の絞り込みに使う選択肢
     *
     * @return array<string, list<array<string, string>>>
     */
    public static function filterOptions(): array
    {
        return [
            'roles' => Role::options(),
            'statuses' => [
                ['value' => 'active', 'label' => '有効'],
                ['value' => 'inactive', 'label' => '無効'],
                ['value' => 'invited', 'label' => '招待中'],
            ],
        ];
    }
}
