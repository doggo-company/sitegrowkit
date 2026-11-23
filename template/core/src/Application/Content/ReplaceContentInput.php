<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

/**
 * 一括置換の入力（P1 ⑧ / GUI 化）。
 *
 * CLI と管理画面は**同じ service**（`ReplaceContent`）へこの 1 つの形で入る。
 * 画面が使えない抜け道（`allowStaleBackup` / `limit`）は、**画面側の adapter が
 * 決して立てない**ことで塞ぐ — service に「どの経路か」で分岐を書かない。
 */
final readonly class ReplaceContentInput
{
    public function __construct(
        public string $type,
        public string $field,
        public string $from,
        public string $to,
        /** 触る最大件数（CLI の `--limit`。明示された絞り込みであり、黙った先頭切りではない） */
        public ?int $limit = null,
        /**
         * これを超える件数なら**何もせずに断る**（画面用）。
         * 「200 件と聞いて押したら先頭だけ変わった」を作らない。
         */
        public ?int $maxRows = null,
        /** 24 時間より古い backup でも進む（CLI の明示フラグ。画面は立てない） */
        public bool $allowStaleBackup = false,
        /**
         * backup の**中身**まで `BackupRestorer::verify` で確かめる（画面用）。
         * CLI の既存契約は「目録と時刻の検査」なので、そちらは false のまま。
         */
        public bool $verifyBackupContents = false,
    ) {}
}
