<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `php artisan agentcms:backup-run <run>` を切り離して起動する（2026-09-09・OG3 改訂）。
 *
 * ## なぜ queue の `background` driver ではないか
 *
 * Laravel 13 の `background` driver も別プロセス起動だが、起動は応答後の `defer()` の中で
 * 行われ、**失敗しても例外は握りつぶされてログへ行くだけ**である。画面は「始めました」と
 * 言ったきり、何も始まらない。ここでは HTTP の中で起動を試み、**起動できたかどうかを
 * その場で返す**。起動できない環境（`proc_open` が塞がれている等）は理由ごと画面へ出す。
 *
 * ## 切り離し
 *
 * 子の stdin/stdout/stderr をすべて `/dev/null` へ向ける。pipe を握ったままだと
 * 親（php-fpm）の応答が子の終了まで待たされ、結局 web の要求時間に縛られる。
 */
final class ProcessBackupRunLauncher implements BackupRunLauncher
{
    /** 起動そのものにかける上限（取得の時間ではない — 子はすぐ切り離される） */
    private const SPAWN_TIMEOUT_SECONDS = 15;

    public function __construct(
        /** テスト用に差し替える。null なら `ini_get('disable_functions')` */
        private readonly ?string $disabledFunctions = null,
    ) {}

    public function unavailableReason(): ?string
    {
        if (! function_exists('proc_open') || $this->isDisabled('proc_open')) {
            return 'このサーバーでは PHP の proc_open が無効なため、画面からは取得を始められません';
        }

        if (self::phpBinary() === null) {
            return 'このサーバーでは php コマンドの場所が分からないため、画面からは取得を始められません';
        }

        return null;
    }

    public function launch(string $runId): ?int
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw new BackupFailed($reason);
        }

        if (! BackupRunStore::isValidRunId($runId)) {
            throw new BackupFailed('run の id が不正です');
        }

        $command = self::phpBinary().' '.Artisan::artisanBinary().' agentcms:backup-run '.$runId;

        try {
            $process = Process::fromShellCommandline(
                self::isWindows()
                    ? 'start "" /B '.$command.' > NUL 2>&1'
                    : $command.' < /dev/null > /dev/null 2>&1 & echo $!',
                base_path(),
                null,
                null,
                self::SPAWN_TIMEOUT_SECONDS,
            );

            $process->run();
        } catch (Throwable $e) {
            throw new BackupFailed('取得の処理を別プロセスで起動できませんでした', 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new BackupFailed('取得の処理を別プロセスで起動できませんでした（exit '.$process->getExitCode().'）');
        }

        $pid = (int) trim($process->getOutput());

        return $pid > 0 ? $pid : null;
    }

    private function isDisabled(string $function): bool
    {
        $list = $this->disabledFunctions ?? (string) ini_get('disable_functions');

        return in_array($function, array_map(trim(...), explode(',', $list)), true);
    }

    /**
     * 見つからなければ null。
     *
     * `Artisan::phpBinary()` は見つからなくても `'php'` を返すので、そのまま使うと
     * 起動は「成功」し、すぐ死ぬ子だけが残る。先に探して、無ければ始めない。
     */
    private static function phpBinary(): ?string
    {
        $found = (new PhpExecutableFinder)->find(false);

        return $found === false ? null : ProcessUtils::escapeArgument($found);
    }

    private static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
