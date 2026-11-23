<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use HashContext;

/**
 * 「これから何が起きるか」を 1 つの値へ畳む（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## 本文も名前もメールも受け取らない
 *
 * 十年分を canonical な配列に組み立てると**メモリが持たない**。
 * そして本文・著者名・メールアドレスは**他人の個人情報**である。
 * 移行の副作用としてそれを台帳や画面へ写すのは、一番やってはいけない。
 *
 * だから受け取るのは「何を・どこへ・どうするか」の骨だけにしてある。
 * 「入れない運用にする」ではなく、**入れられない形**にしておく。
 *
 * ## 1 件ずつ畳む
 *
 * 溜めてから hash すると件数に比例してメモリが要る。`hash_update` で
 * 逐次畳むので、10 件でも 10 万件でも使う量は変わらない。
 *
 * ## 順番に意味がある
 *
 * 同じ集合でも順番が違えば、**途中で落ちたときに残るものが変わる**。
 * WXR に書かれている順で畳むので、同じファイルなら必ず同じ値になる。
 */
final class PlanActionDigest
{
    private readonly HashContext $context;

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct()
    {
        $this->context = hash_init('sha256');
    }

    /**
     * 1 件分を畳む。
     *
     * @param  string  $kind  content / term / comment / media
     * @param  string  $externalId  WXR 側の id（`wp:post_id` など）
     * @param  string  $targetType  こちらの投稿タイプ / 分類 key
     * @param  string|null  $targetId  既にある場合のこちらの id
     * @param  RelevantTargetState  $state  判定に効いた状態だけ（**中身は持てない形**）
     */
    public function add(
        string $kind,
        string $externalId,
        string $targetType,
        PlanAction $action,
        ?string $targetId,
        RelevantTargetState $state,
    ): void {
        $parts = [$kind, $externalId, $targetType, $action->value, $targetId ?? '', ...$state->canonical()];

        /*
         * **1 件の切れ目を先に書く。**
         *
         * 長さを前置しても、それは 1 つの値の中の境界しか守らない。
         * `RelevantTargetState` に項目が増えれば、
         * 「N 要素が 2 件」と「2N 要素が 1 件」が同じバイト列になり得る。
         * 件数が違うのに値が同じになるのは、この検査を無意味にする。
         */
        hash_update($this->context, 'r'.count($parts).';');

        /*
         * **長さを先に書く。** 素朴に区切り文字で繋ぐと、値の中に区切りが現れたときに
         * 境界が崩れ、`a|bc` と `ab|c` が同じ文字列になる。
         */
        foreach ($parts as $part) {
            hash_update($this->context, strlen($part).':'.$part);
        }

        $this->counts[$action->value] = ($this->counts[$action->value] ?? 0) + 1;
    }

    public function digest(): string
    {
        // `hash_copy` で確定させる。**読んだだけで畳めなくならない**ようにする
        return hash_final(hash_copy($this->context));
    }

    /**
     * 行動ごとの件数。**名前の昇順**で返す。
     *
     * 実行順で並ぶと、同じ結果が実行のたびに別物に見える。
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = $this->counts;
        ksort($counts);

        return $counts;
    }
}
