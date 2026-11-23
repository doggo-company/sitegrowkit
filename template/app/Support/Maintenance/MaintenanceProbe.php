<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Illuminate\Support\Facades\Process;
use Throwable;

/** 外部確認は定期CLIだけで行い、管理画面のHTTP要求からネットワークを呼ばない。 */
class MaintenanceProbe
{
    /** @return array{key: string, label: string, status: string, message: string} */
    public function dependencies(): array
    {
        try {
            // 稼働中のvendorを監査する（--lockedでは新しいlockと古いvendorの混在を見逃す）。
            // 固定引数で読むだけ。install/updateやプロジェクトのComposer scriptsは実行しない。
            $result = Process::path(base_path())->timeout(90)->env(['COMPOSER_NO_INTERACTION' => '1'])
                ->run(['composer', '--no-plugins', '--no-scripts', 'audit', '--no-dev', '--format=json', '--no-interaction']);
            $decoded = json_decode($result->output(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! isset($decoded['advisories']) || ! is_array($decoded['advisories'])) {
                return $this->row('dependencies', 'PHP依存ライブラリ', 'unknown', '監査結果を読み取れません。サーバー管理者に確認してください。');
            }
            $count = count($decoded['advisories']);
            $ignored = $decoded['ignored-advisories'] ?? [];
            if ($count > 0 || (is_array($ignored) && $ignored !== [])) {
                return $this->row('dependencies', 'PHP依存ライブラリ', 'fail', '脆弱性情報があります。composer auditで詳細を確認し、修正版を検証して配布してください。');
            }
            if (! $result->successful()) {
                return $this->row('dependencies', 'PHP依存ライブラリ', 'unknown', '監査コマンドが正常終了していません。保守終了パッケージや通信状態を確認してください。');
            }

            return $this->row('dependencies', 'PHP依存ライブラリ', 'pass', '今回の監査で既知の脆弱性は報告されませんでした。');
        } catch (Throwable) {
            // 例外に認証情報や外部応答が含まれても、画面やログへ転記しない。
            return $this->row('dependencies', 'PHP依存ライブラリ', 'unknown', '依存関係を確認できません。Composerの配置と通信を確認してください。');
        }
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    public function certificate(): array
    {
        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return $this->row('certificate', 'HTTPS証明書', 'unknown', 'HTTPSの公開URLが設定されていないため未確認です。');
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? 443;
        // 接続先はサーバー管理者のAPP_URLのみ。HTTPの入力やCMS設定から指定させない。
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => trim($host, '[]'), 'SNI_enabled' => true]]);
        try {
            $socket = @stream_socket_client('ssl://'.$host.':'.$port, $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                return $this->row('certificate', 'HTTPS証明書', 'unknown', '証明書を検証できません。期限・信頼性・公開URL・通信を確認してください。');
            }
            $options = stream_context_get_params($socket);
            fclose($socket);
            $certificate = $options['options']['ssl']['peer_certificate'] ?? null;
            $details = $certificate instanceof \OpenSSLCertificate ? openssl_x509_parse($certificate) : false;
            $expires = is_array($details) ? ($details['validTo_time_t'] ?? null) : null;
            if (! is_int($expires)) {
                return $this->row('certificate', 'HTTPS証明書', 'unknown', '証明書の期限を読み取れませんでした。');
            }

            return $this->certificateExpiry($expires);
        } catch (Throwable) {
            return $this->row('certificate', 'HTTPS証明書', 'unknown', '証明書を確認できませんでした。');
        }
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    public function certificateExpiry(int $expires): array
    {
        $days = (int) floor(($expires - now()->getTimestamp()) / 86400);

        return $this->row('certificate', 'HTTPS証明書', $days < 0 ? 'fail' : ($days < 30 ? 'warn' : 'pass'),
            $days < 0 ? '証明書の有効期限が切れています。' : '有効期限は'.gmdate('Y-m-d', $expires).'（残り'.$days.'日）です。');
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private function row(string $key, string $label, string $status, string $message): array
    {
        return compact('key', 'label', 'status', 'message');
    }
}
