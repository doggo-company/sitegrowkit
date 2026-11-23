<?php

declare(strict_types=1);

namespace App\Notifications;

use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * 利用者の招待メール（PHASE WP-BASELINE / STAGE #18A）。
 *
 * **パスワードは送らない。** 管理者が決めた文字列を通知することも、
 * 画面へ表示することもしない。本人だけがパスワード再設定リンクから
 * 自分のパスワードを決める（Laravel の標準ブローカーをそのまま使う）。
 *
 * リンクの有効期限は `config('auth.passwords.users.expire')`（分）。
 */
final class UserInvitation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $invitedByName,
        private readonly string $roleLabel,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));

        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        /*
         * 🔴 **名乗るのは案件の名前**（WP 一周 10 周目 2026-09-04 / 効くもの ①）。
         *
         * ここは `config('app.name')` を直に読んでいた。サイト名を
         * 「沼津どうぶつ病院」にしたあとでも、招待は
         * **「【SiteGrowKit】管理画面へ招待されました」**で届いていた
         * （同じ受信箱の 1 行下では、問い合わせが案件の名前で届いている）。
         *
         * 招待された人はその案件の名前しか知らない。件名・本文・署名で
         * 製品名が 4 回出れば、心当たりの無い人はフィッシングだと思う。
         *
         * しかも 5 歩の 1 歩目が「案件の名前に変えると、**画面にもメールにも
         * 反映されます**」と約束している（`App\Support\FirstSteps`）。
         *
         * まだ決めていなければ製品名に戻る — 空欄で名乗らせない。
         */
        $site = (string) app(SettingsStore::class)->get('site_name', config('app.name'));

        return (new MailMessage)
            ->subject("【{$site}】管理画面へ招待されました")
            ->greeting("{$notifiable->name} 様")
            ->line("{$this->invitedByName} さんが、あなたを「{$site}」の管理画面へ招待しました。")
            ->line("権限：{$this->roleLabel}")
            ->line('下のボタンからパスワードを設定すると、管理画面を利用できます。')
            ->action('パスワードを設定する', $url)
            ->line("このリンクは {$expire} 分で無効になります。期限が切れた場合は、招待した担当者に再送を依頼してください。")
            ->line('心当たりがない場合は、このメールを破棄してください。')
            ->salutation("{$site} 管理画面");
    }

    /** パスワード再設定ブローカーの token を発行する */
    public static function issueTokenFor(User $notifiable): string
    {
        // issueFor と同じ既定 broker の公開 API を使う。
        return Password::createToken($notifiable);
    }
}
