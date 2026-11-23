<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * `content_types` への書き込み（Phase 9.7 / STAGE 20C・SPEC §J.5）。
 *
 * **読み取りとは別の instance である。** 読み側（`ContentTypeSettingsResolver`）は
 * リクエスト内でメモ化しているので、同じ instance に書かせると
 * 「保存したのに古い値を返す」が起きる（Field Group が 9.5-B で踏んだのと同じ形）。
 *
 * **後勝ちにしない。** 更新はすべて `lock_version` の CAS で行い、
 * 期待した版と違えば 0 行更新になる。呼び出し側はそれを競合として扱う。
 */
final class ContentTypeSettingsWriter
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * 1 つの型の設定を保存する。
     *
     * @param  array<string, mixed>  $changes  列名 => 値（列は呼び出し側が allowlist 済み）
     * @return bool 適用できたら true、競合なら false（**何も変えない**）
     */
    public function save(string $contentTypeId, array $changes, int $expectedLockVersion): bool
    {
        if ($changes === []) {
            return true;
        }

        $exists = $this->connection->table(ContentTypeSettingsResolver::TABLE)
            ->where('content_type_id', $contentTypeId)
            ->exists();

        if (! $exists) {
            // 行が無い型は「まだ既定のまま」なので、期待版は 0 でなければならない
            if ($expectedLockVersion !== 0) {
                return false;
            }

            try {
                $this->connection->table(ContentTypeSettingsResolver::TABLE)->insert([
                    ...$changes,
                    'content_type_id' => $contentTypeId,
                    'lock_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // 存在確認と insert の間に他者が先に作った。**上書きしない**
                return false;
            }

            return true;
        }

        $affected = $this->connection->table(ContentTypeSettingsResolver::TABLE)
            ->where('content_type_id', $contentTypeId)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                ...$changes,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * いま DB に入っている版。**競合を報告するときに使う。**
     *
     * 読み側（`ContentTypeSettingsResolver`）はリクエスト内でメモ化しているので、
     * 「保存に失敗した時点の実際の版」はそちらから読めない（自分が書いた分がまだ映らない）。
     * 食い違いを説明するには、**その場で読み直す**しかない。
     */
    public function currentLockVersion(string $contentTypeId): int
    {
        $value = $this->connection->table(ContentTypeSettingsResolver::TABLE)
            ->where('content_type_id', $contentTypeId)
            ->value('lock_version');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * 管理メニューの並びを**全件まとめて**保存する（SPEC §J.2 / §J.5）。
     *
     * **1 件でも版が違えば全体を rollback する。** 部分的に並び替わった状態を作らない
     * — 半分だけ動いた並びは、利用者にはただの「壊れた並び」に見える。
     *
     * 競合は **例外で transaction を抜ける**。closure から `false` を返しても
     * `Connection::transaction()` は commit してしまうので、戻り値では戻せない。
     *
     * @param  array<string, array{order: int, lockVersion: int}>  $orders  内部 ID => 順位と期待版
     * @return bool 全件適用できたら true
     */
    public function reorder(array $orders): bool
    {
        if ($orders === []) {
            return true;
        }

        try {
            $this->connection->transaction(function () use ($orders): void {
                foreach ($orders as $contentTypeId => $target) {
                    if (! $this->save($contentTypeId, ['menu_order' => $target['order']], $target['lockVersion'])) {
                        throw new ContentTypeReorderConflict;
                    }
                }
            });
        } catch (ContentTypeReorderConflict) {
            return false;
        }

        return true;
    }
}
