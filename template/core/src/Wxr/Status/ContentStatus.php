<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Status;

/**
 * こちらの公開状態（Phase 9.14 / STAGE 27C・SPEC §C.8.2）。
 *
 * **2 つしかない。** `SchemaRules` が `draft` / `published` だけを通す。
 * WordPress は 8 つ以上を持つので、写すときは必ず畳むことになる。
 * 語彙を増やすのは別 Phase である（§C.8.7）。
 */
enum ContentStatus: string
{
    case Draft = 'draft';

    case Published = 'published';
}
