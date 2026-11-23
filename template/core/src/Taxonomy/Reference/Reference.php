<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

/**
 * 「何がこの分類を参照しているか」1 種類分（Phase 9.3B / STAGE 2・SPEC §I.3）。
 *
 * Admin が「3 件のニュースと 1 ページで使われています」と
 * **具体的に**表示できる材料を持つ。
 */
final readonly class Reference
{
    /**
     * @param  string  $kind  機械可読な種別（`assignment` / `child_term` / `page_document` …）
     * @param  string  $label  人が読む説明（日本語）
     * @param  int  $count  参照件数
     * @param  list<string>  $samples  代表例（多すぎる場合は先頭のみ）
     */
    public function __construct(
        public string $kind,
        public string $label,
        public int $count,
        public array $samples = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'count' => $this->count,
            'samples' => $this->samples,
        ];
    }
}
