<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * どの経路から呼ばれたか（Phase 9.1B / STEP 8）。
 * 監査で「Admin と AI のどちらが実行したか」を区別するために使う。
 */
enum OperationInterface: string
{
    case Admin = 'admin';
    case Api = 'api';
    case Mcp = 'mcp';
    case WebMcp = 'webmcp';
    case Cli = 'cli';
    case System = 'system';
}
