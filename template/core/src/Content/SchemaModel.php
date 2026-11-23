<?php

declare(strict_types=1);

namespace AgentCms\Core\Content;

use AgentCms\Core\Schema\ContentSchema;

/**
 * Content Schema と接続されたモデルの契約（HasContentSchema trait が実装を提供）。
 */
interface SchemaModel
{
    public static function schemaId(): string;

    public static function schema(): ContentSchema;
}
