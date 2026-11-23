<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ProcessUtils;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/** 書き出しをHTTPから切り離す。経過は実件数で記録し、無限の処理中を表示しない。 */
class ContentExportRuns
{
    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $paths = glob(ContentExport::root().'/*.json') ?: [];
        rsort($paths);

        return $paths === [] ? null : $this->find(basename($paths[0], '.xml.json'));
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $file = ContentExport::path($id).'.json';
        if (! is_file($file)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($file), true);
        if (! is_array($state) || ($state['id'] ?? null) !== $id || ! is_int($state['updated'] ?? null)
            || ! in_array($state['status'] ?? null, ['queued', 'running', 'done', 'failed'], true)
            || ! is_int($state['count'] ?? null) || $state['count'] < 0 || ! is_string($state['message'] ?? null)) {
            return null;
        }
        if (in_array($state['status'], ['queued', 'running'], true) && time() - $state['updated'] > 120) {
            $state['status'] = 'stalled';
            $state['message'] = '処理の更新が2分以上ありません。サーバーを確認してから再実行できます';
        }

        return $state;
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        if (! is_dir(ContentExport::root()) && ! mkdir(ContentExport::root(), 0700, true) && ! is_dir(ContentExport::root())) {
            throw new RuntimeException('書き出し先を作成できません');
        }
        $lock = fopen(ContentExport::root().'/.start.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('開始処理中です。少し待ってください');
        }
        try {
            $current = $this->current();
            if ($current !== null && in_array($current['status'], ['queued', 'running'], true)) {
                return $current;
            }
            $id = strtolower((string) Str::ulid());
            $state = ['id' => $id, 'status' => 'queued', 'count' => 0, 'updated' => time(), 'message' => '開始しています'];
            $this->save($state);
            try {
                $this->launch($id);
            } catch (Throwable) {
                $state['status'] = 'failed';
                $state['message'] = '別プロセスを起動できませんでした。サーバーで php artisan agentcms:content-export を実行する方法もあります';
                $this->save($state);
            }

            return $this->find($id) ?? $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function launch(string $id): void
    {
        ContentExport::path($id);
        $php = (new PhpExecutableFinder)->find(false);
        if (! function_exists('proc_open') || $php === false) {
            throw new RuntimeException('PHPの別プロセスを起動できません');
        }
        $command = ProcessUtils::escapeArgument($php).' '.Artisan::artisanBinary().' agentcms:content-export-run '.$id;
        $process = Process::fromShellCommandline(PHP_OS_FAMILY === 'Windows'
            ? 'start "" /B '.$command.' > NUL 2>&1'
            : $command.' < /dev/null > /dev/null 2>&1 &', base_path(), null, null, 15);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('別プロセスを起動できません');
        }
    }

    public function run(string $id, ContentExport $export): bool
    {
        $state = $this->find($id);
        if ($state === null || $state['status'] !== 'queued') {
            return false;
        }
        $lock = fopen(ContentExport::path($id).'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return false;
        }
        $start = time();
        try {
            // lock待ちの間に別のworkerが完了した場合は二度実行しない。
            $state = $this->find($id);
            if ($state === null || $state['status'] !== 'queued') {
                return false;
            }
            $state['status'] = 'running';
            $state['message'] = '記事・ページを書き出しています';
            $this->save($state);
            $count = $export->write($id, function (int $count) use (&$state, $start): void {
                if (time() - $start > 600) {
                    throw new RuntimeException('書き出しの時間上限を超えました');
                }
                $state['count'] = $count;
                $this->save($state);
            });
            $state['status'] = 'done';
            $state['count'] = $count;
            $state['message'] = '書き出しが完了しました';
            $this->save($state);

            return true;
        } catch (Throwable) {
            $state['status'] = 'failed';
            $state['message'] = '書き出しを完了できませんでした。空き容量・保存権限・記事の文字とページの版を確認してください';
            $this->save($state);

            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $state['updated'] = time();
        $path = ContentExport::path($state['id']).'.json';
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('書き出し状態を記録できません');
        }
        chmod($tmp, 0600);
        if (! rename($tmp, $path)) {
            throw new RuntimeException('書き出し状態を確定できません');
        }
    }
}
