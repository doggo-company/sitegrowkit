<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use Illuminate\Database\Connection;
use Throwable;

/**
 * `content_types` を **1 リクエストに 1 回だけ**読む（Phase 9.7 / SPEC §J.4）。
 *
 * 面ごとに `where content_type_id = ?` を投げると、一覧を開くだけで
 * 型の数だけ query が飛ぶ。行数は型の数（多くて数十）なので、
 * **全部まとめて読んでメモ化する**ほうが速く、読み方も 1 つで済む。
 *
 * **boot では呼ばない。** route 登録とモデル登録は `SchemaRegistry::declared()`
 * （ファイルだけ）で完結しており、この resolver は要求を受けたあとにだけ動く。
 * container へは `scoped` で登録する（singleton にすると Octane で古い値が残る）。
 */
final class ContentTypeSettingsResolver
{
    public const TABLE = 'content_types';

    /** @var array<string, ContentTypeSettings>|null */
    private ?array $rows = null;

    private ContentTypeReadiness $readiness = ContentTypeReadiness::Indeterminate;

    /** 読みに行った回数（テストと診断のためだけに数える） */
    private int $loads = 0;

    public function __construct(private readonly Connection $connection) {}

    /** 設定を読めたか（SPEC §J.4） */
    public function readiness(): ContentTypeReadiness
    {
        $this->load();

        return $this->readiness;
    }

    /**
     * 1 つの型の設定。**行が無ければ既定を返す**（SPEC §J.7）。
     *
     * 読めなかったときも既定を返す — 一覧を落とさないためである。
     * 書き込みを止めるのは `ContentTypeLifecyclePolicy`（readiness を見る）の仕事。
     */
    public function for(string $contentTypeId): ContentTypeSettings
    {
        $this->load();

        return $this->rows[$contentTypeId] ?? ContentTypeSettings::defaultsFor($contentTypeId);
    }

    /**
     * 保存されている行すべて。**orphan の診断に使う**（定義が無いのに行だけある型）。
     *
     * @return array<string, ContentTypeSettings>
     */
    public function stored(): array
    {
        $this->load();

        return $this->rows ?? [];
    }

    /** 1 リクエスト内で読みに行った回数（重複 query が無いことをテストで固定する） */
    public function loadCount(): int
    {
        return $this->loads;
    }

    private function load(): void
    {
        if ($this->rows !== null) {
            return;
        }

        $this->loads++;

        try {
            if (! $this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
                $this->readiness = ContentTypeReadiness::Unmigrated;
                $this->rows = [];

                return;
            }

            $rows = [];

            foreach ($this->connection->table(self::TABLE)->get() as $record) {
                /** @var array<string, mixed> $row */
                $row = (array) $record;
                $settings = ContentTypeSettings::fromRow($row);

                if ($settings->contentTypeId === '') {
                    continue;
                }

                $rows[$settings->contentTypeId] = $settings;
            }

            $this->readiness = ContentTypeReadiness::Ready;
            $this->rows = $rows;
        } catch (Throwable) {
            // **理由は握りつぶすが、状態は握りつぶさない。**
            // 例外を投げると一覧ごと落ちて、復元も診断もできなくなる
            $this->readiness = ContentTypeReadiness::Indeterminate;
            $this->rows = [];
        }
    }
}
