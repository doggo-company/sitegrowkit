<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions\Console;

use AgentCms\Core\Application\AuditRecorder;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Revisions\ManifestIntegrityError;
use AgentCms\Core\Revisions\RevisionReason;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 版が 1 件も無い既存の投稿へ、初版を作る（Phase 9.8 / STAGE 21D・SPEC §F.3.2）。
 *
 * ## なぜ要るのか
 *
 * 「現在の内容 = 最新の版」という約束は、**仕組みを入れた瞬間には成り立っていない**。
 * それまでに書かれた投稿には版が 1 件も無いからである。だから導入時に 1 度、
 * いまの姿を初版として記録する。
 *
 * ## `create` と書かない
 *
 * その版は**実際の作成操作ではない**。`create` と書くと「この日時に作られた」と
 * 履歴が嘘をつく。`baseline` は「導入した時点の姿を記録しただけ」という意味である。
 * 元の投稿の時刻は snapshot の `entry_created_at` / `entry_updated_at` に入る。
 *
 * ## 履歴の修復には使えない
 *
 * 版が 1 件でもあれば飛ばす。契約外の書き込みで**古い版のまま内容だけ進んだ**投稿は、
 * これを流しても直らない。**導入時（版 0 件）専用**である。
 *
 * ## 途中で通常の更新が走っても壊れない
 *
 * 投稿ごとに transaction を張り、行をロックしてから**版の数を数え直す**。
 * ロックの前に数えると、その隙に通常の更新が版を作り、
 * 「古い内容の baseline が最新版になる」が起きる。
 */
final class ContentBaselineCommand extends Command
{
    protected $signature = 'agentcms:content-baseline
        {--type= : この投稿タイプだけを対象にする}
        {--limit=200 : 1 回で処理する件数の上限}
        {--after-type= : 再開位置（この投稿タイプから）}
        {--after-id=0 : 再開位置（この ID より後から）}
        {--status : 書き込まず、版が無い投稿の件数だけを出す}';

    protected $description = '版が無い既存の投稿へ初版（baseline）を作る';

