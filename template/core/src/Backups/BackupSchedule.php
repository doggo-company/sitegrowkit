<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Console\Scheduling\Schedule;

/**
 * 毎日の自動 backup を schedule に載せる。
 *
 * **時刻を設定したときだけ**載せる（`AGENTCMS_BACKUP_SCHEDULE=03:40`）。空なら今までどおり
 * 自動では走らない — 自動で取るかどうかは運用の判断のまま、判断した人が 1 行で入れられる。
 *
 * 動かすのはサーバーの cron（`* * * * * php artisan schedule:run`）。cron が無ければ
 * 時刻を入れても走らない。`agentcms deploy setup` は cron を置く。
 *
 * 読めない時刻は載せない（毎回の artisan を落とさない）。代わりに
 * `agentcms:backup-offsite --list` が「時刻の設定が読めません」と出して exit 1 で終わる。
 *
 * 同じ置き場所を使うサイトが多いときは、サイトごとに時刻をずらす。
 */
final class BackupSchedule
{
    public const COMMAND = 'agentcms:backup-auto';

    public static function time(): string
    {
        return trim((string) config('agentcms.backup.schedule', ''));
    }

    public static function enabled(): bool
    {
        return self::time() !== '';
    }

    public static function valid(): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', self::time()) === 1;
    }

    public static function register(Schedule $schedule): void
    {
        if (! self::enabled() || ! self::valid()) {
            return;
        }

        // 大きなサイトでも重ならないよう、前の回が残っていれば飛ばす（3 時間で印は切れる）
        $schedule->command(self::COMMAND)->dailyAt(self::time())->withoutOverlapping(180);
    }
}
