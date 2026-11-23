<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use RuntimeException;

/**
 * 保存済みの項目定義が、いま作った定義と食い違う（Phase 9.8 / STAGE 21D・SPEC §F.2.3 (d)）。
 *
 * 同じ fingerprint なのに中身が違うなら、hash の衝突・実装の誤り・行の破損の
 * どれかである。**どれであっても、その版は正しく復元できない。**
 *
 * だから気づかずに書き進めない。掴んだら transaction ごと巻き戻す。
 * 「版だけ書けなかった」も「定義だけ古い」も作らない。
 */
final class ManifestIntegrityError extends RuntimeException
{
    public function __construct(
        public readonly string $contentType,
        public readonly string $fingerprint,
    ) {
        parent::__construct(
            "投稿タイプ [{$contentType}] の項目定義が保存済みの内容と一致しません（{$fingerprint}）",
        );
    }

    /** 利用者・管理者へ見せてよい説明（原因の詳細は出さない） */
    public function safeMessage(): string
    {
        return '項目定義の記録が壊れているため、この操作を続けられません。管理者へ連絡してください。';
    }
}
