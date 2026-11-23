<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

/**
 * 生成物の書き込み先（Phase 9.4 / STAGE #17A）。
 *
 * **呼び出し側からパスを渡せない**。すべて config から解決し、
 * 末端のファイル名は `ContentTypeIdentifier` が検証済みの語だけで組み立てる。
 * これにより「入力値がパスの一部になる」経路が構造的に存在しない。
 */
final readonly class ContentTypePaths
{
    public function __construct(
        private string $schemaPath,
        private string $modulesPath,
    ) {}

    public function schemaFile(ContentTypeIdentifier $id): string
    {
        return $this->schemaPath.'/'.$id->value.'.json';
    }

    public function moduleDir(ContentTypeIdentifier $id): string
    {
        return $this->modulesPath.'/'.$id->studly();
    }

    public function modelFile(ContentTypeIdentifier $id): string
    {
        return $this->moduleDir($id).'/Models/'.$id->studly().'.php';
    }

    public function migrationDir(ContentTypeIdentifier $id): string
    {
        return $this->moduleDir($id).'/database/migrations';
    }

    public function migrationFile(ContentTypeIdentifier $id, string $timestamp): string
    {
        return $this->migrationDir($id).'/'.$timestamp.'_create_'.$id->table().'_table.php';
    }

    public function schemaDir(): string
    {
        return $this->schemaPath;
    }

    public function modulesDir(): string
    {
        return $this->modulesPath;
    }
}
