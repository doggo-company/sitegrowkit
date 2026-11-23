<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use AgentCms\Core\Taxonomy\TermSlug;
use InvalidArgumentException;

/**
 * こちらの分類の中の 1 枠（Phase 9.14 / STAGE 27D-1・SPEC §C.3）。
 *
 * 受け皿と保存する綴りの組で、同じ組は同じ項目を指す。
 *
 * ## 綴りは保存できる形しか通さない
 *
 * ここへ来るのは `TermGraph` が決めた**保存する綴り**であって、
 * WXR に書かれていた綴りそのものではない。日本語や percent の綴りは
 * 封をする時点で当て直されている（§C.8 と同じ作法）。
 * 形を確かめるのは、当て直しを飛ばした値がここへ入らないようにするためである。
 */
final class TermSlot
{
    public function __construct(
        public readonly string $taxonomy,
        public readonly string $slug,
    ) {
        LedgerKey::assertTargetType($taxonomy);

        if (! TermSlug::isValid($slug)) {
            // **値そのものを出さない**（外来の綴りが例外の文面に載る）
            throw new InvalidArgumentException(
                '分類項目の枠には、保存できる形の綴りだけを入れてください。',
            );
        }
    }

    public function signature(): string
    {
        return $this->taxonomy."\0".$this->slug;
    }
}
