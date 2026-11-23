<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 取得の進みを受け取る口（2026-09-09・OG3 改訂）。
 *
 * `BackupBuilder` は段階が変わるたび、段階の中で数えられるものが増えるたびに呼ぶ。
 * **数えられないものは呼ばない。** 受け取る側（`BackupRunner`）はこれを
 * そのまま状態ファイルへ書く。
 */
interface BackupProgress
{
    /** 段階が変わった */
    public function stage(BackupStage $stage): void;

    /**
     * 段階の中で実際に数えた進み。
     *
     * @param  int  $done  ここまでに終えた数（表の数・写したファイル数）
     * @param  int|null  $total  分かっていれば全体の数。分からなければ null（架空の総数を作らない）
     */
    public function advance(BackupStage $stage, int $done, ?int $total = null): void;
}
