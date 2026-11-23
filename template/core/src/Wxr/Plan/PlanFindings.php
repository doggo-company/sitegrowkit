<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use InvalidArgumentException;

/**
 * 取り込めないものを**全部**集める（Phase 9.14 / STAGE 27C・SPEC §A.3）。
 *
 * ## 1 件目で止めない
 *
 * 見つけた順に 1 件ずつ例外にすると、利用者は**直しては実行し、
 * また別の理由で止まる**を何十回も繰り返すことになる。
 * 十年分の移行でそれをやらせるのは、実質的に移行させないのと同じである。
 *
 * ## 黙って省略しない
 *
 * 数千件を全部並べても読めない。だが先頭だけ出して総数を伏せると、
 * **利用者は出た分を直せば終わると思う**。
 * 出す明細は絞り、**総数と省略した件数は必ず言う**。
 *
 * ## 個人情報を持たない
 *
 * 明細は画面にも台帳にも出る。だから持つのは外部 id と元の種類と理由だけで、
 * 本文も著者名もメールアドレスもここへは来ない。
 */
final class PlanFindings
{
    /** 理由ごとに明細を出す上限 */
    public const DETAIL_CAP = 50;

    /**
     * 明細に出す外部 id の長さの上限（台帳の桁と同じ）。
     *
     * これを超える id は**生の値を持たない**。「長すぎる」を報告するために
     * 長い値を 50 件抱えるのは本末転倒で、台帳に収まらない id は
     * 本文級の長さで来ることがある。
     */
    public const EXTERNAL_ID_DISPLAY_MAX = 64;

    /** @var array<string, list<array{external_id: string, source_type: string}>> */
    private array $shown = [];

    /** @var array<string, int> */
    private array $totals = [];

    /**
     * 理由ごとの内訳。**渡した理由にだけ付く。**
     *
     * 渡さない理由の出力へ空の内訳を足すと、何も変わっていないのに
     * 出力が変わる。読む側の期待値を動かさない。
     *
     * @var array<string, array<string, int>>
     */
    private array $breakdown = [];

    private bool $blocks = false;

    /**
     * @param  string|null  $bySource  内訳の鍵（例: 元の公開状態）。
     *                                 **件数だけを数える** — id は `shown` が持つので二重に持たない
     */
    public function add(PlanIssue $reason, string $externalId, string $sourceType, ?string $bySource = null): void
    {
        /*
         * **明細に何でも入れさせない。**
         *
         * ここは画面へ 1 行 1 件で出る。改行や端末の制御文字を通すと、
         * 表示が崩れるだけでなく**本文をそのまま流し込む経路**になる。
         */
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $externalId) === 1) {
            throw new InvalidArgumentException('外部 id に改行や制御文字は入れられません。');
        }

        if (preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $sourceType) !== 1) {
            throw new InvalidArgumentException(
                'source_type は WXR の識別子の形だけを入れてください。',
            );
        }

        if ($bySource !== null && preg_match('/^[A-Za-z0-9_.\-:]{1,64}$/', $bySource) !== 1) {
            /*
             * **内訳の鍵は画面にも台帳にも出る。**
             * 形を求めないと、ここが本文の流れ込む経路になる。
             */
            throw new InvalidArgumentException(
                '内訳の鍵には識別子の形だけを入れてください。',
            );
        }

        $key = $reason->value;

        $this->totals[$key] = ($this->totals[$key] ?? 0) + 1;

        if ($bySource !== null) {
            // 明細は絞っても、**内訳は数え続ける**（§A.3）
            $this->breakdown[$key][$bySource] = ($this->breakdown[$key][$bySource] ?? 0) + 1;
        }

        // 明細は捨てても、**数えるのはやめない**
        if (count($this->shown[$key] ?? []) < self::DETAIL_CAP) {
            $this->shown[$key][] = [
                'external_id' => self::displayable($externalId),
                'source_type' => $sourceType,
            ];
        }

        if ($reason->blocksPlan()) {
            $this->blocks = true;
        }
    }

    /**
     * 明細へ出せる形。**収まらない値は生のまま持たない。**
     *
     * 印は中身から作るので、同じ値なら同じ印になる（別物と取り違えない）。
     */
    private static function displayable(string $externalId): string
    {
        if (strlen($externalId) <= self::EXTERNAL_ID_DISPLAY_MAX) {
            return $externalId;
        }

        return 'sha256:'.substr(hash('sha256', $externalId), 0, 16).':'.strlen($externalId).'B';
    }

    public function isEmpty(): bool
    {
        return $this->totals === [];
    }

    /**
     * plan の hash を出してよいか。
     *
     * **警告つきで出さない。** 出せば利用者はそれを `apply` へ貼る。
     * 「問題はあるが通した plan」を一度でも許すと、次からは全部それになる。
     */
    public function blocksPlan(): bool
    {
        return $this->blocks;
    }

    /**
     * 理由ごとのまとめ。**理由の名前の昇順**で返す。
     *
     * 見つけた順に並ぶと、同じ結果が実行のたびに別物に見える。
     *
     * @return array<string, array{total: int, shown: list<array{external_id: string, source_type: string}>, omitted: int, by_source?: array<string, int>}>
     */
    public function toArray(): array
    {
        $summary = [];

        foreach ($this->totals as $key => $total) {
            $shown = $this->shown[$key] ?? [];

            $summary[$key] = [
                'total' => $total,
                'shown' => $shown,
                'omitted' => $total - count($shown),
            ];

            if (isset($this->breakdown[$key])) {
                // 内訳も**名前順**。見つけた順に並ぶと同じ結果が別物に見える
                $bySource = $this->breakdown[$key];
                ksort($bySource);

                $summary[$key]['by_source'] = $bySource;
            }
        }

        ksort($summary);

        return $summary;
    }
}
