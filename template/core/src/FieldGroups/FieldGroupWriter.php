<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

use AgentCms\Core\Application\OperationContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Field Group の書き込み（Phase 9.5-B / ADR-0016）。
 *
 * **読み取りは `FieldGroupRepository`、書き込みはここ**。読み取り側は
 * リクエスト内でメモ化されているので、同じ instance に書かせると
 * 「保存したのに古い値を返す」が起きる。
 *
 * ## 版はすべて残す（owner 決定 G5-(c)）
 *
 * create / update / archive / restore のたびに `field_group_revisions` へ
 * **保存後の状態**を 1 行残す。壊したときに戻せることを先に確保する。
 * 版の中身は定義そのもの（key / label / status / 並び順 / 項目 / 割当先）で、
 * 投稿の値は 1 つも含まない。
 *
 * ## SQL
 *
 * すべて query builder の binding を通す。文字列連結で組み立てない。
 */
final class FieldGroupWriter
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array{kind: string, value: string}>  $locations
     */
    public function create(
        string $key,
        string $label,
        array $fields,
        array $locations,
        OperationContext $context,
    ): string {
        $id = (string) Str::ulid();

        $this->connection->transaction(function () use ($id, $key, $label, $fields, $locations, $context): void {
            $this->connection->table(FieldGroupRepository::TABLE)->insert([
                'id' => $id,
                'key' => $key,
                'label' => $label,
                'sort_order' => $this->nextSortOrder(),
                // draft / publish の運用は Phase 2（owner 決定 G5）。作成は即 published
                'status' => FieldGroupStatus::Published->value,
                'fields' => $this->encode($fields),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->replaceLocations($id, $locations);
            $this->snapshot($id, $context);
        });

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array{kind: string, value: string}>  $locations
     */
    public function update(
        string $id,
        string $key,
        string $label,
        array $fields,
        array $locations,
        OperationContext $context,
    ): void {
        $this->connection->transaction(function () use ($id, $key, $label, $fields, $locations, $context): void {
            $this->connection->table(FieldGroupRepository::TABLE)
                ->where('id', $id)
                ->update([
                    'key' => $key,
                    'label' => $label,
                    'fields' => $this->encode($fields),
                    'updated_at' => now(),
                ]);

            $this->replaceLocations($id, $locations);
            $this->snapshot($id, $context);
        });
    }

    /**
     * 状態だけを変える（archive / restore）。
     *
     * **定義は 1 バイトも触らない。** 復元したときに元の項目がそのまま戻る。
     */
    public function changeStatus(string $id, FieldGroupStatus $status, OperationContext $context): void
    {
        $this->connection->transaction(function () use ($id, $status, $context): void {
            $this->connection->table(FieldGroupRepository::TABLE)
                ->where('id', $id)
                ->update(['status' => $status->value, 'updated_at' => now()]);

            $this->snapshot($id, $context);
        });
    }

    /**
     * 並び替え。
     *
     * 渡された順に 0 から振り直す。**一覧に出ている束をすべて渡す契約**で、
     * 部分的な並び替えは受け取らない（欠けた束の位置が黙って変わるため）。
     *
     * @param  list<string>  $orderedIds
     */
    public function reorder(array $orderedIds, OperationContext $context): void
    {
        $this->connection->transaction(function () use ($orderedIds, $context): void {
            foreach ($orderedIds as $position => $id) {
                $this->connection->table(FieldGroupRepository::TABLE)
                    ->where('id', $id)
                    ->update(['sort_order' => $position, 'updated_at' => now()]);
            }

            // 並び順は定義の一部なので、版にも残す
            foreach ($orderedIds as $id) {
                $this->snapshot($id, $context);
            }
        });
    }

    /** @param  list<array{kind: string, value: string}>  $locations */
    private function replaceLocations(string $id, array $locations): void
    {
        $this->connection->table(FieldGroupRepository::LOCATIONS_TABLE)
            ->where('field_group_id', $id)
            ->delete();

        if ($locations === []) {
            return;
        }

        $this->connection->table(FieldGroupRepository::LOCATIONS_TABLE)->insert(array_map(
            static fn (array $location): array => [
                'field_group_id' => $id,
                'kind' => $location['kind'],
                'value' => $location['value'],
            ],
            $locations,
        ));
    }

    /**
     * 保存後の状態を 1 版として残す。
     *
     * revision は束ごとの連番。`uq_field_group_revision` があるので、
     * 同時更新が重なれば片方が競合で落ちる（黙って上書きしない）。
     */
    private function snapshot(string $id, OperationContext $context): void
    {
        $group = (array) $this->connection->table(FieldGroupRepository::TABLE)->where('id', $id)->first();

        if ($group === []) {
            return;
        }

        $locations = $this->connection->table(FieldGroupRepository::LOCATIONS_TABLE)
            ->where('field_group_id', $id)
            ->orderBy('id')
            ->get(['kind', 'value'])
            ->map(static fn (object $row): array => ['kind' => $row->kind, 'value' => $row->value])
            ->all();

        $next = (int) $this->connection->table('field_group_revisions')
            ->where('field_group_id', $id)
            ->max('revision') + 1;

        $this->connection->table('field_group_revisions')->insert([
            'field_group_id' => $id,
            'revision' => $next,
            'snapshot' => $this->encode([
                'key' => $group['key'],
                'label' => $group['label'],
                'status' => $group['status'],
                'sort_order' => $group['sort_order'],
                // 文字列で返る driver と配列で返る driver があるので、形をそろえてから入れる
                'fields' => is_string($group['fields'])
                    ? json_decode($group['fields'], true, 32, JSON_THROW_ON_ERROR)
                    : $group['fields'],
                'locations' => $locations,
            ]),
            'actor_id' => $context->actorId,
            'actor_label' => mb_substr($context->actorLabel, 0, 120),
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>|list<mixed>  $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function nextSortOrder(): int
    {
        return (int) $this->connection->table(FieldGroupRepository::TABLE)->max('sort_order') + 1;
    }
}
