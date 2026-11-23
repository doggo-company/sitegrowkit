<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 復元の節目に呼ばれる観測点（2026-09-09・release R09）。
 *
 * **試験のためだけに在る。** rename の直後・commit の直前・commit の直後で
 * 「ここで落ちたら」を決定的に作るために、試験が container へ自分の実装を bind する。
 *
 * 環境変数や設定では切り替えない。本番は `NullRestoreProbe` が bind されていて
 * 何もしない — 外から落とす場所を与えない。
 */
interface RestoreProbe
{
    /** rename が 1 つ成功した直後（transaction の中） */
    public const AFTER_MOVE = 'after_move';

    /** DB の印を書いたあと、commit の直前（transaction の中） */
    public const BEFORE_COMMIT = 'before_commit';

    /** commit が返った直後、cache を捨てる前 */
    public const AFTER_COMMIT = 'after_commit';

    public function at(string $point): void;
}
