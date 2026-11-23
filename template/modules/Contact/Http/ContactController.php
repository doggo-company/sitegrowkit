<?php

declare(strict_types=1);

namespace Modules\Contact\Http;

use AgentCms\Core\Mail\RecordedMailer;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Schema\SchemaRules;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Turnstile\TurnstileVerifier;
use App\Support\ContactEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Contact\Mail\AdminNotification;
use Modules\Contact\Mail\AutoReply;
use Modules\Contact\Models\ContactSubmission;
use Modules\Notifications\NotificationType;
use Modules\Notifications\Notifier;

/**
 * 公開フォーム。送信順序は仕様（CONTACT FORM）で固定:
 * Validation → Turnstile siteverify → DB 保存 → 管理者通知 → 自動返信 → 通知結果記録 → Push 通知
 * 最重要ルール: メール送信に失敗しても問い合わせデータを失わない。
 * Push は Email と独立（Notifier は決して例外を投げない — 相互に失敗を波及させない）。
 */
final class ContactController
{
    public function show(): View
    {
        return view('contact::form');
    }

    public function store(
        Request $request,
        SchemaRegistry $schemas,
        TurnstileVerifier $turnstile,
        RecordedMailer $mailer,
        SettingsStore $settings,
        Notifier $notifier,
    ): RedirectResponse {
        // 窓口を閉じているなら受けない（表示の側と同じ判断。UX 一周 6 周目 R6-1）
        abort_if(app(ContactEntry::class)->closed(), 404);

        // 1. Validation（Schema 由来）
        $validated = $request->validate(
            SchemaRules::for($schemas->get('contact'), 'contact_submissions'),
        );

        // 2. Turnstile server-side verification（失敗時は保存しない）
        $token = (string) $request->input('cf-turnstile-response', '');
        if ($token === '' || ! $turnstile->verify($token, $request->ip())) {
            throw ValidationException::withMessages([
                'turnstile' => 'ボット確認に失敗しました。ページを再読み込みしてもう一度お試しください。',
            ]);
        }

        // 3. DB へ保存（メールより先。以降の失敗はデータを失わせない）
        $submission = ContactSubmission::query()->create([
            ...$validated,
            'ip' => $request->ip(),
        ]);

        // 4. 管理者通知 → 5. 自動返信 → 6. 通知結果記録
        $adminTo = (string) ($settings->get('contact_email') ?: config('agentcms.mail.admin_to'));
        $siteName = (string) $settings->get('site_name', config('app.name'));

        $adminError = $mailer->send(new AdminNotification($submission), $adminTo);
        $replyError = $mailer->send(new AutoReply($submission, $siteName), $submission->email);

        $submission->forceFill([
            'admin_notified_at' => $adminError === null ? now() : null,
            'admin_notification_error' => $adminError,
            'autoreply_sent_at' => $replyError === null ? now() : null,
            'autoreply_error' => $replyError,
        ])->save();

        // 7. Push 通知（payload は最小情報のみ — 本文・メールアドレスは含めない）
        $detailUrl = route('admin.contact.show', ['id' => $submission->id]);
        $notifier->send(
            NotificationType::ContactNew,
            '新しいお問い合わせ',
            "{$submission->name}さんからお問い合わせが届きました",
            $detailUrl,
        );

        // メール通知失敗時の Push は submission 1 件につき最大 1 回（通知嵐・ループを構造的に起こさない）
        if ($adminError !== null) {
            $notifier->send(
                NotificationType::ContactEmailFailed,
                'メール通知の送信に失敗しました',
                'お問い合わせ通知メールの送信に失敗しました。管理画面で内容を確認してください。',
                $detailUrl,
            );
        }

        return redirect()->route('contact.show')->with('contact_success', true);
    }
}
