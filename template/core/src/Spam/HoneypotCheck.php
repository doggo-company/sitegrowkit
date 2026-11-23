<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

use Illuminate\Http\Request;

/**
 * 人には見えない項目（Phase 9.12 §F.2 / 9.13 で一般化）。
 *
 * ## 名前は「埋めたくなる」もの
 *
 * `honeypot` のような名前だと bot 側から避けられる。よくある入力欄の名前を使う。
 * **利用者の項目と衝突しない**ことは Field Group の key 規則が保証する
 * （`MACHINE_NAME` は `[a-z][a-z0-9_]*`。ここは `-` を含むので絶対に当たらない）。
 *
 * ## 弾いたことを相手に教えない
 *
 * 判定は「埋まっていたか」だけを返す。どう応答するかは呼ぶ側が決める。
 */
final class HoneypotCheck implements SpamCheck
{
    /** Field Group の key 規則（`-` を含められない）と構造的に衝突しない名前 */
    public const FIELD = 'contact-url';

    public function check(Request $request, SpamSubject $subject): SpamVerdict
    {
        $value = $request->input(self::FIELD);

        // 空文字も「埋まっていない」。ブラウザは空の input も送る
        $filled = is_string($value) ? trim($value) !== '' : $value !== null;

        return $filled ? SpamVerdict::block(SpamReason::Honeypot) : SpamVerdict::pass();
    }
}
