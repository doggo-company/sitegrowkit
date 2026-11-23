<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

/**
 * WXR の `<item>` 1 件（Phase 9.14 / STAGE 27B・SPEC §C.1）。
 *
 * **WordPress の語彙のまま持つ。** ここで名前を変えると、
 * 「WXR に何が入っていたか」と「こちらへ何を入れたか」を突き合わせられなくなる。
 * こちらの語彙への読み替えは 27D（apply）の仕事である。
 */
final class WxrItem
{
    /**
     * `$terms` は**割り当てだけで、親は持たない。**
     *
     * 親子は `<channel>` 直下の定義にしかなく（`WxrReader::termDefinitions()`）、
     * item 側からは原理的に取れない。常に空になるキーを置くと、
     * 下流は**階層を扱えているように見えて扱えていない**状態になる。
     *
     * @param  list<array{domain: string, nicename: string, name: string}>  $terms
     * @param  array<string, string>  $meta  `_` で始まるものは除いてある
     * @param  list<WxrComment>  $comments
     */
    public function __construct(
        public readonly string $postId,
        public readonly string $postType,
        public readonly string $status,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly ?string $publishedAt,
        public readonly ?string $parentId,
        public readonly ?string $attachmentUrl,
        /**
         * 記事がコメントを受け付けるか（§C.4.3）。**読み替えない・畳まない。**
         *
         * `open` / `closed` 以外の値も、WordPress の語彙のまま運ぶ。
         * ここで真偽値へ畳むと、「open でも closed でもない値が書いてあった」を
         * 下流が知る手立てが無くなる — **要素が無いこと**（正常な不在）と
         * **知らない値が書いてあること**は別に扱う約束である。
         *
         * 要素が無い・空のときは `null`。
         */
        public readonly ?string $commentStatus = null,
        public readonly array $terms = [],
        public readonly array $meta = [],
        public readonly array $comments = [],
    ) {}
}
