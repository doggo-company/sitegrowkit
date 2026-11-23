<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\PlanIssue;
use InvalidArgumentException;

/**
 * 台帳と現実を突き合わせて、決められないものを言う（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 理由を 1 つにまとめない
 *
 * 「別の型に入っている」と「対象が消えている」は、**次にやることが違う**。
 * 前者は `--type` の指定を戻すか先の batch を取り消す、後者は台帳を片付ける。
 * まとめると、片方の手順でもう片方を直そうとすることになる。
 *
 * ## ここでは決めない
 *
 * 何を作り、何を飛ばし、何を使い回すかは、この先の仕事である（`PlanAction`）。
 * ここは**止める理由を集めて、台帳が言っていることをそのまま返す**。
 * 対象が消えていても返り値は `Exact` のままにする — 台帳にはそう書いてあり、
 * それを黙って `Conflict` へ落とすと**返り値が台帳と食い違う**。
 * 止めるのは findings の仕事で、`blocksPlan()` が既に真になっている。
 *
 * ## 1 件目で止めない
 *
 * 見つけた順に投げると「最初の 1 件」しか出せず、直しては実行しを繰り返させる（§A.3）。
 */
final class LedgerReconciler
{
    public function __construct(
        private readonly LedgerLookup $ledger,
        private readonly TargetRowLookup $targets,
        private readonly TrashedTargetLookup $trash,
    ) {}

    /**
     * @param  list<LedgerKey>  $keys
     * @return list<LedgerVerdict> 入力と同じ並び
     */
    public function reconcile(array $keys, PlanFindings $findings): array
    {
        if (count($keys) > LedgerLookup::MAX_WINDOW) {
            /*
             * **自分でも数える。**
             *
             * いまは必ず `inspect()` を通るので実質守られているが、
             * それは呼び出しの順番に頼った守りである。別の経路から呼ばれた瞬間に
             * 効かなくなるので、ここでも切る。
             */
            throw new InvalidArgumentException(
                '台帳の照合は一度に '.LedgerLookup::MAX_WINDOW.' 件までです（'
                .count($keys).' 件渡されました）。',
            );
        }

        $entries = $this->ledger->inspect($keys);

        /** @var list<int> 対象の行を確かめる必要がある位置 */
        $checking = [];
        $refs = [];

        foreach ($entries as $at => $entry) {
            if ($entry->state === LedgerState::Conflict) {
                $findings->add(
                    PlanIssue::LedgerConflict,
                    $keys[$at]->externalId,
                    $keys[$at]->kind->value,
                );

                continue;
            }

            if ($entry->state !== LedgerState::Exact || $entry->targetId === null) {
                continue;
            }

            /*
             * **同じ行を二度渡さない。** 別の外部 id が同じ対象を指すこと
             * （重なった定義）はあり得るので、位置ではなく行で畳む。
             */
            $ref = new TargetRef($keys[$at]->kind, $keys[$at]->targetType, $entry->targetId);
            $signature = $ref->signature();

            $refs[$signature] ??= $ref;
            $checking[$at] = $signature;
        }

        [$absent, $trashed] = $this->presence($refs);

        /** @var array<int, true> 頼れないと分かった位置 */
        $broken = [];

        foreach ($checking as $at => $signature) {
            if (isset($absent[$signature])) {
                $findings->add(
                    PlanIssue::LedgerTargetMissing,
                    $keys[$at]->externalId,
                    $keys[$at]->kind->value,
                );

                $broken[$at] = true;

                continue;
            }

            if (isset($trashed[$signature])) {
                /*
                 * **消滅と混ぜない。** 直す先が違う。
                 * ゴミ箱は人へ返す（取り消しの跡か人の判断か決められない）、
                 * 消滅は台帳を片付ける。
                 */
                $findings->add(
                    PlanIssue::LedgerTargetTrashed,
                    $keys[$at]->externalId,
                    $keys[$at]->kind->value,
                );

                $broken[$at] = true;
            }
        }

        $verdicts = [];

        foreach ($entries as $at => $entry) {
            $verdicts[] = new LedgerVerdict($entry, ! isset($broken[$at]));
        }

        return $verdicts;
    }

    /**
     * **無かった行**と**ゴミ箱に入っていた行**を集める。
     *
     * 在った行の一覧を返して後から引き当てる形にすると、
     * 引き当てに失敗したときの値を決めることになる。
     * 決め方を間違えれば「消えているのに在る」と読む。
     *
     * 物理的な存在とゴミ箱は**別々に引く**。1 つの問い合わせにまとめると、
     * 「無い」と「入っている」が同じ答えから作られることになり、
     * どちらの意味で使われているのか呼ぶ側から見えなくなる。
     *
     * @param  array<string, TargetRef>  $refs  signature => 指す先
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function presence(array $refs): array
    {
        if ($refs === []) {
            return [[], []];
        }

        $signatures = array_keys($refs);
        $list = array_values($refs);

        $absent = [];

        foreach ($this->targets->exists($list) as $at => $exists) {
            if (! $exists) {
                $absent[$signatures[$at]] = true;
            }
        }

        $trashed = [];

        foreach ($this->trash->trashed($list) as $at => $inTrash) {
            if ($inTrash) {
                $trashed[$signatures[$at]] = true;
            }
        }

        return [$absent, $trashed];
    }
}
