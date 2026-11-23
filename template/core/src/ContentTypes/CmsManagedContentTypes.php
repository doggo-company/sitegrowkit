<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * CMS が作った Content Type の発見（Phase 9.4 / STAGE #17A）。
 *
 * **新しい登録簿ファイルを作らない。** `content/schemas/*.json` が
 * これまでどおり単一情報源で、`managedBy: "cms"`（既存キー）の
 * collection schema が「CMS が作った型」である。
 *
 * モデルクラスは ID から**決定的に導出**される（`ContentTypeIdentifier`）。
 * 生成側と発見側が同じ規約を共有するので、対応表を持つ必要がない。
 */
final class CmsManagedContentTypes
{
    /**
     * schema id => モデル FQCN。
     *
     * クラスを読み込めない型は**返さない**。存在しないクラスを登録すると、
     * 一覧を開いただけで致命的エラーになる。読み込めない型は
     * `ListContentTypeDefinitions` が「利用不可」として画面に出す。
     *
     * @return array<string, class-string>
     */
    public static function discover(SchemaRegistry $schemas): array
    {
        $types = [];

        foreach (self::schemas($schemas) as $id => $schema) {
            $errors = [];
            // **既に登録されている** ID なので「空いているか」は問わない。
            // 同梱の `post` は `/admin/post` を占有するため新しい ID としては予約されており、
            // `tryFrom()` を使うと自分自身を発見できなくなる
            $identifier = ContentTypeIdentifier::forRegistered($id, $errors);

            if ($identifier === null) {
                continue;
            }

            $class = $identifier->modelClass();

            if (class_exists($class, autoload: true)) {
                $types[$id] = $class;
            }
        }

        return $types;
    }

    /**
     * CMS 管理の collection schema。
     *
     * **`declared()` を読む**（Phase 9.5-A / ADR-0015）。ここは route 登録と
     * モデル登録から呼ばれるので、boot 中に DB へ触れてはいけない。
     * Field Group overlay は Content Type を増やさないため、
     * 型の集合は `all()` と同じである。
     *
     * @return array<string, ContentSchema>
     */
    public static function schemas(SchemaRegistry $schemas): array
    {
        return array_filter(
            $schemas->declared(),
            static fn (ContentSchema $schema): bool => $schema->kind === 'collection'
                && $schema->managedBy === ContentSchema::MANAGED_BY_CMS,
        );
    }
}
