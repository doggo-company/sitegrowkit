<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

/**
 * 1 件の割当先（Phase 9.5-A / ADR-0015 G4）。
 *
 * `kind` が enum に無い値（`page_template` や未知の文字列）のときは
 * `$kind === null` になり、**どの割当先にも一致しない**。
 * 生の値は `$rawKind` に残す — 一覧で「未対応の割当先」として
 * 見えるようにするためで、黙って消さない。
 */
final readonly class FieldGroupLocation
{
    public function __construct(
        public ?FieldGroupLocationKind $kind,
        public string $rawKind,
        public string $value,
    ) {}

    public static function fromRow(string $kind, string $value): self
    {
        return new self(FieldGroupLocationKind::tryFrom($kind), $kind, $value);
    }

    /** この割当先が指定の Content Type に一致するか */
    public function matchesContentType(string $schemaId): bool
    {
        return $this->kind === FieldGroupLocationKind::ContentType && $this->value === $schemaId;
    }
}
