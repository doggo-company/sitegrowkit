<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

/**
 * パスの正規化（Phase 9.10 / STAGE 23B・SPEC §B.3）。
 *
 * **保存時と照合時で必ずここを通す。** 片方だけ規則を変えると
 * 「登録したのに効かない」という、いちばん質の悪い壊れ方をする。
 * だから正規化は 1 か所にしか書かない。
 *
 * 決めていることと、その理由:
 *
 * - 末尾の `/` は落とす（ルートを除く）… 同じページを 2 通りで登録させない
 * - query と fragment は落とす … query の扱いは `keep_query` が決める（SPEC §B.5）
 * - percent-decode は **1 回だけ** … 二重デコードは `%252e%252e` を通す穴になる
 * - 大小文字は畳まない … URL は大小を区別する。畳むと別ページへ飛ばしてしまう
 * - `.` / `..` を含むパスは**拒否する** … 正規化で解決せず、入力として断る
 */
final class RedirectPath
{
    /** 保存する上限（列は 2048。超えるものは扱わない） */
    public const MAX_LENGTH = 2048;

    /**
     * 正規化する。扱えない形なら null。
     *
     * null が返るのは「入力として断る」ときで、**空文字を返して通さない**。
     */
    public static function normalize(string $raw): ?string
    {
        $path = trim($raw);

        if ($path === '') {
            return null;
        }

        // 絶対 URL で来ても path だけを見る（ホストは web サーバーの仕事）
        if (preg_match('#^https?://#i', $path) === 1) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '/';
        }

        // query と fragment を落とす
        $path = (string) preg_replace('/[?#].*$/', '', $path);

        // **1 回だけ** decode する
        $path = rawurldecode($path);

        // 制御文字が混ざっているものは扱わない（ヘッダー分割の材料にしない）
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        $path = '/'.ltrim($path, '/');
        $path = (string) preg_replace('#/{2,}#', '/', $path);

        // `.` / `..` は解決せずに断る
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if ($path === '') {
            $path = '/';
        }

        return mb_strlen($path) > self::MAX_LENGTH ? null : $path;
    }

    /**
     * 照合の鍵（SPEC §B.2）。
     *
     * **照合方法まで含める。** `exact:/a` と `prefix:/a` は別のルールとして共存してよい。
     */
    public static function hash(RedirectMatchType $type, string $normalizedPath): string
    {
        return hash('sha256', $type->value.'|'.$normalizedPath);
    }

    /**
     * 転送先を組み立てる。
     *
     * - `prefix` のときは、一致した先の残りを継ぎ足す
     * - `keepQuery` なら元の query を付け直す。**転送先が既に持つキーを上書きしない**
     *   （転送先の指定のほうが新しい意図である）
     */
    public static function buildTarget(
        string $target,
        string $requestPath,
        RedirectMatchType $type,
        string $sourcePath,
        bool $keepQuery,
        string $query = '',
    ): string {
        $url = $target;

        if ($type === RedirectMatchType::Prefix) {
            $rest = mb_substr($requestPath, mb_strlen($sourcePath));

            if ($rest !== '') {
                $url = rtrim($url, '/').'/'.ltrim($rest, '/');
            }
        }

        if (! $keepQuery || $query === '') {
            return $url;
        }

        [$base, $existing] = array_pad(explode('?', $url, 2), 2, '');

        parse_str($query, $incoming);
        parse_str($existing, $current);

        // 転送先が持っているキーが勝つ
        $merged = [...$incoming, ...$current];

        $rebuilt = http_build_query($merged);

        return $rebuilt === '' ? $base : $base.'?'.$rebuilt;
    }
}
