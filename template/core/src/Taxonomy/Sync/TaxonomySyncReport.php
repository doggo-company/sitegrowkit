<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Sync;

/**
 * 同期結果（Phase 9.3B / STAGE 1・SY-15）。
 *
 * `created` / `updated` / `unchanged` / `conflicts` の 4 分類を **key 付き**で返す。
 * **DB 接続情報や秘密は含めない。**
 */
final readonly class TaxonomySyncReport
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $unchanged
     * @param  list<array{key: string, reason: string}>  $conflicts
     * @param  bool  $applied  書き込みを実行したか（conflict 時は false = 1 行も書いていない）
     */
    public function __construct(
        public array $created,
        public array $updated,
        public array $unchanged,
        public array $conflicts,
        public bool $applied,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'conflicts' => $this->conflicts,
        ];
    }
}
