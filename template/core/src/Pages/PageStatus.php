<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages;

/**
 * ページの進行状態（Phase 9.1D）。
 *
 * 「公開状態」と「保管状態」を 1 つの enum に混ぜないため、
 * trashed は **復元できる保管状態**として扱い、物理削除（purge）は別操作にする。
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Trashed = 'trashed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Published => '公開中',
            self::Trashed => 'ゴミ箱',
        };
    }
}
