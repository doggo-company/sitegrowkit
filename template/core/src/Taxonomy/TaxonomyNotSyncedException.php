<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use RuntimeException;

/**
 * taxonomy が定義されているのに DB へ未同期のときに投げる（Phase 9.3B / STAGE 1・PV-4）。
 *
 * **runtime はここで明示的に失敗する。暗黙に作成して回復しない**（SY-17）。
 * 回復手段は `php artisan agentcms:taxonomy-sync` の明示実行だけである。
 */
final class TaxonomyNotSyncedException extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self(
            "分類 [{$key}] がデータベースへ同期されていません。"
            .'`php artisan agentcms:taxonomy-sync` を実行してください（自動では作成しません）。'
        );
    }

    public static function managedByMismatch(string $key, string $expected, string $actual): self
    {
        return new self(
            "分類 [{$key}] の管理区分が定義（{$expected}）とデータベース（{$actual}）で一致しません。"
            .'自動では修正しません。定義ファイルかデータベースのどちらが正しいかを確認してください。'
        );
    }
}
