<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/** 本番の観測点。何もしない（`RestoreProbe`） */
final class NullRestoreProbe implements RestoreProbe
{
    public function at(string $point): void {}
}
