<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

/**
 * `<channel>` 直下にある分類項目の**定義**（Phase 9.14 / STAGE 27B・SPEC §C.3）。
 *
 * ## 割り当てとは別物である
 *
 * item の中の `<category domain nicename>` は「この記事はこの項目に属する」という
 * **割り当て**で、その項目の親が誰かは書かれていない。
 * 親子は channel 直下の `<wp:category>` / `<wp:term>` にしかない。
 *
 * ここを読まないと「親から先に作る」（§C.3）が成立せず、
 * **入れ子だった分類が全部フラットに並ぶ**。しかも取り込みは成功に見える。
 *
 * ## 親は id ではなく slug
 *
 * WXR 1.2 の `wp:category_parent` / `wp:term_parent` は**親の slug**が入る。
 * `wp:term_id` と取り違えると、存在しない親を探し続けることになるので、
 * 名前で区別する（`parentSlug`）。
 */
final class WxrTermDefinition
{
    public function __construct(
        /** WXR 側の `wp:term_id`。こちらの id ではない */
        public readonly string $termId,
        /** `category` / `post_tag` / `wp:term_taxonomy` の値 */
        public readonly string $taxonomy,
        public readonly string $slug,
        public readonly string $name,
        /** 親の **slug**。最上位なら null */
        public readonly ?string $parentSlug,
    ) {}
}