    public function handle(
        SchemaRegistry $schemas,
        ContentTypeRegistry $types,
        RevisionWriter $revisions,
        AuditRecorder $audit,
    ): int {
        $targets = $this->targetTypes($schemas, $types);

        if ($targets === []) {
            $this->info('対象の投稿タイプがありません。');

            return self::SUCCESS;
        }

        if ((bool) $this->option('status')) {
            return $this->reportStatus($targets, $revisions);
        }

        $limit = max(1, (int) $this->option('limit'));
        $afterType = (string) ($this->option('after-type') ?? '');
        $afterId = max(0, (int) $this->option('after-id'));

        $written = 0;
        $skipped = 0;
        $failed = 0;
        $cursor = null;

        foreach ($targets as $contentType => $model) {
            if ($afterType !== '' && strcmp($contentType, $afterType) < 0) {
                continue;
            }

            $from = $contentType === $afterType ? $afterId : 0;

            foreach ($this->entries($model, $from) as $entry) {
                if ($written + $skipped + $failed >= $limit) {
                    break 2;
                }

                $cursor = [$contentType, (int) $entry->getKey()];

                try {
                    $result = $this->baseline($schemas, $revisions, $contentType, $model, (int) $entry->getKey());
                    $result ? $written++ : $skipped++;
                } catch (ManifestIntegrityError $e) {
                    // 定義が壊れている型は**その型ごと**止める。次の投稿へ進まない
                    $failed++;
                    $this->error("[{$contentType}] {$e->safeMessage()}");

                    break;
                } catch (Throwable $e) {
                    // 1 件の失敗で全体を止めない。次回に回す
                    $failed++;
                    report($e);
                    $this->warn("[{$contentType}] ID {$entry->getKey()} を処理できませんでした。");
                }
            }
        }

        $this->line("初版を作成: {$written} 件 / 既に版あり: {$skipped} 件 / 失敗: {$failed} 件");

        if ($cursor !== null) {
            // **型と ID の組**で再開する。ID だけだと型が変わったときに低い ID を飛ばす
            $this->line("次回の再開位置: --after-type={$cursor[0]} --after-id={$cursor[1]}");
        }

        $remaining = $this->remaining($targets, $revisions);
        $this->line("版が無い投稿: 残り {$remaining} 件".($remaining === 0 ? '（完了）' : ''));

        /*
         * 監査には **型・件数・範囲**だけを残す。snapshot の中身は入れない
         * （版と監査を混ぜない — SPEC §F.3.2）。
         *
         * `Operation` の実装を 1 つ増やさずに、**ID を直接**渡す。
         * この名前は artisan から流したときにだけ現れるもので、
         * 「1 操作 = 1 クラス」を名乗る実体が在るわけではない。
         */
        $audit->record(
            'content.revision.baseline',
            OperationClassification::Write,
            OperationContext::cli('agentcms:content-baseline'),
            Result::success([], []),
            metadata: [
                'types' => array_keys($targets),
                'written' => $written,
                'skipped' => $skipped,
                'failed' => $failed,
                'remaining' => $remaining,
                'cursor' => $cursor === null ? null : ['type' => $cursor[0], 'id' => $cursor[1]],
            ],
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 1 件ぶんの初版。**投稿ごとに transaction**。
     *
     * @return bool 書いたら true、既に版があって飛ばしたら false
     */
    private function baseline(
        SchemaRegistry $schemas,
        RevisionWriter $revisions,
        string $contentType,
        Model $model,
        int $id,
    ): bool {
        return DB::transaction(function () use ($schemas, $revisions, $contentType, $model, $id): bool {
            $locked = $model->newQuery()
                ->withoutGlobalScope(TrashScope::NAME)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Model) {
                return false;
            }

            /*
             * **ロックしてから数え直す。** 数えてからロックすると、その隙に
             * 通常の更新が版を作り、古い内容の baseline が最新版になり得る。
             */
            if ($revisions->count($contentType, $id) > 0) {
                return false;
            }

            $revisions->write(
                $schemas->get($contentType),
                $contentType,
                $locked,
                RevisionReason::Baseline,
                OperationContext::cli('agentcms:content-baseline'),
                force: true,
            );

            return true;
        });
    }

    /**
     * 対象の投稿タイプ（**投稿タイプだけ**。media / contact / site は対象外）。
     *
     * @return array<string, Model>
     */
    private function targetTypes(SchemaRegistry $schemas, ContentTypeRegistry $types): array
    {
        $only = (string) ($this->option('type') ?? '');
        $targets = [];

        foreach ($schemas->all() as $id => $schema) {
            if (! EditorialCollections::isEditorial($schema) || ! $types->has($id)) {
                continue;
            }

            if ($only !== '' && $id !== $only) {
                continue;
            }

            if (! Schema::hasTable($schema->table)) {
                continue;
            }

            /** @var class-string<Model> $class */
            $class = $types->modelFor($id);
            $targets[$id] = new $class;
        }

        // **決定的な順序**（型の昇順）。同じ結果が何度でも再現する
        ksort($targets);

        return $targets;
    }

    /**
     * 版が無い投稿（ID の昇順）。ゴミ箱の中も対象にする — 戻したときに履歴が要る。
     *
     * @return iterable<int, Model>
     */
    private function entries(Model $model, int $afterId): iterable
    {
        return $model->newQuery()
            ->withoutGlobalScope(TrashScope::NAME)
            ->where($model->getKeyName(), '>', $afterId)
            ->orderBy($model->getKeyName())
            ->cursor();
    }

    /** @param  array<string, Model>  $targets */
    private function reportStatus(array $targets, RevisionWriter $revisions): int
    {
        $remaining = 0;

        foreach ($targets as $contentType => $model) {
            $count = $this->missingCount($contentType, $model);
            $remaining += $count;
            $this->line(str_pad($contentType, 20).' 版が無い投稿: '.$count.' 件');
        }

        $this->line('---');
        $this->line("合計: {$remaining} 件".($remaining === 0 ? '（完了）' : ''));

        return self::SUCCESS;
    }

    /** @param  array<string, Model>  $targets */
    private function remaining(array $targets, RevisionWriter $revisions): int
    {
        $total = 0;

        foreach ($targets as $contentType => $model) {
            $total += $this->missingCount($contentType, $model);
        }

        return $total;
    }

    /** 版が 1 件も無い投稿の数（完了判定はこれが 0 になること） */
    private function missingCount(string $contentType, Model $model): int
    {
        return $model->newQuery()
            ->withoutGlobalScope(TrashScope::NAME)
            ->whereNotExists(function ($query) use ($contentType, $model): void {
                $query->select(DB::raw(1))
                    ->from(RevisionWriter::TABLE)
                    ->whereColumn(RevisionWriter::TABLE.'.content_id', $model->getTable().'.'.$model->getKeyName())
                    ->where(RevisionWriter::TABLE.'.content_type', $contentType);
            })
            ->count();
    }
}
