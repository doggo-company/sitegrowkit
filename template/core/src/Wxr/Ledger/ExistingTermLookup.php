<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use AgentCms\Core\Taxonomy\TaxonomyResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * その綴りの項目が、こちらの分類に既にあるか（Phase 9.14 / STAGE 27D-1・SPEC §C.3）。
 *
 * ## ここが `reuse` の出どころ
 *
 * §C.3 は「既存の分類へ**項目だけ**足す」である。既にある項目には足しようがないので、
 * **作らずに使う**。作っていないものを取り消しで消してはならないので、
 * 台帳の所有権も持たない（`created_by_import = false`）。
 *
 * ## 保管された項目も「在る」と読む
 *
 * 保管（archive）は綴りを解放しない。在るのに無いと読むと、
 * 同じ綴りをもう 1 つ作ろうとして一意制約に弾かれる。
 * 保管された分類へ項目を足してよいかは別の話で、
 * それは `TargetStateResolver` が `taxonomy_archived` として既に止めている。
 *
 * ## 見つからないことと、確かめられないことを混ぜない
 *
 * 分類そのものが同期されていなければ `null` を返す。ここで理由を立てない。
 * その状態は `TargetStateResolver` が既に止めている（§C.3）。
 *
 * ## 1 行も書かない
 *
 * `plan` の約束は「DB を読むが書かない」である（§D.1）。
 */
final class ExistingTermLookup
{
    /** 1 度に確かめられる枠の数（`LedgerLookup` と同値） */
    public const MAX_WINDOW = 1000;

    private const TABLE = 'taxonomy_terms';

    public function __construct(private readonly TaxonomyResolver $taxonomies) {}

    /**
     * **渡された並びのまま**、既にある項目の id を返す。無ければ `null`。
     *
     * 保管されているかも**一緒に**返す。
     *
     * 別々に引くと、**在るかどうかと保管かどうかで別の瞬間の DB を見る**ことになる。
     * その間に保管されれば、「在るが保管ではない」という存在しない状態から plan を作る。
     *
     * @param  list<TermSlot>  $slots
     * @return list<array{id: string, archived: bool}|null>
     */
    public function find(array $slots): array
    {
        $this->assertWindow($slots);

        $found = $this->fetch($slots);

        $terms = [];

        foreach ($slots as $slot) {
            $terms[] = $found[$slot->signature()] ?? null;
        }

        return $terms;
    }

    /**
     * @param  list<TermSlot>  $slots
     */
    private function assertWindow(array $slots): void
    {
        if (count($slots) > self::MAX_WINDOW) {
            throw new InvalidArgumentException(
                '分類項目の枠は一度に '.self::MAX_WINDOW.' 件までしか確かめられません（'
                .count($slots).' 件渡されました）。',
            );
        }

        $seen = [];

        foreach ($slots as $slot) {
            $signature = $slot->signature();

            if (isset($seen[$signature])) {
                // 枠そのものは出さない（外来の綴りを含む）
                throw new InvalidArgumentException('同じ枠を二度渡すことはできません。');
            }

            $seen[$signature] = true;
        }
    }

    /**
     * 分類ごとに 1 回だけ引く。
     *
     * @param  list<TermSlot>  $slots
     * @return array<string, array{id: string, archived: bool}> 枠の signature => その項目
     */
    private function fetch(array $slots): array
    {
        /** @var array<string, array<string, string>> */
        $byTaxonomy = [];

        foreach ($slots as $slot) {
            $byTaxonomy[$slot->taxonomy][$slot->slug] = $slot->slug;
        }

        $found = [];

        foreach ($byTaxonomy as $taxonomy => $slugs) {
            $row = $this->taxonomies->find($taxonomy);

            if ($row === null) {
                // 同期されていない。ここでは理由を立てない（resolver の担当）
                continue;
            }

            $terms = DB::table(self::TABLE)
                ->where('taxonomy_id', (string) $row->getKey())
                ->whereIn('slug', array_values($slugs))
                ->get(['id', 'slug', 'archived_at']);

            foreach ($terms as $term) {
                $fields = (array) $term;

                $found[$taxonomy."\0".(string) $fields['slug']] = [
                    'id' => (string) $fields['id'],
                    'archived' => $fields['archived_at'] !== null,
                ];
            }
        }

        return $found;
    }
}
