<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

/**
 * ルールと 404 の記録を外へ出す形（Phase 9.10 / STAGE 23B）。
 *
 * `source_hash` は出さない。**照合の鍵であって、人にも AI にも用が無い**。
 */
final class RedirectPresenter
{
    /** @return array<string, mixed> */
    public static function rule(RedirectRule $rule): array
    {
        return [
            'id' => (int) $rule->getKey(),
            'match_type' => $rule->match_type,
            'source_path' => $rule->source_path,
            'target_url' => $rule->target_url,
            'status_code' => $rule->status_code,
            'status_label' => $rule->status()->label(),
            // 301 / 308 はブラウザが覚える。画面で注意を出すのに使う
            'is_sticky' => $rule->status()->isSticky(),
            'is_enabled' => $rule->is_enabled,
            'keep_query' => $rule->keep_query,
            'created_reason' => $rule->created_reason,
            'note' => $rule->note,
            'hit_count' => $rule->hit_count,
            'last_hit_at' => $rule->last_hit_at?->toIso8601String(),
            'lock_version' => $rule->lock_version,
        ];
    }

    /**
     * 404 の記録。
     *
     * **`last_ip_hash` は出さない。** 個人を追う材料をそのまま画面へ渡す理由が無い
     * （在ることは `has_ip` で分かれば足りる）。
     *
     * @return array<string, mixed>
     */
    public static function notFound(NotFoundLog $log): array
    {
        return [
            'id' => (int) $log->getKey(),
            'path' => $log->path,
            'last_query' => $log->last_query,
            'last_referrer' => $log->last_referrer,
            'last_user_agent' => $log->last_user_agent,
            'has_ip' => $log->last_ip_hash !== null,
            'hit_count' => $log->hit_count,
            'first_seen_at' => $log->first_seen_at?->toIso8601String(),
            'last_seen_at' => $log->last_seen_at?->toIso8601String(),
            'resolved_at' => $log->resolved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function decision(?RedirectDecision $decision, string $normalizedPath): array
    {
        if ($decision === null) {
            return [
                'path' => $normalizedPath,
                'matched' => false,
                'target' => null,
                'status_code' => null,
                'hops' => 0,
            ];
        }

        return [
            'path' => $normalizedPath,
            'matched' => true,
            'rule' => self::rule($decision->rule),
            'target' => $decision->target,
            'status_code' => $decision->status()->value,
            'hops' => $decision->hops,
        ];
    }
}
