<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions\Console;

use AgentCms\Core\Application\AuditRecorder;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * どの版からも参照されなくなった項目定義を片付ける（Phase 9.8 / STAGE 21D・SPEC §H.4）。
 *
 * ## 版は消さない
 *
 * 消すのは**定義だけ**である。版そのものの間引き（prune）も投稿の完全削除も、
 * この Stage では作らない。backup と全体復元ができるまで、戻せない口を増やさない。
 *
 * ## 既定は何もしない
 *
 * 何が消えるかを先に見せ、`--apply` を明示されたときだけ消す。
 * 「流したら消えていた」を作らない。
 *
 * ## 数え直してから消す
 *
 * 候補を選んだあとに、別の書き込みがその定義を使う版を作るかもしれない。
 * だから transaction の中で**もう一度**参照数を確かめる。1 件でもあれば飛ばす。
 * FK の `restrict` は最後の安全網であって、最初の砦ではない。
 *
 * ## 1 回の実行は 1 つの transaction
 *
 * 候補ごとに transaction を張ると、3 件目で落ちたときに 1 件目と 2 件目だけが
 * 消えた状態が残る。**それは掃除の途中で止まった、という報告のしようがない状態**である。
 * 全部消えるか、1 つも消えないか、どちらかにする（SPEC §H.4 の手順 2 と 5）。
 */
final class ManifestPruneCommand extends Command
{
    protected $signature = 'agentcms:manifest-prune
        {--apply : 実際に削除する（既定は何も消さない）}
        {--limit=100 : 1 回で見る件数の上限}';

    protected $description = 'どの版からも参照されていない項目定義を片付ける';

    public function handle(AuditRecorder $audit): int
    {
        if (! Schema::hasTable(SchemaManifestRepository::TABLE)) {
            $this->info('項目定義の表がまだありません。');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = $this->orphans($limit);

        if ($candidates === []) {
            $this->info('参照されていない項目定義はありません。');

            return self::SUCCESS;
        }

        foreach ($candidates as $row) {
            $this->line("  {$row->content_type} / {$row->fingerprint}");
        }

        if (! $apply) {
            $this->line(count($candidates).' 件が候補です。削除するには --apply を付けてください。');

            return self::SUCCESS;
        }

        $deleted = $this->delete($candidates);

        $this->line("削除: {$deleted} 件 / 参照が見つかり中止: ".(count($candidates) - $deleted).' 件');

        /*
         * 監査には **件数と、どの型のどの fingerprint か**だけを残す。
         * 定義そのもの（manifest 本文）は入れない。
         */
        $audit->record(
            'content.revision.manifest.prune',
            OperationClassification::Destructive,
            OperationContext::cli('agentcms:manifest-prune'),
            Result::success([], []),
            metadata: [
                'deleted' => $deleted,
                'candidates' => array_map(
                    static fn (\stdClass $row): array => [
                        'content_type' => (string) $row->content_type,
                        'fingerprint' => (string) $row->fingerprint,
                    ],
                    $candidates,
                ),
            ],
        );

        return self::SUCCESS;
    }

    /**
     * どの版からも指されていない定義。**bounded**（一度に全部見ない）。
     *
     * @return list<\stdClass>
     */
    private function orphans(int $limit): array
    {
        $rows = DB::table(SchemaManifestRepository::TABLE.' as m')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from(RevisionWriter::TABLE.' as r')
                    ->whereColumn('r.content_type', 'm.content_type')
                    ->whereColumn('r.schema_fingerprint', 'm.fingerprint');
            })
            ->orderBy('m.content_type')
            ->orderBy('m.fingerprint')
            ->limit($limit)
            ->get(['m.content_type', 'm.fingerprint']);

        /** @var list<\stdClass> $orphans */
        $orphans = array_values($rows->all());

        return $orphans;
    }

    /**
     * 候補をまとめて 1 つの transaction で消す。
     *
     * 途中で落ちたら、それまでに消したものも含めて**全部戻る**。
     *
     * @param  list<\stdClass>  $candidates
     */
    private function delete(array $candidates): int
    {
        return DB::transaction(function () use ($candidates): int {
            $deleted = 0;

            foreach ($candidates as $row) {
                /*
                 * **候補を選んだあとに使われたかもしれない。** transaction の中で
                 * 数え直す。1 件でもあれば消さない（FK の restrict はその後ろの網）。
                 */
                $referenced = DB::table(RevisionWriter::TABLE)
                    ->where('content_type', $row->content_type)
                    ->where('schema_fingerprint', $row->fingerprint)
                    ->count();

                if ($referenced > 0) {
                    continue;
                }

                DB::table(SchemaManifestRepository::TABLE)
                    ->where('content_type', $row->content_type)
                    ->where('fingerprint', $row->fingerprint)
                    ->delete();

                $deleted++;
            }

            return $deleted;
        });
    }
}
