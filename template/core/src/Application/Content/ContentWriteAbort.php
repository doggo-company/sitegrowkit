<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Result;
use RuntimeException;

/**
 * transaction の内側から**部分適用を残さずに**中断するための内部例外
 * （Phase 9.3B / STAGE 3B・SPEC §G.5 / §G.6）。
 *
 * validation error / conflict を戻り値で返してしまうと `DB::transaction()` が
 * commit してしまうため、**必ず例外で巻き戻す**。
 * Application Service の外へは漏らさない（呼び出し元が catch して `Result` を返す）。
 */
final class ContentWriteAbort extends RuntimeException
{
    private function __construct(public readonly Result $result)
    {
        parent::__construct($result->message ?? 'content write aborted');
    }

    public static function with(Result $result): self
    {
        return new self($result);
    }
}
