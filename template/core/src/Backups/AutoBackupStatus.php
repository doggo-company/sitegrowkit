<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 最後の自動 backup（`agentcms:backup-auto`）がどうなったか。
 *
 * ## なぜ要るのか
 *
 * cron が叩く `schedule:run` は、中で走ったコマンドが失敗しても **exit 0（DONE）** で終わる。
 * 理由は `storage/logs/laravel.log` にしか出ない。cron も外の見張りも「成功」と読むので、
 * **夜中の失敗は誰にも届かない**。だから結果をここに残し、状態の表示
 * （`agentcms:backup-offsite --list` が exit 1）・管理画面のバックアップ画面とサイドバーが読む。
 *
 * 失敗だけでなく次も「見てほしい」に数える:
 *
 *   - 始めた記録だけが残り、終わった記録が無い（途中でプロセスが落ちた）
 *   - 時刻を決めているのに、最後の成功から {@see STALE_AFTER_HOURS} 時間を超えた（cron が止まった）。
 *     一度も走っていないときは、時刻の設定を最初に見た時刻から数える（cron の置き忘れ）
 *   - 記録が読めない
 *
 * ## 置き場所
 *
 * `storage/app/private/backup-status/auto.json`（web から見えない・他人は読めない 0660／ディレクトリ 2770）。backup の zip の中にも、
 * 手元の backup の置き場所にも入れない — 復元で古い結果が戻ってくる、手元の置き場所が
 * 壊れたときに結果も書けない、を起こさないため。
 *
 * 理由の文は、呼ぶ側が {@see OffsiteBackups::redact()} を通してから渡す（鍵を書き残さない）。
 */
final class AutoBackupStatus
{
    /** 毎日走るなら、最後の成功からこれを超えたら止まっている（1 日＋取得にかかる時間＋余裕） */
    public const STALE_AFTER_HOURS = 30;

    /** 始めてからこれを超えて終わりの記録が無ければ、途中で止まった（重なり止めの 3 時間＋余裕） */
    public const RUNNING_TOO_LONG_HOURS = 4;

    private const FILE = 'backup-status/auto.json';

    private const MAX_REASON = 500;

    public function path(): string
    {
        return Storage::disk('local')->path(self::FILE);
    }

    /** 書けたら true（書けなければログに残し false — 呼ぶ側が失敗として扱う） */
    public function started(): bool
    {
        $previous = $this->raw();

        return $this->write([
            'state' => 'running',
            'started_at' => now()->getTimestamp(),
            'finished_at' => null,
            'backup' => null,
            'stage' => null,
            'reason' => null,
            'last_success_at' => $previous['last_success_at'] ?? null,
            'armed_at' => $previous['armed_at'] ?? null,
        ]);
    }

    /** 取れた。`$offsite` は外の置き場所へ送れたか（未設定なら false） */
    public function succeeded(string $backupId, bool $offsite): bool
    {
        $previous = $this->raw();
        $now = now()->getTimestamp();

        return $this->write([
            'state' => $offsite ? 'ok' : 'local-only',
            'started_at' => $previous['started_at'] ?? $now,
            'finished_at' => $now,
            'backup' => $backupId,
            'stage' => null,
            'reason' => null,
            'last_success_at' => $now,
            'armed_at' => $previous['armed_at'] ?? null,
        ]);
    }

    /** @param  'backup'|'offsite'|'prune'  $stage  どこで落ちたか */
    public function failed(string $stage, string $reason, ?string $backupId): bool
    {
        $previous = $this->raw();
        $now = now()->getTimestamp();

        return $this->write([
            'state' => 'failed',
            'started_at' => $previous['started_at'] ?? $now,
            'finished_at' => $now,
            'backup' => $backupId,
            'stage' => in_array($stage, ['backup', 'prune'], true) ? $stage : 'offsite',
            'reason' => mb_substr($reason, 0, self::MAX_REASON),
            'last_success_at' => $previous['last_success_at'] ?? null,
            'armed_at' => $previous['armed_at'] ?? null,
        ]);
    }

