<?php

declare(strict_types=1);

namespace App\Application\Activity;

use AgentCms\Core\Audit\AuditLog;

/**
 * 履歴 1 行の表示形（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ## 出さないもの
 *
 * 本文・パスワード・token・cookie・認証情報・問い合わせ本文は
 * **そもそも保存されていない**（`DatabaseAuditRecorder` が落とす）。
 * ここではさらに、metadata を **キー名と短いスカラーだけ**に畳んで表示する。
 *
 * IP と User-Agent は個人情報なので、**オーナーにだけ**渡す。
 */
final class ActivityPresenter
{
    /** metadata の値をそのまま出してよい長さの上限 */
    private const VALUE_MAX = 80;

    /** @return array<string, mixed> */
    public function present(AuditLog $log, bool $includeNetwork = false): array
    {
        $row = [
            'id' => (int) $log->id,
            'occurredAt' => $log->created_at?->toIso8601String(),
            'actorId' => $log->actor_id === null ? null : (int) $log->actor_id,
            // 利用者が無効化されても、当時の表示名は履歴に残る
            'actorLabel' => $log->actor_label !== '' ? $log->actor_label : '不明',
            'actorType' => $log->actor_type,
            'operation' => $log->operation,
            'operationLabel' => ActivityLabels::operation($log->operation),
            'result' => $log->result,
            'resultLabel' => ActivityLabels::result($log->result),
            'interface' => $log->interface,
            'interfaceLabel' => ActivityLabels::interface($log->interface),
            'resourceType' => $log->resource_type,
            'resourceId' => $log->resource_id,
            'summary' => $this->summary($log),
        ];

        if ($includeNetwork) {
            $row['ipAddress'] = $log->ip_address;
            $row['userAgent'] = $log->user_agent;
            $row['anonymized'] = $log->anonymized_at !== null;
        }

        return $row;
    }

    /**
     * metadata の安全な要約。
     *
     * 配列は **キー名または要素の一覧**だけにし、長い文字列は落とす
     * （本文が紛れ込んでも表示されない二重の防御）。
     */
    private function summary(AuditLog $log): string
    {
        $metadata = $log->metadata;

        if (! is_array($metadata) || $metadata === []) {
            return '';
        }

        $parts = [];

        foreach ($metadata as $key => $value) {
            $parts[] = $key.': '.$this->scalar($value);
        }

        return implode(' / ', $parts);
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'はい' : 'いいえ';
        }

        if (is_array($value)) {
            $items = array_map(fn (mixed $item): string => $this->scalar($item), array_slice($value, 0, 10));

            return implode('、', $items).(count($value) > 10 ? ' ほか' : '');
        }

        if ($value === null) {
            return '—';
        }

        $text = (string) (is_scalar($value) ? $value : '');

        return mb_strlen($text) > self::VALUE_MAX ? mb_substr($text, 0, self::VALUE_MAX).'…' : $text;
    }
}
