<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * 破壊的操作の確認要求（Phase 9.1B / STEP 7）。
 *
 * `token` は **操作 + 引数 + actor + 期限**へ束縛される。
 * 別の対象へ使い回せない（`ConfirmationTokens` を参照）。
 */
final readonly class Confirmation
{
    /** @param  list<string>  $impact */
    public function __construct(
        public string $token,
        public string $summary,
        public array $impact,
        public string $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'summary' => $this->summary,
            'impact' => $this->impact,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
