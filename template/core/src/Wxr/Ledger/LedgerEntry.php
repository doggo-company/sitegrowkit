<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

/**
 * 台帳を 1 件引いた結果（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 決められたときだけ値を渡す
 *
 * `Exact` 以外で `target_id` を渡すと、呼ぶ側は**どれか 1 つを選んだ値**を
 * 受け取ることになる。選んだ理由はどこにも無いので、
 * 「決められない」が「これに決めた」に静かにすり替わる。
 */
final class LedgerEntry
{
    private function __construct(
        public readonly LedgerState $state,
        public readonly ?string $targetId = null,
        public readonly ?string $batchId = null,
        public readonly ?bool $createdByImport = null,
    ) {}

    public static function absent(): self
    {
        return new self(LedgerState::Absent);
    }

    public static function conflict(): self
    {
        return new self(LedgerState::Conflict);
    }

    /**
     * @param  bool  $createdByImport  この取り込みが**作った**対象か。
     *                                 使い回した対象は `target_id` を持つが所有はしない。
     */
    public static function exact(string $targetId, string $batchId, bool $createdByImport): self
    {
        /*
         * 台帳の行は他から書かれ得る。読んだものをそのまま外へ渡すと、
         * **台帳が本文を運ぶ経路**になる。返すところでも形を確かめる。
         */
        LedgerKey::assertStoredIdentifier($targetId, 'target_id');
        LedgerKey::assertStoredIdentifier($batchId, 'batch_id');

        return new self(LedgerState::Exact, $targetId, $batchId, $createdByImport);
    }
}
