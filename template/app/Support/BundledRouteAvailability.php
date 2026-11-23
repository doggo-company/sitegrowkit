<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Menus\RouteAvailability;

/**
 * メニューの組み込みページを出してよいかを、`BundledPages` に聞く（UX 一周 2026-09-27）。
 *
 * 同梱の見本ページを非公開にしたのに、メニューにだけリンクが残ると
 * **押すと 404** になる。判定は `BundledPages` 1 か所に寄せたまま、メニューにも効かせる。
 */
final class BundledRouteAvailability implements RouteAvailability
{
    public function __construct(private readonly BundledPages $pages) {}

    public function reason(string $route): ?string
    {
        // 窓口のフォームを下書きにしているなら、メニューにも出さない（UX 一周 6 周目 R6-1）
        if ($route === 'contact.show' && app(ContactEntry::class)->closed()) {
            return 'お問い合わせのフォームを下書きにしています（公開すると出ます）';
        }

        return $this->pages->reason($route);
    }
}
