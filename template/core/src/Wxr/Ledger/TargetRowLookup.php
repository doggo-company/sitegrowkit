<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 台帳が指している行が、いま実際にあるか（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 意味判断ではなく、物理的な存在
 *
 * ゴミ箱の投稿を「無い」と読むか「在る」と読むか、保管された分類項目をどう読むかは
 * **取り込みの方針そのもの**で、Owner Gate（status mapping）の決定を待っている。
 * その決定より先にここが片側へ倒れないよう、**行が物理的にあるかどうかだけ**を返す。
 *
 * だから Eloquent を通さない。`SoftDeletes` を使う型では、既定の絞り込みが
 * `deleted_at` を黙って見る。**待っているはずの決定が、読み方の副作用で決まる。**
 * ここは `deleted_at` を 1 度も見ない。
 *
 * ゴミ箱や保管の解釈は Owner Gate 決定後の次の段で行う。
 *
 * ## 1 件ずつ引かない
 *
 * 十年分は数万件になる。引く先は「種類と取り込み先」の組で決まるので、
 * その組ごとに 1 回でよい。
 *
 * ## 1 行も書かない
 *
 * `plan` の約束は「DB を読むが書かない」である（§D.1）。
 */
final class TargetRowLookup
{
    /** 1 度に確かめられる行の数（`LedgerLookup` と同値） */
    public const MAX_WINDOW = 1000;

    /**
     * 投稿タイプ以外の行き先。**取り込み先の名前は固定である。**
     *
     * @var array<string, string>
     */
    private const FIXED_TABLES = [
        'comment' => 'content_comments',
        'media' => 'media',
        'page' => 'pages',
        'term' => 'taxonomy_terms',
    ];

    public function __construct(private readonly ContentTypeResolver $types) {}

    /**
     * **渡された並びのまま**、在るかどうかを返す。
     *
     * @param  list<TargetRef>  $refs
     * @return list<bool>
     */
    public function exists(array $refs): array
    {
        $this->assertWindow($refs);

        $present = $this->fetch($refs);

        $found = [];

        foreach ($refs as $ref) {
            $found[] = isset($present[$ref->signature()]);
        }

        return $found;
    }

    /**
     * @param  list<TargetRef>  $refs
     */
    private function assertWindow(array $refs): void
    {
        if (count($refs) > self::MAX_WINDOW) {
            throw new InvalidArgumentException(
                '対象の行は一度に '.self::MAX_WINDOW.' 件までしか確かめられません（'
                .count($refs).' 件渡されました）。',
            );
        }

        $seen = [];

        foreach ($refs as $ref) {
            $signature = $ref->signature();

            if (isset($seen[$signature])) {
                // 指す先そのものは出さない（台帳から読んだ値を含む）
                throw new InvalidArgumentException('同じ行を二度渡すことはできません。');
            }

            $seen[$signature] = true;
        }
    }

    /**
     * 種類と取り込み先の組ごとに 1 回だけ引く。
     *
     * @param  list<TargetRef>  $refs
     * @return array<string, true> 在った行の signature
     */
    private function fetch(array $refs): array
    {
        /** @var array<string, array{kind: ImportKind, targetType: string, ids: array<string, string>}> */
        $groups = [];

        foreach ($refs as $ref) {
            $group = $ref->kind->value."\0".$ref->targetType;

            $groups[$group] ??= ['kind' => $ref->kind, 'targetType' => $ref->targetType, 'ids' => []];
            $groups[$group]['ids'][$ref->targetId] = $ref->targetId;
        }

        $present = [];

        foreach ($groups as $group) {
            $table = $this->tableFor($group['kind'], $group['targetType']);

            if ($table === null) {
                /*
                 * 台帳の `target_type` は**過去の**取り込み先である。
                 * 型が消えていれば探しに行く先が無い。**推測で別の型を探さない。**
                 * 「見つからない」として返し、理由付けは呼ぶ側に任せる。
                 */
                continue;
            }

            [$name, $key] = $table;

            $ids = DB::table($name)
                ->whereIn($key, array_values($group['ids']))
                ->pluck($key);

            foreach ($ids as $id) {
                $present[$group['kind']->value."\0".$group['targetType']."\0".(string) $id] = true;
            }
        }

        return $present;
    }

    /**
     * @return array{0: string, 1: string}|null 表の名前と主キーの列名
     */
    private function tableFor(ImportKind $kind, string $targetType): ?array
    {
        if ($kind !== ImportKind::Content) {
            /*
             * **表の有無は確かめない。**
             *
             * 確かめて「無い」を返すと、`content_comments` の表そのものが無い
             * （module が外れている）ときに「対象が消えた」と報告することになる。
             * 直す先がまるで違うのに、同じ理由で案内してしまう。
             * 表が無ければ問い合わせが落ち、**基盤の問題として上へ抜ける**。
             */
            return [self::FIXED_TABLES[$kind->value], 'id'];
        }

        if (! $this->types->knows($targetType)) {
            return null;
        }

        /*
         * 表と主キーの名前だけを型から取る。**問い合わせは Query Builder で行う** —
         * モデル経由に引くと `SoftDeletes` の既定の絞り込みが働く。
         */
        $model = $this->types->newModel($targetType);

        return [$model->getTable(), $model->getKeyName()];
    }
}
