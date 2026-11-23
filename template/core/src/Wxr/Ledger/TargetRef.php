<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

/**
 * 台帳が指している「こちら側の 1 行」（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 鍵と同じ形しか通さない
 *
 * `target_id` は台帳から読んだ値である。台帳の行は他から書かれ得るので、
 * そのまま問い合わせへ渡すと**台帳が本文を運ぶ経路**になる。
 * 形は `LedgerKey` と同じ規則で、同じところに閉じてある。
 */
final class TargetRef
{
    public function __construct(
        public readonly ImportKind $kind,
        public readonly string $targetType,
        public readonly string $targetId,
    ) {
        LedgerKey::assertTargetType($targetType);
        LedgerKey::assertStoredIdentifier($targetId, 'target_id');
    }

    /** 同じ行を二度渡していないかを見分ける値 */
    public function signature(): string
    {
        return $this->kind->value."\0".$this->targetType."\0".$this->targetId;
    }
}
