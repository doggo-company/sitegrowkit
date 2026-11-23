<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

use RuntimeException;

/**
 * backup を作れなかった / 戻せなかった（Phase 9.11 / STAGE 24B）。
 *
 * **理由をそのまま利用者へ出さない。** path や SQL が混ざると、
 * 画面に置き場所の手がかりが出てしまう。呼び出し側が読める文へ直す。
 */
final class BackupFailed extends RuntimeException {}
