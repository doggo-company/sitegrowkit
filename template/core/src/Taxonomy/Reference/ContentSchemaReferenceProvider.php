<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy\Reference;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;

/**
 * T-2: ContentSchema の `taxonomies[]` からの参照（Phase 9.3B / STAGE 3A・SPEC §I.2）。
 *
 * STAGE 2A で「依存先（`taxonomies[]` 宣言）が未実装」として保留していた provider。
 * STAGE 3A で宣言が入ったので登録する。
 *
 * **対象は ContentSchema → Taxonomy の参照**である。
 * 個別 Term への参照ではないので、`forTerm()` は常に「参照なし」を返す
 * （term への参照は assignment / 子 term / PageDocument が担当する）。
 *
 * 参照元は **effective な SchemaRegistry**（base + override 統合後）を見るため、
 * override から追加・置換された宣言も正しく数える。
 *
 * SchemaRegistry の読み込みに失敗した場合は**例外をそのまま投げる**。
 * `ReferenceInspector` の provider 隔離が undetermined として fail closed にするので、
 * ここで握りつぶして「参照ゼロ」にしない。
 */
final class ContentSchemaReferenceProvider implements ReferenceProvider
{
    /** samples に載せる最大件数 */
    private const SAMPLE_LIMIT = 5;

    public function __construct(private readonly SchemaRegistry $schemas) {}

    public function kind(): string
    {
        return 'content_schema';
    }

    /**
     * term への照会では常に参照なし。
     * ContentSchema は taxonomy を宣言するだけで、個別の term は指さない。
     */
    public function forTerm(TaxonomyTerm $term): ReferenceProbe
    {
        return ReferenceProbe::none();
    }

    public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
    {
        $referencing = [];

        foreach ($this->schemas->all() as $schema) {
            // taxonomy key は schema 内で一意なので、同じ Content Type を重複計上しない
            if ($schema->hasTaxonomy($taxonomy->key)) {
                $referencing[] = $schema;
            }
        }

        if ($referencing === []) {
            return ReferenceProbe::none();
        }

        return ReferenceProbe::found(count($referencing), $this->samples($referencing));
    }

    /**
     * 表示用の安全な情報だけを返す。
     * **絶対パス・SQL・接続情報・秘密情報を含めない。**
     *
     * @param  list<ContentSchema>  $schemas
     * @return list<string>
     */
    private function samples(array $schemas): array
    {
        return array_map(
            static fn (ContentSchema $schema): string => $schema->label !== ''
                ? "{$schema->label}（{$schema->id}）"
                : $schema->id,
            array_slice($schemas, 0, self::SAMPLE_LIMIT),
        );
    }
}
