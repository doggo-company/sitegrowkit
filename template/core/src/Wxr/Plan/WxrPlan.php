<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use InvalidArgumentException;

/**
 * `plan` の結果（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## 使える plan と、使えない plan がある
 *
 * 取り込めないものが 1 件でもあれば、**hash を出さない**。
 * 「問題はあるが通した plan」を一度でも許すと、次からは全部それになる。
 * 出せば利用者はそれを `apply` へ貼るので、警告は読まれない。
 *
 * ## 作った時点の姿で固まる
 *
 * 集めている途中の `PlanFindings` / `PlanActionDigest` を持ち続けると、
 * **後から足された分で件数だけが変わり、hash は変わらない**状態が作れる。
 * 「見せた plan」と「照合する値」が食い違うので、ここで写して固める。
 */
final class WxrPlan
{
    /** @var array<string, array{total: int, shown: list<array{external_id: string, source_type: string}>, omitted: int}> */
    private readonly array $findings;

    /** @var array<string, int> */
    private readonly array $counts;

    public function __construct(
        PlanFindings $findings,
        PlanActionDigest $actions,
        /** 使えない plan では `null`（**警告つきの hash を作らない**） */
        private readonly ?string $fingerprint,
    ) {
        if ($findings->blocksPlan() && $fingerprint !== null) {
            throw new InvalidArgumentException(
                '取り込めないものがある plan に hash を付けることはできません。',
            );
        }

        if (! $findings->blocksPlan() && $fingerprint === null) {
            throw new InvalidArgumentException(
                '止める理由が無い plan には hash が要ります（apply が照合できません）。',
            );
        }

        if ($fingerprint !== null && preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('plan の hash は 64 桁の小文字 16 進です。');
        }

        // **写して固める**（後から足された分で件数だけが動かないように）
        $this->findings = $findings->toArray();
        $this->counts = $actions->counts();
    }

    /** `apply` へ渡せるか */
    public function isUsable(): bool
    {
        return $this->fingerprint !== null;
    }

    /** `apply` が照合する値。使えない plan では `null` */
    public function fingerprint(): ?string
    {
        return $this->fingerprint;
    }

    /**
     * 行動ごとの件数。
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * 見つけた問題（理由ごとに件数・明細・省略数）。
     *
     * 🔴 `PlanFindings::toArray()` と同じ形にする（`by_source?` を含む）。
     * ここだけ古い形のままだと、`by_source` を読む側で phpstan が
     * 「そんな鍵は無い」と止める（実測 2026-09-04・`flat_taxonomy_term_created`
     * の内訳を読もうとして踏んだ）。
     *
     * @return array<string, array{total: int, shown: list<array{external_id: string, source_type: string}>, omitted: int, by_source?: array<string, int>}>
     */
    public function findings(): array
    {
        return $this->findings;
    }
}
