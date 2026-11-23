<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

/**
 * どこで開くか（Phase 9.9 / STAGE 22B）。
 *
 * `_blank` には常に `rel="noopener"` を付ける（OG10）。編集者に選ばせない —
 * 選ぶ理由が無く、付け忘れには実害がある。
 */
enum MenuTarget: string
{
    case Self = '_self';
    case Blank = '_blank';

    public function rel(): ?string
    {
        return $this === self::Blank ? 'noopener' : null;
    }
}
