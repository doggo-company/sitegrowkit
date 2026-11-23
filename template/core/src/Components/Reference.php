<?php

declare(strict_types=1);

namespace AgentCms\Core\Components;

/**
 * 再利用 component を参照している 1 ページ（Phase 9.6 / P0-C7）。
 *
 * **公開に出ているか・下書きにだけ在るか・ゴミ箱かを分けて持つ。**
 * 数え方は消費する側が決めるので、ここでは畳まない（`ReusableReferences` の表を参照）。
 */
final readonly class Reference
{
    public function __construct(
        public int $pageId,
        public string $title,
        public bool $inPublished,
        public bool $inDraft,
        public bool $trashed,
    ) {}

    /** いま公開されている見た目が変わるか（ゴミ箱のページは公開されていない） */
    public function affectsPublic(): bool
    {
        return $this->inPublished && ! $this->trashed;
    }
}
