<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * type 名から schema とモデルを解決する（Phase 9.1B / STEP 4）。
 *
 * 各 Content service が同じ解決手順を書かないための composition 部品。
 * **継承のための基底クラスではない**（owner 決定 G16: 1 操作 = 1 クラス）。
 */
final class ContentTypeResolver
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeRegistry $types,
    ) {}

    public function knows(string $type): bool
    {
        return $this->types->has($type) && $this->schemas->has($type);
    }

    public function schema(string $type): ContentSchema
    {
        return $this->schemas->get($type);
    }

    /** @return class-string<Model> */
    public function modelClass(string $type): string
    {
        /** @var class-string<Model> $class */
        $class = $this->types->modelFor($type);

        return $class;
    }

    public function newModel(string $type): Model
    {
        $class = $this->modelClass($type);

        return new $class;
    }

    /** @return list<string> */
    public function availableTypes(): array
    {
        return array_values(array_filter(
            $this->types->ids(),
            fn (string $id): bool => $this->schemas->has($id),
        ));
    }
}
