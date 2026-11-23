<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use InvalidArgumentException;

/**
 * 取り込み先の分類の、**いまの状態**（Phase 9.14 / STAGE 27C・SPEC §C.3）。
 *
 * 分類の定義は code-managed の SSoT で、実行時に増やせない（§C.3 / 9.3B）。
 * つまり **plan の前提が「受け皿が既にある」ことに依存している**。
 * その受け皿の形が変われば、plan が言ったことは成り立たない。
 */
final class TargetTaxonomyState
{
    /** @param list<string> $associatedTypes この分類を宣言している型 */
    public function __construct(
        public readonly string $key,
        /** 実体の id。key が同じでも作り直されていれば別の受け皿である */
        public readonly string $id,
        public readonly string $status,
        /**
         * 階層を持つか。
         *
         * ここが変わると **「親から先に作る」の意味そのものが変わる**（§C.3）。
         * 階層でない分類に親子を持ち込むと、親が黙って捨てられる。
         */
        public readonly bool $hierarchical,
        /** 所有者。自由文字列にせず、既存の SSoT の enum をそのまま使う */
        public readonly TaxonomyManagedBy $managedBy,
        /**
         * 定義ファイルが DB へ同期済みか。
         *
         * 未同期なら受け皿は**実在しない**。plan が「入れられる」と言った前提が
         * 最初から崩れている。
         */
        public readonly bool $synced,
        public readonly array $associatedTypes,
    ) {
        /*
         * `status` は DB の文字列カラムで、`active` 以外は無効として読む既存契約
         * （`Taxonomy::isArchived()`）。ここで新しい enum を作ると DB とずれるので
         * 作らないが、**説明文を入れられる自由欄にはしない**。
         */
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $status) !== 1) {
            throw new InvalidArgumentException(
                "status は状態の名前だけを入れてください（分類 [{$key}] / 受け取った値: [{$status}]）。",
            );
        }

        if ($associatedTypes !== array_unique($associatedTypes)) {
            /*
             * ここは集合である。重複に意味は無いのに残すと、
             * **同じ意味なのに hash が変わる**。
             */
            throw new InvalidArgumentException(
                "分類 [{$key}] に同じ型が 2 回関連付けられています。",
            );
        }
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        $types = $this->associatedTypes;
        // sort() は添字を振り直すので、これで並びの揺れない list になる
        sort($types);

        return [
            'associatedTypes' => $types,
            'hierarchical' => $this->hierarchical,
            'id' => $this->id,
            'managedBy' => $this->managedBy->value,
            'status' => $this->status,
            'synced' => $this->synced,
        ];
    }
}
