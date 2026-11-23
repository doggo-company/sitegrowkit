<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * code-managed taxonomy の宣言（Phase 9.3B / STAGE 1・SPEC §J.4）。
 *
 * SSoT は `content/taxonomies/<key>.json`。
 *
 * **定義ファイルは runtime ULID を持たない**（§J.2）。
 * **term 一覧も持たない**（term は taxonomy-sync の管轄外 — SY-8）。
 */
final readonly class TaxonomyDefinition
{
    /** 定義ファイルに書いてよい属性はこれだけ（未知属性は FAIL） */
    public const ALLOWED_ATTRIBUTES = ['key', 'label', 'hierarchical', 'managed_by'];

    public const REQUIRED_ATTRIBUTES = ['key', 'label', 'hierarchical', 'managed_by'];

    /**
     * key の文法は **`TaxonomyKey` が単一情報源**（STAGE 3A）。
     * ContentSchema の `taxonomies[].key` と同じ規則を使い、将来乖離させない。
     */
    public const KEY_PATTERN = TaxonomyKey::PATTERN;

    public const KEY_MAX = TaxonomyKey::MAX_LENGTH;

    public const LABEL_MAX = 100;

    public function __construct(
        public string $key,
        public string $label,
        public bool $hierarchical,
        /** code definition では常に Code（SY-11 により sync は変更しない） */
        public TaxonomyManagedBy $managedBy,
        /** 由来ファイル（エラー表示用。値の一部ではない） */
        public string $sourceFile,
    ) {}
}
