<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Console;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Wxr\Apply\CommentImporter;
use AgentCms\Core\Wxr\Apply\ContentImporter;
use AgentCms\Core\Wxr\Apply\ImportGraph;
use AgentCms\Core\Wxr\Apply\MediaImporter;
use AgentCms\Core\Wxr\Apply\PageImporter;
use AgentCms\Core\Wxr\Apply\TermImporter;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\PlanIdentity;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use AgentCms\Core\Wxr\WxrError;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * WXR を取り込む（Phase 9.14 / STAGE 27D・SPEC §D.4・§D.5・§E.2）。
 *
 * ## artisan だけ
 *
 * 画面も API も持たない（§E.2）。数百 MB のファイルを upload させる口を
 * 管理画面に開けないし、移行は制作者が 1 回だけ行う作業で、
 * 実行前に backup と plan の確認が要る。**ボタン 1 つにしてはいけない。**
 *
 * ## 止める順は、直す順と同じにする
 *
 * 1. `--i-have-a-backup` が無ければ止める（**ファイルを開く前**）
 * 2. plan を組み立てる
 * 3. plan が使えなければ、その理由をそのまま出して止める
 * 4. `--plan-hash` と照合して止める
 * 5. ここで初めて batch を作る
 *
 * 1 を最初に置くのは、**backup を持っていない人に数百 MB を読ませない**ため。
 * 4 を 1 の隣に置かないのは、**plan を実行しないと値が得られない**ためで、
 * 先に弾くと「backup はあるが hash を知らない」人に取り方を案内できない。
 * 5 を最後に置くのは、**止まる場合に batch の行を 1 つも作らない**ため。
 * 作ると「何もしていない `running` の batch」が残り、次に実行する人が
 * 「前回どこまで行ったのか」を読み違える。
 *
 * ## この段では利用者のデータへ 1 件も触らない
 *
 * 記事も分類項目もコメントも作らない（27D-3 以降）。
 * 入口の守りを、**失うもののない状態で**固める。
 */
final class ApplyWxrCommand extends Command implements Operation
{
    /**
     * 監査に残す操作の名前（§E.2）。
     *
     * **AI へは出さない。** `OperationCatalog` には 1 行も足さない（§E.3）。
     * ここに名前があるのは記録のためだけで、公開の一覧とは別物である。
     */
    private const OPERATION = 'wxr.apply';

    protected $signature = 'agentcms:wxr-apply
        {file : WordPress の書き出し XML}
        {--plan-hash= : plan の最後に出た値}
        {--i-have-a-backup : backup を取得済みであることの確認}
        {--type=* : WXR の post_type とこちらの型の対応（例 post:news）}
        {--taxonomy=* : WXR の分類とこちらの分類の対応（例 category:news_category）}
        {--media : 添付も取り込む}
        {--strict-term-definitions : plan と同じ値を渡す（分類の定義が1件も無い場合でも平らな項目を作らない）}';

    protected $description = 'WXR を取り込みます（先に plan を実行し、backup を取ってください）';

    public function operationId(): string
    {
        return self::OPERATION;
    }

