<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Wxr\WxrComment;

/**
 * 1 つの記事のコメントの親子を決める（Phase 9.14 / STAGE 27D-5b・SPEC §C.4）。
 *
 * ## 返信は 1 段まで
 *
 * 9.13 は 2 段目を断る（OG4）。WordPress は無制限に入れ子にできるので、
 * 十年分の WXR では 2 段目以降が普通に出る。**捨てずに 1 段目へ付け替える。**
 *
 * ## plan と apply が同じ答えを出す
 *
 * 「何件付け替わるか」を数えるのは plan、実際に付けるのは apply である。
 * 別々に決めると、**plan が言った件数と違う形で入る**。ここに 1 つだけ置く。
 *
 * ## 輪は入れる前に止める
 *
 * 1 の親が 2・2 の親が 1 の WXR は、辿ると止まらない。
 * `TermGraph` の循環と同じ扱いで、見つけたら数えて plan で止める。
 */
final class CommentTree
{
    /** @var array<string, string|null> id => 付ける先（1 段目）。null は 1 段目そのもの */
    private array $parents = [];

    /** @var list<string> 親がこの記事の中に無いもの。**1 段目として入れる**（捨てない） */
    private array $missing = [];

    /** @var list<string> 2 段目以降だったので 1 段目へ付け替えたもの */
    private array $flattened = [];

    /** @var list<string> 辿ると戻るもの */
    private array $cycles = [];

    /** @var list<string> 作る順（1 段目 → 返信） */
    private array $order = [];

    /**
     * @param  list<WxrComment>  $comments  **1 つの記事の分だけ**
     */
    public static function of(array $comments): self
    {
        $tree = new self;

        /** @var array<string, string|null> WXR に書かれたままの親 */
        $raw = [];

        foreach ($comments as $comment) {
            $raw[$comment->commentId] = $comment->parentId;
        }

        foreach ($comments as $comment) {
            $tree->place($comment, $raw);
        }

        // **1 段目を先に作る。** 返信は親のこちらの id が決まってからでないと作れない
        foreach ([true, false] as $top) {
            foreach ($comments as $comment) {
                if (($tree->parents[$comment->commentId] === null) === $top) {
                    $tree->order[] = $comment->commentId;
                }
            }
        }

        return $tree;
    }

    /** 付ける先。**null は 1 段目**（付け替えた結果としての 1 段目も含む） */
    public function parentOf(string $commentId): ?string
    {
        return $this->parents[$commentId] ?? null;
    }

    /** @return list<string> */
    public function missing(): array
    {
        return $this->missing;
    }

    /** @return list<string> */
    public function flattened(): array
    {
        return $this->flattened;
    }

    /** @return list<string> */
    public function cycles(): array
    {
        return $this->cycles;
    }

    /** @return list<string> 作る順（1 段目 → 返信） */
    public function order(): array
    {
        return $this->order;
    }

    /**
     * @param  array<string, string|null>  $raw
     */
    private function place(WxrComment $comment, array $raw): void
    {
        $id = $comment->commentId;
        $parent = $comment->parentId;

        if ($parent === null) {
            $this->parents[$id] = null;

            return;
        }

        if (! array_key_exists($parent, $raw)) {
            /*
             * 親がこの記事の中に無い（別の記事を指している・書き出しが欠けている）。
             * **捨てない。** 1 段目として入れる — 他人の文章を消す方が悪い。
             */
            $this->missing[] = $id;
            $this->parents[$id] = null;

            return;
        }

        // **自分から上へ辿る。** 通った跡を持つので、輪はその場で分かる
        $seen = [$id => true];
        $ancestor = $parent;

        while (true) {
            if (isset($seen[$ancestor])) {
                $this->cycles[] = $id;
                // 止める理由なので、付ける先は決めない（plan がここで止まる）
                $this->parents[$id] = null;

                return;
            }

            $seen[$ancestor] = true;

            $next = $raw[$ancestor];

            if ($next === null || ! array_key_exists($next, $raw)) {
                break;
            }

            $ancestor = $next;
        }

        if ($ancestor !== $parent) {
            // 2 段目以降だった。**捨てずに 1 段目へ付け替える**
            $this->flattened[] = $id;
        }

        $this->parents[$id] = $ancestor;
    }
}
