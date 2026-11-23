<?php

declare(strict_types=1);

namespace AgentCms\Core\Spam;

/**
 * 何で弾いたか（Phase 9.12 §F.4 / 9.13 で一般化）。
 *
 * **理由ごとに応答が違う。** 一括で「エラー」にしない。
 * どう応答するかは呼ぶ側（controller）が決める — フォームは 429 を返すが、
 * コメントは `spam` へ落として拾い直せるようにする、というように分かれる。
 */
enum SpamReason: string
{
    case Honeypot = 'honeypot';
    case RateLimit = 'rate_limit';
    case Bot = 'bot';

    public function label(): string
    {
        return match ($this) {
            self::Honeypot => '見えない項目が埋まっていた',
            self::RateLimit => '短時間に送信が集中した',
            self::Bot => 'ボット確認に通らなかった',
        };
    }
}
