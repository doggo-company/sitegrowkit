<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use AgentCms\Core\Wxr\Plan\PlanAction;

/**
 * 台帳が何と言っているか、そしてそれが**使えるか**（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 2 つを 1 つの値にしない
 *
 * 台帳が `exact` と言っていても、指している行がもう無い・ゴミ箱にある、
 * ということがある。このとき `entry` を書き換えて `conflict` にすると、
 * **返り値が台帳と食い違う** — 台帳にはそう書いていない。
 *
 * かといって `entry` だけを返すと、行動を決める側が
 * 「使えるかどうか」をもう一度自分で調べることになる。
 *
 * だから**台帳の言い分と、それが使えるかを別の欄で持つ**。
 */
final class LedgerVerdict
{
    public function __construct(
        /** 台帳が言っていることそのもの。**書き換えない** */
        public readonly LedgerEntry $entry,
        /**
         * その記録を頼りにしてよいか。
         *
         * 対象の行が無い・ゴミ箱にある場合は `false`。
         * そのときは既に止める理由が findings へ入っている。
         */
        public readonly bool $usable,
    ) {}

    /**
     * `apply` が何をするか。
     *
     * **止める場合も行動は積む。** 積まないと、報告した件数と行動の件数が
     * 合わなくなり、「何件が対象なのか」が読めなくなる。
     *
     * `Exact` を `skip_existing` にするのは V1 の決めごとである
     * （`PlanAction` の docblock — 台帳は「取り込みの後に人が直したか」を
     * 見分ける材料を持たないので、更新すると人の編集を黙って上書きする）。
     *
     * @param  bool  $existingTargetFound  **この取り込みが作らなくても、頼れる対象が既にある。**
     *                                     分類項目では 2 通りある — こちらの分類に同じ綴りの項目が
     *                                     既にある場合（§C.3）と、同じ取り込みの中の代表を指す
     *                                     alias である場合（重なった `wp:term_id`）。
     *                                     どちらも**この取り込みが作ったわけではない**ので、
     *                                     台帳の所有権を持たない。
     *                                     記事と添付は既存へ寄せないので、常に `false` である。
     *
     *                                     **既定値は置かない。** 呼び出しが 2 か所になった時点で、
     *                                     渡し忘れが `Create` として黙って通る形になる。
     *                                     渡すことを強いれば、書く人は毎回考える。
     */
    public function action(bool $existingTargetFound): PlanAction
    {
        if (! $this->usable) {
            return PlanAction::Conflict;
        }

        return match ($this->entry->state) {
            LedgerState::Absent => $existingTargetFound ? PlanAction::Reuse : PlanAction::Create,
            LedgerState::Exact => PlanAction::SkipExisting,
            LedgerState::Conflict => PlanAction::Conflict,
        };
    }

    /**
     * 頼ってよい対象の id。**使えない記録の id は渡さない。**
     *
     * 消えた対象・ごみ箱の対象の id を渡すと、受け取った側は
     * それが生きているものとして扱う。
     */
    public function usableTargetId(): ?string
    {
        return $this->usable && $this->entry->state === LedgerState::Exact
            ? $this->entry->targetId
            : null;
    }
}
