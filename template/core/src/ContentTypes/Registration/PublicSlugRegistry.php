<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\Pages\Page;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 公開スラッグが空いているか（Phase 9.7 / STAGE 20C・SPEC §E.2 / §J.6）。
 *
 * **1 か所で全部と突き合わせる。** 公開 URL の取り合いは 3 方向から来る。
 *
 * | 相手 | 何が起きるか |
 * | --- | --- |
 * | 別の投稿タイプ | 同じ URL を 2 つの型が名乗る |
 * | ページ | ページが開けなくなる（fallback より先に投稿タイプが受ける） |
 * | 既存の route | `/login` や `/admin` を奪う。**認証画面が開けなくなる** |
 *
 * 予約語を画面や service に散らかさない。**ここが唯一の判定である。**
 */
final class PublicSlugRegistry
{
    public function __construct(
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly Registrar $router,
    ) {}

    /**
     * その slug を使えるか。**使えないなら日本語で理由を返す**（使えるなら null）。
     *
     * @param  string  $selfContentTypeId  自分自身。**自分が使っている slug を自己衝突にしない**
     */
    public function conflictFor(string $slug, string $selfContentTypeId): ?string
    {
        foreach ($this->resolver->stored() as $id => $settings) {
            if ($id !== $selfContentTypeId && $settings->publicSlug === $slug) {
                return "投稿タイプ [{$id}] が同じ公開スラッグを使っています";
            }
        }

        if ($this->takenByPage($slug)) {
            return '同じスラッグのページがあります（下書きやゴミ箱のページも含みます）';
        }

        if (in_array($slug, $this->reservedRouteSegments(), true)) {
            return 'この URL は管理画面や公開サイトの機能が使っています';
        }

        return null;
    }

    /**
     * ページが使っているか。
     *
     * **状態を問わない。** 下書きでもゴミ箱でも、あとで公開・復元されたときに
     * 同じ URL を取り合うことになる。取り合ってから気づくより、先に断るほうがよい。
     *
     * 表がまだ無い / 読めないときは **使われている扱い**にする（fail-closed）。
     * 「確かめられなかったので通した」を作らない。
     */
    private function takenByPage(string $slug): bool
    {
        try {
            if (! Schema::hasTable('pages')) {
                return true;
            }

            return Page::query()->where('slug', $slug)->exists();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * 既に登録されている route の**先頭セグメント**。
     *
     * route collection をそのまま読むので、`login` / `admin` / `api` / `up` のような
     * 語をここへ書き写さない（書き写すと、route が増えたときに必ず古くなる）。
     *
     * **可変セグメントは無視する。** `{type}` や fallback は特定の URL を占有していない。
     * 汎用の公開 route も fallback なので、**自分自身を予約語として数えてしまうことはない**。
     *
     * @return list<string>
     */
    private function reservedRouteSegments(): array
    {
        $segments = [];

        if (! $this->router instanceof Router) {
            return [];
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $uri = trim((string) $route->uri(), '/');

            if ($uri === '' || $uri === '/') {
                continue;
            }

            $first = explode('/', $uri)[0];

            // `{slug}` のような可変部は誰の URL でもない
            if ($first === '' || str_contains($first, '{')) {
                continue;
            }

            $segments[$first] = true;
        }

        return array_keys($segments);
    }
}
