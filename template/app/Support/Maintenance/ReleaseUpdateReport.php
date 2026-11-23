<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use Throwable;

/** CLIの署名確認記録を読む。HTTP表示から外部通信・更新・署名検証を起動しない。 */
final class ReleaseUpdateReport
{
    public function __construct(private readonly ?string $projectRoot = null) {}

    /** @return array{state: string, checkedAt: ?string, currentVersion: ?string, candidateVersion: ?string, signatureVerified: bool, archiveVerified: bool, message: string} */
    public function read(): array
    {
        $root = $this->projectRoot ?? base_path();
        $empty = ['state' => 'missing', 'checkedAt' => null, 'currentVersion' => null, 'candidateVersion' => null, 'signatureVerified' => false, 'archiveVerified' => false, 'message' => 'まだ配布版の確認記録がありません。配布元の案内を受け取ったら、サーバー管理者に署名と配布物の確認を依頼してください。'];
        $record = $this->readJson($root, '.agentcms/local/update-check.json');
        if ($record === null) {
            return $empty;
        }
        $project = $this->readJson($root, '.agentcms/project.json');
        try {
            if (($record['format_version'] ?? null) !== 1 || ! is_string($record['checked_at'] ?? null) || ! in_array($record['status'] ?? null, ['verified', 'failed'], true) || ($record['applied'] ?? null) !== false) {
                return [...$empty, 'state' => 'invalid', 'message' => '確認記録の形式を読み取れません。署名確認をやり直してください。'];
            }
            $checked = strtotime($record['checked_at']);
            if ($checked === false) {
                return [...$empty, 'state' => 'invalid', 'message' => '確認日時を読み取れません。署名確認をやり直してください。'];
            }
            $age = now()->getTimestamp() - $checked;
            $sameProject = $project !== null && is_array($record['project'] ?? null);
            foreach (['kit_version', 'template_schema_version', 'agent_context_version'] as $key) {
                if (! isset($project[$key]) || ($record['project'][$key] ?? null) !== $project[$key]) {
                    $sameProject = false;
                }
            }
            $current = $this->version($project['kit_version'] ?? null);
            $base = [...$empty, 'checkedAt' => gmdate(DATE_ATOM, $checked), 'currentVersion' => $current];
            // 古い検査や変更前のプロジェクトへの結果を、現在の合格表示へ使わない。
            if ($age < 0 || $age > 48 * 3600 || ! $sameProject || $current === null) {
                return [...$base, 'state' => 'stale', 'message' => '確認から48時間を超えたか、利用中の版が変わっています。配布物を再確認してください。'];
            }
            if ($record['status'] === 'failed' || ($record['signature'] ?? null) !== 'verified') {
                return [...$base, 'state' => 'current', 'message' => '配布物の確認に失敗しています。導入せず、署名・信頼鍵・配布ファイルをサーバー管理者に確認してください。'];
            }
            $candidate = $this->version($record['candidate']['version'] ?? null);
            if ($candidate === null) {
                return [...$base, 'state' => 'invalid', 'message' => '候補版の記録を読み取れません。署名確認をやり直してください。'];
            }
            $archiveVerified = ($record['archive']['status'] ?? null) === 'verified';
            $compatibility = $record['compatibility']['status'] ?? 'unknown';
            $verdict = $record['verdict'] ?? 'unknown';
            $admissibility = $record['admissibility']['status'] ?? 'unknown';
            $message = match (true) {
                $verdict === 'older', $compatibility === 'candidate_behind' => '候補は利用中の版より古いか、構造契約が合いません。導入しないでください。',
                $admissibility === 'blocked' => '配布物の署名は確認されていますが、導入条件を満たしていません。サーバー管理者が原因を確認するまで導入しないでください。',
                $admissibility !== 'review_required', $compatibility !== 'same', ! in_array($verdict, ['same', 'newer'], true) => '互換性または利用中の版の確認が必要です。移行手順を確認するまで導入しないでください。',
                ! $archiveVerified => '署名は確認されていますが、手元の配布ファイルは未照合です。配布物の照合が必要です。',
                $verdict === 'newer' => '新しい候補版を確認しました。まだ更新されていません。バックアップと差分・移行手順の確認が必要です。',
                default => '確認した候補は利用中と同じ版です。配布元の最新情報を自動確認した結果ではありません。',
            };

            return [...$base, 'state' => 'current', 'candidateVersion' => $candidate, 'signatureVerified' => true, 'archiveVerified' => $archiveVerified, 'message' => $message];
        } catch (Throwable) {
            // 記録の壊れ方や内部パスを画面へ流さない。
            return [...$empty, 'state' => 'invalid', 'message' => '確認記録を読み取れません。署名確認をやり直してください。'];
        }
    }

    private function version(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 128 && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.+-]+)?$/D', $value) === 1 ? $value : null;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $root, string $relative): ?array
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                return null;
            }
        }
        if (! is_file($path)) {
            return null;
        }
        try {
            // 実際に読む量も制限する。判定前後に大きくなっても全量は読み込まない。
            $raw = @file_get_contents($path, false, null, 0, 65537);
            if ($raw === false || strlen($raw) > 65536) {
                return null;
            }
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

            return is_array($data) && ! array_is_list($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
