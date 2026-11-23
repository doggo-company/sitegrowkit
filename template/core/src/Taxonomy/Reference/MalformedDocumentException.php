<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use RuntimeException;

/**
 * PageDocument の構造確認を安全に完了できない（Phase 9.3B / STAGE 2A CORRECTION C）。
 *
 * **「参照なし」と混同してはならない。**
 * JSON decode 不能 / root 欠落 / 判定に必要な位置の型不正などは、
 * 「参照が無い」のではなく「参照の有無を確定できない」である。
 * fail closed とし、削除を許可しない。
 */
final class MalformedDocumentException extends RuntimeException
{
    public static function because(string $what): self
    {
        return new self($what);
    }
}
