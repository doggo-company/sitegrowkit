<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Console;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Wxr\Rollback\ContentReverter;
use AgentCms\Core\Wxr\Rollback\LedgerSweeper;
use AgentCms\Core\Wxr\Rollback\MediaReverter;
use AgentCms\Core\Wxr\Rollback\PageReverter;
use AgentCms\Core\Wxr\Rollback\TermReverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 取り込みを取り消す（Phase 9.14 / STAGE 27E-2・SPEC §D.3・§I.1・§E.2）。
 *
 * ## backup を求める
 *
 * 27E-1 は「物理削除をしないので apply ほど不可逆ではない」を理由に求めなかった。
 * **27E-2 でその前提は成り立たない** — apply は増やすだけだが、取り消しは消す。
 * 復元手段が WXR ファイルそのものである以上、**そのファイルが手元にあることを
 * 確かめさせるのは形式ではなく実質**である（§D.3）。
 *
 * 確認は**フラグだけ**で、ファイルの場所は受け取らない。受け取ると、
 * 「渡した道が正しいか」を確かめる責任がこちらへ移り、確かめられないものを
 * 確かめたことにする経路ができる。
 *
 * ## 止める順は、直す順と同じにする
 *
 * 1. `--i-have-a-backup` が無ければ止める（**1 行も消す前に**）
 * 2. batch の記録を引き、走っていないことを確かめる
 * 3. 取り消し済みかどうかは、印ではなく**残っている行**で判定する（F-11）
 *
 * ## batch id は必須。「直近を取り消す」の便利さは作らない
 *
 * 取り消す対象を間違える方が重い。どの取り込みを取り消すかを、明示させる。
 *
 * ## 順は、参照が消える順である
 *
 * 束 → 分類項目 → メディア → 所有していない記録。
 * 束を消すと割り当ての行が消え、そこで初めて分類項目の参照が 0 になる。
 * 束を消すと本文が消え、そこで初めてメディアの参照が 0 になる。
 * 逆にすると、消せるはずのものが「まだ使われている」と報告される。
 */
final class RollbackWxrCommand extends Command implements Operation
{
    /** 監査に残す操作の名前（§E.2）。**AI へは出さない**（§E.3 — `OperationCatalog` に足さない）。 */
    private const OPERATION = 'wxr.rollback';

    /** 戻せなかった id を明細に出す上限（`PlanFindings` の DETAIL_CAP と同じ考え） */
    private const DETAIL_CAP = 50;

    protected $signature = 'agentcms:wxr-rollback
        {batch : 取り消す取り込みの記録（apply が出した id）}
        {--i-have-a-backup : backup を取得済みであることの確認}';

    protected $description = '取り込みを取り消します（触られていない束は削除します。先に backup を取ってください）';

    public function operationId(): string
    {
        return self::OPERATION;
    }

