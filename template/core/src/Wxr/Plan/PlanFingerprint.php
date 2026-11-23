<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

/**
 * `apply` が照合する 1 つの値（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## この値を人が運ぶ
 *
 * `plan` は DB へ 1 行も書かない。だから `apply` が「最新の plan」を自分で
 * 計算し直して自分と比べても、**比べているのは apply が今そう思っていること**で、
 * 利用者が画面で見たものではない。間でファイルが差し替わっても、
 * 型が archived になっても、それでは気づけない。
 *
 * `plan` がこの値を出し、`apply` が `--plan-hash` で受け取り、
 * その場で計算し直した値と突き合わせる。**一致しなければ 1 行も書かない。**
 *
 * ## 3 つの材料
 *
 * | 材料 | 変わると |
 * | --- | --- |
 * | 契約の版 | 判定の規則が変わった。古い意味で apply させない |
 * | 何を何処へ入れるか（`PlanIdentity`） | ファイルか指定が違う |
 * | 取り込み先と、これから起きること | 同じ指示でも結果が変わる |
 */
final class PlanFingerprint
{
    /**
     * 判定の規則の版。
     *
     * **規則を変えたら必ず上げる。** 上げ忘れると、規則を変えたあとに
     * 変える前の意味で作られた hash が通り、利用者が見たのとは違うことが起きる。
     */
    public const CONTRACT_VERSION = 'wxr-plan/1';

    public static function of(
        PlanIdentity $identity,
        TargetState $target,
        PlanActionDigest $actions,
    ): string {
        return hash('sha256', self::canonical($identity, $target, $actions));
    }

    /**
     * hash の元。**個人情報も本文も入らない** —
     * 行動は畳んだ digest と件数だけで、明細はここに入らない。
     */
    public static function canonical(
        PlanIdentity $identity,
        TargetState $target,
        PlanActionDigest $actions,
    ): string {
        return (string) json_encode(
            [
                'actions' => [
                    'counts' => $actions->counts(),
                    'digest' => $actions->digest(),
                ],
                'contract' => self::CONTRACT_VERSION,
                'identity' => json_decode($identity->canonical(), true, 512, JSON_THROW_ON_ERROR),
                'target' => $target->canonical(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
