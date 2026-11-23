<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Permalink\ContentPermalink;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * その slug の URL が、Page の描画（fallback）まで届くか（release-20260909 / wxr-pages）。
 *
 * ## `pages.slug` が空いていても、URL が空いているとは限らない
 *
 * 公開サイトの `/about` `/service` `/contact` `/news` `/works` は名前付き route が
 * **先に**受け、PageDocument の描画は `Route::fallback()` にしかいない。
 * 同じ綴りの Page を作ると DB には入るのに、**その URL では既存の画面が出て、
 * 取り込んだ本文が一度も読めない**（レビュー再現 2026-09-09）。
 *
 * 投稿タイプの公開 slug（`/blog` など）も同じで、fallback の中で先に当たる。
 *
 * ## 判定は router に聞く
 *
 * 予約語の一覧をここに書くと、route を足した日にずれる。**実際に GET を解かせて**、
 * 当たった route が fallback でなければ「予約されている」とする。
 */
final class PageSlugReservation
{
    public function __construct(
        private readonly Router $router,
        private readonly ContentPermalink $permalinks,
    ) {}

    public function reserved(string $slug): bool
    {
        if ($this->permalinks->typeForBase($slug) !== null) {
            return true;
        }

        try {
            $route = $this->router->getRoutes()->match(Request::create('/'.$slug, 'GET'));
        } catch (NotFoundHttpException) {
            // fallback すら無い環境。Page の URL としてぶつかる相手もいない
            return false;
        } catch (MethodNotAllowedHttpException) {
            // 別の method で同じ URL を持つ route がある（POST /contact など）。譲る
            return true;
        }

        return ! $route->isFallback;
    }
}