    /** 触られていない束を**物理削除する**ので `Destructive`（§D.3）。 */
    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function handle(
        OperationAudit $audit,
        ContentReverter $contents,
        TermReverter $terms,
        MediaReverter $media,
        PageReverter $pages,
        LedgerSweeper $sweeper,
    ): int {
        $context = OperationContext::cli('wxr-rollback');
        $id = (string) $this->argument('batch');

        if (! $this->option('i-have-a-backup')) {
            /*
             * **1 行も消す前に断る**（§D.3）。復元手段は WXR ファイルそのものなので、
             * それが手元に無い人に取り消しを始めさせない。
             */
            $this->line('取り消しは触られていない行を削除します。先に backup を取ってください:');
            $this->line('  php artisan agentcms:backup');
            $this->line('取得済みなら --i-have-a-backup を付けて再実行してください。');

            return $this->refuse($audit, $context, 'backup_not_confirmed');
        }

        $batch = DB::table('import_batches')->where('id', $id)->first();

        if ($batch === null) {
            $this->error('その id の取り込みの記録がありません。');

            return $this->refuse($audit, $context, 'batch_not_found');
        }

        if ($batch->status === 'running') {
            // 走っている最中に消し始めると、入れる側と消す側が同じ行を取り合う
            $this->error('この取り込みはまだ走っています。終わってから取り消してください。');

            return $this->refuse($audit, $context, 'batch_running', $id);
        }

        /*
         * **「取り消し済み」は印ではなく実態で判定する。** 段ごとに戻せる種類が違うので、
         * 印（rolled_back_at）で断ると、次の段が入ったあとも**その種類を永久に
         * 取り消せない** batch が残る（F-11 — 27E-1 でメディアがそうなった）。
         * 行が残っているかぎり、取り消しは続きから何度でも実行できる
         * （reverter は消えた行を走査しないので冪等）。
         */
        if ($batch->rolled_back_at !== null && ! $this->hasRemainingRecords($id)) {
            $this->error('この取り込みは既に取り消されています。');

            return $this->refuse($audit, $context, 'batch_already_rolled_back', $id);
        }

        /*
         * **順を変えない。** 束を消すと割り当ての行が消え、そこで初めて
         * 分類項目の参照が 0 になる。束を消すと本文が消え、そこで初めて
         * メディアの参照が 0 になる。逆にすると、消せるはずのものが
         * 「まだ使われている」と報告される。
         */
        $result = $contents->revert($id);

        $counts = $result['counts'];

        // ページは記事と別の束（release-20260909 / wxr-pages）。メディアの参照より先に消す
        $pageResult = $pages->revert($id);

        $unrevertable = [...$result['unrevertable'], ...$pageResult['unrevertable']];

        foreach ([$pageResult['counts'], $terms->revert($id), $media->revert($id, $context), $sweeper->sweep($id)] as $more) {
            foreach ($more as $key => $value) {
                $counts[$key] = ($counts[$key] ?? 0) + $value;
            }
        }

        /*
         * **`failed_at` は消さない**（§D.2）。落ちてから取り消した batch と
         * 入り切ってから取り消した batch は、`failed_at` の有無で見分ける。
         */
        DB::table('import_batches')->where('id', $id)->update([
            'status' => 'rolled_back',
            /*
             * **最初に取り消した時刻を守る**（`failed_at` と同じ側 — 履歴を消さない）。
             * 再実行は正常な運用なので、上書きすると「いつ取り消したか」が
             * 「最後に走らせた時刻」に化ける。
             */
            'rolled_back_at' => $batch->rolled_back_at ?? now(),
        ]);

        $this->report($counts, $unrevertable);

        $audit->record($this, $context, Result::success(), resourceType: 'import_batch', resourceId: $id);

        $this->line("取り消しを記録しました: {$id}");

        return self::SUCCESS;
    }

    /**
     * この batch の台帳の行が残っているか。
     *
     * 残っている = まだ取り消せるものがある（この段では kind=comment / term、
     * あるいは触られて残した行）。**触られて残した行しか無い場合も再実行を許す** —
     * 何も起きない冪等な実行で、「まだ残っているものがある」ことを報告し続ける。
     */
    private function hasRemainingRecords(string $batchId): bool
    {
        return DB::table('import_records')->where('batch_id', $batchId)->exists();
    }

    /**
     * 断ったことも監査に残す。残さないと「誰も実行していない」と
     * 「実行して断られた」が同じに見える。理由は**固定の符号**だけ（§E.2）。
     */
    private function refuse(OperationAudit $audit, OperationContext $context, string $reason, ?string $id = null): int
    {
        $audit->record(
            $this,
            $context,
            Result::conflict(),
            resourceType: 'import_batch',
            resourceId: $id,
            metadata: ['reason' => $reason],
        );

        return self::FAILURE;
    }

    /**
     * 何を戻し、何を**戻さなかった**かを言う（§A.3 — 黙って捨てない・黙って残さない）。
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $unrevertable
     */
    private function report(array $counts, array $unrevertable): void
    {
        $lines = [
            'reverted_content' => '記事を削除した',
            'reverted_comment' => 'コメントを削除した',
            'reverted_term' => '分類項目を削除した',
            'reverted_media' => 'メディアを削除した',
            'dropped_records' => '記録だけ消した（元からあった対象は触っていません）',
            'kept_touched' => '触られていたので戻さなかった',
            'kept_referenced' => 'まだ使われているので残した',
            'kept_missing' => '対象が見つからないので記録だけ残した',
            'kept_conflict' => '戻せなかった（実行中に状態が変わった）',
        ];

        foreach ($lines as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $this->line("{$label}: {$counts[$key]} 件");
            }
        }

        if ($unrevertable !== []) {
            $shown = array_slice($unrevertable, 0, self::DETAIL_CAP);
            $this->line('戻せなかった wp:post_id: '.implode(', ', $shown));

            if (count($unrevertable) > self::DETAIL_CAP) {
                $this->line('… 他 '.(count($unrevertable) - self::DETAIL_CAP).' 件');
            }
        }
    }
}