    /**
     * **他所のサイトの全部を書き込む操作。**
     *
     * `Destructive` にするのは、消すからではなく**取り返しがつきにくい**からである。
     * backup を要求するのも plan の照合を求めるのも、同じ理由による。
     */
    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function handle(
        PlanBuilder $builder,
        OperationAudit $audit,
        ContentImporter $importer,
        TermImporter $terms,
        ImportGraph $graphs,
        CommentImporter $comments,
        MediaImporter $media,
        PageImporter $pages,
    ): int {
        /*
         * **実行者は artisan である**（§E.2 — 画面も API も持たない）。
         * `interface = cli` で残す。
         */
        $context = OperationContext::cli('wxr-apply');

        if (! $this->option('i-have-a-backup')) {
            /*
             * **ファイルを開く前に断る**（§D.4）。
             * backup を持っていない人に数百 MB を読ませない。
             */
            $this->line('取り込みは大量の行を作ります。先に backup を取ってください:');
            $this->line('  php artisan agentcms:backup');
            $this->line('取得済みなら --i-have-a-backup を付けて再実行してください。');

            return $this->refuse($audit, $context, 'backup_not_confirmed');
        }

        $path = (string) $this->argument('file');

        $options = ImportOptions::fromCli(
            array_values((array) $this->option('type')),
            array_values((array) $this->option('taxonomy')),
            (bool) $this->option('media'),
            ! (bool) $this->option('strict-term-definitions'),
        );

        try {
            $plan = $builder->build($path, $options);
        } catch (WxrError $e) {
            $this->error($e->getMessage());

            return $this->refuse($audit, $context, 'unreadable_file');
        }

        if (! $plan->isUsable()) {
            $this->reportUnusable($plan);

            return $this->refuse($audit, $context, 'plan_not_usable');
        }

        if (! $this->matchesPlan($plan)) {
            return $this->refuse($audit, $context, 'plan_hash_mismatch');
        }

        return $this->record($path, $plan, $options, $audit, $context, $importer, $terms, $graphs, $comments, $media, $pages);
    }

    /**
     * plan の結果と照合する。
     *
     * **どちらの hash も 1 文字も出さない**（§D.4）。期待している値を出せば、
     * それを貼れば通るだけのゲートになり、照合の意味が無くなる。
     */
    private function matchesPlan(WxrPlan $plan): bool
    {
        $given = (string) ($this->option('plan-hash') ?? '');

        if ($given === '') {
            $this->line('先に plan を実行し、その最後に出た値を --plan-hash へ渡してください:');
            $this->line('  php artisan agentcms:wxr-plan <file>');

            return false;
        }

        if (! hash_equals((string) $plan->fingerprint(), $given)) {
            $this->line('plan の結果と一致しません。ファイルか指定が変わっています。');
            $this->line('もう一度 plan を実行し、出た値を渡してください。');

            return false;
        }

        return true;
    }

    /**
     * 取り込めない理由をそのまま出す。**1 件目で止めない**（§A.3）。
     */
    /**
     * 断ったことを監査に残す。
     *
     * **通らなかったことも記録する。** 残さないと「誰も実行していない」と
     * 「実行して断られた」が同じに見える。
     *
     * 理由は**固定の符号**だけを渡す。文面や外来の値を入れると、
     * 監査が本文を運ぶ経路になる（§E.2 — 本文・投稿者名・メールを入れない）。
     */
    private function refuse(OperationAudit $audit, OperationContext $context, string $reason): int
    {
        $audit->record(
            $this,
            $context,
            Result::conflict(),
            resourceType: 'import_batch',
            metadata: ['reason' => $reason],
        );

        return self::FAILURE;
    }

    private function reportUnusable(WxrPlan $plan): void
    {
        $this->error('この WXR は取り込めません。plan が見つけた理由:');

        foreach ($plan->findings() as $reason => $found) {
            $this->line("  {$reason}: {$found['total']} 件");
        }
    }

