<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 何件弾いたか（Phase 9.12 §F.4 / 9.13 で一般化）。
 *
 * ## 中身は残さない
 *
 * 弾いた書き込みの**本文は 1 文字も持たない**。スパムの本文を貯める理由が無く、
 * 貯めれば「保存していない」と言えなくなる。持つのは
 * **種類・対象・理由・日・件数**の 5 つだけである。
 *
 * ## 1 日 1 行へ畳む
 *
 * 1 送信 1 行にすると、bot に叩かれた分だけ表が伸びる。
 * 日単位の集計なら、行数は「対象数 × 理由 3 × 日数」で上から抑えられる。
 *
 * ## 数えられなくても書き込みは止めない
 *
 * この表が無い・書けない状態でも、判定そのものは成立する。
 * 数えられないことを理由に**正しい書き込みまで落とす**のは筋が悪い。
 */
final class SpamBlocks
{
    public const TABLE = 'spam_blocks';

    private const INTEGRITY_VIOLATION = '23000';

    public function record(SpamSubject $subject, SpamReason $reason): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $keys = [
            'kind' => $subject->kind,
            'subject_id' => $subject->subjectId(),
            'reason' => $reason->value,
            'blocked_on' => now()->toDateString(),
        ];

        if ($this->increment($keys)) {
            return;
        }

        try {
            DB::table(self::TABLE)->insert($keys + [
                'hits' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $e;
            }

            // 同じ日・同じ理由の行を別の要求が先に作った。数え直すだけでよい
            $this->increment($keys);
        }
    }

    /** その対象の記録を片付ける（対象そのものが消えたとき） */
    public function forget(SpamSubject $subject): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)
            ->where('kind', $subject->kind)
            ->where('subject_id', $subject->subjectId())
            ->delete();
    }

    /** @param array<string, mixed> $keys */
    private function increment(array $keys): bool
    {
        return DB::table(self::TABLE)
            ->where($keys)
            ->update([
                'hits' => DB::raw('hits + 1'),
                'updated_at' => now(),
            ]) > 0;
    }
}
