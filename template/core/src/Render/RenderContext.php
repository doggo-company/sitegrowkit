<?php

declare(strict_types=1);

namespace AgentCms\Core\Render;

use AgentCms\Core\Pages\Page;

/**
 * 描画に必要な周辺情報（Phase 9.1D）。
 *
 * Renderer が個別に DB や設定を引かないよう、**1 回だけ組み立てて渡す**。
 */
final readonly class RenderContext
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $entry  詳細ページの対象（一覧ページでは null）
     */
    public function __construct(
        public Page $page,
        public array $settings,
        public ?array $entry = null,
        public bool $preview = false,
        // 内容交換用の描画ではフォームの実行部など、画面専用の部品を出さない。
        public bool $export = false,
    ) {}
}
