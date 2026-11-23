<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

/**
 * Result meta と Audit metadata の組み立て（Phase 9.3B / STAGE 3B・§14）。
 *
 * **要約だけを載せる。** term 名・入力 payload 全体・機微情報は入れない。
 * adapter ごとに別の形を作らないため 1 箇所へ集約する。
 */
final class ContentAudit
{
    /**
     * 成功 Result の meta。**既存の `type` を消さない**。
     *
     * @return array<string, mixed>
     */
    public static function meta(string $type, AssignmentOutcome $outcome): array
    {
        $meta = ['type' => $type];

        if (! $outcome->touched) {
            return $meta;
        }

        $meta[ContentTaxonomyInput::RESPONSE_KEY] = $outcome->summary();

        if ($outcome->warnings !== []) {
            $meta['warnings'] = $outcome->warnings;
        }

        return $meta;
    }

    /**
     * Audit metadata。件数の要約だけ。
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function metadata(string $type, int $id, array $fields, AssignmentOutcome $outcome): array
    {
        $metadata = ['type' => $type, 'id' => $id, 'fields' => $fields];

        if ($outcome->touched) {
            $metadata['added'] = $outcome->added;
            $metadata['kept'] = $outcome->kept;
            $metadata['removed'] = $outcome->removed;
            $metadata[ContentTaxonomyInput::RESPONSE_KEY] = $outcome->byKey;
        }

        return $metadata;
    }
}
