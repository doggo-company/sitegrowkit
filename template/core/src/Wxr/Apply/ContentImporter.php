<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Apply;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemFields;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TermGraph;
use AgentCms\Core\Wxr\Status\CommentSetting;
use AgentCms\Core\Wxr\Status\WxrStatus;
use AgentCms\Core\Wxr\Status\WxrStatusColumn;
use AgentCms\Core\Wxr\WxrItem;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 記事を入れる（Phase 9.14 / STAGE 27D-3・SPEC §E.1・§D.2・§D.5）。
 *
 * ## 既存の Application Service を通す
 *
 * `CreateContent` を通る（§E.1）。**取り込み専用の書き込み経路を作らない** —
 * 作ると validation・policy・監査のどれかが抜け、「import だけ通る穴」になる。
 *
 * ## 台帳に無い記事を作らない
 *
 * 記事の作成と台帳の記録は**同じ transaction**で行う。分けると、その間に落ちたときに
 * **台帳に無い記事**が残る。台帳に無い記事は取り消しから見えないので、
 * **消せない記事が十年分**できる。
 *
 * ## 1 記事 = 1 transaction
 *
 * §D.5 の (a)「chunk ごとに commit」を、**1 記事の粒度**で満たす。
 * まとめて 1 つにすると、落ちたときに入っていた分まで消え、
 * **冪等性があるのに作り直しになる**。
 *
 * ## メディアの URL は、**作るときに**置き換える
 *
 * 置換済みの本文を最初から書く。作った後で差し替えると `UpdateContent` を通り、
 * 投稿の行の `updated_at` が動く — 取り消しの (a) がそれを「人が触った」と読み、
 * **取り込んだ直後の記事が 1 件も取り消せなくなる**（§D.3・§C.6）。
 * だから対応表は `MediaImporter` から**外から渡る**（走査は 1 周増える）。
 *
 * ## どこへ入れるかは自分で決めない
 *
 * `ItemTarget` を通す。`plan` と同じ判定を使わないと、
 * **plan が言ったのと違う場所へ入る**。hash は plan 側の判断から作るので、
 * 両方が同じ誤りをすれば照合は一致してしまい、食い違いを見つけられない。
 */
final class ContentImporter
{
    public function __construct(
        private readonly ItemTarget $targets,
        private readonly LedgerReconciler $ledger,
        private readonly CreateContent $create,
        private readonly ContentTypeResolver $types,
        private readonly CommentGate $comments,
    ) {}

    /**
     * 入れて、**作った件数**を返す（plan の予定ではない）。
     *
     * @param  array<string, string>  $mediaUrls  元の URL => こちら側の URL（取り込めたものだけ）
     * @return array<string, int>
     */
    public function import(
        WxrReader $reader,
        TermGraph $graph,
        ImportOptions $options,
        string $batchId,
        OperationContext $context,
        array $mediaUrls = [],
    ): array {
        $counts = [];

        foreach ($reader->items() as $item) {
            $target = $this->targets->of($item, $options);

            if ($target === null || $target['kind'] !== ImportKind::Content) {
                // 入れないもの（§A.2）と media（27D-4 以降）はここでは触らない
                continue;
            }

            $action = $this->actionFor($item, $target['target']);

            $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;

            if ($action === PlanAction::Create) {
                $this->createOne($item, $target['target'], $graph, $options, $batchId, $context, $mediaUrls);
            }
        }

        return $counts;
    }

    /**
     * 台帳を引いて行動を決める。
     *
     * **plan のときと同じ道を通る**（`LedgerReconciler`）。ここで自前の判定を書くと、
     * plan が `skip_existing` と言ったものを apply が作る、が起こり得る。
     *
     * 理由は plan が全部出し済みなので捨てる（**同じ理由を二度数えない**）。
     */
    private function actionFor(WxrItem $item, string $target): PlanAction
    {
        $key = new LedgerKey(ImportKind::Content, $target, $item->postId);

        return $this->ledger->reconcile([$key], new PlanFindings)[0]->action(false);
    }