    /**
     * batch を作る。**ここまで来て初めて 1 行書く。**
     *
     * `counts` に入るのは、**この段ではまだ plan が数えた件数**である。
     * 作った件数へ変わるのは 27D-3 で、そのとき数の意味も変わる。
     */
    private function record(
        string $path,
        WxrPlan $plan,
        ImportOptions $options,
        OperationAudit $audit,
        OperationContext $context,
        ContentImporter $importer,
        TermImporter $terms,
        ImportGraph $graphs,
        CommentImporter $comments,
        MediaImporter $media,
        PageImporter $pages,
    ): int {
        $id = Str::ulid()->toString();
        $identity = new PlanIdentity((new WxrReader($path))->fingerprint(), $path, $options);

        DB::table('import_batches')->insert([
            'id' => $id,
            // **パスは持たない**（§D.2 / §G）
            'source' => $identity->storedSource(),
            'file_hash' => $identity->fileHash,
            'plan_hash' => (string) $plan->fingerprint(),
            'options' => (string) json_encode($options->toCanonical(), JSON_UNESCAPED_UNICODE),
            'status' => 'running',
            // 始めた時点では**まだ 1 件も作っていない**ので空で置く
            'counts' => '{}',
            'started_at' => now(),
        ]);

        try {
            /*
             * **分類項目を先に入れる**（§C.3「親から先に作る」の外側）。
             * 記事の割り当ては 27D-5 だが、そのときに項目が揃っている必要がある。
             */
            /*
             * **関係は 1 回だけ組む。** 項目を作る側と記事を結び付ける側が
             * 別々に組むと、走査が 1 周増えるうえに、
             * **項目を作った代表と記事が指す代表が食い違う**余地が残る。
             */
            $graph = $graphs->of(new WxrReader($path), $options);

            /*
             * **種類ごとに数える。** 混ぜた合計にすると、十年分では
             * コメントが桁で上回るので合計はほぼコメントの数になり、
             * **記事が何本入ったのかが読めない**。
             *
             * 合計は種類ごとの数から導けるので持たない。
             * 件数の無い種類はキーごと出さない（何も変わっていないのに出力が変わらないように）。
             */
            /*
             * **順は分類項目 → 記事 → コメント。**
             *
             * 記事の割り当ては項目が揃っていないと付けられず、
             * コメントは記事のこちらの id が台帳に無いと入れられない。
             * **書く順そのものが依存の順**なので、1 行ずつ置く。
             */
            /*
             * **メディアは記事より先**（§C.6）。本文の URL を置き換えるには、
             * 記事を作る前にこちら側の URL が要る。作った後で差し替えると
             * `UpdateContent` を通って投稿の行が動き、取り消しの (a) が
             * 「人が触った」と読む — **取り込んだ直後の記事が 1 件も取り消せなくなる**。
             *
             * `--media` が無ければ `MediaImporter` は添付を 1 件も見ないので、
             * **外へ 1 度も繋がない**（DoD 12）。
             */
            $medium = $media->import(new WxrReader($path), $options, $id, $context);

            $term = $terms->import(new WxrReader($path), $graph, $options, $id, $context);
            $content = $importer->import(new WxrReader($path), $graph, $options, $id, $context, $medium['urls']);
            // ページは記事と別の表へ（release-20260909 / wxr-pages）。メディアの URL は同じ表で置き換える
            $page = $pages->import(new WxrReader($path), $options, $id, $context, $medium['urls']);
            $comment = $comments->import(new WxrReader($path), $options, $id);

            $counts = array_filter(
                ['comment' => $comment, 'content' => $content, 'media' => $medium['counts'], 'page' => $page, 'term' => $term],
                static fn (array $kind): bool => $kind !== [],
            );

            ksort($counts);

            // **入るのは作った件数**であって、plan の予定ではない
            $this->finish($id, 'completed', counts: $counts);
        } catch (Throwable $e) {
            /*
             * **落ちた時刻を残す**（§D.5）。`status` だけだと、落ちてから取り消した batch と
             * 入り切ってから取り消した batch が同じ `rolled_back` になる。
             */
            $this->finish($id, 'failed', failed: true);

            /*
             * **落ちたことも監査に残す。** 終わり方 3 通りのうちここだけ何も残らないと、
             * 半端に入った取り込みについて「何が起きたか」を後から辿れない。
             *
             * 監査が書けなくても**元の例外は握り潰さない**。
             * 取り込みが半端に入った事実の方が重い（`OperationAudit::recordOrFlag()` と同じ理屈）。
             */
            try {
                $audit->record(
                    $this,
                    $context,
                    Result::conflict(),
                    resourceType: 'import_batch',
                    resourceId: $id,
                    metadata: ['reason' => 'import_failed'],
                );
            } catch (Throwable) {
                /*
                 * **監査が書けなくても、落ちた事実を握り潰さない。**
                 * 取り込みが半端に入った事実の方が重い
                 * （`OperationAudit::recordOrFlag()` と同じ理屈）。
                 * batch は既に `failed` になっており、下で必ず報告する。
                 */
            }

            /*
             * **stack trace ではなく、何が起きたかを出す。**
             * 利用者が次にやることは rollback するか続きから流すかで（§D.5）、
             * そのどちらも batch id が要る。
             */
            $this->error($e->getMessage());
            $this->line("途中で落ちました。この取り込みの記録: {$id}");
            $this->line('rollback するか、直してから続きを流してください。');

            return self::FAILURE;
        }

        /*
         * **対象は batch id**（§E.2）。ファイル名も本文も入れない。
         * ファイル名は `import_batches.source` が持っており、batch id から辿れる。
         * 同じものを 2 か所へ書くと、片方だけ消したときに食い違う。
         */
        $audit->record($this, $context, Result::success(), resourceType: 'import_batch', resourceId: $id);

        $this->reportCounts($counts);

        $this->line("取り込みを記録しました: {$id}");

        return self::SUCCESS;
    }

