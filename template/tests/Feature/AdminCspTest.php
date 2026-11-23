<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理画面の Content-Security-Policy（P1 ⑪）。
 *
 * ## 公開面のものとは別に置く
 *
 * `PublicCspMiddleware` は既に在るが、**管理画面には当たっていない**
 * （「admin は Inertia/Vite の制約が異なるため」と外してあった）。
 * その制約とは **インラインの `<script>` と `<style>`** で、
 * どちらも消せない理由がある:
 *
 * - dark mode の即時適用 — 外部 file にすると**一瞬ちらつく**
 * - 背景色 — 同上
 * - `@fonts` が吐く `@font-face` — Laravel が出すもの
 *
 * だから **nonce** で通す。`'unsafe-inline'` を許すと、
 * **CSP を書いた意味がほとんど無くなる**。
 */
final class AdminCspTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_管理画面にcspが付く(): void
    {
        $response = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $csp, '管理画面に CSP が付いていない');

        foreach ([
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ] as $directive) {
            $this->assertStringContainsString($directive, $csp, $directive);
        }
    }

    /**
     * **`unsafe-inline` を許さない。**
     *
     * 許した瞬間、インライン注入に対する守りが消える。
     * **効いていない CSP を足して緑にしない**ための検査である。
     */
    public function test_unsafe_inlineを許さない(): void
    {
        $csp = (string) $this->actingAs($this->admin())
            ->get('/dashboard')->headers->get('Content-Security-Policy');

        // **先に在ることを確かめる。** CSP が空でも「含まれていない」は成り立つので、
        // それだけ見ていると **CSP を消した日に緑のまま通る**
        $this->assertNotSame('', $csp, 'CSP が無い（この検査は空振りしている）');

        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    /**
     * **nonce は応答ごとに変える**（F-25）。
     *
     * 使い回すと、**1 回漏れた nonce が恒久の穴になる**。
     * 攻撃者は次の応答でも同じ値を使えてしまい、nonce を配る意味が消える。
     *
     * この性質は middleware の docblock が主張していたが、**誰も見ていなかった**。
     */
    public function test_nonceは応答ごとに変わる(): void
    {
        $admin = $this->admin();

        $nonces = [];

        foreach ([1, 2] as $ignored) {
            $csp = (string) $this->actingAs($admin)
                ->get('/dashboard')->headers->get('Content-Security-Policy');

            preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $m);

            $this->assertNotEmpty($m, 'CSP に nonce が入っていない');

            $nonces[] = $m[1];
        }

        $this->assertNotSame(
            $nonces[0],
            $nonces[1],
            'nonce が使い回されている（1 回漏れたら恒久の穴になる）',
        );
    }

    /**
     * **画面が組み立てるものが、そのまま通ること。**
     *
     * インラインを 1 つでも取りこぼすと、その画面は**動かないのに 200 を返す**。
     * だから「200 が返る」ではなく「**出したものに nonce が載っている**」を見る。
     */
    public function test_出したインラインにはすべてnonceが載る(): void
    {
        $response = $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $html = (string) $response->getContent();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $m);
        $this->assertNotEmpty($m, 'CSP に nonce が入っていない');

        $nonce = $m[1];

        // 実行される script と、当たる style。**データブロックは対象外**
        preg_match_all('#<script([^>]*)>#i', $html, $scripts);

        foreach ($scripts[1] as $attr) {
            if (str_contains($attr, 'application/json')) {
                // Inertia の payload。**実行されない**ので CSP の対象にならない
                continue;
            }

            $this->assertStringContainsString(
                $nonce,
                $attr,
                "nonce の無い script がある: <script{$attr}>",
            );
        }

        preg_match_all('#<style([^>]*)>#i', $html, $styles);

        foreach ($styles[1] as $attr) {
            $this->assertStringContainsString(
                $nonce,
                $attr,
                "nonce の無い style がある: <style{$attr}>",
            );
        }
    }
}
