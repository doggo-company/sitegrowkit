<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * 破壊的操作の確認（Phase 9.1B / STEP 7）。
 *
 * token は **操作 ID + 引数のハッシュ + actor + 期限**へ束縛する。
 * 「別の対象に確認を使い回す」ことができないのが要件。
 *
 * 保存先は cache（既定 TTL 10 分）。DB テーブルを増やさない。
 */
final class ConfirmationGate
{
    private const PREFIX = 'agentcms:confirm:';

    private const TTL_SECONDS = 600;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * 確認が必要かを判定し、必要なら Confirmation を発行する。
     * 既に正しい token が提示されていれば null（= そのまま実行してよい）。
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $impact
     */
    public function require(
        string $operationId,
        OperationClassification $classification,
        OperationContext $context,
        array $arguments,
        string $summary,
        array $impact,
    ): ?Confirmation {
        if (! $classification->requiresConfirmation()) {
            return null;
        }

        $expected = $this->fingerprint($operationId, $arguments, $context);

        if ($context->confirmationToken !== null && $this->consume($context->confirmationToken, $expected)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $this->cache->put(self::PREFIX.$token, $expected, self::TTL_SECONDS);

        return new Confirmation(
            token: $token,
            summary: $summary,
            impact: $impact,
            expiresAt: now()->addSeconds(self::TTL_SECONDS)->toIso8601String(),
        );
    }

    /**
     * 分類に関わらず**必ず確認を取る**（Phase 9.8 / STAGE 21D・SPEC §D.2.2）。
     *
     * 既定では確認は `Destructive` / `ExternalEffect` にだけ求める。だが
     * 過去の版への復元は `Write` でありながら **いまの内容を丸ごと置き換える**。
     * 何が失われるかを先に見せずに実行してよい操作ではない。
     *
     * 分類のほうを `Destructive` へ変えて済ませない。あれは「取り消せるか」を
     * 表す軸で、復元は取り消せる（また戻せる）。**分類は正直なまま、
     * 確認だけを要求する**のが実態に合う。
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $impact
     */
    public function requireAlways(
        string $operationId,
        OperationContext $context,
        array $arguments,
        string $summary,
        array $impact,
    ): ?Confirmation {
        $expected = $this->fingerprint($operationId, $arguments, $context);

        if ($context->confirmationToken !== null && $this->consume($context->confirmationToken, $expected)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $this->cache->put(self::PREFIX.$token, $expected, self::TTL_SECONDS);

        return new Confirmation(
            token: $token,
            summary: $summary,
            impact: $impact,
            expiresAt: now()->addSeconds(self::TTL_SECONDS)->toIso8601String(),
        );
    }

    /** token は 1 回で使い切る（再利用禁止） */
    private function consume(string $token, string $expected): bool
    {
        $stored = $this->cache->get(self::PREFIX.$token);

        if (! is_string($stored) || ! hash_equals($stored, $expected)) {
            return false;
        }

        $this->cache->forget(self::PREFIX.$token);

        return true;
    }

    /** @param  array<string, mixed>  $arguments */
    private function fingerprint(string $operationId, array $arguments, OperationContext $context): string
    {
        ksort($arguments);

        return hash('sha256', implode('|', [
            $operationId,
            json_encode($arguments, JSON_THROW_ON_ERROR),
            $context->actorKey(),
        ]));
    }
}
