<?php

declare(strict_types=1);

namespace App\Support\Document;

/**
 * 「左｜右」を 1 行ずつ並べた文字を、表の行にする（メニュー表・営業時間のブロック・UX 一周 2026-09-28）。
 *
 * 店の人が入れる欄は 1 つの複数行の文字にしてある。行を足す・消すのが、文字を打つだけで済む。
 * 区切りは全角の「｜」・半角の「|」・タブのどれでもよい（最初の 1 つで分ける）。
 * 区切りが無い行は、左だけの行にする（黙って捨てない）。空の行は飛ばす。
 */
final class PairRows
{
    /** @return list<array{0: string, 1: string}> */
    public static function parse(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[｜|\t]/u', $line, 2) ?: [$line];
            $rows[] = [trim($parts[0]), trim($parts[1] ?? '')];
        }

        return $rows;
    }
}
