<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 自動 backup の失敗の知らせ。core は view を持たないので本文はここで組む（中身は全部 e() を通す）。
 */
final class AutoBackupFailedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $failure,
        public readonly ?string $backupId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').'] 自動バックアップが失敗しました');
    }

    public function content(): Content
    {
        $lines = [
            'サイト: '.config('app.url'),
            '何が起きたか: '.$this->failure,
            '手元の backup: '.($this->backupId ?? 'この回は作れていません'),
            '',
            '次にすること:',
            '1. サーバーで php artisan agentcms:backup-offsite --list を実行し、置き場所の設定と外にある世代を確かめる',
            '2. 直したら php artisan agentcms:backup-auto で取り直す（成功すると管理画面の印も消えます）',
            '',
            '管理画面の「バックアップ」にも同じ内容が出ています。',
            'このメールは AGENTCMS_BACKUP_ALERT_MAIL に書かれた宛先へ送っています。',
        ];

        return new Content(htmlString: '<p>'.implode('<br>', array_map(static fn (string $l): string => e($l), $lines)).'</p>');
    }
}
