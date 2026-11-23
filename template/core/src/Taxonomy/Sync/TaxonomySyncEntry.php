<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Sync;

use AgentCms\Core\Taxonomy\TaxonomyDefinition;

/**
 * 1 taxonomy 分の同期判定（Phase 9.3B / STAGE 1・SPEC §J.4）。
 *
 * preflight の結果であり、**この時点では 1 行も書いていない**。
 */
final readonly class TaxonomySyncEntry
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const UNCHANGED = 'unchanged';

    public const CONFLICT = 'conflict';

    /**
     * @param  array<string, mixed>  $changes  update のとき適用する安全な属性だけ
     * @param  string|null  $reason  conflict の日本語理由
     */
    public function __construct(
        public string $action,
        public TaxonomyDefinition $definition,
        public array $changes = [],
        public ?string $reason = null,
    ) {}

    public function key(): string
    {
        return $this->definition->key;
    }
}
