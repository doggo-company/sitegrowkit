<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr;

/**
 * WXR の `<wp:comment>` 1 件（Phase 9.14 / STAGE 27B・SPEC §C.4）。
 *
 * **IP は持たない。** WXR には `wp:comment_author_IP` が入っているが、
 * こちらは生の IP を保存しない方針なので、**読んだ時点で捨てる**
 * （持ってから捨てるのではなく、形に入れない）。
 */
final class WxrComment
{
    public function __construct(
        public readonly string $commentId,
        public readonly ?string $parentId,
        public readonly string $author,
        public readonly ?string $email,
        public readonly ?string $url,
        public readonly string $content,
        public readonly string $approved,
        public readonly ?string $createdAt,
    ) {}
}
