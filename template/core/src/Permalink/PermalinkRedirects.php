<?php

declare(strict_types=1);

namespace AgentCms\Core\Permalink;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Redirects\RedirectMatchType;
use AgentCms\Core\Redirects\RedirectPath;
use AgentCms\Core\Redirects\RedirectRule;

/**
 * URL の入口・構造を変えたときに、**旧 URL を生かし続ける**（P1 ③ 段 3）。
 *
 * ## OG10 の適用範囲について
 *
 * 9.10 の OG10 は「**記事の slug を変えたとき**の自動ルールは作らない」と決めた。
 * **編集のたびに変わり得るものへ自動生成を付けると、ルールが際限なく増える**からである。
 *
 * こちらは**サイト全体の操作**であって、別物である:
 *
 * | | 記事の slug 変更（OG10） | 入口・構造の変更（ここ） |
 * | --- | --- | --- |
 * | 頻度 | 編集のたびに起き得る | 移行のときだけ |
 * | 作るルール | **記事ごとに 1 本** | **サイトに 1 本**（接頭辞） |
 * | 増え方 | 記事数に比例 | 変更回数に比例 |
 *
 * だから OG10 を**上書きしているのではなく、適用範囲の外**である。
 * 記事の slug 変更は、いまも自動生成しない。
 *
 * ## 壊れる URL が無ければ、何も作らない
 *
 * まだ 1 件も公開していない型では、**死ぬ URL が存在しない**。
 * その場合は転送も作らず、書けない組み合わせでも変更を通す
 * （**公開前に構造を決められない CMS には意味が無い**）。
 */
final class PermalinkRedirects
{
    public function __construct(private readonly ContentTypeResolver $types) {}

    /**
     * その変更を通してよいか。**通せないなら理由を返す**（通せるなら null）。
     *
     * 断る文には**何が起きるか**を書く。「できません」だけでは、
     * 利用者は「では何ならできるのか」を推測するしかない。
     */
    public function refusalFor(string $type, PermalinkChange $change): ?string
    {
        if (! $change->isMove() || $change->canRewrite()) {
            return null;
        }

        if ($this->publishedCount($type) === 0) {
            // 壊れる URL が無い。**公開前なら構造は自由に決められる**
            return null;
        }

        return 'この変更は、いま公開している URL を 1 本の転送では引き継げません'
            .'（入口より後ろの形が変わるため）。'
            .'公開済みの記事の URL がすべて 404 になるので、変更を受け付けませんでした。';
    }

    /**
     * 転送ルールを 1 本置く（**置けないときは何もしない**）。
     *
     * **公開件数は見ない。** 記事が 1 件も無くても、**一覧の URL（`/blog`）は生きていた**。
     * 「断るかどうか」と「転送を置くかどうか」は別の問いなので、条件を混ぜない。
     *
     * 同じ入口のルールが既に在れば**書き換える**。
     * `/blog` → `/news` のあとに `/news` → `/journal` と続けば、
     * 転送は連鎖として解決される（`RedirectResolver::MAX_HOPS`）。
     *
     * @return bool 置いたか
     */
    public function record(string $type, PermalinkChange $change): bool
    {
        $source = $change->source();
        $target = $change->target();

        if ($source === null || $target === null) {
            return false;
        }

        $normalized = RedirectPath::normalize($source);

        if ($normalized === null) {
            return false;
        }

        $hash = RedirectPath::hash(RedirectMatchType::Prefix, $normalized);

        /*
         * **新しい URL から出ていた自動の転送は消す**（UX 2 周目 2026-11-23）。
         *
         * 業種を選び直して `/news-2` → `/works-2` → `/news-2` と戻すと、`/news-2` → `/works-2` の
         * 古いルールが残り、`/works-2` → `/news-2` と輪になる。`RedirectResolver` は輪を断るので、
         * 古い `/works-2` が 404 になっていた。固定ページの URL 変更（`UpdatePageMetadata`）と同じ作法。
         * 手で作った転送には触らない。
         */
        $newPath = $target === '/' ? null : RedirectPath::normalize($target);

        if ($newPath !== null) {
            RedirectRule::query()
                ->where('created_reason', RedirectRule::REASON_SLUG_CHANGE)
                ->where('source_hash', RedirectPath::hash(RedirectMatchType::Prefix, $newPath))
                ->delete();
        }

        /*
         * **`source_hash` は fillable ではない**（外から渡された値を鍵にしない作法）。
         * こちらで作った値なので `forceFill` で入れる。
         */
        $rule = RedirectRule::query()->where('source_hash', $hash)->first() ?? new RedirectRule;

        $rule->forceFill([
            'match_type' => RedirectMatchType::Prefix->value,
            'source_path' => $normalized,
            'source_hash' => $hash,
            'target_url' => $target,
            'status_code' => 301,
            'is_enabled' => true,
            'keep_query' => true,
            // **手で作ったものと混ぜない**（9.10 が値だけ予約していた枠）
            'created_reason' => RedirectRule::REASON_SLUG_CHANGE,
            'note' => "投稿タイプ [{$type}] の URL 構造を変えたときに自動で作成",
        ]);

        $rule->save();

        return true;
    }

    /**
     * 公開されている件数。**0 なら死ぬ URL が無い。**
     *
     * 型を読めないときは 0 と答えない — **読めないことを「無い」に丸めると、
     * 生きている URL を黙って壊す**。安全側（在る）に倒す。
     */
    private function publishedCount(string $type): int
    {
        if (! $this->types->knows($type)) {
            return 1;
        }

        try {
            return $this->types->newModel($type)->newQuery()->scopes('published')->count();
        } catch (\Throwable) {
            return 1;
        }
    }
}
