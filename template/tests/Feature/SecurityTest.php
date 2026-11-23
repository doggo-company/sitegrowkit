<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Http\SecurityHeadersMiddleware;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * docs/SECURITY.md の機械検証。
 */
final class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_on_public_pages(): void
    {
        $response = $this->get('/contact');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('challenges.cloudflare.com', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_csp_additional_origins_from_config(): void
    {
        // ADR-0010 §5: テンプレート import の外部依存は config 経由でのみ CSP へ追加できる
        config()->set('agentcms.csp.script_src', ['https://cdn.jsdelivr.net']);
        config()->set('agentcms.csp.style_src', ['https://fonts.googleapis.com']);
        config()->set('agentcms.csp.font_src', ['https://fonts.gstatic.com']);

        $csp = (string) $this->get('/contact')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com https://cdn.jsdelivr.net", $csp);
        $this->assertStringContainsString('style-src', $csp);
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);
        $this->assertStringContainsString('font-src \'self\' https://fonts.gstatic.com', $csp);
    }

    public function test_csp_defaults_unchanged_without_config(): void
    {
        config()->set('agentcms.csp', [
            'script_src' => [], 'style_src' => [], 'font_src' => [],
            'img_src' => [], 'connect_src' => [], 'frame_src' => [],
        ]);

        $csp = (string) $this->get('/contact')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com;", $csp.';');
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $csp);
    }

    public function test_security_headers_on_admin_login(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** 本番のHTTPSだけを保護し、開発環境や偽装proxyヘッダーでは有効にしない。 */
    public function test_hsts_requires_production_and_a_secure_request(): void
    {
        $middleware = new SecurityHeadersMiddleware;
        foreach ([
            ['production', 'https://example.test/', [], 'max-age=31536000'],
            ['production', 'http://example.test/', [], null],
            ['local', 'https://example.test/', [], null],
            ['production', 'http://example.test/', ['HTTP_X_FORWARDED_PROTO' => 'https'], null],
        ] as [$environment, $url, $server, $expected]) {
            app()->instance('env', $environment);
            $response = $middleware->handle(Request::create($url, server: $server), static fn () => new Response(''));
            $this->assertSame($expected, $response->headers->get('Strict-Transport-Security'));
        }

        // サーバー管理者が設定したポリシーをアプリの既定値で短くしない。
        $response = $middleware->handle(Request::create('https://example.test/'),
            static fn () => new Response('', headers: ['Strict-Transport-Security' => 'max-age=63072000']));
        $this->assertSame('max-age=63072000', $response->headers->get('Strict-Transport-Security'));
    }

    /**
     * 枠に入れられるのは **同じオリジンの下書きプレビューだけ**（Phase 9.6 / P0-C2）。
     *
     * Visual Page Editor の Canvas がプレビューを iframe で読むため、
     * その 1 画面だけ `SAMEORIGIN` を選べるようにしてある。
     * 認めるのは `SAMEORIGIN` ただ 1 つで、他の値は握り潰して `DENY` に戻る。
     */
    public function test_only_same_origin_framing_can_be_opted_into(): void
    {
        $middleware = new SecurityHeadersMiddleware;

        $cases = [
            'SAMEORIGIN' => 'SAMEORIGIN',
            'ALLOWALL' => 'DENY',
            'ALLOW-FROM https://evil.example' => 'DENY',
            '' => 'DENY',
        ];

        foreach ($cases as $chosen => $expected) {
            $response = $middleware->handle(
                Request::create('/admin/pages/1/preview'),
                static function () use ($chosen): Response {
                    $response = new Response('');

                    if ($chosen !== '') {
                        $response->headers->set('X-Frame-Options', $chosen);
                    }

                    return $response;
                },
            );

            $this->assertSame(
                $expected,
                $response->headers->get('X-Frame-Options'),
                "[{$chosen}] を選んだときの結果が違う",
            );
        }

        // 何も選んでいない画面は今までどおり DENY のまま
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_csrf_middleware_is_registered_for_web(): void
    {
        // テスト環境では PreventRequestForgery が常にスキップされるため 419 は直接検証できない。
        // web グループへの登録を検証し、実挙動は E2E（トークン無し POST の失敗）で担保する。
        /** @var Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        $this->assertContains(
            PreventRequestForgery::class,
            $kernel->getMiddlewareGroups()['web'] ?? [],
        );
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_session_cookie_settings(): void
    {
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_admin_requires_authentication(): void
    {
        foreach (['/admin', '/admin/news', '/admin/works', '/admin/contact', '/admin/site'] as $path) {
            $this->get($path)->assertRedirect();
        }
    }
}
