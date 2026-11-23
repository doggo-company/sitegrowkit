<?php

declare(strict_types=1);

namespace AgentCms\Core\Media;

/**
 * 画像バリエーションの形式（Phase 9.5-D / ADR-0018）。
 *
 * 並びが **配信の優先順**そのものである（AVIF → WebP → 原本）。
 * 新しい形式を足すときは、この enum の宣言順を変えれば配信順も変わる。
 */
enum VariantFormat: string
{
    case Avif = 'avif';

    case Webp = 'webp';

    public function mime(): string
    {
        return match ($this) {
            self::Avif => 'image/avif',
            self::Webp => 'image/webp',
        };
    }

    /** その形式を書き出せる GD 関数 */
    public function encoder(): string
    {
        return match ($this) {
            self::Avif => 'imageavif',
            self::Webp => 'imagewebp',
        };
    }

    /**
     * 画質を読む config キー。
     *
     * 値そのものは `VariantCapability::quality()` が読む。
     * enum は **何であるか**だけを持ち、環境や設定の解釈は持たない。
     */
    public function qualityKey(): string
    {
        return match ($this) {
            self::Avif => 'avif_quality',
            self::Webp => 'webp_quality',
        };
    }

    /**
     * 配信で試す順（先頭が最優先）。
     *
     * @return list<self>
     */
    public static function preferenceOrder(): array
    {
        return self::cases();
    }
}
