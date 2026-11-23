<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

/**
 * Field Group の状態（Phase 9.5-A / ADR-0015）。
 *
 * **overlay に参加するのは `Published` だけ**。draft と archived は
 * 一覧には出るが、Content Type の項目を 1 つも増やさない。
 *
 * 「進行状態」と「保管状態」を 1 つの enum に混ぜていないか、
 * 値を増やすときは必ず確認すること（`published` と `archived` は
 * どちらも「今この定義が効いているか」という 1 つの軸の上にある）。
 */
enum FieldGroupStatus: string
{
    case Draft = 'draft';

    case Published = 'published';

    case Archived = 'archived';

    /** overlay に参加するか */
    public function contributesFields(): bool
    {
        return $this === self::Published;
    }
}
