<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * taxonomy key → runtime Taxonomy（Phase 9.3B / STAGE 1・SPEC §J.5・PV-3 / PV-4）。
 *
 * **read-only。DB を書かない。**
 * - `firstOrCreate` / `updateOrCreate` / `ensure` を持たない
 * - 見つからなければ **明示的に失敗**する（暗黙作成での回復をしない）
 * - `managed_by` の不一致を黙って修正しない
 * - checked-in JSON へ ULID を書き戻さない
 * - キャッシュはリクエスト内メモリのみで、DB 書き込みを伴わない
 *
 * Term Resolver / slug 解決 / assignment 解決は **STAGE 2 以降**。
 */
final class TaxonomyResolver
{
    /** @var array<string, Taxonomy> リクエスト内メモリキャッシュ（永続化しない） */
    private array $cache = [];

    public function __construct(private readonly TaxonomyDefinitionRegistry $definitions) {}

    /**
     * key から Taxonomy を得る。**未同期なら例外**。
     *
     * @throws TaxonomyNotSyncedException
     */
    public function resolve(string $key): Taxonomy
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $taxonomy = $this->find($key);

        if ($taxonomy === null) {
            throw TaxonomyNotSyncedException::missing($key);
        }

        // 定義がある key は managed_by が定義と一致していること（黙って直さない）
        if ($this->definitions->has($key)) {
            $expected = $this->definitions->get($key)->managedBy;

            if ($taxonomy->managed_by !== $expected) {
                throw TaxonomyNotSyncedException::managedByMismatch(
                    $key,
                    $expected->value,
                    $taxonomy->managed_by->value,
                );
            }
        }

        return $this->cache[$key] = $taxonomy;
    }

    /** 存在しなければ null を返すだけ。**行を作らない**。 */
    public function find(string $key): ?Taxonomy
    {
        return Taxonomy::query()->where('key', $key)->first();
    }

    /** immutable な taxonomy ULID。checked-in JSON へは書き戻さない（§J.2） */
    public function idFor(string $key): string
    {
        return $this->resolve($key)->id;
    }

    public function isSynced(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /** テスト用にリクエスト内キャッシュを捨てる（DB は触らない） */
    public function forget(): void
    {
        $this->cache = [];
    }
}
