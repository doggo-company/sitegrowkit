<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

/**
 * 転送の status code（Phase 9.10 / STAGE 23B・OG2）。
 *
 * 既定は **301**。SEO 評価の引き継ぎが目的なので恒久移動が正しい。
 * ただしブラウザは 301 を強く覚えるので、**間違えると戻しにくい**。
 * その注意は管理画面が出す（`warning()`）。
 */
enum RedirectStatus: int
{
    /** 恒久的に移動した（既定） */
    case MovedPermanently = 301;

    /** 一時的（あとで戻す予定がある） */
    case Found = 302;

    /** 一時的。method を変えない */
    case TemporaryRedirect = 307;

    /** 恒久的。method を変えない */
    case PermanentRedirect = 308;

    /** ブラウザが覚えてしまうものか（画面で注意を出すかの判断に使う） */
    public function isSticky(): bool
    {
        return $this === self::MovedPermanently || $this === self::PermanentRedirect;
    }

    public function label(): string
    {
        return match ($this) {
            self::MovedPermanently => '301 恒久的に移動',
            self::Found => '302 一時的に移動',
            self::TemporaryRedirect => '307 一時的（method を保つ）',
            self::PermanentRedirect => '308 恒久的（method を保つ）',
        };
    }
}