    /**
     * 人と見張りに見せる形。日時は ISO8601（UTC）。
     *
     * @return array{state: string, attention: bool, message: string, schedule: ?string, startedAt: ?string, finishedAt: ?string, lastSuccessAt: ?string, backup: ?string, reason: ?string}
     */
    public function read(): array
    {
        $schedule = BackupSchedule::enabled() && BackupSchedule::valid() ? BackupSchedule::time() : null;
        $base = [
            'state' => 'off', 'attention' => false, 'message' => '', 'schedule' => $schedule,
            'startedAt' => null, 'finishedAt' => null, 'lastSuccessAt' => null, 'backup' => null, 'reason' => null,
        ];

        $path = $this->path();

        if (! file_exists($path) && ! is_link($path)) {
            if ($schedule === null) {
                return [...$base, 'message' => '自動バックアップは使っていません（AGENTCMS_BACKUP_SCHEDULE が空）'];
            }

            /*
             * 時刻は決めたが、まだ 1 回も走っていない。**いつから待っているか**を、最初に見た時刻で
             * 覚えておく — 覚えないと、cron を置き忘れたサイトは永遠に「まだ記録がありません」のままで、
             * 誰も気づけない。覚えられない（書けない）なら、それ自体を見てほしいこととして出す。
             */
            if (! $this->write(['state' => 'never', 'started_at' => null, 'finished_at' => null, 'backup' => null, 'stage' => null, 'reason' => null, 'last_success_at' => null, 'armed_at' => now()->getTimestamp()])) {
                return [...$base, 'state' => 'unknown', 'attention' => true, 'message' => '自動バックアップの結果を記録できません（'.self::FILE.' に書けません。storage の書き込み権限を確かめてください）'];
            }
        }

        $data = $this->raw();

        if ($data === null) {
            return [...$base, 'state' => 'unknown', 'attention' => true, 'message' => '最後の自動バックアップの記録が読めません（'.self::FILE.'）'];
        }

        $view = [
            ...$base,
            'startedAt' => self::iso($data['started_at']),
            'finishedAt' => self::iso($data['finished_at']),
            'lastSuccessAt' => self::iso($data['last_success_at']),
            'backup' => $data['backup'],
            'reason' => $data['reason'],
        ];
        $now = now()->getTimestamp();

        if ($data['state'] === 'never') {
            if ($schedule === null) {
                return [...$base, 'message' => '自動バックアップは使っていません（AGENTCMS_BACKUP_SCHEDULE が空）'];
            }

            if ($data['armed_at'] !== null && $now - $data['armed_at'] > self::STALE_AFTER_HOURS * 3600) {
                return [...$view, 'state' => 'stale', 'attention' => true, 'message' => '自動バックアップが止まっています: 時刻を決めてから一度も取れていません（'.self::local($data['armed_at']).' から待っています。毎日 '.$schedule.' のはず。サーバーの cron で schedule:run が動いているか確かめてください）'];
            }

            return [...$view, 'state' => 'never', 'message' => '自動バックアップの記録はまだありません（毎日 '.$schedule.' に走ります。'.self::STALE_AFTER_HOURS.' 時間たっても取れなければ「止まっています」と出します）'];
        }

        if ($data['state'] === 'failed') {
            $head = match ($data['stage']) {
                'backup' => '最後の自動バックアップが失敗しました（backup を作れませんでした）',
                'prune' => '最後の自動バックアップは外へ送れましたが、外の古い世代を片付けられませんでした（置き場所の鍵に削除の権限があるか確かめてください。このままでは置き場所が増え続けます）',
                default => '最後の自動バックアップの送り出しが失敗しました（手元の backup は残っています）',
            };

            return [...$view, 'state' => 'failed', 'attention' => true, 'message' => $head.'（'.self::local($data['finished_at']).'）: '.$data['reason']];
        }

        if ($data['state'] === 'running') {
            if ($now - (int) $data['started_at'] > self::RUNNING_TOO_LONG_HOURS * 3600) {
                return [...$view, 'state' => 'interrupted', 'attention' => true, 'message' => '最後の自動バックアップが途中で止まったままです（'.self::local($data['started_at']).' に始まり、終わった記録がありません）'];
            }

            // 毎晩途中で落ちていても、前回の成功が古すぎれば「止まっている」を先に言う
            $last = $data['last_success_at'];

            if ($schedule !== null && $last !== null && $now - $last > self::STALE_AFTER_HOURS * 3600) {
                return [...$view, 'state' => 'stale', 'attention' => true, 'message' => '自動バックアップが止まっています: 最後に取れたのは '.self::local($last).' です（いまの回は '.self::local($data['started_at']).' に始まったまま）'];
            }

            return [...$view, 'state' => 'running', 'message' => '自動バックアップを取っています（'.self::local($data['started_at']).' に開始）'];
        }

        $last = $data['last_success_at'];

        if ($schedule !== null && ($last === null || $now - $last > self::STALE_AFTER_HOURS * 3600)) {
            return [...$view, 'state' => 'stale', 'attention' => true, 'message' => '自動バックアップが止まっています: 最後に取れたのは '.self::local($last).' です（毎日 '.$schedule.' のはず。サーバーの cron で schedule:run が動いているか確かめてください）'];
        }

        return $data['state'] === 'ok'
            ? [...$view, 'state' => 'ok', 'message' => '最後の自動バックアップ: '.self::local($data['finished_at']).' に取り、外の置き場所へ送りました（'.$data['backup'].'）']
            : [...$view, 'state' => 'local-only', 'message' => '最後の自動バックアップ: '.self::local($data['finished_at']).' に取りました（外の置き場所は未設定 — このサーバーの中にしかありません）'];
    }

