<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

/**
 * assignment 差分の結果（Phase 9.3B / STAGE 3B・SPEC §I.6）。
 *
 * **Audit と Result meta には要約だけを載せる。** term 名・入力全体は含めない。
 */
final readonly class AssignmentOutcome
{
    /**
     * @param  array<string, array{added: int, kept: int, removed: int}>  $byKey
     * @param  list<string>  $warnings
     */
    private function __construct(
        public bool $touched,
        public int $added,
        public int $kept,
        public int $removed,
        public array $byKey,
        public array $warnings,
    ) {}

    /** payload に taxonomies が無かった（assignment へ触れていない） */
    public static function untouched(): self
    {
        return new self(false, 0, 0, 0, [], []);
    }

    /**
     * @param  array<string, array{added: int, kept: int, removed: int}>  $byKey
     * @param  list<string>  $warnings
     */
    public static function of(array $byKey, array $warnings): self
    {
        $added = $kept = $removed = 0;

        foreach ($byKey as $counts) {
            $added += $counts['added'];
            $kept += $counts['kept'];
            $removed += $counts['removed'];
        }

        return new self(true, $added, $kept, $removed, $byKey, $warnings);
    }

    /**
     * Result meta / Audit metadata 用の要約。
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'added' => $this->added,
            'kept' => $this->kept,
            'removed' => $this->removed,
            'byKey' => $this->byKey,
        ];
    }
}
