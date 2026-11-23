<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * 公開前チェックが、**その画面自身の nonce を書き換えない**
 * （WP 一周 7 周目 2026-09-04 / #22 の残り 3 件）。
 *
 * ## 何が出ていたか
 *
 * `/admin/readiness` を**URL から直接開く**と、console に 3 件出る:
 *
 * ```
 * Executing inline script violates … 'script-src 'self' 'nonce-ts3ap0jQ…''
 * Applying inline style violates … （2 件）
 * ```
 *
 * 3 件の中身は `AdminCspMiddleware` が「消せない」と書いている 3 つ
 * — dark mode の即時適用・背景色・`@font-face` — そのものである。
 * 実物を読むと、**応答ヘッダの nonce と、head に書かれた nonce が別物**だった。
 *
 * | | nonce |
 * | --- | --- |
 * | 応答ヘッダ | `ts3ap0jQ…` |
 * | head の script / style 3 つ | `aRFTu7IN…` |
 *
 * ## なぜずれるか
 *
 * `PublicationReadiness::fetch()` は検査のあいだ `Vite` を使い捨てへ差し替える
 *（#22・preload 台帳を外側へ持ち込まないため）。**差し替えは container にだけ効く。**
 * ファサードは一度解決した実体を静的に持ち続けるので、
 * `Vite::useCspNonce()` は**差し替えたはずの外側の Vite** を掴んだままになる。
 *
 * 検査は公開ページを内側のリクエストとして描くので、そこでも
 * `AdminCspMiddleware` が走り、`Vite::useCspNonce()` が**外側の nonce を上書きする**。
 * middleware が既にヘッダへ書いた値は古いまま — こうして 2 つに割れる。
 *
 * ## 画面は動くので気づけない
 *
 * 弾かれるのは dark mode と背景色と font なので、**見た目はほぼ変わらない**。
 * console を開いた人だけが「壊れている」と読む。#22 の `Link` ヘッダと同じ形である。
 */
final class ReadinessCspNonceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🔴 **これが本体。** お客さんが見るのは応答そのものである。
     *
     * ヘッダが許した nonce で、head の inline が書かれている。
     */
    public function test_ヘッダのnonceでheadのinlineが書かれている(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/admin/readiness')
            ->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $allowed = $this->nonceIn($csp);

        $this->assertNotSame('', $allowed, 'CSP に nonce が入っていない');

        foreach ($this->inlineNonces((string) $response->getContent()) as $index => $used) {
            $this->assertSame(
                $allowed,
                $used,
                "{$index} 番目の inline が、ヘッダで許していない nonce で書かれている",
            );
        }
    }

    /** 検査を通しても、外側の nonce はそのままである */
    public function test_検査のあとも外側のnonceが残る(): void
    {
        $before = Vite::useCspNonce();

        app(PublicationReadiness::class)->check();

        $this->assertSame($before, Vite::cspNonce(), '検査が外側の nonce を書き換えた');
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function nonceIn(string $csp): string
    {
        preg_match("/'nonce-([^']+)'/", $csp, $m);

        return $m[1] ?? '';
    }

    /**
     * head の `<script>` / `<style>` が名乗る nonce を並べる。
     *
     * `type="application/json"` は**データブロック**で、ブラウザは実行のために
     * 用意しない（`script-src` の対象外）ので数えない。外部 file も同様。
     *
     * @return list<string>
     */
    private function inlineNonces(string $html): array
    {
        $found = [];

        foreach (preg_split('/(?=<(?:script|style)\b)/i', $html) ?: [] as $chunk) {
            if (! preg_match('/^<(script|style)([^>]*)>/i', $chunk, $m)) {
                continue;
            }

            $attributes = $m[2];

            if (preg_match('/\bsrc=/i', $attributes) || preg_match('/application\/json/i', $attributes)) {
                continue;
            }

            $found[] = preg_match('/\bnonce="([^"]*)"/i', $attributes, $n) ? $n[1] : '';
        }

        return $found;
    }
}