    /**
     * **何件入ったか**を出す（WP 一周 2026-09-06 / F-21）。
     *
     * ## なぜ要るのか
     *
     * ここは batch id の 1 行しか出していなかった。`plan` は
     * 「`create` 8 件」と**予告する**のに、実行した結果が画面に出ない。
     * 数は `import_batches.counts` に入っているが、**SQL を叩ける人にしか読めない**。
     * WordPress から来た人は、入ったのか入らなかったのかを確かめる手立てが無かった。
     *
     * ## plan と同じ形で出す
     *
     * 行の書き方も桁の揃え方も `plan` と同じにする（`PlanWxrCommand::report()`）。
     * **並べて見比べられること**が目的なので、ここだけ別の書き方にすると比べられない。
     *
     * 見出しだけは `plan` の「入るもの」に対して「取り込みの結果」にしてある。
     * 結果には `skip_existing`（台帳に在ったので作らなかった）も並ぶので、
     * **「入ったもの」と名乗ると、入っていないものが入ったように読める**。
     *
     * 種類ごとの内訳はその下へ 1 段下げて置く。合計だけにすると、十年分では
     * コメントが桁で上回り、**記事が何本入ったのかが読めない**（`counts` の docblock）。
     *
     * @param  array<string, array<string, int>>  $counts  種類 => 行動 => 件数
     */
    private function reportCounts(array $counts): void
    {
        $this->newLine();

        if ($counts === []) {
            $this->line('取り込むものはありませんでした。');

            return;
        }

        /** @var array<string, array<string, int>> $byAction 行動 => 種類 => 件数 */
        $byAction = [];

        foreach ($counts as $kind => $actions) {
            foreach ($actions as $action => $count) {
                $byAction[$action][$kind] = $count;
            }
        }

        ksort($byAction);

        $this->info('取り込みの結果:');

        foreach ($byAction as $action => $kinds) {
            ksort($kinds);

            $this->line(sprintf('  %-24s %6d 件', $action, array_sum($kinds)));

            foreach ($kinds as $kind => $count) {
                $this->line(sprintf('    %-22s %6d 件', $kind, $count));
            }
        }
    }

    /**
     * @param  array<string, array<string, int>>|null  $counts  **種類ごと**の行動ごとの件数
     */
    private function finish(string $id, string $status, bool $failed = false, ?array $counts = null): void
    {
        $columns = [
            'status' => $status,
            'finished_at' => now(),
            'failed_at' => $failed ? now() : null,
        ];

        if ($counts !== null) {
            $columns['counts'] = (string) json_encode($counts);
        }

        DB::table('import_batches')->where('id', $id)->update($columns);
    }
}
