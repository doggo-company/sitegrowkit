<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Menus\MenuLinkType;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Support\Industry\IndustrySetup;
use Illuminate\Support\Facades\Route;

/**
 * 既定の導線の**宣言 1 か所**（B-28）。
 *
 * ## なぜ 1 か所なのか
 *
 * 同じ「既定の 5 項目」が **seeder（`MenuSeeder`）と、メニューが空のときの逃げ道**の
 * 2 か所に在ると、片方だけ育つ。順序も表示名もこの表が決める。
 *
 * ## 何を返さないか
 *
 * **URL の形はここが決めない。** route は `route()`、投稿タイプの一覧は
 * `ContentPermalink::index()` — `MenuTree` が使っているのと**同じ resolver**を通す。
 * ここで組み立て直すと、URL の規則が 2 か所になる。
 *
 * ## 在るものだけ — **「経路が在る」ではなく「描けるか」で見る**
 *
 * route が無い生成物ではその項目ごと入れない（利用者の素の生成物には無い route がある）。
 * 投稿タイプの一覧も、その型が在るときだけ。
 *
 * ▷ **route が在っても、開けるとは限らない**（B-46）。同梱ページの `/about` `/service` は
 * **取り込んでいなければ 404 を返す** — `PublicSiteController` が `view()->exists()` で
 * そう決めている。**route だけを見ると、404 のページを既定の導線に載せてしまう。**
 *
 * ▷ **実際に起きた**（2026-08-27・実案件）: 取り込んだ見本のサンプル本文を消して
 * `/about` `/service` を 404 にしたのに（B-40）、**route は「次に戻すときの足場」として
 * 残してある**。残した判断は正しく、**読む側が「route が在る＝ページが在る」と
 * 雑に読んでいた。** だから足場を残すのをやめるのではなく、**読み方のほうを直す。**
 *
 * ▷ 同梱ページには `view` を書く。**書いてある項目は、その view が在るときだけ出す。**
 * `contact.show` のように view の名前で決まらないものは `view` を持たない（route だけで見る）。
 */
final class DefaultNavigation
{
    /**
     * 宣言。**順序と表示名はここが正本**。
     *
     * @return list<array{title: string, type: MenuLinkType, ref: string}>
     */
    public static function declared(): array
    {
        return [
            /*
             * 同梱ページは**その view が在るときだけ**出す（B-46）。
             * **どの view かは `BundledPages` が持つ**（D-1）— ここには書かない。
             */
            ['title' => '私たちについて', 'type' => MenuLinkType::Route, 'ref' => 'about'],
            // 呼び名は業種で変わる（カフェなら「メニュー」）。選ぶ前は「サービス」
            ['title' => app(IndustrySetup::class)->serviceNoun(), 'type' => MenuLinkType::Route, 'ref' => 'service'],
            ['title' => '実績', 'type' => MenuLinkType::ContentIndex, 'ref' => 'works'],
            ['title' => 'ニュース', 'type' => MenuLinkType::ContentIndex, 'ref' => 'news'],
            // `contact.show` は view の名前で決まらない（`ContactEntry` が振り分ける）
            ['title' => 'お問い合わせ', 'type' => MenuLinkType::Route, 'ref' => 'contact.show'],
        ];
    }

    /**
     * `MenuItem` へ入れる形（`MenuSeeder` が使う）。**在るものだけ**。
     *
     * @return list<array<string, mixed>>
     */
    public static function seedItems(): array
    {
        $out = [];

        foreach (self::declared() as $item) {
            if (! self::available($item)) {
                continue;
            }

            $out[] = $item['type'] === MenuLinkType::Route
                ? ['title' => $item['title'], 'link_type' => $item['type']->value, 'link_route' => $item['ref']]
                : ['title' => $item['title'], 'link_type' => $item['type']->value, 'content_type' => $item['ref']];
        }

        return $out;
    }

    /**
     * 描くための形（メニューが空のときの逃げ道が使う）。**在るものだけ**。
     *
     * @return list<array{title: string, url: string}>
     */
    public static function links(): array
    {
        $out = [];

        foreach (self::declared() as $item) {
            if (! self::available($item)) {
                continue;
            }

            /*
             * **描く側は、`MenuTree` が描くときと同じ基準で落とす。**
             *
             * 投稿タイプの一覧は「型が在る」だけでは足りない — **公開されていない型**の
             * 一覧は 404 になる（`MenuTree::indexReason()` も同じ理由で落としている）。
             * seed 側（`seedItems()`）は入れたままでよい: 保存されるのは項目の宣言で、
             * 出すかどうかは描くときに `MenuTree` が決めるからである。
             */
            if (
                $item['type'] === MenuLinkType::ContentIndex
                && ! app(ContentTypeLifecyclePolicy::class)->isPublic($item['ref'])
            ) {
                continue;
            }

            $url = $item['type'] === MenuLinkType::Route
                ? route($item['ref'], [], absolute: false)
                : app(ContentPermalink::class)->index($item['ref'], absolute: false);

            if ($url === null || $url === '') {
                continue;
            }

            $out[] = ['title' => $item['title'], 'url' => $url];
        }

        return $out;
    }

    /**
     * その項目は**本当に開けるか**。
     *
     * **route が在るだけでは足りない**（B-46）。同梱ページは view を消せば 404 になるので、
     * `view` が書いてある項目は**描けるか**まで見る。
     *
     * @param  array{title: string, type: MenuLinkType, ref: string, view?: string}  $item
     */
    private static function available(array $item): bool
    {
        if ($item['type'] !== MenuLinkType::Route) {
            // 定義だけ残った型は MenuTree でも解決できない。保存時の判定と揃える。
            return app(SchemaRegistry::class)->has($item['ref'])
                && app(ContentTypeRegistry::class)->has($item['ref']);
        }

        /*
         * **同梱ページを出してよいかは `BundledPages` が決める**（D-1）。
         *
         * 同じ問いに答える場所が 4 つあった（導線 / 404 / sitemap / メニュー）ので、
         * 1 つに寄せた。**3 つ目のページを足すときは `BundledPages` だけを触る。**
         */
        return app(BundledPages::class)->isAvailable($item['ref']);
    }
}
