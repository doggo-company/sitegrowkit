<?php

namespace App\Models;

use App\Support\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $role
 * @property bool $is_active
 * @property Carbon|null $invited_at
 * @property Carbon|null $last_login_at
 * @property int $session_epoch
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
/*
 * **role / is_active を fillable にしない**（STAGE #18A）。
 * 一括代入でロールや有効状態が書き換わる経路を作らないため、
 * これらは専用の Application Service（`App\Application\Users\*`）だけが
 * `forceFill()` で更新する。
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
/**
 * 管理者。
 *
 * **`MustVerifyEmail` を実装している**ため、`verified` middleware が実際に働く。
 * 実装していないと Laravel の `EnsureEmailIsVerified` は素通りし、
 * route へ `verified` と書いてあっても未確認の利用者を通してしまう
 * （config/fortify.php の `Features::emailVerification()` は有効）。
 *
 * 管理者は Seeder / CLI で作成され、その時点で `email_verified_at` が入る。
 * 公開登録は無効なので、確認待ちのまま滞留する経路は存在しない。
 */
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** 権限判定に使うロール。**不明な値は閲覧者へ倒す**（昇格させない） */
    public function role(): Role
    {
        return Role::fromStorage($this->getAttribute('role'));
    }

    /** まだ本人がパスワードを設定していない招待中の利用者か */
    public function isPendingInvitation(): bool
    {
        return $this->invited_at !== null && $this->last_login_at === null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
            /* @chisel-2fa */
            'two_factor_confirmed_at' => 'datetime',
            /* @end-chisel-2fa */
        ];
    }
}
