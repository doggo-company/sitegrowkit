<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * ULID 主キーの共通設定（Phase 9.3B / STAGE 1）。
 *
 * `creating` で行うのは **identity の採番だけ**である。
 * 暗黙の sync・cascade cleanup・関連行の書き換えは**一切行わない**（SY-8 / SY-9 / PV-3）。
 * 採番そのものは `TaxonomyId` に集約してある。
 */
trait HasTaxonomyId
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected static function bootHasTaxonomyId(): void
    {
        static::creating(static function (self $model): void {
            $key = $model->getKeyName();

            if (! is_string($model->getAttribute($key)) || $model->getAttribute($key) === '') {
                $model->setAttribute($key, TaxonomyId::generate());
            }
        });
    }
}
