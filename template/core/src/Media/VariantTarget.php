<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

/**
 * 「どの形式を・どの幅で」作るか（レスポンシブ画像 / release-20260909）。
 *
 * 派生物の同一性は **format + width** である。`width` は原本の幅と等しければ
 * 「同寸の format 変換」（Phase 9.5-D の従来の variant）、小さければ「幅候補」。
 * 原本より大きい幅は存在しない（拡大は作らない — `VariantCapability::supportsWidth()`）。
 *
 * 判定や環境の解釈は持たない。**何であるか**だけを表す。
 */
final readonly class VariantTarget
{
    public function __construct(
        public VariantFormat $format,
        public int $width,
    ) {}

    /** 原本と同寸（= format 変換だけ）の target か */
    public function isFullSize(int $originalWidth): bool
    {
        return $this->width === $originalWidth;
    }

    /** DB 行と突き合わせるための識別子（`webp@640`） */
    public function key(): string
    {
        return $this->format->value.'@'.$this->width;
    }
}
