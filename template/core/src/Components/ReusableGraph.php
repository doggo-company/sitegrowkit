<?php

declare(strict_types=1);

namespace AgentCms\Core\Components;

/**
 * 再利用 component の参照の連なり（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 再帰しない
 *
 * 参照は何段でも重ねられる。再帰で辿ると**鎖が長いだけで落ちる**ので、
 * 反復で辿り、上限を持つ（`Wxr\Plan\TermGraph` と同じ作法）。
 *
 * ## 循環と大きさは別々に見る
 *
 * - **循環**: A→B→A も A→B→C→A も拒否する（**推移閉包まで**）
 * - **大きさ**: 循環していなくても、**展開すると上限を超える**組み合わせがある。
 *   500 node の document が 500 node の実体を 2 つ参照すれば掛け算になる。
 *   深さも同じで、循環していない深い入れ子は循環検出では捕まらない
 *
 * ## draft と published の両方を辺として見る
 *
 * 循環の判定は**両方の和**で行う。片方だけを見ると、「下書きでは循環していないが
 * 公開すると循環する」（あるいはその逆）が通る。**どちらの状態でも循環しない**ことを
 * 求める方が、後から直せなくなる形を作らない。
 */
final class ReusableGraph
{
    /**
     * 辿る実体の上限。
     *
     * **これは循環の上限ではない**（循環は色分けで見る）。
     * 「循環していないが異常に多い」参照の連なりで、走査そのものが終わらなくなるのを防ぐ。
     */
    public const MAX_COMPONENTS = 1000;

    /**
     * 展開を打ち切る件数。**上限の判定ではなく、走査が止まらないことの保険**である。
     *
     * `expandedSize()` は循環があると終わらない。呼ぶ側が先に循環を潰す約束だが、
     * その約束が破れても**止まらないより打ち切る**方がよい。
     * 実際の上限（`DocumentValidator::MAX_NODES`）よりはるかに大きくしてあるので、
     * ここに当たった時点で結果は「上限超過」として扱われる。
     */
    private const MAX_EXPANDED_NODES = 100_000;

    /**
     * 指定した id から辿れる実体を**全部**読む（推移閉包）。
     *
     * @param  list<string>  $ids
     * @return array<string, ReusableComponent> id => 実体
     */
    public function load(array $ids): array
    {
        $loaded = [];
        $queue = array_values(array_unique($ids));

        while ($queue !== [] && count($loaded) < self::MAX_COMPONENTS) {
            $batch = array_values(array_filter(
                $queue,
                static fn (string $id): bool => ! array_key_exists($id, $loaded),
            ));

            $queue = [];

            if ($batch === []) {
                break;
            }

            /*
             * **1 回の問い合わせでまとめて読む。** id ごとに引くと、
             * 参照の段数だけ問い合わせが増える（N+1）。
             */
            $rows = ReusableComponent::query()->whereIn('id', $batch)->get();

            foreach ($batch as $id) {
                // 見つからなかったものも「読もうとした」印として残す（呼ぶ側が欠落を報せる）
                $loaded[$id] = $rows->firstWhere('id', $id);
            }

            foreach ($rows as $row) {
                foreach ($this->edges($row) as $next) {
                    if (! array_key_exists($next, $loaded)) {
                        $queue[] = $next;
                    }
                }
            }
        }

        return array_filter($loaded, static fn (?ReusableComponent $row): bool => $row instanceof ReusableComponent);
    }

    /**
     * その実体が指している先。**draft と published の両方**を辺として見る。
     *
     * @return list<string>
     */
    public function edges(ReusableComponent $component): array
    {
        // `draft_document` は NOT NULL。空かどうかは走査側が扱う
        $ids = ReusableReferences::instanceIds($component->draft_document);

        if (is_array($component->published_document)) {
            $ids = array_merge($ids, ReusableReferences::instanceIds($component->published_document));
        }

        return array_values(array_unique($ids));
    }

    /**
     * 循環に加わっている実体の id。
     *
     * **数えるのは「循環に加わっている実体」**である（参照している node の数ではない）。
     * 同じ循環を 2 つの node が指していても、報せるのは 1 つの循環である。
     *
     * @param  list<string>  $rootIds  document が直接指している id
     * @param  array<string, ReusableComponent>  $loaded
     * @return list<string>
     */
    public function cycleMembers(array $rootIds, array $loaded): array
    {
        $members = [];
        // 0 = 未訪問 / 1 = 訪問中（この道の上）/ 2 = 済み
        $state = [];

        foreach (array_unique($rootIds) as $root) {
            if (($state[$root] ?? 0) !== 0) {
                continue;
            }

            /*
             * **再帰しない。** 「これから辿る」と「戻ってきた」を同じ stack に積み、
             * 戻りで訪問中の印を降ろす（色分けの深さ優先探索）。
             */
            $stack = [[$root, false]];

            while ($stack !== []) {
                [$id, $leaving] = array_pop($stack);

                if ($leaving) {
                    $state[$id] = 2;

                    continue;
                }

                if (($state[$id] ?? 0) === 1) {
                    // 訪問中のものへ戻った = 循環
                    $members[$id] = true;

                    continue;
                }

                if (($state[$id] ?? 0) === 2) {
                    continue;
                }

                $state[$id] = 1;
                $stack[] = [$id, true];

                $component = $loaded[$id] ?? null;

                if (! $component instanceof ReusableComponent) {
                    continue;
                }

                foreach ($this->edges($component) as $next) {
                    $stack[] = [$next, false];
                }
            }
        }

        return array_keys($members);
    }

    /**
     * **展開した後**の深さと件数。
     *
     * 実体を差し込んだ木の大きさである。循環していると終わらないので、
     * **呼ぶ側が先に循環を潰しておく**こと（`cycleMembers()` が空であること）。
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, ReusableComponent>  $loaded
     * @return array{depth: int, nodes: int}
     */
    public function expandedSize(array $document, array $loaded): array
    {
        $root = is_array($document['root'] ?? null) ? $document['root'] : $document;

        $depth = 0;
        $nodes = 0;
        $stack = [[$root, 0]];

        while ($stack !== []) {
            [$node, $level] = array_pop($stack);

            if (! is_array($node)) {
                continue;
            }

            $nodes++;
            $depth = max($depth, $level);

            if ($nodes > self::MAX_EXPANDED_NODES) {
                // ここへ来るのは循環が残っている場合だけ。**止まらないより打ち切る**
                break;
            }

            $instanceOf = $node['instanceOf'] ?? null;
            $component = is_string($instanceOf) ? ($loaded[$instanceOf] ?? null) : null;

            if ($component instanceof ReusableComponent) {
                // 参照は自分の子を持たないので、実体の木を**同じ深さから**差し込む
                $stack[] = [$component->draft_document, $level];

                continue;
            }

            foreach ($node['children'] ?? [] as $child) {
                $stack[] = [$child, $level + 1];
            }
        }

        return ['depth' => $depth, 'nodes' => $nodes];
    }
}
