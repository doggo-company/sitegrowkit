<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use InvalidArgumentException;

/**
 * 取り込み先の、いまの状態をまとめたもの（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * `plan` と `apply` の間で**ここが 1 つでも変われば、起きることが変わる**。
 * 型が archived になる、分類の定義が差し替わる、受け皿が未同期に戻る。
 * ファイルの hash と指定だけでは、その差が 1 つも見えない。
 */
final class TargetState
{
    /**
     * @param  list<TargetTypeState>  $types
     * @param  list<TargetTaxonomyState>  $taxonomies
     */
    public function __construct(
        private readonly array $types,
        private readonly array $taxonomies,
    ) {
        /*
         * **同じものを 2 回渡させない。** canonical では id / key を配列のキーに
         * するので、重複すると**後から来た方が黙って勝つ**。
         * 違う状態を渡したのに同じ値になるのは、この検査の意味を失わせる。
         */
        self::assertUnique(
            array_map(static fn (TargetTypeState $t): string => $t->id, $types),
            '型',
        );

        self::assertUnique(
            array_map(static fn (TargetTaxonomyState $t): string => $t->key, $taxonomies),
            '分類',
        );
    }

    /**
     * @param  list<string>  $names
     */
    private static function assertUnique(array $names, string $what): void
    {
        $duplicates = array_unique(array_diff_assoc($names, array_unique($names)));

        if ($duplicates !== []) {
            throw new InvalidArgumentException(
                "同じ{$what}が 2 つ以上あります（".implode(', ', $duplicates).'）。',
            );
        }
    }

    /**
     * **並びで揺れない形。** 型や分類を引いてきた順は、状態の一部ではない。
     * 揺れると、何も変わっていないのに再 plan を求めることになる。
     *
     * @return array{taxonomies: array<string, mixed>, types: array<string, mixed>}
     */
    public function canonical(): array
    {
        $types = [];

        foreach ($this->types as $type) {
            $types[$type->id] = $type->canonical();
        }

        $taxonomies = [];

        foreach ($this->taxonomies as $taxonomy) {
            $taxonomies[$taxonomy->key] = $taxonomy->canonical();
        }

        ksort($types);
        ksort($taxonomies);

        return [
            'taxonomies' => $taxonomies,
            'types' => $types,
        ];
    }
}
