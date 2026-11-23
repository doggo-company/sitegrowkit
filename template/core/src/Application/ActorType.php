<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/** 操作の実行主体（Phase 9.1B / STEP 8） */
enum ActorType: string
{
    case User = 'user';
    case Agent = 'agent';
    case System = 'system';
}
