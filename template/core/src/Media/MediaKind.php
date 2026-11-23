<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

/**
 * Media の種別（Phase 9.5-D / ADR-0018）。
 *
 * **テーブルは分けない。** 利用者から見てメディアライブラリは 1 つだからである。
 * 分けるのは validation / storage / picker の 3 つだけ。
 */
enum MediaKind: string
{
    case Image = 'image';

    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Image => '画像',
            self::File => 'ファイル',
        };
    }

    /** その種別を置く disk（config が正本） */
    public function disk(): string
    {
        return match ($this) {
            self::Image => (string) config('agentcms.uploads.disk'),
            self::File => (string) config('agentcms.files.disk'),
        };
    }

    /**
     * その種別を置くディレクトリ（disk の中）。
     *
     * 画像が `media/` なのは **昔からそう置いているから**であって、
     * `agentcms.uploads.dir`（= `uploads`、`UploadValidator` の既定）ではない。
     * 掃除コマンドが「どこを見ればよいか」を知るために、ここを唯一の情報源にする。
     */
    public function directory(): string
    {
        return match ($this) {
            self::Image => 'media',
            self::File => (string) config('agentcms.files.dir', 'files'),
        };
    }

    /** 寸法を持つ種別か */
    public function hasDimensions(): bool
    {
        return $this === self::Image;
    }
}
