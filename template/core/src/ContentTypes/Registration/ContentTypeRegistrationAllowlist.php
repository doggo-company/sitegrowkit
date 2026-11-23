<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\Permalink\PermalinkStructure;

/**
 * `registration` / `display_overrides` の allowlist（Phase 9.7 / SPEC §F.5.1）。
 *
 * **この 2 つの JSON が第 2 の schema になるのを防ぐのが目的である。**
 * 深い構造を許すと、そこへ field 定義のようなものが入り始める。
 *
 * 読むときと書くときで扱いが違う:
 * - **読む**（`filterRegistration` / `filterDisplay`）… 未知のキーは**落とす**。
 *   落としたキーは呼び出し側へ返して診断に出す。一覧を落とさない
 * - **書く**（`validateRegistration` / `validateDisplay`）… 未知のキーは**エラー**。
 *   送ったのに何も起きない状態を作らない（既存の Application Service と同じ方針）
 */
final class ContentTypeRegistrationAllowlist
{
    /** JSON 全体の上限（SPEC §F.5.1） */
    public const MAX_BYTES = 2048;

    /**
     * `registration` の allowlist（SPEC §D.1 の「登録」列）。
     *
     * @var array<string, 'bool'|'slug'|'permalink'>
     */
    public const REGISTRATION = [
        'public' => 'bool',
        'publicly_queryable' => 'bool',
        'has_archive' => 'bool',
        'exclude_from_search' => 'bool',
        // OG-C = (a): 列と検証だけ持つ。UI と階層 URL は別 Stage
        'hierarchical' => 'bool',
        'api_enabled' => 'bool',
        'api_base' => 'slug',
        // 公開 URL の構造（P1 ③ 段 2）。**選択肢だけ**（自由なパターンは受けない）
        'permalink' => 'permalink',
    ];

    /**
     * `display_overrides` の allowlist（SPEC §F.5.1）。
     *
     * @var array<string, 'string'|'bool'|'icon'>
     */
    public const DISPLAY = [
        'label' => 'string',
        'plural_label' => 'string',
        'description' => 'string',
        'icon' => 'icon',
        'show_in_menu' => 'bool',
    ];

    /**
     * 文字数の上限。**`string` の項目にだけ意味がある**ので、
     * 真偽値やアイコンに架空の上限を持たせない。
     *
     * @var array<string, int>
     */
    public const DISPLAY_MAX = [
        'label' => 60,
        'plural_label' => 60,
        'description' => 200,
    ];

    /**
     * icon は**既知の名前だけ**（SPEC §D.1）。
     *
     * URL や SVG の自由入力を通さない。外部読み込みと XSS の口を作らないためである。
     *
     * @var list<string>
     */
    public const ICONS = [
        'document', 'news', 'image', 'gallery', 'tag', 'calendar', 'folder',
        'star', 'cart', 'question', 'pin', 'chat', 'user', 'chart', 'link',
    ];

    /** 公開スラッグ / `api_base` の文法（SPEC §E.2。公開 route の既存規則に合わせる） */
    public const SLUG_PATTERN = '/\A[a-z0-9-]{1,60}\z/';

    /**
     * 読み取り用。**未知・不正なキーを落として返す**。
     *
     * @param  mixed  $raw  DB の JSON 列（文字列 / 配列 / null）
     * @param  list<string>  $dropped  落としたキー（呼び出し側の配列へ追記する）
     * @return array<string, bool|string>
     */
    public static function filterRegistration(mixed $raw, array &$dropped = []): array
    {
        return self::filter($raw, self::REGISTRATION, $dropped, static fn (string $key, mixed $value): mixed => match (self::REGISTRATION[$key]) {
            'bool' => is_bool($value) ? $value : null,
            'slug' => self::slugOrNull($value),
            // 知らない値は**落とす**（呼ぶ側が既定へ倒し、落としたことを覚える）
            'permalink' => PermalinkStructure::tryFromValue($value)?->value,
        });
    }

