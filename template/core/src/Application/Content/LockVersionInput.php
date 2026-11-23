<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

/**
 * 送られてきた版の入力（Phase 9.8 / STAGE 21C 是正・OG11）。
 *
 * ## 「送っていない」と「壊れている」は違う
 *
 * 移行のあいだ、版を送らない相手は通す。だがそこへ **壊れた値を丸めて**しまうと、
 * `lock_version: "abc"` を送った相手が「送っていない」扱いで素通りし、
 * **上書き検出が働かないまま上書きする**。送ったつもりの側からは気づけない。
 *
 * だから 3 状態で持つ。
 *
 * | 状態 | 入力 | 扱い |
 * | --- | --- | --- |
 * | 未指定 | **キーが無い**（内部からの引数省略を含む） | 移行期間中は通す（監査に印を残す） |
 * | 指定 | 0 以上、かつ安全に扱える整数（`"3"` のような数字の文字列を含む） | 必ず検証する |
 * | 不正 | それ以外。**キーが在って `null` や `''` も含む** | **検証エラーで止める** |
 *
 * 空の値を「送っていない」に丸めない。送る側には版の欄があり、そこへ空を入れて
 * 送ってきたのである。黙って無視すると、**上書き検出が働いていると思ったまま上書きが通る**。
 *
 * HTML の form は数値も文字列で送るので、`"3"` を壊れているとは言わない。
 * 一方 `1.5` や `-1` は版として意味を持たないので、黙って切り捨てない。
 *
 * ## 大きさの上限
 *
 * 上限は **JavaScript が整数として正確に扱える範囲**（`Number.MAX_SAFE_INTEGER`）にする。
 * MCP も API も相手は JSON なので、これを超えると往復で値が変わり得る。
 * PHP 側の上限（`PHP_INT_MAX`）より手前なので、cast の丸めも同時に防げる。
 */
final class LockVersionInput
{
    private function __construct(
        public readonly ?int $version,
        public readonly bool $rejected,
    ) {}

    /**
     * 版として扱える最大値。
     *
     * `PHP_INT_MAX` ではなく **JavaScript が正確に扱える上限**にする。
     * MCP も API も JSON でやり取りするので、これを超えた数は往復で変わり得る。
     * 「送った版と比べたつもりが、別の数と比べていた」を作らない。
     */
    public const MAX = 9_007_199_254_740_991;

    /** キーが無かった（移行期間中は通る） */
    public static function unspecified(): self
    {
        return new self(null, false);
    }

    /**
     * 値が在った。形が合わなければ拒否として持ち帰る。
     *
     * **`null` も `''` も拒否である。** ここへ来ている時点で「キーは在った」ので、
     * 「送っていない」とは意味が違う。
     */
    public static function of(mixed $raw): self
    {
        if (is_int($raw)) {
            return $raw >= 0 && $raw <= self::MAX ? new self($raw, false) : new self(null, true);
        }

        // form からは文字列で来る。**数字だけ**を受ける（`1.5` も `-1` も `' 1'` も通さない）
        if (is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1) {
            /*
             * **cast してから判定しない。**
             *
             * `(int) "9223372036854775808"` は `PHP_INT_MAX` へ丸められるので、
             * cast のあとでは範囲内に見えてしまう。丸めた値で版を比べると、
             * 別の版なのに一致してしまうことが起き得る。文字列のまま比べる。
             */
            $normalized = ltrim($raw, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $limit = (string) self::MAX;

            $withinRange = strlen($normalized) < strlen($limit)
                || (strlen($normalized) === strlen($limit) && strcmp($normalized, $limit) <= 0);

            return $withinRange ? new self((int) $normalized, false) : new self(null, true);
        }

        return new self(null, true);
    }

    /**
     * 要求全体から読む。**キーの有無で分ける**（値が空かどうかではない）。
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromRequest(array $input, string $key = 'lock_version'): self
    {
        return array_key_exists($key, $input) ? self::of($input[$key]) : self::unspecified();
    }

    /** 既に整数として持っている呼び出し（内部経路・テスト）を素通しする */
    public static function normalize(self|int|null $input): self
    {
        return match (true) {
            $input instanceof self => $input,
            $input === null => self::unspecified(),
            default => self::of($input),
        };
    }

    /**
     * 拒否の理由。**内部情報も他の利用者の情報も入れない。**
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return ['lock_version' => ['版の指定が正しくありません（0 以上の整数で指定してください）']];
    }
}
