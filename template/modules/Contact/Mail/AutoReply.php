<?php

declare(strict_types=1);

namespace Modules\Contact\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Contact\Models\ContactSubmission;

final class AutoReply extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ContactSubmission $submission,
        public readonly string $siteName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "お問い合わせを受け付けました｜{$this->siteName}",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'contact::mail.auto-reply');
    }
}
