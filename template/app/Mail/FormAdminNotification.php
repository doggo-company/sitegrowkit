<?php

declare(strict_types=1);

namespace App\Mail;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 管理者への通知（Phase 9.12 / STAGE 25C）。
 *
 * **本文は届いた `__labels` で組み立てる。** 定義から引き直すと、
 * 項目を消したあとに届いた通知が読めなくなる（OG4）。
 */
final class FormAdminNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Form $form,
        public readonly FormSubmission $submission,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "【{$this->form->name}】新しい送信が届きました（#{$this->submission->id}）");
    }

    public function content(): Content
    {
        return new Content(text: 'forms.mail.admin-notification');
    }
}
