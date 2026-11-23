<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 404 になるときだけ転送を考える（Phase 9.10 / STAGE 23C・SPEC §C・OG1）。
 *
 * ## なぜ前段の middleware ではないのか
 *
 * 全リクエストの前に置くと、**普通のページ表示に DB を 1 回足す**。
 * 配布先は共有ホスティングで、そこは軽くない費用である。
 * 一方、転送したい URL はほぼ必ず「もう無い URL」= 404 になるので、
 * 404 が確定してから引けば通常のアクセスへの費用はゼロになる。
 *
 * 制約は正直に言う: **今も表示できる URL は転送できない。**
 * 転送したいなら、先にゴミ箱へ入れるか非公開にする。
 *
 * ## 転送しないなら記録する
 *
 * どちらも 404 の 1 か所で決める。面ごとに書かない
 * （9.8 の `TrashReadinessUnavailable` と同じ作法）。
 */
final class NotFoundHandler
{
    public function __construct(
        private readonly RedirectResolver $resolver,
        private readonly NotFoundLogger $logger,
    ) {}

    /**
     * 転送するなら応答を返す。しないなら null（= これまでどおり 404）。
     */
    public function handle(Request $request): ?RedirectResponse
    {
        // GET / HEAD 以外は転送しない。POST を 301 で転送すると method が変わる
        if (! RedirectResolver::isAllowedMethod($request->getMethod())) {
            return null;
        }

        // API 利用者に HTML の転送を返さない
        if ($request->expectsJson()) {
            return null;
        }

        $path = RedirectPath::normalize($request->path());

        /*
         * **DB を引く前に、対象外を落とす。**
         *
         * `/admin` の 404（未認可・打ち間違い）は 1 日に何度も起きる。
         * そこで表の有無を確かめるだけでも問い合わせが 1 本増える。
         * 転送も記録もしない path なら、**DB へ行かずに帰る**。
         */
        if ($path === null || RedirectResolver::isProtected($path)) {
            return null;
        }

        /*
         * **表が無いなら何もしない。** migration より前でも、この経路が
         * 500 を出してはいけない。404 は 404 のまま返す。
         */
        if (! Schema::hasTable('redirect_rules')) {
            return null;
        }

        $decision = $this->resolver->resolve($path, (string) $request->getQueryString());

        if ($decision === null) {
            $this->logger->record($request);

            return null;
        }

        $this->countHit($decision->rule);

        return new RedirectResponse($decision->target, $decision->status()->value);
    }

    /**
     * ヒット数を数える（OG6）。
     *
     * **数えられなくても転送は成功させる。** 記録の失敗で利用者を 404 に落とさない。
     */
    private function countHit(RedirectRule $rule): void
    {
        try {
            RedirectRule::query()->whereKey($rule->getKey())->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (QueryException $e) {
            report($e);
        }
    }
}
