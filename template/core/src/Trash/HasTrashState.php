<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 投稿タイプのモデルにゴミ箱の既定除外を付ける（Phase 9.8 / STAGE 21B・OG1 = (b)）。
 *
 * **Laravel の `SoftDeletes` は使わない**（継承もしない）。理由は `TrashScope` に書いた。
 *
 * 付けるのは **editorial collection のモデルだけ**である。`media` / `contact` / `site` は
 * ゴミ箱の対象ではない（SPEC §A）。生成される型は `ContentTypeGenerator` が付ける。
 *
 * この trait は **read しか足さない**。ゴミ箱へ入れる・戻す・完全に消すは
 * STAGE 21C 以降であり、ここには書かない。
 */
trait HasTrashState
{
    /**
     * この trait は **schema と結ばれたモデル専用**である。
     * 型の名前が引けないと readiness を解決できないので、契約として要求する。
     */
    abstract public static function schemaId(): string;

    public static function bootHasTrashState(): void
    {
        static::addGlobalScope(TrashScope::NAME, new TrashScope);
    }

    /**
     * ゴミ箱も含めて読む。**「今選ばれている参照先の名前を出す」ためにある。**
     *
     * 既に保存されている関連の相手がゴミ箱へ入っても、画面に「ID 42」とだけ
     * 出すのでは何を選んだのか読めない。名前は引けるようにしておき、
     * 「ゴミ箱にあります」と添えるのは UI（21E）の仕事にする。
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeWithTrashed(Builder $query): Builder
    {
        self::assertReadable();

        return $query->withoutGlobalScope(TrashScope::NAME);
    }

    /**
     * ゴミ箱だけを読む。**対応していない型では黙って 0 件を返さない。**
     *
     * 「ゴミ箱は空だった」と「この型にゴミ箱はまだ無い」は、別のことである。
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeOnlyTrashed(Builder $query): Builder
    {
        app(TrashReadinessResolver::class)->assertUsable(static::schemaId());

        return $query
            ->withoutGlobalScope(TrashScope::NAME)
            ->whereNotNull($query->getModel()->qualifyColumn(TrashColumns::DELETED_AT));
    }

    /**
     * 既定と同じ「active だけ」。`withTrashed()` を通した query から明示的に戻す。
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeActiveOnly(Builder $query): Builder
    {
        $readiness = self::assertReadable();

        $query->withoutGlobalScope(TrashScope::NAME);

        return $readiness === TrashReadiness::Ready
            ? $query->whereNull($query->getModel()->qualifyColumn(TrashColumns::DELETED_AT))
            : $query;
    }

    /**
     * 読み取りを続けてよいかだけを確かめる。
     *
     * `known-unmigrated` は**続けてよい**（trashed の行が存在し得ない）。
     * 止めるのは `indeterminate` だけである。
     */
    private static function assertReadable(): TrashReadiness
    {
        $readiness = self::trashReadiness();

        if (! $readiness->allowsReads()) {
            throw new TrashReadinessUnavailable(static::schemaId());
        }

        return $readiness;
    }

    /**
     * この型がゴミ箱として動くか（例外を投げずに尋ねる）。
     *
     * 画面や service が「ゴミ箱の導線を出してよいか」を判断するための入口。
     */
    public static function supportsTrash(): bool
    {
        return app(TrashReadinessResolver::class)->supports(static::schemaId());
    }

    public static function trashReadiness(): TrashReadiness
    {
        return app(TrashReadinessResolver::class)->readiness(static::schemaId());
    }
}
