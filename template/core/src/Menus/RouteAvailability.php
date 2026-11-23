<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

/**
 * メニューの「組み込みページ」（route 名で指す項目）を、いま出してよいか。
 *
 * Core が知っているのは **route が在るかどうか**だけである。
 * 在っても出せない事情（同梱の見本ページを運用者が非公開にした等）は project 側が持つので、
 * ここは境界だけを置き、中身は project が差し込む（UX 一周 2026-09-27）。
 *
 * 差し込まれていなければ、これまでどおり「route が在れば出す」。
 */
interface RouteAvailability
{
    /** 出せない理由。**出せるなら null** */
    public function reason(string $route): ?string;
}
