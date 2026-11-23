<?php

declare(strict_types=1);

namespace AgentCms\Core\Schema;

use AgentCms\Core\Application\OperationClassification;

/**
 * Schema 変更の危険度分類（Phase 9.2B）。
 *
 * Content Type を運用したあとの変更は、既存データへの影響が大きく異なる。
 * Phase 9.1B の `OperationClassification` / `ConfirmationGate` / `AuditRecorder` へ
 * そのまま接続できる形にしてある。
 *
 * **AI が破壊的な schema 変更を勝手に実行しない**ための土台であり、
 * 実際の変更 UI / API は Phase 9.4（Content Type Admin Builder）で載せる。
 */
enum SchemaMutation: string
{
    /** 既存データに影響しない */
    case Safe = 'safe';

    /** 既存データの移行が要る（失敗すると不整合になる） */
    case MigrationRequired = 'migration_required';

    /** 既存データが失われ得る */
    case Destructive = 'destructive';

    public function label(): string
    {
        return match ($this) {
            self::Safe => 'そのまま適用できます',
            self::MigrationRequired => '既存データの移行が必要です',
            self::Destructive => '既存データが失われます',
        };
    }

    /** Application Service の分類へ写す */
    public function toOperationClassification(): OperationClassification
    {
        return match ($this) {
            self::Safe => OperationClassification::Write,
            self::MigrationRequired, self::Destructive => OperationClassification::Destructive,
        };
    }

    /** 実行前に人間の確認が要るか */
    public function requiresConfirmation(): bool
    {
        return $this !== self::Safe;
    }
}
