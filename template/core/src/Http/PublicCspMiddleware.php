<?php

declare(strict_types=1);

namespace AgentCms\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 公開サイト用 Content-Security-Policy。
 * 既定の外部許可は Cloudflare Turnstile のみ（Phase 1 仕様）。
 * テンプレート import 等の外部依存は config('agentcms.csp.*')（.env の AGENTCMS_CSP_*）で
 * オリジン単位に追加できる（ADR-0010 §5。既定は空 = 挙動不変）。
 */
final class PublicCspMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            $this->directive('script-src', "'self' https://challenges.cloudflare.com", 'script_src'),
            $this->directive('style-src', "'self' 'unsafe-inline'", 'style_src'),
            $this->directive('img-src', "'self' data:", 'img_src'),
            $this->directive('font-src', "'self'", 'font_src'),
            $this->directive('connect-src', "'self' https://challenges.cloudflare.com", 'connect_src'),
            $this->directive('frame-src', 'https://challenges.cloudflare.com', 'frame_src'),
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }

    private function directive(string $name, string $defaults, string $configKey): string
    {
        /** @var array<int, string> $extra */
        $extra = (array) config("agentcms.csp.{$configKey}", []);
        $sources = array_merge(explode(' ', $defaults), array_map(trim(...), $extra));

        return $name.' '.implode(' ', array_values(array_unique(array_filter($sources))));
    }
}
