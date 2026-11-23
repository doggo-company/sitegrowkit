<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use RuntimeException;

/**
 * 適用の中断（Phase 9.4 / STAGE #17A）。
 *
 * これが投げられた時点で `ContentTypeInstaller` は **必ず rollback を完了**させている。
 * 呼び出し側は利用者向けメッセージを取り出すだけでよい。
 */
final class ContentTypeInstallFailed extends RuntimeException
{
    /** @param  list<string>  $rollbackNotes */
    public function __construct(
        string $message,
        public readonly array $rollbackNotes = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
