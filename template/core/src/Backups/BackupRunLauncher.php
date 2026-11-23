<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 取得を**別プロセスで**始める口（2026-09-09・OG3 改訂）。
 *
 * web の要求時間の中で取らない。HTTP は「始めた」とだけ答え、実際の処理は
 * `php artisan agentcms:backup-run <run>` が別プロセスで行う。
 * worker の常駐は要らない — 起動できる環境なら、それだけで済む。
 *
 * 起動できない環境（`proc_open` が塞がれた共有ホスティング等）では
 * `unavailableReason()` が理由を返し、画面は CLI の手順を出す。
 * **黙って「処理中」にしない。**
 */
interface BackupRunLauncher
{
    /** 起動できない理由。できるなら null */
    public function unavailableReason(): ?string;

    /**
     * 別プロセスを起動する。
     *
     * @return int|null 起動した process の pid（取れない環境では null）
     *
     * @throws BackupFailed 起動できなかった
     */
    public function launch(string $runId): ?int;
}
