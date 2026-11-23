<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;

/**
 * 分類を投稿タイプへ関連付ける（Phase 9.7 / STAGE 20D・SPEC §H.3）。
 *
 * **多対多である。** 1 つの分類を複数の投稿タイプへ、1 つの投稿タイプへ複数の分類を付けられる。
 *
 * **ファイルは書かない。** 関連付けは DB にだけ持つので、読み取り専用の FS でも動く。
 * ファイル宣言（`content/schemas/*.json` の `taxonomies`）は正のまま残り、
 * 同じ key があればファイル側が勝つ（`SchemaRegistry` の overlay 規則）。
 */
final class AttachTaxonomyToContentType implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly SchemaRegistry $schemas,
        private readonly TaxonomyDefinitionWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'taxonomy.definition.attach';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    public function execute(string $key, string $contentType, bool $multiple, bool $required, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $taxonomy = Taxonomy::query()->where('key', $key)->first();

        if ($taxonomy === null) {
            return $this->outcome->record($this, $context, Result::notFound('分類が見つかりません'),
                resourceType: 'taxonomy', resourceId: $key);
        }

        $schema = $this->schemas->declared()[$contentType] ?? null;

        if ($schema === null || ! EditorialCollections::isEditorial($schema)) {
            return $this->outcome->record($this, $context, Result::notFound('投稿タイプが見つかりません'),
                resourceType: 'taxonomy', resourceId: $key, metadata: ['contentType' => $contentType]);
        }

        // ファイルが同じ key を宣言している型へ、DB から重ねても効かない（黙って通さない）
        if ($schema->hasTaxonomy($key)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict("投稿タイプ [{$contentType}] は定義ファイルでこの分類を宣言しています"),
                resourceType: 'taxonomy',
                resourceId: $key,
                metadata: ['contentType' => $contentType],
            );
        }

        if (! $this->writer->attach($contentType, $taxonomy->id, $multiple, $required)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('この投稿タイプには既に関連付けられています'),
                resourceType: 'taxonomy',
                resourceId: $key,
                metadata: ['contentType' => $contentType],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['association' => ['taxonomy' => $key, 'contentType' => $contentType]]),
            resourceType: 'taxonomy',
            resourceId: $key,
            metadata: ['contentType' => $contentType, 'multiple' => $multiple, 'required' => $required],
        );
    }
}
