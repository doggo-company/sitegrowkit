<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use RuntimeException;
use Throwable;

/** 確認結果は非公開領域に原子的に保存し、古い結果や別の依存構成を合格扱いしない。 */
final class MaintenanceReport
{
    public function path(): string
    {
        return storage_path('app/private/maintenance/report.json');
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach (['composer.lock', 'vendor/composer/installed.json'] as $file) {
            $hash = is_file(base_path($file)) ? hash_file('sha256', base_path($file)) : false;
            $parts[] = $hash === false ? 'missing:'.$file : $hash;
        }
        // 公開URLの変更後に、前のドメインの証明書結果を使わない。
        $parts[] = (string) config('app.url');

        return hash('sha256', implode('|', $parts));
    }

    /** @param list<array{key: string, label: string, status: string, message: string}> $checks */
    public function save(array $checks, ?string $expectedFingerprint = null): void
    {
        if ($expectedFingerprint !== null && $expectedFingerprint !== $this->fingerprint()) {
            throw new RuntimeException('確認中に依存関係または公開URLが変わりました。再確認してください。');
        }
        $fingerprint = $expectedFingerprint ?? $this->fingerprint();
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('保守結果の保存先を作成できません。');
        }
        if (is_link($path)) {
            throw new RuntimeException('保守結果の保存先がシンボリックリンクです。');
        }
        $temp = tempnam($dir, '.report-');
        if ($temp === false) {
            throw new RuntimeException('保守結果の一時ファイルを作れません。');
        }
        try {
            $json = json_encode(['version' => 1, 'checked_at' => now()->getTimestamp(), 'fingerprint' => $fingerprint, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || ! chmod($temp, 0600) || ! rename($temp, $path)) {
                throw new RuntimeException('保守結果を保存できません。');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /** @return array{state: string, checkedAt: ?string, checks: list<array{key: string, label: string, status: string, message: string}>} */
    public function read(): array
    {
        $empty = ['state' => 'missing', 'checkedAt' => null, 'checks' => []];
        $path = $this->path();
        if (! is_file($path) || is_link($path) || filesize($path) > 65536) {
            return $empty;
        }
        try {
            $raw = file_get_contents($path);
            $data = $raw === false ? null : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_int($data['checked_at'] ?? null) || ! is_array($data['checks'] ?? null) || count($data['checks']) !== 2) {
                return $empty;
            }
            $checks = [];
            foreach ($data['checks'] as $row) {
                if (! is_array($row) || ! in_array($row['status'] ?? '', ['pass', 'warn', 'fail', 'unknown'], true)) {
                    return $empty;
                }
                $clean = ['key' => '', 'label' => '', 'status' => '', 'message' => ''];
                foreach (['key', 'label', 'status', 'message'] as $key) {
                    if (! is_string($row[$key] ?? null)) {
                        return $empty;
                    }
                    $clean[$key] = mb_substr($row[$key], 0, 300);
                }
                $checks[] = $clean;
            }
            if (array_column($checks, 'key') !== ['dependencies', 'certificate']) {
                return $empty;
            }
            $age = now()->getTimestamp() - $data['checked_at'];
            $fresh = $age >= 0 && $age <= 48 * 3600 && ($data['fingerprint'] ?? null) === $this->fingerprint();

            return ['state' => $fresh ? 'current' : 'stale', 'checkedAt' => gmdate(DATE_ATOM, $data['checked_at']), 'checks' => $checks];
        } catch (Throwable) {
            return $empty;
        }
    }
}
