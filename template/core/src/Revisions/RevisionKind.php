<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

/**
 * 版の種類（Phase 9.8 / STAGE 21D・SPEC §F.6）。
 *
 * 自動保存（`autosave`）の**実装は P0-B2** で、この Stage では作らない。
 * 列と値だけ先に用意するのは、あとから足すと**保持件数の数え方**（OG10 は
 * manual 50 / autosave 5 と別々に決めている）を作り直すことになるからである。
 */
enum RevisionKind: string
{
    /** 利用者の操作で生まれた版 */
    case Manual = 'manual';

    /** 自動保存（P0-B2。この Stage では書かない） */
    case Autosave = 'autosave';
}
