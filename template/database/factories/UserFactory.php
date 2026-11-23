<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // 既定は owner。これまでの「管理者 1 人」を表す利用者であり、
            // 既存テストが表現している運用（全操作ができる利用者）と一致する
            // 全権限の人（作りの画面も開ける）。店主に絞る試験は Role::Owner を明示する（2026-09-28）
            'role' => Role::Developer->value,
            'is_active' => true,
            'session_epoch' => 0,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            /* @chisel-2fa */
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            /* @end-chisel-2fa */
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** 指定したロールの利用者 */
    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role->value]);
    }

    public function administrator(): static
    {
        return $this->role(Role::Administrator);
    }

    public function editor(): static
    {
        return $this->role(Role::Editor);
    }

    public function viewer(): static
    {
        return $this->role(Role::Viewer);
    }

    /** 無効化された利用者（ログインも既存 session でのアクセスも拒否される） */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /** 招待したがまだ一度もログインしていない利用者 */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'invited_at' => now(),
            'last_login_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        /* @chisel-2fa */
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
        /* @end-chisel-2fa */
    }
}
