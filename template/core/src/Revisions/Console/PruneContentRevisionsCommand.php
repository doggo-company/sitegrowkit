<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions\Console;

use AgentCms\Core\Revisions\RevisionKind;
use AgentCms\Core\Revisions\RevisionWriter;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * 自動保存で増えた記事の版を間引く（Phase 9.15 / STAGE 28C・SPEC §C.5〜C.7）。
 *
 * ## 保存の経路には入れない
 *
 * 間引きは編集のたびには走らない。`agentcms:page-version-prune` と同じく
 * **独立したコマンド**である。保存の途中に削除を挟むと、保存が遅くなるだけでなく、
 * **削除の失敗で保存そのものが落ちる**。
 *
 * ## ページ側の守りを、そのまま写さない
 *
 * ページの間引きは「**いま指している版を消さない**」を守っている
 * （`pages.draft_version_id` は外部キーではなく、消しても DB が止まらないため）。
 *
 * **記事側にはこの守りの移植先が無い。** 版を指す列が 1 つも存在せず、
 * 履歴が純粋で、現在の内容は記事本体の行が持つ。
 * **無い守りを、在るふりをして書かない。**
 *
 * ▷ 将来 `current_revision_id` のような列が増えたら、**そのときは条件も増える**。
 *
 * ## 消してよいのは自動保存だけ
 *
 * 1. `kind = 'autosave'`（明示的にそう名乗って作られた版）
 * 2. その記事の自動保存の版を新しい順に並べて、**上位 N 件に入らない**
 *
 * `kind = 'manual'` は 1 件も消さない。**利用者が意図して残したもの**だからである。
 *
 * ## 数えるのと消すのを、同じ問い合わせから作る
 *
 * `--dry-run` が「消す予定」を別の式で数えると、**予告と実行がずれる**。
 * 対象の id を 1 度だけ作り、数えるのも消すのもそれを使う。
 */
final class PruneContentRevisionsCommand extends Command
{
    /**
     * 残す件数の既定。
     *
     * **決めているのは「事故で失ってよい作業量 = 10 分」**であって件数ではない。
     * 20 = 30 秒間隔で 10 分ぶん。**間隔を変えたら、ここも変える。**
     */
    public const DEFAULT_KEEP = 20;

    protected $signature = 'agentcms:revision-prune
        {--keep= : 記事ごとに残す自動保存の版の数（既定は設定値）}
        {--type= : 対象の投稿タイプ（省略時はすべて）}
        {--dry-run : 実際には消さず、対象件数だけを表示する}';

    protected $description = '自動保存で増えた記事の版を、記事ごとに直近 N 件だけ残して削除します';

    public function handle(ConnectionInterface $connection): int
    {
        $keep = $this->keep();

        if ($keep < 1) {
            /*
             * **0 を「全部消す」にしない。** 打ち間違いで履歴が消える方向へ倒れる指定は
             * 受け取らない（`page-version-prune` と同じ側）。
             */
            $this->error('--keep は 1 以上で指定してください（0 は履歴を全部消す指定になります）');

            return self::FAILURE;
        }

        $removable = [];

        foreach ($this->entries($connection) as $entry) {
            $removable = [...$removable, ...$this->removableFor($connection, $entry, $keep)];
        }

        if ($removable === []) {
            $this->info('間引く版はありません。');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info(count($removable).' 件が対象です（--dry-run のため削除していません）。');

            return self::SUCCESS;
        }

        $deleted = $connection->table(RevisionWriter::TABLE)->whereIn('id', $removable)->delete();

        $this->info("{$deleted} 件の自動保存の版を削除しました。");

        return self::SUCCESS;
    }

    /**
     * 自動保存の版を持つ記事（型と id の組）。
     *
     * @return list<array{type: string, id: int}>
     */
    private function entries(ConnectionInterface $connection): array
    {
        $query = $connection->table(RevisionWriter::TABLE)
            ->where('kind', RevisionKind::Autosave->value)
            ->select('content_type', 'content_id')
            ->distinct();

        $type = $this->option('type');

        if (is_scalar($type) && $type !== '') {
            $query->where('content_type', (string) $type);
        }

        // `all()` は key を保つので list にならない。**`@var` で黙らせず、実際に list にする**
        return array_values($query->get()
            ->map(static fn (object $row): array => [
                'type' => (string) $row->content_type,
                'id' => (int) $row->content_id,
            ])
            ->all());
    }

    /**
     * その記事で消してよい版の id。
     *
     * @param  array{type: string, id: int}  $entry
     * @return list<int>
     */
    private function removableFor(ConnectionInterface $connection, array $entry, int $keep): array
    {
        $ids = $connection->table(RevisionWriter::TABLE)
            ->where('content_type', $entry['type'])
            ->where('content_id', $entry['id'])
            // **自動保存だけ。** 手で残した版はここへ来ない
            ->where('kind', RevisionKind::Autosave->value)
            ->orderByDesc('revision')
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return array_values(array_slice($ids, $keep));
    }

    private function keep(): int
    {
        $option = $this->option('keep');

        // **型で受け取り方を変えない。** artisan は文字列で、試験は int で渡してくる。
        // `is_string()` だけで見ると、試験からの指定が黙って無視される
        if (is_scalar($option) && $option !== '') {
            return (int) $option;
        }

        $configured = (int) config('agentcms.content.autosave_keep', self::DEFAULT_KEEP);

        return $configured > 0 ? $configured : self::DEFAULT_KEEP;
    }
}
