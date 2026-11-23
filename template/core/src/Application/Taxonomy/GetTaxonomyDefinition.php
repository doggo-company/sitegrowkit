<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionWriter;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * 定義 1 件の表示用データ（Phase 9.7 / STAGE 20D）。
 *
 * **画面（controller）が DB を直接引かないための口**である。
 * 分類の業務ロジックを adapter へ複製しない、という既存の線を守る。
 */
final class GetTaxonomyDefinition
{
    public function __construct(
        private readonly TaxonomyDefinitionWriter $writer,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Taxonomy $taxonomy): array
    {
        $associations = $this->db->connection()
            ->table(TaxonomyDefinitionWriter::ASSOCIATIONS)
            ->where('taxonomy_id', $taxonomy->id)
            ->orderBy('content_type')
            ->get(['content_type', 'multiple', 'required']);

        return [
            'key' => $taxonomy->key,
            'label' => $taxonomy->label,
            'description' => $taxonomy->getAttribute('description'),
            'hierarchical' => $taxonomy->hierarchical,
            'status' => $taxonomy->isArchived() ? 'archived' : 'active',
            'managedBy' => $taxonomy->managed_by->value,
            'lockVersion' => $this->writer->lockVersionOf($taxonomy->id),
            'termCount' => TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->count(),
            'contentTypes' => array_map(static fn (object $row): array => [
                'contentType' => (string) $row->content_type,
                'multiple' => (bool) $row->multiple,
                'required' => (bool) $row->required,
            ], $associations->all()),
        ];
    }
}
