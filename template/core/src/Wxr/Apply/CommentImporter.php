<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Apply;

use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Plan\CommentFields;
use AgentCms\Core\Wxr\Plan\CommentTree;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\WxrComment;
use AgentCms\Core\Wxr\WxrItem;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * コメントを入れる（Phase 9.14 / STAGE 27D-5b・SPEC §C.4・§E.1・§G）。
 *
 * ## 9.13 の表へ直接入れる
 *
 * §E.1 は投稿と分類項目に「既存の Service を通す」を課しているが、
 * **コメントだけは明示的に例外**である。公開面の受付は spam 判定と rate limit と
 * 一体になっていて、移行から切り離せない。
 *
 * そして移行は**人の操作ではない**。spam 判定を通すと、
 * **十年前に人が承認したコメントを、いま機械が spam に落とす**。
 * rate limit は「1 人が短時間に何度も書く」を止めるもので、
 * 1 ファイルから一括で入れる移行では**移行が途中で止まるだけ**である。
 *
 * だから通さない。代わりに**元の状態をそのまま写す**。
 *
 * ## 記事の状態でコメントを決めない
 *
 * 半端に終わった取り込みを続けるとき、「記事は入っているがコメントは入っていない」が
 * 普通に起こる。記事が済んでいるからとまとめて飛ばすと、**その分が黙って落ちる**。
 *
 * ## 台帳に無いコメントを作らない
 *
 * 本体と台帳は**同じ transaction**で書く。分けると、その間に落ちたときに
 * 台帳に無いコメントが残る。台帳に無いものは取り消しから見えないので、
 * **消せないコメントが十年分**できる。
 */
final class CommentImporter
{
    /** 台帳での行き先（`target_type`）。コメントは投稿タイプを持たない */
    public const TARGET = 'comment';

    public function __construct(
        private readonly ItemTarget $targets,
        private readonly LedgerReconciler $ledger,
    ) {}

    /**
     * 入れて、**作った件数**を返す。
     *
     * @return array<string, int>
     */
    public function import(WxrReader $reader, ImportOptions $options, string $batchId): array
    {
        $counts = [];

        foreach ($reader->items() as $item) {
            if ($item->comments === []) {
                continue;
            }

            $target = $this->targets->of($item, $options);

            if ($target === null || $target['kind'] !== ImportKind::Content) {
                // 入れない記事のコメントは入れない（`plan` も同じところで見ていない）
                continue;
            }

            $this->importFor($item, $target['target'], $batchId, $counts);
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function importFor(WxrItem $item, string $target, string $batchId, array &$counts): void
    {
        $contentId = $this->contentId($item, $target);

        /*
         * 親子は `plan` と**同じ 1 つの決め方**を通る（`CommentTree`）。
         * ここで自前に決めると、plan が数えた付け替えの件数と入る形が食い違う。
         */
        $tree = CommentTree::of($item->comments);

        /** @var array<string, WxrComment> */
        $comments = [];

        foreach ($item->comments as $comment) {
            $comments[$comment->commentId] = $comment;
        }

        /** @var array<string, string> WXR の comment_id => こちらの id */
        $made = [];

        // **1 段目が先**（返信は親のこちらの id が決まってからでないと作れない）
        foreach ($tree->order() as $externalId) {
            $key = new LedgerKey(ImportKind::Comment, self::TARGET, $externalId);

            // 理由は plan が全部出し済みなので捨てる（**同じ理由を二度数えない**）
            $verdict = $this->ledger->reconcile([$key], new PlanFindings)[0];
            $action = $verdict->action(false);

            $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;

            if ($action !== PlanAction::Create) {
                $id = $verdict->usableTargetId();

                if ($id !== null) {
                    // 既に入っているものも、返信の親として要る
                    $made[$externalId] = $id;
                }

                continue;
            }

            $made[$externalId] = $this->createOne(
                $comments[$externalId],
                $target,
                $contentId,
                $this->parentId($tree->parentOf($externalId), $made),
                $batchId,
            );
        }
    }

    /**
     * 付ける先のこちらの id。
     *
     * @param  array<string, string>  $made
     */
    private function parentId(?string $parentExternalId, array $made): ?int
    {
        if ($parentExternalId === null) {
            return null;
        }

        if (! isset($made[$parentExternalId])) {
            /*
             * **黙って 1 段目にしない。** 親は必ず先に作られる順で並んでいるので
             * （`CommentTree::order()`）、ここへ来るのは組み立ての誤りである。
             * 静かに 1 段目へ落とすと、返信だったものが誰にも気づかれずに独立する。
             */
            throw new RuntimeException("wp:comment_id {$parentExternalId} の対象を辿れません");
        }

        return (int) $made[$parentExternalId];
    }

    /**
     * その記事のこちら側の id。
     *
     * **記事の行動とは別に引く。** 記事が `skip_existing` でもコメントは入るので、
     * 作ったかどうかではなく**台帳が指す先**を見る。
     */
    private function contentId(WxrItem $item, string $target): int
    {
        $id = $this->ledger
            ->reconcile([new LedgerKey(ImportKind::Content, $target, $item->postId)], new PlanFindings)[0]
            ->usableTargetId();

        if ($id === null) {
            // 記事が入っていなければコメントの行き先が無い。plan が止めているのでここへは来ない
            throw new RuntimeException("wp:post_id {$item->postId} の対象を辿れません");
        }

        return (int) $id;
    }

    /**
     * 1 件を、**台帳の記録まで含めて 1 つの transaction で**入れる。
     */
    private function createOne(
        WxrComment $comment,
        string $target,
        int $contentId,
        ?int $parentId,
        string $batchId,
    ): string {
        return DB::transaction(function () use ($comment, $target, $contentId, $parentId, $batchId): string {
            $fields = CommentFields::of($comment);

            $id = (string) DB::table('content_comments')->insertGetId([
                'content_type' => $target,
                'content_id' => $contentId,
                'parent_id' => $parentId,
                'status' => $fields['status'],
                'author_name' => $fields['author_name'],
                'author_email' => $fields['author_email'],
                'author_url' => $fields['author_url'],
                'body' => $fields['body'],
                /*
                 * **埋めない列**（§G・§C.4）。
                 *
                 * `ip_hash` は元の IP が無いので作れない（作れば嘘になる）。
                 * `user_agent` は WXR に無い。`author_user_id` は利用者を作らないので無い。
                 * `approved_at` は WordPress が承認時刻を記録しないので、
                 * 入れられる値は代理でしかない。
                 */
                'is_staff' => false,
                // 書かれたのは十年前である。**取り込んだ時刻ではない**
                'created_at' => $comment->createdAt ?? now(),
                // 触ったのはいまである（取り消しが「人が後から触ったか」を見る — §D.3）
                'updated_at' => now(),
            ]);

            /*
             * **同じ transaction で台帳へ。**
             * 分けると、その間に落ちたときに台帳に無いコメントが残り、取り消せなくなる。
             */
            DB::table('import_records')->insert([
                'batch_id' => $batchId,
                'external_id' => $comment->commentId,
                'kind' => ImportKind::Comment->value,
                'target_type' => self::TARGET,
                'target_id' => $id,
                // **こちらが作ったので所有する**（取り消しで消してよい）
                'created_by_import' => true,
                'created_at' => now(),
            ]);

            return $id;
        });
    }
}