    /**
     * @return array{state: string, started_at: ?int, finished_at: ?int, last_success_at: ?int, backup: ?string, stage: ?string, reason: ?string, armed_at: ?int}|null
     */
    private function raw(): ?array
    {
        $path = $this->path();

        if (! is_file($path) || is_link($path) || filesize($path) > 16384) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ($data['version'] ?? null) !== 1
            || ! in_array($data['state'] ?? null, ['never', 'running', 'ok', 'local-only', 'failed'], true)) {
            return null;
        }

        $int = static fn (mixed $v): ?int => is_int($v) ? $v : null;
        $str = static fn (mixed $v): ?string => is_string($v) ? mb_substr($v, 0, self::MAX_REASON) : null;

        return [
            'state' => (string) $data['state'],
            'started_at' => $int($data['started_at'] ?? null),
            'finished_at' => $int($data['finished_at'] ?? null),
            'last_success_at' => $int($data['last_success_at'] ?? null),
            'backup' => $str($data['backup'] ?? null),
            'stage' => $str($data['stage'] ?? null),
            'reason' => $str($data['reason'] ?? null),
            'armed_at' => $int($data['armed_at'] ?? null),
        ];
    }

    /**
     * 原子的に置き換える。cron（schedule:run）と web（管理画面）のどちらが先に作っても、
     * 同じグループならもう片方が読み書きできるよう 0660／2770（setgid）にする（他人には読ませない。
     * 理由の文は鍵を伏せてある）。書けなければログに残して false。
     *
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): bool
    {
        $path = $this->path();
        $dir = dirname($path);
        $temp = false;

        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
                throw new BackupFailed('置き場所を作れません');
            }

            // umask で落ちたグループの書き込みを戻し、setgid で中のファイルを同じグループにする
            @chmod($dir, 02770);

            if (is_link($path)) {
                throw new BackupFailed('置き場所がシンボリックリンクです');
            }

            $json = json_encode(['version' => 1, ...$data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $temp = @tempnam($dir, '.auto-');

            if ($temp === false || @file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || ! @chmod($temp, 0660) || ! @rename($temp, $path)) {
                throw new BackupFailed('書き込めません');
            }

            return true;
        } catch (Throwable $e) {
            Log::error('agentcms:backup-auto: 自動バックアップの結果を記録できませんでした（'.self::FILE.'）: '.$e->getMessage());

            return false;
        } finally {
            if (is_string($temp) && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private static function iso(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate(DATE_ATOM, $timestamp);
    }

    /** CLI とメールで読む時刻（アプリの時刻帯） */
    private static function local(?int $timestamp): string
    {
        if ($timestamp === null) {
            return '記録なし';
        }

        return Carbon::createFromTimestamp($timestamp)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i T');
    }
}
