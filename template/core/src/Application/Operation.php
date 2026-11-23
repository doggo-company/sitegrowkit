<?php

declare(strict_types=1);

namespace AgentCms\Core\Application;

/**
 * Application Service が自分の素性を宣言するための contract（Phase 9.1B / STEP 6）。
 *
 * **1 操作 = 1 クラス**（owner 決定 G16）。共通の基底クラスは作らず、
 * 必要な部品は composition で持たせる。
 */
interface Operation
{
    /** 例: content.update */
    public function operationId(): string;

    public function classification(): OperationClassification;
}
