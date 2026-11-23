<?php

declare(strict_types=1);

namespace AgentCms\Core\Trash;

use RuntimeException;

/**
 * ゴミ箱の対応状況を確かめられなかった（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2）。
 *
 * **空の一覧を返す代わりに投げる。** 0 件を 200 で返すと、公開サイトは
 * 「記事が 1 件も無いページ」を配信する。それは CDN にもブラウザにも残るし、
 * 利用者には「サイトの中身が全部消えた」と見える。読めないなら読めないと言う。
 *
 * 面ごとの変換は 1 か所で行う（HTTP は 503 + `Retry-After`、MCP は
 * service-unavailable）。**例外の中身をそのまま外へ出さない。**
 */
final class TrashReadinessUnavailable extends RuntimeException
{
    public function __construct(public readonly string $contentType)
    {
        // 画面へ出るのは TrashReadiness::message()。ここは監査・ログ向けの短い記述
        parent::__construct("投稿タイプ [{$contentType}] のゴミ箱対応状況を確認できませんでした");
    }

    /** 管理者・利用者へ見せてよい説明（原因の詳細は含めない） */
    public function safeMessage(): string
    {
        return (string) TrashReadiness::Indeterminate->message();
    }
}
