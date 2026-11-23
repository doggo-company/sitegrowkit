<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Rollback;

use Illuminate\Support\Carbon;

/**
 * 取り消しが見る「1 つの束の宛先」（Phase 9.14 / STAGE 27E-2・SPEC §D.3）。
 *
 * ## 3 つは必ず一緒に動く
 *
 * どの型の・どの id を・**いつ取り込んだか**は、判定のたびに 3 つ揃って要る。
 * 素の行のまま持ち回すと、台帳の列名が判定の中まで漏れ、
 * 列を 1 つ足したときに「どこまでが台帳の話か」が読めなくなる。
 *
 * `importedAt` は**台帳の行の作成時刻**であって、記事の作成時刻ではない。
 * 取り込みは記事と台帳を同じ transaction で書くので両者は近いが、
 * 「触られたか」を決めるのは**こちらが記録した時刻**の側である。
 */
final class BundleTarget
{
    public function __construct(
        public readonly string $contentType,
        public readonly int $contentId,
        public readonly Carbon $importedAt,
    ) {}
}
