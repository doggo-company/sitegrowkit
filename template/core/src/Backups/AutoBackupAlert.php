<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use AgentCms\Core\Mail\RecordedMailer;
use Illuminate\Support\Facades\Log;

/**
 * 自動 backup の失敗を、決めた宛先へメールで知らせる。
 *
 * **宛先（`AGENTCMS_BACKUP_ALERT_MAIL`）が空なら何もしない。** 自動 backup を使わない人・
 * 知らせを要らない人には何も増えない。カンマ区切りで複数書ける。形の正しくない宛先は飛ばす。
 *
 * 送れなくても例外にしない（`RecordedMailer` がログに残す）— 知らせの失敗で、
 * 失敗の記録や終了コードを変えないため。本文は {@see AutoBackupStatus::read()} の文で、
 * 鍵は {@see OffsiteBackups::redact()} を通して伏せてある。
 */
final class AutoBackupAlert
{
    public function __construct(private readonly RecordedMailer $mailer) {}

    /** @return list<string> */
    public function recipients(): array
    {
        $raw = (string) config('agentcms.backup.alert_mail', '');
        $valid = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $address) {
            if ($address === '') {
                continue;
            }

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                Log::warning('agentcms:backup-auto: 知らせの宛先の形が正しくないので飛ばしました（AGENTCMS_BACKUP_ALERT_MAIL）');

                continue;
            }

            $valid[] = $address;
        }

        return array_values(array_unique($valid));
    }

    /** 送ったら true（宛先が無い・送れなかったら false） */
    public function send(string $message, ?string $backupId): bool
    {
        $to = $this->recipients();

        if ($to === []) {
            return false;
        }

        return $this->mailer->send(new AutoBackupFailedMail($message, $backupId), $to) === null;
    }
}
