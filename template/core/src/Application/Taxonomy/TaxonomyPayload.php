<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Taxonomy;

/**
 * content payload の `taxonomies` を **生のまま**運ぶ Value Object
 * （Phase 9.3B / STAGE 3B・SPEC §K.1）。
 *
 * ## なぜ配列で渡さないのか
 *
 * PATCH semantics（§K.2）では次の 3 状態が**別の意味**を持つ。
 *
 * | 入力 | 意味 |
 * | --- | --- |
 * | `taxonomies` キー自体が無い | assignment へ**一切触れない** |
 * | `taxonomies: null` | **validation error**（「全解除」ではない） |
 * | `taxonomies: {...}` | 指定された taxonomy key だけを全置換 |
 *
 * `is_array($input['taxonomies'] ?? null) ? ... : []` のような
 * adapter 側の正規化は、**この 3 状態を 1 つに潰してしまう**。
 * 「不正な値を送ったのに黙って何もしなかった」を作らないため、
 * adapter は**キーの有無だけ**を判定し、値は検証せずそのまま運ぶ。
 *
 * - `null`（VO そのものが渡されない） … キー未指定
 * - `TaxonomyPayload` … キーは存在する。`raw` が null / scalar でも**そのまま保持する**
 *
 * 値の検証は共通 Application 層（`ContentTaxonomyInput`）だけが行う。
 */
final readonly class TaxonomyPayload
{
    private function __construct(public mixed $raw) {}

    /** キーが存在したときに使う（値がどんな型でも保持する） */
    public static function of(mixed $raw): self
    {
        return new self($raw);
    }

    /**
     * 入力配列から取り出す。**キーが無ければ null**（= 未指定）。
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function fromInput(array $input, string $key = 'taxonomies'): ?self
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        return new self($input[$key]);
    }
}
