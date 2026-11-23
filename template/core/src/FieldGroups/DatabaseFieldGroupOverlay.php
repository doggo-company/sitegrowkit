<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

use AgentCms\Core\Schema\SchemaFieldOverlay;

/**
 * DB の Field Group を Content Type へ重ねる（Phase 9.5-A / ADR-0015）。
 *
 * 参加条件は 3 つとも満たすことだけ:
 *
 * 1. `status` が `published`（draft / archived は効かない）
 * 2. 定義が読める（壊れていれば 1 つも足さない）
 * 3. 割当先の `kind` が `content_type`（`form` は 9.5-A では実行面が無い。
 *    `page_template` と未知の kind はどこにも一致しない — G11）
 *
 * **ファイルとの衝突判定はここでしない。** 同じ key があればファイルが勝つ、は
 * `SchemaRegistry` の責務である（判定を 2 か所に置くと必ず食い違う）。
 */
final class DatabaseFieldGroupOverlay implements SchemaFieldOverlay
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $index = null;

    public function __construct(private readonly FieldGroupRepository $repository) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsFor(string $schemaId): array
    {
        return ($this->index ??= $this->build())[$schemaId] ?? [];
    }

    /**
     * schema id => 追加する field 宣言。
     *
     * 順序は Field Group の並び（sort_order → key）と、その中の宣言順で決まる。
     * 同じ DB なら常に同じ順序になる。
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function build(): array
    {
        $index = [];

        foreach ($this->repository->all() as $group) {
            if (! $group->contributesFields()) {
                continue;
            }

            foreach ($group->locations as $location) {
                if ($location->kind !== FieldGroupLocationKind::ContentType) {
                    continue;
                }

                foreach ($group->declarations as $declaration) {
                    $index[$location->value][] = $declaration;
                }
            }
        }

        return $index;
    }
}
