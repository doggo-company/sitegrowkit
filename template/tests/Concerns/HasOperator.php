<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Support\Role;

/**
 * Application Service を直接呼ぶテスト用の「実在する操作者」。
 *
 * STAGE #18A でロール認可が入り、`OperationContext` の actor は
 * **実在して有効な利用者**でなければ許可されなくなった
 * （削除・無効化された利用者の session が生き残らないための契約）。
 *
 * これまで存在しない ID をそのまま渡していた fixture を、
 * 実在する有効なオーナーへ置き換えるための helper。
 * 1 テスト内では同じ利用者を使い回す。
 */
trait HasOperator
{
    private ?User $operator = null;

    /** 実在する有効なオーナーの ID */
    protected function operatorId(): int
    {
        return (int) $this->operator()->getKey();
    }

    protected function operator(): User
    {
        return $this->operator ??= User::factory()->create();
    }

    /** 指定ロールの利用者（権限境界のテスト用） */
    protected function userWithRole(Role $role): User
    {
        return User::factory()->role($role)->create();
    }
}
