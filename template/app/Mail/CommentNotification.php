<?php

declare(strict_types=1);

namespace App\Mail;

use AgentCms\Core\Comments\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 新着コメントの管理者通知（Phase 9.13 / STAGE 26D・SPEC §E.4）。
 *
 * **投稿者のメールアドレスを `replyTo` に入れない。**
 * 返信は管理画面から行う（`ReplyToComment`）。メールで直接返すと、
 * その往復がどこにも残らず、承認や moderation と食い違う。
 */
final class CommentNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Comment $comment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "【コメント】新しい投稿が届きました（#{$this->comment->id}）");
    }

    public function content(): Content
    {
        return new Content(text: 'comments.mail.notification');
    }
}
