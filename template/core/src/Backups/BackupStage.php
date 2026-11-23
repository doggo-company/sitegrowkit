<?php

declare(strict_types=1);

namespace AgentCms\Core\Backups;

/**
 * 取得の段階（2026-09-09・OG3 改訂）。
 *
 * **画面に出す「進み」はこの段階と、その中で実際に数えた数だけ**である。
 * 架空のパーセントは出さない — 大きさが分からないものに割合は付けられない。
 */
enum BackupStage: string
{
    /** 別プロセスの起動を待っている（まだ何も始まっていない） */
    case Queued = 'queued';

    case Database = 'database';
    case Media = 'media';
    case SecretScan = 'secret_scan';
    case Archive = 'archive';

    /** できた zip を開いて manifest を読み直し、戻せることを確かめる */
    case Verify = 'verify';

    public function label(): string
    {
        return match ($this) {
            self::Queued => '開始を待っています',
            self::Database => 'データベースを書き出しています',
            self::Media => 'メディアを写しています',
            self::SecretScan => '秘密情報が混ざっていないか調べています',
            self::Archive => 'ひとつのファイルにまとめています',
            self::Verify => 'できあがった backup を確かめています',
        };
    }

    /** @return list<self> 画面に並べる順 */
    public static function ordered(): array
    {
        return [self::Database, self::Media, self::SecretScan, self::Archive, self::Verify];
    }
}
