<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * セットアップ直後の「管理画面を開く」を、ログイン済みで開くための 1 回きりのリンク（UX 一周 3 周目 N1）。
 *
 * ## 守ること
 *
 * - **ローカル（APP_ENV=local）でだけ**発行も受け付けもする。本番・ステージングでは口ごと無い
 * - **1 回使ったら消える**。期限も短い（既定 5 分）
 * - 保存するのは **token のハッシュ**だけ。token そのものは発行した人にしか渡らない
 * - 2 段階認証を有効にした人・無効化された人は、このリンクでは入れない（いつものログインへ戻す）
 *
 * パスワードを打たずに入れる口なので、条件を 1 つでも満たさなければ**入れない側へ倒す**。
 */
final class OneTimeLogin
{
    public const DEFAULT_TTL_SECONDS = 300;

    public const MAX_TTL_SECONDS = 900;

    private const PREFIX = 'agentcms:one-time-login:';

    public static function available(): bool
    {
        return app()->environment('local');
    }

    /** 発行する。ローカルでなければ null */
    public static function issue(User $user, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): ?string
    {
        if (! self::available()) {
            return null;
        }

        $ttl = max(1, min($ttlSeconds, self::MAX_TTL_SECONDS));
        $token = Str::random(64);

        Cache::put(self::key($token), $user->getKey(), $ttl);

        return $token;
    }

    /** 使う。**使えたかどうかに関わらず、この token は二度と使えない** */
    public static function consume(string $token): ?User
    {
        if (! self::available() || strlen($token) !== 64) {
            return null;
        }

        $id = Cache::pull(self::key($token));

        if ($id === null) {
            return null;
        }

        $user = User::query()->find($id);

        if (! $user instanceof User || ! $user->is_active || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        return $user;
    }

    private static function key(string $token): string
    {
        return self::PREFIX.hash('sha256', $token);
    }
}
