<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use RuntimeException;

/**
 * taxonomy 定義ファイルが不正なときに投げる（Phase 9.3B / STAGE 1）。
 *
 * メッセージは **利用者が読む日本語**にする。
 * ファイルパスは相対名（basename）だけを含め、DB 接続情報や秘密は含めない。
 */
final class TaxonomyDefinitionException extends RuntimeException
{
    /** @param list<string> $problems 日本語の問題説明（1 行 1 件） */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' / ', $problems));
    }
}
