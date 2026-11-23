<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

use Illuminate\Http\Request;

/**
 * bot / 濫用の検査 1 つ分（Phase 9.12 §F.1 / 9.13 で一般化）。
 *
 * **ベンダーを固定しない。** Turnstile を直接呼ぶ形にすると、
 * 別の判定へ替える・自前の判定を足す、が本体の書き換えになる。
 * 実装は `SpamProtection` が合成する。
 *
 * 設定が無い検査は**素通しを返す**（呼ぶ側に「効くかどうか」を判断させない）。
 */
interface SpamCheck
{
    public function check(Request $request, SpamSubject $subject): SpamVerdict;
}
