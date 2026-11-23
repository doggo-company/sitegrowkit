<?php

declare(strict_types=1);

namespace AgentCms\Core\Content;

use InvalidArgumentException;

/**
 * schema id → Eloquent モデルの対応（Phase 9.1B / STEP 4）。
 *
 * Application Service が **News / Works を hardcode せず**に
 * 「type 名だけ」でコンテンツを扱えるようにするための登録簿。
 * 登録は project 層（`ModulesServiceProvider`）が行う — core は個別モジュールを知らない。
 */
final class ContentTypeRegistry
{
    /** @var array<string, array{model: class-string, routeBase: string}> */
    private array $types = [];

    /** @param  class-string  $modelClass */
    public function register(string $schemaId, string $modelClass, ?string $routeBase = null): void
    {
        $this->types[$schemaId] = [
            'model' => $modelClass,
            'routeBase' => $routeBase ?? $schemaId,
        ];
    }

    public function has(string $schemaId): bool
    {
        return array_key_exists($schemaId, $this->types);
    }

    /** @return class-string */
    public function modelFor(string $schemaId): string
    {
        if (! $this->has($schemaId)) {
            throw new InvalidArgumentException("Unknown content type [{$schemaId}]");
        }

        return $this->types[$schemaId]['model'];
    }

    public function routeBaseFor(string $schemaId): string
    {
        if (! $this->has($schemaId)) {
            throw new InvalidArgumentException("Unknown content type [{$schemaId}]");
        }

        return $this->types[$schemaId]['routeBase'];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->types);
    }
}
