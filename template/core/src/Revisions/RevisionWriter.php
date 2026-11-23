<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 版を書くのは **ここだけ**（Phase 9.8 / STAGE 21D・SPEC §F.2.3）。
 *
 * ## 経路ごとに書かない
 *
 * `CreateContent` / `UpdateContent` / ゴミ箱からの復元 / 版の復元 / baseline。
 * どれも「内容が変わった」を記録する点では同じである。別々に insert を書くと、
 * どれか 1 つで手順が抜け、**内容は変わったのに版が無い**（あるいはその逆）が生まれる。
 * それが起きると不変条件「現在の内容 = 最新の版」が崩れ、**復元が信用できなくなる**。
 *
 * ## 呼ぶ側の transaction の中で呼ぶ
 *
 * ここは自分で transaction を開かない。entry の更新と版の insert が
 * **同じ transaction**に入っていなければ、「entry だけ進んで版が無い」が作れてしまう。
 * 呼ぶ側が既に entry をロックして書いているので、その内側に入る。
 *
 * ## 手順（順序に意味がある）
 *
 * 1. いまの schema を canonical 化して fingerprint を出す
 * 2. 定義を materialize（無ければ入れる / 在れば**完全一致を確認**）
 * 3. **書き終えた entry** から snapshot を作る
 * 4. 直前の版と内容を比べ、**変わっていなければ書かない**（OG7）
 * 5. 版番号を採って insert
 *
 * 3 を先にやると「入っていない値の版」ができる。だから呼ぶ側は
 * entry と分類を書き終えてから呼ぶ。
 */
final class RevisionWriter
{
    public const TABLE = 'content_entry_revisions';

    public function __construct(private readonly SchemaManifestRepository $manifests) {}

    /**
     * 版を 1 件書く。**内容が前の版と同じなら書かずに null を返す。**
     *
     * @param  bool  $force  内容が同じでも書く（初版・版の復元のように「書いたこと」自体に意味がある場合）
     * @return int|null 書いた版番号。書かなかったら null
     *
     * @throws ManifestIntegrityError
     */
    public function write(
        ContentSchema $schema,
        string $contentType,
        Model $model,
        RevisionReason $reason,
        OperationContext $context,
        bool $force = false,
        RevisionKind $kind = RevisionKind::Manual,
    ): ?int {
        $fingerprint = $this->manifests->materialize($contentType, $schema);
        $contentId = (int) $model->getKey();

        $snapshot = RevisionSnapshot::of($schema, $contentType, $model);

        $latest = $this->latest($contentType, $contentId);

        if (! $force && $latest !== null && RevisionSnapshot::sameContent($latest['snapshot'], $snapshot)) {
            // 「保存を押しただけで中身が同じ」版を作らない（OG7 = (c)）
            return null;
        }

        $revision = ($latest['revision'] ?? 0) + 1;

        DB::table(self::TABLE)->insert([
            'content_type' => $contentType,
            'content_id' => $contentId,
            'revision' => $revision,
            'kind' => $kind->value,
            'snapshot' => (string) json_encode(
                $snapshot,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            'schema_fingerprint' => $fingerprint,
            'reason' => $reason->value,
            'created_interface' => $context->interface->value,
            'created_by' => $context->actorId,
            'created_at' => now(),
        ]);

        return $revision;
    }

    /**
     * **記事本体が一致しているべき版** = 最後の手動保存（Phase 9.15 / STAGE 28C 回帰修正）。
     *
     * ## `latest()` と問いが違う
     *
     * | 呼ぶ側 | 問い | 使うもの |
     * | --- | --- | --- |
     * | `write()` | **次の版番号は何番か** | `latest()`（絶対の最新） |
     * | 復元の守り | **本体はどの版と一致しているはずか** | ここ（最後の手動保存） |
     *
     * **混ぜてはいけない。** 採番にこちらを使うと、自動保存の版と番号が重複する。
     *
     * ## なぜ自動保存を基準にしないのか
     *
     * 自動保存は **わざと記事本体を書かない**（`AutosaveContent` 参照）。
     * だから基準にすると、自動保存が 1 件でもある記事は
     * **常に「食い違っている」ことになり、復元が丸ごと使えなくなる**。
     *
     * 守りの意図は「**記録に無い変更を黙って消さない**」ことであり、
     * その「記録」とは**手で保存した内容**である。
     *
     * @return array{revision: int, snapshot: array<string, mixed>, fingerprint: string, reason: string, created_at: string|null, created_interface: string, created_by: int|null, kind: string}|null
     */
    public function latestManual(string $contentType, int $contentId): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('kind', RevisionKind::Manual->value)
            ->orderByDesc('revision')
            ->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * **手で保存したあとに積まれた自動保存**があれば、その最新の 1 件
     * （Phase 9.15 / STAGE 28C・SPEC §C.3）。
     *
     * 「最後の手動保存より新しい自動保存」だけが復帰の対象である。
     * 手で保存したあとに何も書いていなければ、知らせることは何も無い。
     *
     * **知らせるだけで、勝手には戻さない。** 開いた瞬間に中身が変わるのは、
     * 編集する人にとって**事故と区別が付かない**。
     *
     * @return array{revision: int, created_at: string|null}|null
     */
    public function pendingAutosave(string $contentType, int $contentId): ?array
    {
        $base = fn (): Builder => DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId);

        $lastManual = (int) ($base()->where('kind', RevisionKind::Manual->value)->max('revision') ?? 0);

        $row = $base()
            ->where('kind', RevisionKind::Autosave->value)
            ->where('revision', '>', $lastManual)
            ->orderByDesc('revision')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'revision' => (int) $row->revision,
            'created_at' => $row->created_at === null ? null : (string) $row->created_at,
        ];
    }

    /**
     * その entry の最新の版。
     *
     * **呼ぶ側が entry をロックしている前提**である。ロックの外で採番すると、
     * 2 つの transaction が同じ番号を採る。DB 側の unique が最後の砦になるが、
     * そこまで行かせない。
     *
     * @return array{revision: int, snapshot: array<string, mixed>, fingerprint: string, reason: string}|null
     */
    public function latest(string $contentType, int $contentId): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->orderByDesc('revision')
            ->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * 指定した版。
     *
     * @return array{revision: int, snapshot: array<string, mixed>, fingerprint: string, reason: string}|null
     */
    public function find(string $contentType, int $contentId, int $revision): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('revision', $revision)
            ->first();

        return $row === null ? null : self::hydrate($row);
    }

    /** その entry に版が何件あるか（backfill と fail-closed の判定に使う） */
    public function count(string $contentType, int $contentId): int
    {
        return DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->count();
    }

    /**
     * @return array{revision: int, snapshot: array<string, mixed>, fingerprint: string, reason: string, created_at: string|null, created_interface: string, created_by: int|null, kind: string}
     */
    public static function hydrate(\stdClass $row): array
    {
        $snapshot = json_decode((string) $row->snapshot, true);

        return [
            'revision' => (int) $row->revision,
            // 壊れた行を空の内容として扱わない。読めないなら空配列で持ち帰り、使う側が止める
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'fingerprint' => (string) $row->schema_fingerprint,
            'reason' => (string) $row->reason,
            'kind' => (string) $row->kind,
            'created_at' => $row->created_at === null ? null : (string) $row->created_at,
            'created_interface' => (string) $row->created_interface,
            'created_by' => $row->created_by === null ? null : (int) $row->created_by,
        ];
    }
}