    /**
     * 読み取り用。**未知・不正なキーを落として返す**。
     *
     * @param  list<string>  $dropped
     * @return array<string, bool|string>
     */
    public static function filterDisplay(mixed $raw, array &$dropped = []): array
    {
        return self::filter($raw, self::DISPLAY, $dropped, static fn (string $key, mixed $value): mixed => match (self::DISPLAY[$key]) {
            'bool' => is_bool($value) ? $value : null,
            'icon' => is_string($value) && in_array($value, self::ICONS, true) ? $value : null,
            'string' => self::textOrNull($value, self::DISPLAY_MAX[$key] ?? 60),
        });
    }

    /**
     * 書き込み用。**未知のキーも不正な値もエラーにする**。
     *
     * @param  array<string, mixed>  $input
     * @return list<string> エラー（空なら妥当）
     */
    public static function validateRegistration(array $input): array
    {
        return self::validate($input, array_keys(self::REGISTRATION), static function (string $key, mixed $value): ?string {
            return match (self::REGISTRATION[$key]) {
                'bool' => is_bool($value) ? null : "{$key} は true / false で指定してください",
                'slug' => self::slugOrNull($value) === null
                    ? "{$key} は英小文字・数字・ハイフンの 1〜60 文字で指定してください"
                    : null,
                'permalink' => PermalinkStructure::tryFromValue($value) === null
                    ? "{$key} は ".implode(' / ', array_column(PermalinkStructure::cases(), 'value')).' から選んでください'
                    : null,
            };
        });
    }

    /**
     * 書き込み用。**未知のキーも不正な値もエラーにする**。
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function validateDisplay(array $input): array
    {
        return self::validate($input, array_keys(self::DISPLAY), static function (string $key, mixed $value): ?string {
            $max = self::DISPLAY_MAX[$key] ?? 60;

            return match (self::DISPLAY[$key]) {
                'bool' => is_bool($value) ? null : "{$key} は true / false で指定してください",
                'icon' => is_string($value) && in_array($value, self::ICONS, true)
                    ? null
                    : "{$key} は用意されたアイコン名から選んでください",
                'string' => self::textOrNull($value, $max) === null
                    ? "{$key} は 1〜{$max} 文字で入力してください（改行・制御文字は使えません）"
                    : null,
            };
        });
    }

    /** 公開スラッグの検証（SPEC §E.2）。一意性は DB の unique が担う */
    public static function isValidSlug(mixed $value): bool
    {
        return self::slugOrNull($value) !== null;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $dropped
     * @param  callable(string, mixed): mixed  $coerce
     * @return array<string, bool|string>
     */
    private static function filter(mixed $raw, array $rules, array &$dropped, callable $coerce): array
    {
        $decoded = self::decode($raw);

        if ($decoded === null) {
            return [];
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $rules)) {
                $dropped[] = is_string($key) ? $key : '(不正なキー)';

                continue;
            }

            $coerced = $coerce($key, $value);

            if ($coerced === null) {
                $dropped[] = $key;

                continue;
            }

            $result[$key] = $coerced;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     * @param  callable(string, mixed): ?string  $check
     * @return list<string>
     */
    private static function validate(array $input, array $allowed, callable $check): array
    {
        $errors = [];

        $unknown = array_values(array_diff(array_keys($input), $allowed));

        if ($unknown !== []) {
            $errors[] = '受け付けられない項目が含まれています（'.implode('、', $unknown).'）';
        }

        foreach ($input as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            // 深さ 1。入れ子を許すと第 2 の schema になる
            if (is_array($value) || is_object($value)) {
                $errors[] = "{$key} に入れ子は書けません";

                continue;
            }

            $error = $check($key, $value);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        $encoded = json_encode($input, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            $errors[] = '設定が大きすぎます（'.self::MAX_BYTES.' バイトまで）';
        }

        return $errors;
    }

    /**
     * DB の JSON 列を配列にする。**深さ 1 だけ受け取る**（入れ子は読まずに捨てる）。
     *
     * @return array<array-key, mixed>|null
     */
    private static function decode(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            if (strlen($raw) > self::MAX_BYTES) {
                return null;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, depth: 2);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($raw) ? $raw : null;
    }

    /** 表示用の 1 行テキスト。空・長すぎ・制御文字は通さない */
    private static function textOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $max) {
            return null;
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1 ? null : $value;
    }

    private static function slugOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::SLUG_PATTERN, $value) === 1 ? $value : null;
    }
}
