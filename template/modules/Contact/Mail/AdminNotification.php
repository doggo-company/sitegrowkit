<?php

declare(strict_types=1);

namespace Modules\Contact\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Contact\Models\ContactSubmission;

final class AdminNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ContactSubmission $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【お問い合わせ】{$this->submission->name} 様より（#{$this->submission->id}）",
            replyTo: [$this->submission->email],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'contact::mail.admin-notification');
    }
}
