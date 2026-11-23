<?php

declare(strict_types=1);

namespace AgentCms\Core\Comments;

use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;

/**
 * 「ここにコメントを書けるか」の唯一の判定（Phase 9.13 / STAGE 26C・SPEC §C.2）。
 *
 * ## 二層 opt-in の順序を逆にしない
 *
 * ```
 * ① 型が ON か（ContentTypeLifecyclePolicy::commentsEnabled）
 * ② 記事が開いているか（content_comment_settings。行が無ければ型に従う）
 * ```
 *
 * **①が OFF なら、②が何であっても書けない。** 記事側で開いた覚えがあっても、
 * 型を止めたら止まる。逆にすると「型を OFF にしたのに一部の記事で書ける」になる。
 *
 * ## 「無い」と「閉じている」を分ける
 *
 * 型が OFF のときは **route ごと無い**（404）。記事が閉じているときは
 * **画面は出るが受け付けない**。既に承認済みのコメントは表示を維持する
 * （`WP_PARITY_BASELINE.md` §2.5）。呼ぶ側がこの 2 つを取り違えないよう、
 * 別のメソッドにしてある。
 *
 * ## 判定を 2 か所に置かない
 *
 * 型側の可否は 9.7 で入った `commentsEnabled()` が正である。
 * ここで `comments_enabled` を読み直さない。
 */
final class CommentGate
{
    public const SETTINGS_TABLE = 'content_comment_settings';

    public function __construct(
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly ConnectionInterface $connection,
    ) {}

    /** その型でコメント機能が動いているか（false なら公開面に口が無い） */
    public function enabledForType(string $contentType): bool
    {
        return $this->lifecycle->commentsEnabled($contentType);
    }

    /**
     * その記事が新規の書き込みを受け付けるか。
     *
     * **型が OFF なら常に false。** 記事側の設定を見る前に断つ。
     */
    public function acceptsNew(string $contentType, int $contentId): bool
    {
        if (! $this->enabledForType($contentType)) {
            return false;
        }

        return $this->entryOpen($contentType, $contentId) ?? true;
    }

    /**
     * 記事ごとの明示的な設定（**行が無ければ null = 型に従う**）。
     *
     * 既定値を全記事へ書き込まないので、「未設定」がそのまま残る。
     * 型の設定を変えたときに、過去の記事だけ古い値のまま、にならない。
     */
    public function entryOpen(string $contentType, int $contentId): ?bool
    {
        if (! Schema::hasTable(self::SETTINGS_TABLE)) {
            return null;
        }

        $row = $this->connection->table(self::SETTINGS_TABLE)
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->first();

        return $row === null ? null : (bool) $row->is_open;
    }

    /** 記事ごとの設定を書く（`null` で「型に従う」へ戻す） */
    public function setEntryOpen(string $contentType, int $contentId, ?bool $isOpen): void
    {
        $keys = ['content_type' => $contentType, 'content_id' => $contentId];

        if ($isOpen === null) {
            // **行を消して「未設定」へ戻す。** false を書いて代用しない
            $this->connection->table(self::SETTINGS_TABLE)->where($keys)->delete();

            return;
        }

        $this->connection->table(self::SETTINGS_TABLE)->updateOrInsert(
            $keys,
            ['is_open' => $isOpen, 'updated_at' => now()],
        );
    }
}
