<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Taxonomy\AssignedTerms;
use Illuminate\Contracts\Routing\Registrar;

/**
 * 記事に付いている分類を、**押せる先つき**で読む（R05・2026-09-09）。
 *
 * ## なぜ view で URL を組み立てないか
 *
 * 汎用の詳細（`public/content-type/show.blade.php`）は `/{入口}/{分類}/{綴り}` を
 * view の中で直に組んでいた。同じことを `news` / `works` の view にも書くと、
 * **URL の形を知っている場所が 3 つ**になる。しかも `news` / `works` は
 * 名前付き route の世界に居るので、fallback の形をそのまま書くと 404 を配る。
 *
 * ここが答えるのは「**この分類の一覧は、いま開けるか。開けるならどこか**」だけである。
 * 開けないなら `null` を返し、view は文字のまま出す（無理にリンクしない）。
 *
 * ## 開けると言える条件
 *
 * 1. その型が `has_archive`（一覧の URL を持つ）。持たない型は分類の一覧も持たない
 *    （`PublicContentController::term()` と同じ規則）
 * 2. module の route（`{型}.term`）が在れば、それが受ける
 * 3. 無ければ fallback の世界。`ContentTypeLifecyclePolicy::isPublic()` が true で、
 *    `ContentPermalink::index()` が入口を返すときだけ `/{入口}/{分類}/{綴り}`
 *
 * 分類そのものの生死（無効・アーカイブ）は `AssignedTerms` が先に落としている。
 */
final class TermArchiveLinks
{
    public function __construct(
        private readonly AssignedTerms $assigned,
        private readonly ContentTypeSettingsResolver $settings,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly ContentPermalink $permalinks,
        private readonly Registrar $router,
    ) {}

    /**
     * `AssignedTerms::forEntry()` の各項目に `url`（開けなければ null）を足したもの。
     *
     * @return list<array{key: string, label: string, terms: list<array{name: string, slug: string, url: string|null}>}>
     */
    public function forEntry(string $contentType, int $contentId): array
    {
        $out = [];

        foreach ($this->assigned->forEntry($contentType, $contentId) as $taxonomy) {
            $terms = [];

            foreach ($taxonomy['terms'] as $term) {
                $terms[] = $term + ['url' => $this->url($contentType, $taxonomy['key'], $term['slug'])];
            }

            $out[] = ['key' => $taxonomy['key'], 'label' => $taxonomy['label'], 'terms' => $terms];
        }

        return $out;
    }

    /** 分類の一覧の URL（相対）。**開けなければ null** */
    public function url(string $contentType, string $taxonomyKey, string $termSlug): ?string
    {
        if ($taxonomyKey === '' || $termSlug === '') {
            return null;
        }

        // 一覧の URL を持たない型は、分類の一覧も持たない（戻る先が無い）
        if (! $this->settings->for($contentType)->flag('has_archive', true)) {
            return null;
        }

        // module が route を持っていれば、そちらが受ける（`news` / `works`）
        if ($this->router->has("{$contentType}.term")) {
            return route("{$contentType}.term", ['taxonomy' => $taxonomyKey, 'term' => $termSlug], absolute: false);
        }

        // fallback の世界。公開されていない型の一覧は 404 なのでリンクにしない
        if (! $this->lifecycle->isPublic($contentType)) {
            return null;
        }

        $index = $this->permalinks->index($contentType, absolute: false);

        if ($index === null || $index === '') {
            return null;
        }

        return rtrim($index, '/').'/'.rawurlencode($taxonomyKey).'/'.rawurlencode($termSlug);
    }
}
