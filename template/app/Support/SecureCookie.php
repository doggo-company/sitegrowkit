<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `SESSION_SECURE_COOKIE` を、書いた人の意図どおりに読む。
 *
 * ## なぜ要るか（WP 一周 7 周目 2026-09-04）
 *
 * 生成される `.env` には `SESSION_SECURE_COOKIE=auto` が入っている。
 * Laravel はこれをただの文字列として受け取るので、**`"auto"` は truthy** —
 * つまり `http` のサイトなのに `secure` 付きの cookie を送ってしまう。
 *
 * ブラウザは http で secure cookie を保存しない。結果として:
 *
 * 1. ログインは通る（サーバー側の session には書けている）
 * 2. その応答で出た画面は動く
 * 3. **URL を直接開くと login に戻される**（毎回まっさらな session になる）
 *
 * 生成直後のサイトは `http://localhost:8180` なので、**買った人は最初にこれを踏む**。
 *
 * ## `auto` の読み方
 *
 * 書いた人の意図は「HTTPS のときだけ secure」である。判断は `APP_URL` の scheme で行う。
 * リクエストを見て決めないのは、**cookie の属性が経路によってぶれると
 * session が分裂する**（リバースプロキシ配下で https と http が混ざる形）ため。
 * サイトの住所は 1 つなので、そこで決める。
 */
final class SecureCookie
{
    /**
     * @param  mixed  $configured  `SESSION_SECURE_COOKIE` の生の値
     * @param  mixed  $appUrl  `APP_URL`（`env()` は bool も返しうる）
     * @return bool|null 未設定なら null（Laravel の既定＝リクエストに従う）に倒す
     */
    public static function resolve(mixed $configured, mixed $appUrl): ?bool
    {
        if ($configured === null || $configured === '') {
            // 🔴 ここを false に倒してはいけない。HTTPS の本番で secure が外れる
            return null;
        }

        if (is_string($configured) && strtolower(trim($configured)) === 'auto') {
            return is_string($appUrl) && str_starts_with(strtolower($appUrl), 'https://');
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }
}
