<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SecureCookie;
use Tests\TestCase;

/**
 * `SESSION_SECURE_COOKIE=auto` を、意図どおりに読む（WP 一周 7 周目 2026-09-04）。
 *
 * ## 何が起きていたか
 *
 * 生成される `.env`（`.env.example` 由来）には `SESSION_SECURE_COOKIE=auto` が入っている。
 * Laravel の `config/session.php` は `env('SESSION_SECURE_COOKIE')` をそのまま渡すので、
 * **文字列 `"auto"` が truthy として扱われる** — つまり `secure` 付きの cookie になる。
 *
 * 生成直後のサイトは `http://localhost:8180` である。
 * ブラウザは **http で secure cookie を保存しない**。だから:
 *
 * 1. ログインは通る（サーバー側の session には書ける）
 * 2. その応答で表示された画面は動く
 * 3. **URL を直接開くと login に戻される**（cookie が無いので毎回新しい session）
 *
 * 実物のヘッダで確認した:
 *
 * ```
 * Set-Cookie: sitegrowkit-session=…; path=/; secure; httponly; samesite=lax
 * ```
 *
 * 🔴 **7 周目に「再現手順を確立できていない」と書いたのはこれである。**
 * Inertia のクライアント遷移では同じページの中で動くので気づかない。
 * URL を直接開いたときだけ切れるので、「数分後に安定した」ように見えていた。
 *
 * ## なぜ config 側で解くか
 *
 * `.env.example` を直すだけだと、**もう `auto` を持っている案件は直らない**。
 * 書いた人の意図（「HTTPS のときだけ secure」）は明らかなので、そう読む。
 */
final class SecureCookieTest extends TestCase
{
    /** 🔴 これが本体。http のサイトで secure を付けない */
    public function test_autoはhttpならfalse(): void
    {
        $this->assertFalse(SecureCookie::resolve('auto', 'http://localhost:8180'));
    }

    public function test_autoはhttpsならtrue(): void
    {
        $this->assertTrue(SecureCookie::resolve('auto', 'https://example.com'));
    }

    /** 大文字で書く人がいる */
    public function test_autoは大文字小文字を問わない(): void
    {
        $this->assertTrue(SecureCookie::resolve('AUTO', 'https://example.com'));
        $this->assertFalse(SecureCookie::resolve('Auto', 'http://example.com'));
    }

    /** はっきり書いてあるものは、そのまま従う（勝手に上書きしない） */
    public function test_trueとfalseはそのまま(): void
    {
        $this->assertTrue(SecureCookie::resolve('true', 'http://localhost'));
        $this->assertTrue(SecureCookie::resolve(true, 'http://localhost'));
        $this->assertFalse(SecureCookie::resolve('false', 'https://example.com'));
        $this->assertFalse(SecureCookie::resolve(false, 'https://example.com'));
    }

    /**
     * 未設定は `null` のまま返す。
     *
     * Laravel は `null` を「リクエストの https に従う」として扱う。
     * ここで `false` に倒すと、**HTTPS の本番で secure が外れる**。
     */
    public function test_未設定はnullのまま(): void
    {
        $this->assertNull(SecureCookie::resolve(null, 'https://example.com'));
        $this->assertNull(SecureCookie::resolve('', 'https://example.com'));
    }

    /** APP_URL が無いときに `auto` を true へ倒さない（http で詰まないほうを選ぶ） */
    public function test_app_urlが無いときのautoはfalse(): void
    {
        $this->assertFalse(SecureCookie::resolve('auto', ''));
        $this->assertFalse(SecureCookie::resolve('auto', null));
    }
}
