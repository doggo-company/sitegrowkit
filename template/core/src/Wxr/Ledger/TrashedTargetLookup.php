<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 対象がいまゴミ箱に入っているか（Phase 9.14 / STAGE 27C・SPEC §D.3）。
 *
 * ## `TargetRowLookup` と分ける
 *
 * あちらは**物理的に行があるか**しか答えず、`deleted_at` を 1 度も見ない。
 * ゴミ箱の解釈をあちらへ混ぜると、「在るか」と「入っているか」が
 * 1 つの答えになり、**どちらの意味で使われているのか呼ぶ側から見えなくなる**。
 *
 * ## ここも判断はしない
 *
 * 返すのは「入っているか」だけである。ゴミ箱にあることが取り込みを止める理由になるのか、
 * それとも飛ばす理由なのかを決めるのは**この先の仕事**である。
 * 読むことと決めることを 1 か所に混ぜると、
 * 「読めなかった」と「入れないと決めた」が同じ値で表される。
 *
 * ## 「無い」と「ゴミ箱にある」を混ぜない
 *
 * 行が無い場合は `false` を返す。行が無いことは `TargetRowLookup` の担当で、
 * ここで `true` を返すと、**消えた対象がゴミ箱にあると誤って報告される**。
 *
 * ## 1 行も書かない
 *
 * `plan` の約束は「DB を読むが書かない」である（§D.1）。
 */
final class TrashedTargetLookup
{
    /** 1 度に確かめられる行の数（`TargetRowLookup` と同値） */
    public const MAX_WINDOW = 1000;

    public function __construct(private readonly ContentTypeResolver $types) {}

    /**
     * **渡された並びのまま**、ゴミ箱に入っているかを返す。
     *
     * @param  list<TargetRef>  $refs
     * @return list<bool>
     */
    public function trashed(array $refs): array
    {
        if (count($refs) > self::MAX_WINDOW) {
            throw new InvalidArgumentException(
                'ゴミ箱の状態は一度に '.self::MAX_WINDOW.' 件までしか確かめられません（'
                .count($refs).' 件渡されました）。',
            );
        }

        $inTrash = $this->fetch($refs);

        $found = [];

        foreach ($refs as $ref) {
            $found[] = isset($inTrash[$ref->signature()]);
        }

        return $found;
    }

    /**
     * 投稿タイプごとに 1 回だけ引く。
     *
     * 分類項目・コメント・メディアは**ゴミ箱の対象ではない**（9.8 SPEC §A）ので、
     * 列そのものが無い。問い合わせに行かない。
     *
     * @param  list<TargetRef>  $refs
     * @return array<string, true> ゴミ箱に入っていた行の signature
     */
    private function fetch(array $refs): array
    {
        /** @var array<string, array<string, string>> */
        $byType = [];

        /** @var array<string, string> ページ（`pages.status = trashed` がゴミ箱） */
        $pageIds = [];

        foreach ($refs as $ref) {
            if ($ref->kind === ImportKind::Page) {
                $pageIds[$ref->targetId] = $ref->targetId;

                continue;
            }

            if ($ref->kind !== ImportKind::Content || ! $this->types->knows($ref->targetType)) {
                continue;
            }

            $byType[$ref->targetType][$ref->targetId] = $ref->targetId;
        }

        $inTrash = [];

        if ($pageIds !== []) {
            /*
             * ページのゴミ箱は `deleted_at` ではなく `status` で表す（ADR-0013）。
             * 記事と同じ列を探すと、ゴミ箱のページが「生きている」と読まれ、
             * 再実行がそこへ相乗りしようとする。
             */
            $found = DB::table('pages')
                ->whereIn('id', array_values($pageIds))
                ->where('status', PageStatus::Trashed->value)
                ->pluck('id');

            foreach ($found as $id) {
                $inTrash[ImportKind::Page->value."\0".ItemTarget::PAGE_TARGET."\0".(string) $id] = true;
            }
        }

        foreach ($byType as $targetType => $ids) {
            /*
             * 表と主キーの名前だけを型から取り、**問い合わせは Query Builder で行う**。
             * モデル経由に引くと `SoftDeletes` の既定の絞り込みが働き、
             * ゴミ箱の行が最初から見えなくなる — ここで見たいのはまさにそれである。
             */
            $model = $this->types->newModel($targetType);
            $key = $model->getKeyName();

            $found = DB::table($model->getTable())
                ->whereIn($key, array_values($ids))
                ->whereNotNull(TrashColumns::DELETED_AT)
                ->pluck($key);

            foreach ($found as $id) {
                $inTrash[ImportKind::Content->value."\0".$targetType."\0".(string) $id] = true;
            }
        }

        return $inTrash;
    }
}
