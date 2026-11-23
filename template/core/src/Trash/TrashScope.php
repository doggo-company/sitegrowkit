<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

use AgentCms\Core\Content\SchemaModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 *
 * ゴミ箱の既定除外（Phase 9.8 / STAGE 21B・SPEC §C.4.1.1、Owner Decision OG1 = (b)）。
 *
 * ## なぜ Laravel の `SoftDeletes` を使わないのか
 *
 * 標準の trait は **boot の時点で無条件に** `deleted_at is null` を足す。
 * 列がまだ無い型では、一覧を開いた瞬間に SQL エラーになる。
 * 「列がある型だけ除外する」を標準の trait のままでは表現できない。
 *
 * だから **scope の登録は無条件に行い、判定は scope の中で**行う。
 * boot では DB を読まず、要求を受けてから型ごとに 1 回だけ確かめる。
 *
 * ## 3 値の振る舞い
 *
 * | 状態 | ここで足す条件 |
 * | --- | --- |
 * | `ready` | `deleted_at is null` |
 * | `known-unmigrated` | **何も足さない**（trashed の行が存在し得ない） |
 * | `indeterminate` | **投げる**。0 件を返さない（SPEC §C.4.1.2） |
 *
 * `indeterminate` で空を返さないのが要点である。空の 200 は
 * 「サイトの中身が消えた」ように見え、しかもキャッシュされ得る。
 */
final class TrashScope implements Scope
{
    /** `withTrashed()` / `onlyTrashed()` が外せるように名前を固定する */
    public const NAME = 'agentcms.trash';

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $type = self::typeOf($model);
        $readiness = self::resolver()->readiness($type);

        match ($readiness) {
            TrashReadiness::Ready => $builder->whereNull(
                $model->qualifyColumn(TrashColumns::DELETED_AT),
            ),
            TrashReadiness::Unmigrated => null,
            TrashReadiness::Indeterminate => throw new TrashReadinessUnavailable($type),
        };
    }

    private static function typeOf(Model $model): string
    {
        return $model instanceof SchemaModel ? $model::schemaId() : $model->getTable();
    }

    private static function resolver(): TrashReadinessResolver
    {
        return app(TrashReadinessResolver::class);
    }
}
