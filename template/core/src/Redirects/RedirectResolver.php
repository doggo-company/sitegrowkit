<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

use Illuminate\Support\Collection;

/**
 * どこへ転送するかを決める（Phase 9.10 / STAGE 23B・SPEC §D）。
 *
 * ## 転送しない path は、ここで決めて 1 か所に置く
 *
 * `/admin` や `/api` を転送してしまう事故は致命的で、しかも
 * 「転送されたせいで管理画面に入れない」という**直す手段ごと失う**形になる。
 * だから設定で変えられるようにしない（OG12）。定数として持つ。
 *
 * ## 輪は 3 段構えで防ぐ
 *
 * 保存時に自己転送と輪を拒む（`RedirectLoopGuard`）。それでも後から別のルールを
 * 足せば輪はできるので、**要求時にも上限を持つ**。上限を超えたら転送せず、
 * 404 のまま返す — ブラウザに「リダイレクトが多すぎます」を出させない。
 *
 * ## 引き方
 *
 * `exact` を hash で 1 回引く。当たらなければ `prefix` を**長い順**に見る
 * （`/old/sub` が `/old` より先に当たるように）。
 */
final class RedirectResolver
{
    /** 連鎖を解決する段数の上限（OG5） */
    public const MAX_HOPS = 3;

    /**
     * **転送してはいけない接頭辞**（OG12）。
     *
     * 設定で変えられるようにしない。変えられると「管理画面を転送してしまった」を
     * 作れてしまい、それを直す入口も同時に失う。
     */
    public const PROTECTED_PREFIXES = [
        '/admin',
        '/api',
        '/login',
        '/register',
        '/logout',
        '/healthz',
        '/up',
        '/build',
        '/storage',
    ];

    /** 転送してよい method（SPEC §C.4） */
    public const ALLOWED_METHODS = ['GET', 'HEAD'];

    /**
     * 転送先を決める。転送しないなら null。
     *
     * @param  string  $query  元の query（`keep_query` のときだけ使う）
     */
    public function resolve(string $requestPath, string $query = ''): ?RedirectDecision
    {
        $path = RedirectPath::normalize($requestPath);

        if ($path === null || self::isProtected($path)) {
            return null;
        }

        $rules = $this->enabledRules();

        $visited = [];
        $current = $path;
        $hops = 0;
        $last = null;

        while ($hops < self::MAX_HOPS) {
            $rule = self::match($rules, $current);

            if ($rule === null) {
                break;
            }

            $target = RedirectPath::buildTarget(
                $rule->target_url,
                $current,
                $rule->matchType(),
                $rule->source_path,
                $rule->keep_query,
                $hops === 0 ? $query : '',
            );

            $hops++;
            $last = new RedirectDecision($rule, $target, $hops);

            $next = RedirectPath::normalize($target);

            // 外部 URL、または辿れない形になったらそこで終わり
            if ($next === null || preg_match('#^https?://#i', $target) === 1) {
                break;
            }

            /*
             * **同じところへ戻ってきた。** 保存時に防いでいても、
             * 後から足したルールで輪はできる。ここで止めて 404 のまま返す。
             */
            if (isset($visited[$next]) || $next === $current) {
                return null;
            }

            $visited[$current] = true;
            $current = $next;
        }

        if ($last === null) {
            return null;
        }

        /*
         * 上限まで辿ってもまだ次があるなら、**転送しない**。
         * 途中まで送ると、利用者は知らない中継地点へ落ちる。
         */
        if ($hops >= self::MAX_HOPS && self::match($rules, $current) !== null) {
            return null;
        }

        // 自分自身へ送らない（正規化して比べる）
        return RedirectPath::normalize($last->target) === $path
            && preg_match('#^https?://#i', $last->target) !== 1
            ? null
            : $last;
    }

    /** 転送してはいけない path か（前方一致。境界は `/` かちょうど一致） */
    public static function isProtected(string $normalizedPath): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public static function isAllowedMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::ALLOWED_METHODS, true);
    }

    /**
     * 有効なルールを 1 回だけ引く。
     *
     * 連鎖を辿るあいだ何度も DB へ行かないよう、最初にまとめて取る。
     *
     * @return Collection<int, RedirectRule>
     */
    private function enabledRules(): Collection
    {
        /*
         * 並べ替えは **PHP でやる**。`CHAR_LENGTH` は MySQL の関数で、
         * sqlite では `LENGTH` になる。DB の方言をここへ持ち込まない。
         *
         * 完全一致が先。次に前方一致を**長い順**（`/old/sub` が `/old` より先に当たる）。
         */
        return RedirectRule::query()
            ->enabled()
            ->get()
            ->sortBy([
                static fn (RedirectRule $rule): int => $rule->matchType() === RedirectMatchType::Exact ? 0 : 1,
                static fn (RedirectRule $rule): int => -mb_strlen($rule->source_path),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, RedirectRule>  $rules
     */
    private static function match(Collection $rules, string $path): ?RedirectRule
    {
        foreach ($rules as $rule) {
            if ($rule->matchType() === RedirectMatchType::Exact) {
                if ($rule->source_path === $path) {
                    return $rule;
                }

                continue;
            }

            // 前方一致は **境界で切る**（`/old` が `/older` に当たらないように）
            if ($path === $rule->source_path || str_starts_with($path, rtrim($rule->source_path, '/').'/')) {
                return $rule;
            }
        }

        return null;
    }
}
