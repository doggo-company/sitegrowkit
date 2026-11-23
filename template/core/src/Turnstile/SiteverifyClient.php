<?php

declare(strict_types=1);

namespace AgentCms\Core\Turnstile;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile Siteverify API クライアント。
 * ブラウザ側 Widget だけでは検証にならない — サーバー側検証が必須（仕様 CONTACT FORM）。
 */
final class SiteverifyClient implements TurnstileVerifier
{
    public function verify(string $token, ?string $ip = null): bool
    {
        $secret = (string) config('agentcms.turnstile.secret_key');
        if ($secret === '') {
            Log::error('Turnstile secret key is not configured (TURNSTILE_SECRET_KEY)');

            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->retry(2, 250)
                ->post((string) config('agentcms.turnstile.siteverify_url'), array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $e) {
            Log::warning('Turnstile siteverify request failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Turnstile siteverify returned non-2xx', ['status' => $response->status()]);

            return false;
        }

        return (bool) $response->json('success', false);
    }
}
