<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Taxonomy\TermSlug;
use AgentCms\Core\Wxr\Status\WxrStatus;
use AgentCms\Core\Wxr\WxrItem;
use Illuminate\Support\Str;

/**
 * WXR のどの値が、こちらのどの欄へ行くか（Phase 9.14 / STAGE 27D-3b・SPEC §C.1・§F）。
 *
 * ## 対応表を 2 か所に持たない
 *
 * `plan` は「必須の欄が埋まるか」をここから読み、`apply` は「実際に何を渡すか」を
 * ここから読む。**片方だけ直すと、plan が言ったのと違うものが入る**。
 * しかも plan hash はそれを見つけられない — hash は plan 側の判断から作るので、
 * 両方が同じ誤りをすれば一致してしまう（`ItemTarget` と同じ理由）。
 *
 * ## 空を落とさない
 *
 * 受け皿がある欄を「空だから」と外すと、その欄が必須のときに
 * **plan では見えない検証エラー**になる。空でも渡し、
 * 空を入れてよいかは型の側の規則に決めさせる。
 *
 * ## §F の補いはここで効く
 *
 * 空の題名は `(無題)`、空の slug は id から作る。
 * だから**この 2 つは WXR が空でも埋まる**（`plan` はそれを知っていなければ、
 * 埋まる欄を「空だ」と数えて止めてしまう）。
 */
final class ItemFields
{
    /** 空の題名の代わり（§F） */
    public const UNTITLED = '(無題)';

    /**
     * こちらの欄 => WXR から取った値。
     *
     * **型の schema は見ない。** 受け皿があるかどうかは呼ぶ側が決める
     * （`plan` は必須の欄と突き合わせ、`apply` は宣言のある欄だけ渡す）。
     *
     * @return array<string, string|null>
     */
    public static function of(WxrItem $item): array
    {
        return [
            // §F — 空の題名は `(無題)`。空のまま入れると一覧が空行の並びになる
            'title' => $item->title === '' ? self::UNTITLED : $item->title,
            'status' => WxrStatus::map($item->status)->value,
            // §F の拡張 — 空 / 使えない slug は作り直す。空だと URL を作れない
            'slug' => self::slugFor($item),
            'body' => $item->content,
            'excerpt' => $item->excerpt,
            'published_at' => $item->publishedAt,
        ];
    }

    /**
     * WXR の slug（空も含む）を、こちらで保存できる値へ倒す（§F の拡張）。
     *
     * ## 何が起きていたか（2026-09-05 実測・沼津の書き出し）
     *
     * WordPress は Japanese/UTF8 slug のプラグインが無いサイトでは、日本語の見出しを
     * `%XX` へパーセントエンコードして `wp:post_name` へ書き出す（既定の振る舞い）。
     * 1 文字が最大 9 文字に膨らむうえ、`%` は slug の形式（半角英数字とハイフンだけ）に
     * そもそも合わない。実物（沼津の書き出し・87記事）は**全件これ**で、削っても
     * `%` が残るかぎり検証は通らない。だから「削る」ではなく、**使えないなら作り直す**。
     *
     * ## term 側と同じ判定を使う
     *
     * 「半角英数字とハイフンだけ・120文字まで」は分類項目の綴りと同じ契約である
     * （`TermGraph::fold()` が `TermSlug::isValid()` で同じ判定をしている。全 content type の
     * schema も slug 欄は `max: 120` で揃えている）。ここで別の正規表現を持つと、
     * **どちらかだけ直して食い違う**。
     *
     * ## 読める形から先に試し、最後は post_id
     *
     * 1. WXR の post_name がそのまま使えるならそれを使う（英字の綴りを持つ記事はそのまま）
     * 2. 使えないなら見出しから作り直す（`Str::slug()` — 英数字混じりの見出しなら残る）
     * 3. それも使えない（空・日本語だけ・長すぎる）なら `wp:post_id`
     *
     * `wp:post_id` は WXR 内で重複しないことを `WxrReader` が読み込み時点で保証している（同じ
     * id が 2 回出てきたらそこで止める）。だから 3. に倒れても、**複数の記事が同じ
     * slug になることはない**。同じ WXR なら毎回同じ id なので、取り込みをやり直しても
     * 綴りは変わらない。
     */
    private static function slugFor(WxrItem $item): string
    {
        if ($item->slug !== '' && TermSlug::isValid($item->slug)) {
            return $item->slug;
        }

        $fromTitle = Str::slug($item->title);

        if ($fromTitle !== '' && TermSlug::isValid($fromTitle)) {
            return $fromTitle;
        }

        return $item->postId;
    }

    /**
     * その欄が、WXR の値で**埋まる**か。
     *
     * `plan` が必須の欄と突き合わせるときに使う。
     * §F の補いが効く欄は、WXR が空でも埋まる。
     */
    public static function fills(WxrItem $item, string $field): bool
    {
        $values = self::of($item);

        if (! array_key_exists($field, $values)) {
            // 対応する値がそもそも無い欄（型の側にしかない欄）
            return false;
        }

        return $values[$field] !== null && $values[$field] !== '';
    }

    /**
     * WXR に対応のある欄の名前。
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return ['title', 'status', 'slug', 'body', 'excerpt', 'published_at'];
    }
}
