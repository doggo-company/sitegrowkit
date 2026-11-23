<?php

declare(strict_types=1);

namespace AgentCms\Core\Forms;

/**
 * フォームの状態（Phase 9.12 / STAGE 25B・SPEC §C.1）。
 *
 * **3 つを分けている理由。** 「下書き」と「受付終了」は外から見た結果が違う。
 * 下書きは**存在を見せない**（404）。受付終了は**画面は出すが送れない** —
 * 既に配ってしまった URL を踏んだ人へ、無言の 404 ではなく理由を返すためである。
 */
enum FormStatus: string
{
    /** 作りかけ。公開面からは存在しない */
    case Draft = 'draft';

    /** 受付中 */
    case Published = 'published';

    /** 受付を終了した。画面は出るが送信は拒む */
    case Closed = 'closed';

    /** 公開面に画面を出すか */
    public function isVisible(): bool
    {
        return $this !== self::Draft;
    }

    /** 送信を受け付けるか */
    public function acceptsSubmissions(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Published => '受付中',
            self::Closed => '受付終了',
        };
    }
}