    /**
     * 1 記事を、**台帳の記録まで含めて 1 つの transaction で**入れる。
     */
    /**
     * @param  array<string, string>  $mediaUrls
     */
    private function createOne(
        WxrItem $item,
        string $target,
        TermGraph $graph,
        ImportOptions $options,
        string $batchId,
        OperationContext $context,
        array $mediaUrls,
    ): void {
        $taxonomies = $this->taxonomiesFor($item, $graph, $options);

        DB::transaction(function () use ($item, $target, $taxonomies, $batchId, $context, $mediaUrls): void {
            $result = $this->create->execute($target, $this->attributes($item, $target, $mediaUrls), $context, $taxonomies);

            if ($result->status !== OperationStatus::Success) {
                /*
                 * **黙って飛ばさない。** 1 件でも入らなければ止める。
                 * 飛ばすと、入らなかった記事が十年分の中に紛れて誰にも気づかれない。
                 * 外来の値は文面へ入れない（`wp:post_id` は識別子の形しか通っていない）。
                 */
                throw new RuntimeException(
                    "wp:post_id {$item->postId} を入れられませんでした（{$result->status->value}）",
                );
            }

            // `CreateContent` は `['item' => [...]]` を返す（他の面と同じ形）
            $item_ = $result->data['item'] ?? null;
            $id = is_array($item_) ? (string) ($item_['id'] ?? '') : '';

            if ($id === '') {
                throw new RuntimeException("wp:post_id {$item->postId} の作成結果を確認できませんでした");
            }

            /*
             * **元の公開状態を残す**（§C.8.3・§E.1.1）。
             *
             * `wp_status` は schema に宣言しない列なので `ContentValues::split()` の
             * 書き込み対象に入らず、`CreateContent` からは書けない。
             * 宣言しないことが設計（人の編集で消えない）で、これはその帰結である。
             * validation も policy も無い列なので、呼び出しの隣で**その列だけ**を
             * 同じ transaction の中で書くことは、専用経路の新設に当たらない（§E.1.1）。
             */
            DB::table($this->types->newModel($target)->getTable())
                ->where('id', $id)
                ->update([WxrStatusColumn::NAME => WxrStatus::preserve($item->status)]);

            /*
             * **閉じていた記事は閉じたまま入れる**（§C.4.3）。
             *
             * 取り込まないと、元のサイトで閉じていた記事が移行後は開いた状態になり、
             * 十年前の記事に新しいコメントが付き始める。閉じたのは持ち主の判断である。
             *
             * 書くのは 9.13 の口（`CommentGate::setEntryOpen()`）を通す。
             * `content_comment_settings` へ直接書くのは専用経路の新設に当たる（§E.1）。
             *
             * **`closed` のときだけ**。`open` も、知らない値も、要素が無いのも書かない —
             * どれも「型の設定に従う」で、9.13 が避けている
             * 「既定値を全記事へ書く」に戻らないためである。
             */
            if (CommentSetting::shouldClose($item->commentStatus)) {
                $this->comments->setEntryOpen($target, (int) $id, false);
            }

            /*
             * **同じ transaction で台帳へ。**
             * 分けると、その間に落ちたときに台帳に無い記事が残り、取り消せなくなる。
             */
            DB::table('import_records')->insert([
                'batch_id' => $batchId,
                'external_id' => $item->postId,
                'kind' => ImportKind::Content->value,
                'target_type' => $target,
                'target_id' => $id,
                // **こちらが作ったので所有する**（取り消しで消してよい）
                'created_by_import' => true,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * 記事が結び付く分類項目（§C.3・§E.1・§D.2.1）。
     *
     * ## `CreateContent` に渡す
     *
     * `taxonomy_term_assignments` へ直接書かない。書くと validation も policy も
     * 監査も抜けた**取り込みだけ通る穴**になる（§E.1）。
     * 記事本体と同じ transaction・同じ行ロックの下で入る。
     *
     * ## 辿るのは台帳
     *
     * item が持っているのは**元の綴りだけ**で、こちらの綴りは当て直されていることがある
     * （`Str::slug('お知らせ')` は空）。綴りで辿ると、
     * **当てが働いた分類の割り当てが丸ごと落ちる** — 日本語のサイトではそれがほとんどになる。
     *
     * 元の綴り → 代表の `wp:term_id`（`TermGraph`）→ こちらの id（台帳）と辿る。
     * 「どこへ入ったか」の正本は台帳なので、**辿り方を 2 本持たない**。
     *
     * ## 渡さないことと空を渡すことは違う
     *
     * 割り当てが 1 件も無ければ `null`（= キー未指定 = 触らない）を返す。
     * 空の配列を渡すと「全部外す」の意味になり、作った直後の記事に対しては
     * 必須の分類の検証だけが働く。
     */
    private function taxonomiesFor(WxrItem $item, TermGraph $graph, ImportOptions $options): ?TaxonomyPayload
    {
        /** @var array<string, list<string>> */
        $map = [];

        foreach ($item->terms as $assignment) {
            $key = $options->taxonomies[$assignment['domain']] ?? $assignment['domain'];

            $termId = $graph->representativeFor($key, $assignment['nicename']);

            if ($termId === null) {
                /*
                 * **黙って落とさない。** 定義の無い割り当ては plan が止めている
                 * （`TermDefinitionMissing`）ので、ここへ来るのは組み立ての誤りである。
                 * 元の綴りは文面へ入れない（他人のサイトの分類名が入る）。
                 */
                throw new RuntimeException("wp:post_id {$item->postId} の分類を辿れません（{$key}）");
            }

            $id = $this->ledger
                ->reconcile([new LedgerKey(ImportKind::Term, $key, $termId)], new PlanFindings)[0]
                ->usableTargetId();

            if ($id === null) {
                throw new RuntimeException("wp:term_id {$termId} の対象を辿れません");
            }

            $map[$key][] = $id;
        }

        return $map === [] ? null : TaxonomyPayload::of($map);
    }

    /**
     * WXR の値をこちらの欄へ。**受け皿の無いものは渡さない。**
     *
     * @param  array<string, string>  $mediaUrls
     * @return array<string, mixed>
     */
    private function attributes(WxrItem $item, string $target, array $mediaUrls): array
    {
        $schema = $this->types->schema($target);

        $attributes = [];

        foreach (ItemFields::of($item) as $key => $value) {
            if ($value === null || ! $schema->hasField($key)) {
                continue;
            }

            if ($mediaUrls !== []) {
                /*
                 * **取り込めた URL だけを置き換える**（§C.6）。取れなかった URL は
                 * 元のまま残す — 壊れたリンクを別の壊れたリンクに変えない。
                 *
                 * 長い URL から先に当てる。短い方を先に当てると、
                 * `…/a.jpg` が `…/a.jpg?w=1` の中を先に書き換えて壊す。
                 */
                $value = strtr($value, $mediaUrls);
            }

            /*
             * **空でも渡す。** 受け皿がある欄を「空だから」と外すと、
             * その欄が必須のときに **plan では見えない検証エラー**になる。
             * 空を入れてよいかは、型の側の規則が決める。
             */
            $attributes[$key] = $value;
        }

        return $attributes;
    }
}
