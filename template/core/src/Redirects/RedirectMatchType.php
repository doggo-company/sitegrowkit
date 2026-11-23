<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

/**
 * 照合の方法（Phase 9.10 / STAGE 23B・SPEC §B.4・OG3）。
 *
 * **`regex` は入れない。** 管理画面から入った正規表現が 404 のたびに走ると、
 * 壊滅的バックトラックする式ひとつで「404 を投げるだけでサイトが止まる」状態になる。
 * `preg_match` は不正な式で `false` を返すので、失敗も静かである。
 * 実務で要るものの大半は `prefix` で足りる。
 */
enum RedirectMatchType: string
{
    /** 完全一致 */
    case Exact = 'exact';

    /** 前方一致。残りは転送先へ継ぎ足す（`/old/x` → `/new/x`） */
    case Prefix = 'prefix';
}
