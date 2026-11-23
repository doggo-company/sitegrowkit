<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 「記事を書けるようにする」1 回分の設定（WP 一周 2026-09-03 / 効くもの ①）。
 *
 * ## なぜ要るのか
 *
 * WordPress から来た人の一手目は「投稿 → 新規追加」である。
 * いまは 設計 > 投稿タイプ > 設定 に入って、サイドバー・公開スラッグ・公開する・
 * 個別ページの URL・一覧ページの URL の **5 か所**を自分で組み立てないと記事が書けない。
 * 5 か所の意味を先に理解させるのではなく、**まず書ける状態にして、後から絞れる**ようにする。
 *
 * ## B-39 は取り消していない
 *
 * 中身が空の投稿メニューを既定で並べない（オーナー決定 2026-08-24）はそのままである。
 * ここが変えるのは**既定ではなく、既定から抜け出すまでの手数**だけ。
 * 押すかどうかは利用者が決めるので、「空のメニューが勝手に並ぶ」は起きない。
 *
 * ## 足すのであって、消さない
 *
 * `display_overrides` / `registration` は JSON 丸ごとの置き換えである
 * （`ContentTypeSettingsWriter`）。素朴に `show_in_menu` だけを送ると
 * **表示名やアイコンが消える**。だから現在の設定に重ねる。
 * 既に決めてある公開スラッグも上書きしない — 利用者が決めた URL を、こちらの都合で変えない。
 *
 * ## 判定を増やさない
 *
 * ここは**払い出すだけ**で、書き込みも検証も `UpdateContentType` が行う。
 * allowlist・lock version・監査の経路を二重に持たないためである。
 */
final class PublishingQuickStart
{
    /** 公開スラッグが未設定のときの既定。WordPress の既定と同じ語にする */
    public const DEFAULT_SLUG = 'blog';

    /**
     * `UpdateContentType` にそのまま渡せる入力を作る。
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(ContentTypeSettings $current): array
    {
        return [
            'id' => $current->contentTypeId,
            'lock_version' => $current->lockVersion,
            'display_overrides' => [...$current->displayOverrides, 'show_in_menu' => true],
            'registration' => [
                ...$current->registration,
                // 公開サイトに出す / 個別ページの URL を持つ / 一覧ページの URL を持つ
                'public' => true,
                'publicly_queryable' => true,
                'has_archive' => true,
            ],
            'public_slug' => self::slugFor($current),
        ];
    }

    /**
     * 1 クリックを出すべき投稿タイプ（無ければ null）。
     *
     * **判定はここ 1 か所**である。ダッシュボードとサイドバーで別々に数えると、
     * 片方だけ出る・片方だけ消える、が起きる。
     *
     * 出すのは **書ける入れ物が 1 つも無いとき**だけ。
     * 既に何か書ける型が並んでいるなら、これは邪魔なだけである。
     */
    public static function candidateFor(
        SchemaRegistry $schemas,
        ContentTypeResolver $types,
        ContentTypeLifecyclePolicy $lifecycle,
    ): ?string {
        $hidden = null;

        foreach ($types->availableTypes() as $id) {
            $schema = $schemas->get($id);

            if (! EditorialCollections::isEditorial($schema)) {
                continue;
            }

            // 1 つでも書ける入れ物が出ているなら、出口は要らない
            if ($lifecycle->showInMenu($id)) {
                return null;
            }

            if ($hidden === null && EditorialCollections::isManageable($schema)) {
                $hidden = $id;
            }
        }

        return $hidden;
    }

    /** 決めてあるものは尊重する。空のときだけ既定を入れる */
    private static function slugFor(ContentTypeSettings $current): string
    {
        $slug = $current->publicSlug;

        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        return self::DEFAULT_SLUG;
    }
}
