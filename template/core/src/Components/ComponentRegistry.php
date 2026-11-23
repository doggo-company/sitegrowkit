<?php

declare(strict_types=1);

namespace AgentCms\Core\Components;

use RuntimeException;

/**
 * Component Registry（Phase 9.1D・owner 決定 G19）。
 *
 * project 側の JSON（`content/components/*.json`）を読み込む。
 * **ここに無い type は document に置けず、描画もされない**（allowlist）。
 *
 * Content Type の `SchemaRegistry` と同じ構造にしてあるので、
 * component を増やす作業は「JSON を 1 つ足す」だけになる。
 */
final class ComponentRegistry
{
    /** @var array<string, ComponentDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly string $directory) {}

    public function has(string $type): bool
    {
        return isset($this->all()[$type]);
    }

    public function get(string $type): ComponentDefinition
    {
        return $this->all()[$type]
            ?? throw new RuntimeException("Component [{$type}] は登録されていません");
    }

    /** @return array<string, ComponentDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach (glob(rtrim($this->directory, '/').'/*.json') ?: [] as $file) {
            $raw = json_decode((string) file_get_contents($file), true);

            if (! is_array($raw) || ! is_string($raw['type'] ?? null)) {
                throw new RuntimeException("Component 定義が不正です: {$file}");
            }

            $definitions[$raw['type']] = ComponentDefinition::fromArray($raw);
        }

        ksort($definitions);

        return $this->definitions = $definitions;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    /**
     * AI / 管理画面が実行時に取得する一覧。
     * **型を焼き込ませないための入口**（Phase 9.1A K の方針）。
     *
     * @return list<array<string, mixed>>
     */
    public function specs(): array
    {
        return array_values(array_map(
            static fn (ComponentDefinition $d): array => $d->toSpec(),
            $this->all(),
        ));
    }
}
