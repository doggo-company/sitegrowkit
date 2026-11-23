<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

use RuntimeException;

/**
 * 既存データが壊れていて安全に判定できない（Phase 9.3B / STAGE 2A CORRECTION）。
 *
 * **利用者入力の validation error とは別物**である。
 * - validation error … 入力を直せば通る
 * - data corruption … 入力の問題ではなく、DB の既存行が不整合
 *
 * Application Service は両者を区別し、corruption の場合は
 * create / update / reorder / delete を **安全側で拒否**する。
 *
 * メッセージは利用者に見せる日本語にし、SQL や接続情報を含めない。
 */
final class TaxonomyDataCorruptionException extends RuntimeException
{
    public static function missingParent(string $termId, string $parentId): self
    {
        return new self(
            "項目 [{$termId}] の親 [{$parentId}] が見つかりません。データが壊れている可能性があります。"
        );
    }

    public static function cycle(string $termId): self
    {
        return new self(
            "項目 [{$termId}] の親子関係が循環しています。データが壊れている可能性があります。"
        );
    }

    public static function crossTaxonomy(string $termId, string $parentId): self
    {
        return new self(
            "項目 [{$termId}] の親 [{$parentId}] が別の分類に属しています。データが壊れている可能性があります。"
        );
    }

    public static function unreadable(): self
    {
        return new self('分類の階層を読み取れませんでした。データベースの状態を確認してください。');
    }
}
