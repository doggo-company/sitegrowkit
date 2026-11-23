<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OneTimeLogin;
use Illuminate\Console\Command;

/**
 * 管理画面へ 1 回だけ入れるリンクを出す（Setup Wizard の「管理画面を開く」から使う）。
 *
 * 出力は **URL の 1 行だけ**（呼ぶ側がそのまま開く）。ログに残す前提の出力ではないので、
 * 呼ぶ側は詳細ログへ流さないこと。ローカル以外では何も出さずに失敗する。
 */
final class LoginLinkCommand extends Command
{
    protected $signature = 'agentcms:login-link
        {--email= : 入る利用者のメールアドレス（既定は ADMIN_EMAIL）}
        {--ttl=300 : 有効な秒数（最大 900）}';

    protected $description = 'Print a one-time admin login URL (local environment only)';

    public function handle(): int
    {
        if (! OneTimeLogin::available()) {
            $this->components->error('1 回きりのログインリンクは、ローカル環境（APP_ENV=local）でだけ使えます。');

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: config('agentcms.admin.email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! $user->is_active || $user->two_factor_confirmed_at !== null) {
            $this->components->error('この利用者には 1 回きりのリンクを出せません。メールアドレスとパスワードでログインしてください。');

            return self::FAILURE;
        }

        $token = OneTimeLogin::issue($user, (int) $this->option('ttl'));

        if ($token === null) {
            return self::FAILURE;
        }

        $this->line(url('/login/once/'.$token));

        return self::SUCCESS;
    }
}
