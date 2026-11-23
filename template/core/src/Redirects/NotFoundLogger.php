<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 404 を記録する（Phase 9.10 / STAGE 23C・SPEC §F）。
 *
 * ## 1 URL 1 行
 *
 * アクセスごとに行を作ると、bot の探索だけで表が膨れ、
 * **本当に直すべきリンク切れが埋もれる**。同じ path は 1 行にまとめ、
 * `referrer` / `user_agent` / `ip_hash` は最後の 1 件だけ持つ。
 *
 * ## 生の IP を保存しない（OG7）
 *
 * IPv4 は 43 億通りしかないので、塩の無い hash は**事実上そのままの IP** である。
 * `APP_KEY` を混ぜて、総当たりで戻せないようにする。
 * それでも個人情報として扱い、保持期間の対象にする（§F.4）。
 *
 * ## 記録に失敗しても 404 は返す
 *
 * ここは「ついでに残す」処理であって、応答の一部ではない。
 * 表が無い（migration 前）・書けない、いずれの場合も**黙って諦める**。
 * 記録できないことを理由に利用者へ 500 を返さない。
 */
final class NotFoundLogger
{
    /** 行数の上限（OG9）。超えたら少なく踏まれた古いものから捨てる */
    public const DEFAULT_MAX_ROWS = 10_000;

    public function record(Request $request): void
    {
        if (! (bool) config('agentcms.not_found.enabled', true)) {
            return;
        }

        $path = RedirectPath::normalize($request->path());

        // 転送しない path は記録もしない（管理画面の打ち間違いを貯めない）
        if ($path === null || RedirectResolver::isProtected($path)) {
            return;
        }

        try {
            $this->store($path, $request);
        } catch (QueryException $e) {
            // 記録できないことで応答を壊さない。理由はログにだけ残す
            report($e);
        }
    }

    private function store(string $path, Request $request): void
    {
        $hash = hash('sha256', $path);
        $now = now();

        $updated = NotFoundLog::query()->where('path_hash', $hash)->update([
            'last_query' => self::clip($request->getQueryString(), 1024),
            'last_referrer' => self::clip($request->headers->get('referer'), 2048),
            'last_user_agent' => self::clip($request->userAgent(), 255),
            'last_ip_hash' => self::hashIp($request->ip()),
            'hit_count' => DB::raw('hit_count + 1'),
            'last_seen_at' => $now,
        ]);

        if ($updated > 0) {
            return;
        }

        /*
         * 新しい path。**先に上限を確かめる**（入れてから消すと、
         * 入れた行そのものが捨てられることがある）。
         */
        $this->enforceLimit();

        try {
            NotFoundLog::query()->insert([
                'path' => $path,
                'path_hash' => $hash,
                'last_query' => self::clip($request->getQueryString(), 1024),
                'last_referrer' => self::clip($request->headers->get('referer'), 2048),
                'last_user_agent' => self::clip($request->userAgent(), 255),
                'last_ip_hash' => self::hashIp($request->ip()),
                'hit_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } catch (QueryException $e) {
            /*
             * 並走した相手が先に入れた。一意制約に当たっただけなので、
             * 数え直して終わる（**握りつぶさず、やり直す**）。
             */
            $again = NotFoundLog::query()->where('path_hash', $hash)->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_seen_at' => $now,
            ]);

            if ($again === 0) {
                throw $e;
            }
        }
    }

    /**
     * 行数の上限を守る（OG9）。
     *
     * bot は存在しない path を無限に作れる。保持期間だけでは間に合わないので、
     * **少なく踏まれた古いものから**捨てる。よく踏まれている記録は残る。
     */
    private function enforceLimit(): void
    {
        $max = (int) config('agentcms.not_found.max_rows', self::DEFAULT_MAX_ROWS);

        if ($max <= 0) {
            return;
        }

        $count = NotFoundLog::query()->count();

        if ($count < $max) {
            return;
        }

        /*
         * **空ける分だけ捨てる。** まとめて捨てると速いが、
         * その「まとめて」の中に**よく踏まれている記録**が入る。
         * 記録は直すための手がかりなので、多く捨てるほうが損である。
         */
        $ids = NotFoundLog::query()
            // 直したものから先に捨てる。次に、踏まれていない古いもの
            ->orderByRaw('CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('hit_count')
            ->orderBy('last_seen_at')
            ->limit(max(1, $count - $max + 1))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            NotFoundLog::query()->whereIn('id', $ids)->delete();
        }
    }

    /** `APP_KEY` を混ぜる。塩が無いと IPv4 は総当たりで戻せる */
    private static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', (string) config('app.key').'|'.$ip);
    }

    private static function clip(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
