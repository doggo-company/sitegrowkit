<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Content;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;

/**
 * `content.delete` — **互換のための薄い wrapper**（Phase 9.8 / STAGE 21C・OG2 = (c)）。
 *
 * ## 意味が変わった
 *
 * 9.8 より前、これは**物理削除**だった。いまは `content.trash` と同じく
 * **ゴミ箱へ移す**。呼び出しの形は変えていないので今までどおり呼べるが、
 * **結果は変わる**。「消したはずが残っている」は、呼ぶ側から見れば挙動の変更である。
 * 「既存クライアントは壊れない」とは書かない — 壊れないのは**呼べること**だけである。
 *
 * なぜ既定を変えたか。`delete` という語で**取り返しのつかない操作**を AI に渡し続けるより、
 * 既定を安全側へ倒すほうが、この製品の前提に合う（SPEC §D.1・§D.2）。
 *
 * ## 同じ service を 2 つの ID で登録しない
 *
 * 権限判定・確認トークンの指紋・監査・応答は、**呼ばれた名前**で出す必要がある。
 * 1 つの service を 2 つの ID に紐付けると、監査を見ても
 * 「どちらの名前で呼ばれたのか」が読めなくなる（SPEC §D.2.1 の (i) を採らない理由）。
 * だから wrapper を置き、状態を変える手順だけを `ContentTrashState` と共有する。
 *
 * ## 物理削除はどこへ行ったか
 *
 * **無くなった。** この Stage には物理削除へ到達する経路が 1 本も無い。
 * 完全に消す操作（`content.purge`）は、backup と全体復元ができるまで作らない
 * （OG8 = (b)）。無くしたものを戻せない口を、backup より先に増やさない。
 */
final class DeleteContent implements Operation
{
    public function __construct(private readonly TrashContent $trash) {}

    public function operationId(): string
    {
        return 'content.delete';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(
        string $type,
        int $id,
        OperationContext $context,
        LockVersionInput|int|null $lockVersion = null,
    ): Result {
        /*
         * `$this` を渡すのが要点である。中身は `TrashContent` の実装だが、
         * **この operation として**振る舞う — 監査に残るのは `content.delete` で、
         * 確認トークンも `content.delete` に束縛される。
         *
         * 指紋には版が入るので、**物理削除だったころに取った確認**は
         * そのままでは通らない。意味が変わった操作へ古い同意を流し込まない。
         */
        return $this->trash->run($this, $type, $id, $context, $lockVersion);
    }
}
