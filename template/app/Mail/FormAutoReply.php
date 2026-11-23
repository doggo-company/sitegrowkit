<?php

declare(strict_types=1);

namespace App\Mail;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 送信者への自動返信（Phase 9.12 / STAGE 25C・§E.3）。
 *
 * 件名・本文はフォームごとに設定できる。**未設定なら既定の文面**を使う
 * （「設定していないと自動返信が送られない」より、当たり障りのない文面を送る方がよい）。
 */
final class FormAutoReply extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Form $form,
        public readonly FormSubmission $submission,
        public readonly string $siteName,
    ) {}

    public function envelope(): Envelope
    {
        $subject = trim((string) $this->form->autoreply_subject);
        $fromName = trim((string) $this->form->mail_from_name);
        $replyTo = trim((string) $this->form->reply_to);

        /*
         * **差出人アドレスは変えない**（R-11 ④）。
         *
         * フォームごとに From アドレスを変えると SPF / DKIM が合わず、
         * **迷惑メール判定になりやすい**うえ、利用者は原因を追えない。
         * 変えてよいのは**表示名**（同じアドレスなので認証は崩れない）と
         * **返信先**の 2 つで、「送信元の名前やアドレスを変えたい」はこれで満たせる。
         */
        return new Envelope(
            from: $fromName === ''
                ? null
                : new Address(
                    (string) config('mail.from.address'),
                    $fromName,
                ),
            replyTo: $replyTo === '' ? [] : [new Address($replyTo)],
            subject: $subject !== '' ? $subject : "【{$this->siteName}】お問い合わせを受け付けました",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'forms.mail.auto-reply');
    }
}
