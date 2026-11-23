<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Ledger;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 台帳に既に何があるかを、まとめて 1 度だけ読む（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 1 件ずつ引かない
 *
 * 十年分は数万件になる。1 件ずつ引くと `plan` を出すだけで数万回の問い合わせが飛び、
 * **読むだけの操作が本番を遅くする**。まとめて引き、種類ごとに 1 回で済ませる。
 *
 * ## 窓を切らせる
 *
 * 上限が無いと、十年分の id を 1 つの `IN` へ並べることになる。
 * どこかで DB が黙って切るか、落ちる。呼ぶ側に窓を切らせ、
 * 超えたら**数えずに断る**（WXR の中身ではなく組み立ての誤りである）。
 *
 * ## 1 行も書かない
 *
 * `plan` の約束は「DB を読むが書かない」である（§D.1）。
 *
 * ## ここでは決めない
 *
 * 返すのは「台帳から見て今どうなっているか」だけである。
 * それを取り込みの理由へ落とすのも、対象の行が本当に在るかを確かめるのも、
 * この先の仕事である。**読むことと決めることを混ぜない。**
 */
final class LedgerLookup
{
    /** 1 度に引ける鍵の数 */
    public const MAX_WINDOW = 1000;

    private const TABLE = 'import_records';

    /**
     * 渡された鍵を**渡された並びのまま**返す。
     *
     * 並びが変わると、呼ぶ側はどの記事の結果かを id で引き直すことになり、
     * そのために id を配列で抱える。まとめて引いた意味が無くなる。
     *
     * @param  list<LedgerKey>  $keys
     * @return list<LedgerEntry>
     */
    public function inspect(array $keys): array
    {
        $this->assertWindow($keys);

        $rows = $this->fetch($keys);

        $entries = [];

        foreach ($keys as $key) {
            $entries[] = $this->entryFor($key, $rows[$key->lookupSignature()] ?? []);
        }

        return $entries;
    }

    /**
     * @param  list<LedgerKey>  $keys
     */
    private function assertWindow(array $keys): void
    {
        if (count($keys) > self::MAX_WINDOW) {
            throw new InvalidArgumentException(
                '台帳は一度に '.self::MAX_WINDOW.' 件までしか引けません（'.count($keys).' 件渡されました）。',
            );
        }

        $seen = [];

        foreach ($keys as $key) {
            $signature = $key->signature();

            if (isset($seen[$signature])) {
                /*
                 * 同じ鍵が 2 つあると、**同じ対象へ 2 回書く plan** ができる。
                 * 鍵そのものは出さない（外来値を含む）。
                 */
                throw new InvalidArgumentException('同じ鍵を二度渡すことはできません。');
            }

            $seen[$signature] = true;
        }
    }

    /**
     * **種類ごとに 1 回**だけ引く。取り込み先では絞らない。
     *
     * 絞ると、`--type` を変えて実行し直した記録が**見えなくなる**。
     * 見えなければ「まだ入っていない」と読み、同じ記事が 2 つの型へ並ぶ。
     *
     * 読んだ行は**その場で決まった形へ畳む**。
     *
     * driver によって `created_by_import` は真偽値でも整数でも返る。
     * 素のまま持ち回すと、比べる場所ごとに解釈が変わる。
     *
     * @param  list<LedgerKey>  $keys
     * @return array<string, list<array{targetType: string, targetId: string, batchId: string, createdByImport: bool}>>
     */
    private function fetch(array $keys): array
    {
        /** @var array<string, array<string, string>> */
        $byKind = [];

        foreach ($keys as $key) {
            $byKind[$key->kind->value][$key->externalId] = $key->externalId;
        }

        $rows = [];

        foreach ($byKind as $kind => $externalIds) {
            $found = DB::table(self::TABLE)
                ->where('kind', $kind)
                ->whereIn('external_id', array_values($externalIds))
                ->get(['external_id', 'target_type', 'target_id', 'batch_id', 'created_by_import']);

            foreach ($found as $row) {
                $fields = (array) $row;

                $rows[$kind."\0".(string) $fields['external_id']][] = [
                    'targetType' => (string) $fields['target_type'],
                    'targetId' => (string) $fields['target_id'],
                    'batchId' => (string) $fields['batch_id'],
                    'createdByImport' => (bool) $fields['created_by_import'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{targetType: string, targetId: string, batchId: string, createdByImport: bool}>  $rows
     *                                                                                                           その種類とその外部 id の行すべて（取り込み先は問わない）
     */
    private function entryFor(LedgerKey $key, array $rows): LedgerEntry
    {
        if ($rows === []) {
            return LedgerEntry::absent();
        }

        $matching = [];

        foreach ($rows as $row) {
            if ($row['targetType'] === $key->targetType) {
                $matching[] = $row;
            }
        }

        /*
         * **「1 行目を採る」はしない。**
         *
         * 別の取り込み先に入っている（`--type` を変えて実行し直した）、
         * 両方にある、同じ鍵の行が 2 つある — どれであっても、
         * 採った方が正しい保証が無いまま十年分がぶら下がる。
         */
        if (count($matching) !== 1 || count($rows) !== 1) {
            return LedgerEntry::conflict();
        }

        $row = $matching[0];

        return LedgerEntry::exact($row['targetId'], $row['batchId'], $row['createdByImport']);
    }
}
