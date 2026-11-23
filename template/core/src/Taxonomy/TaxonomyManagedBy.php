<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * taxonomy の所有者（Phase 9.3B / STAGE 1・SPEC §B）。
 *
 * - `code`: `content/taxonomies/*.json` が SSoT。Admin / API / MCP からは read-only
 * - `cms`: 管理画面が SSoT。**Phase 9.4 まで作成手段を提供しない**（§P.2）
 *
 * **sync がこの値を自動変更することは禁止**（SY-11）。
 */
enum TaxonomyManagedBy: string
{
    case Code = 'code';

    case Cms = 'cms';

    public function isCode(): bool
    {
        return $this === self::Code;
    }
}
