<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

use Illuminate\Database\Connection;

/**
 * Field Group の読み取り（Phase 9.5-A / ADR-0015）。
 *
 * **9.5-A は読むだけ。** 書き込みメソッドを 1 つも置かない（置かないことが仕様である）。
 *
 * ## テーブル未作成と DB 障害を区別する
 *
 * | 状況 | 挙動 |
 * | --- | --- |
 * | `field_groups` が無い（migration 前 / 機能未導入） | 空を返す = overlay 無しと**完全に同値** |
 * | DB へ到達できない / 権限が無い | **例外をそのまま投げる**。空として扱わない |
 *
 * 後者を握りつぶすと「DB が落ちている間だけ全 Content Type から
 * 項目が消える」という、最も気づきにくい壊れ方をする。
 *
 * ## SQL
 *
 * すべて query builder の binding を通す。文字列連結で SQL を組み立てない。
 *
 * ## memoization
 *
 * 1 instance につき 1 度だけ読む。**instance の寿命は container の scoped**
 * （`CoreServiceProvider`）が決める — 1 リクエスト内では使い回し、
 * リクエスト / job の境界で捨てられる。
 *
 * ここを singleton にすると、Octane や長寿命 worker で
 * **次のリクエストでも古い定義を返す**（PHP-FPM でしか
 * 「container は 1 リクエスト 1 つ」は成り立たない）。
 */
final class FieldGroupRepository
{
    public const TABLE = 'field_groups';

    public const LOCATIONS_TABLE = 'field_group_locations';

    /** @var list<FieldGroupRecord>|null */
    private ?array $records = null;

    private ?bool $installed = null;

    public function __construct(private readonly Connection $connection) {}

    /** テーブルが用意されているか（= 機能が導入済みか） */
    public function isInstalled(): bool
    {
        // hasTable が投げたら **そのまま伝える**。到達不能を「未導入」と読み替えない
        return $this->installed ??= $this->connection->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * 全 Field Group（draft / archived / 定義エラーも含む）。
     *
     * 一覧画面は全部を見せる必要があり、overlay 側が
     * `contributesFields()` で絞る。**ここで絞らない**
     * （絞ると「作ったのに一覧に出ない」が起きる）。
     *
     * @return list<FieldGroupRecord>
     */
    public function all(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        if (! $this->isInstalled()) {
            return $this->records = [];
        }

        // 並びは決定的にする（sort_order → key）。同じ DB なら常に同じ順序で重なる
        $rows = $this->connection->table(self::TABLE)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        // 1 件も無いのが通常運用。割当先を引きに行かない
        if ($rows->isEmpty()) {
            return $this->records = [];
        }

        $locations = $this->locations();

        $records = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = (array) $row;
            $id = (string) ($data['id'] ?? '');

            $records[] = FieldGroupRecord::fromRow($data, $locations[$id] ?? []);
        }

        return $this->records = $records;
    }

    /** ID で 1 件引く（メモ化済みの一覧から探すので追加のクエリは出ない） */
    public function find(string $id): ?FieldGroupRecord
    {
        foreach ($this->all() as $group) {
            if ($group->id === $id) {
                return $group;
            }
        }

        return null;
    }

    /**
     * field_group_id => 割当先。
     *
     * 1 クエリでまとめて引く（Field Group ごとに引くと N+1 になる）。
     *
     * 存在確認はしない。2 つのテーブルは同じ migration で作られるので、
     * 片方だけ無い状態は**データベースの異常**であり、
     * 「割当先が無い」と読み替えてはいけない（例外はそのまま伝わる）。
     *
     * @return array<string, list<FieldGroupLocation>>
     */
    private function locations(): array
    {
        $grouped = [];

        $rows = $this->connection->table(self::LOCATIONS_TABLE)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            $grouped[(string) ($data['field_group_id'] ?? '')][] = FieldGroupLocation::fromRow(
                (string) ($data['kind'] ?? ''),
                (string) ($data['value'] ?? ''),
            );
        }

        return $grouped;
    }
}
