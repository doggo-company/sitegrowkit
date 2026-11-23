<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Theme\ThemeTokens;

/**
 * 店の枠（見本・取り込んだテーマ）に、kit の標準の画面を載せるための 1 か所（UX 一周 2026-09-28）。
 *
 * サイト内検索と、見本の意匠をやめたあとのフォームは、kit 既定の素朴な枠
 * （`layouts.public`）で描かれていた。ヘッダーの色もロゴの色もボタンの色も違い、
 * **そこだけ別のサイトに飛んだように見えた**。ページはすでに店の枠に載せている
 * （`DocumentPageView::siteLayout()`）ので、同じ判定を使って揃える。
 *
 * 枠が載せられる形でなければ、今までどおり kit 既定に落とす。
 */
final class SiteFrame
{
    public function __construct(private readonly DocumentPageView $documents) {}

    /** 載せる layout */
    public function layout(): string
    {
        return $this->documents->siteLayout();
    }

    /** 店の枠に載せるか */
    public function onSite(): bool
    {
        return $this->layout() === DocumentPageView::SITE_LAYOUT;
    }

    /**
     * layout に渡す題。店の枠は題を**そのまま**出し、kit 既定の枠は「題 | サイト名」を自分で組む。
     */
    public function title(string $heading, string $siteName): string
    {
        return $this->onSite() && $siteName !== '' ? $heading.' | '.$siteName : $heading;
    }

    /** 枠の主な色（ボタンなど）。テーマが持たなければ kit 既定 */
    public function accent(): string
    {
        return app(ThemeTokens::class)->resolve('{colors.primary}') ?? '#0f4c46';
    }
}
