<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages\Console;

use AgentCms\Core\Pages\Page;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * 自動保存で増えた版を間引く（Phase 9.6 / P0-C6b・SPEC §4.11 / Owner Gate V8）。
 *
 * ## 保存の経路には入れない
 *
 * 間引きは編集のたびには走らない（Owner 決定 2026-08-19）。`agentcms:audit-prune` と
 * 同じく**独立したコマンド**である。保存の途中に削除を挟むと、保存が遅くなるだけでなく、
 * **削除の失敗で保存そのものが落ちる**。
 *
 * ## DB は止めてくれない
 *
 * `pages.draft_version_id` / `published_version_id` は外部キーではない（§4.11）。
 * **今そのページが指している版を消しても、DB は 1 度も止めない。**
 * そして消えた後は静かである — `PageRepository::draft()` が公開版から複製し直し、
 * 公開版も無ければ空の draft を作る。**例外も警告も出ないまま、編集していた内容が消える。**
 *
 * だから守りはここに要る。**消してよいのは、次の全部を満たす版だけ**である:
 *
 * 1. `autosave = true`（明示的にそう名乗って作られた版）
 * 2. そのページの `draft_version_id` でも `published_version_id` でもない
 * 3. そのページの自動保存の版を新しい順に並べて、**上位 N 件に入らない**
 *
 * ## 数えるのと消すのを、同じ問い合わせから作る
 *
 * `--dry-run` が「消す予定」を別の式で数えると、**予告と実行がずれる**。
 * 対象の id を 1 度だけ作り、数えるのも消すのもそれを使う。
 */
final class PrunePageVersionsCommand extends Command
{
    /** 残す件数の既定（§4.11 — 「事故で失ってよい作業量」であって操作の快適さではない） */
    public const DEFAULT_KEEP = 50;

    protected $signature = 'agentcms:page-version-prune
        {--keep= : ページごとに残す自動保存の版の数（既定は設定値）}
        {--page= : 対象のページ ID（省略時はすべて）}
        {--dry-run : 実際には消さず、対象件数だけを表示する}';

    protected $description = '自動保存で増えたページの版を、ページごとに直近 N 件だけ残して削除します';

    public function handle(ConnectionInterface $connection): int
    {
        $keep = $this->keep();

        if ($keep < 1) {
            /*
             * **0 を「全部消す」にしない。** 打ち間違いで履歴が消える方向へ倒れる設定は
             * 受け取らない（`audit-prune` の「0 以下なら何もしない」と同じ側）。
             */
            $this->error('--keep は 1 以上で指定してください（0 は履歴を全部消す指定になります）');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $removable = [];

        foreach ($this->pages($connection) as $page) {
            $removable = [...$removable, ...$this->removableFor($connection, $page, $keep)];
        }

        if ($removable === []) {
            $this->info('間引く版はありません。');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(count($removable).' 件が対象です（--dry-run のため削除していません）。');

            return self::SUCCESS;
        }

        $deleted = $connection->table('page_versions')->whereIn('id', $removable)->delete();

        $this->info("{$deleted} 件の自動保存の版を削除しました。");

        return self::SUCCESS;
    }

    /**
     * そのページで消してよい版の id。
     *
     * @param  array{id: int, draft: int|null, published: int|null}  $page
     * @return list<int>
     */
    private function removableFor(ConnectionInterface $connection, array $page, int $keep): array
    {
        $versions = $connection->table('page_versions')
            ->where('page_id', $page['id'])
            // **自動保存だけ。** 公開・復元・導入前の版はここへ来ない
            ->where('autosave', true)
            ->orderByDesc('revision')
            ->pluck('id')
            ->map(intval(...))
            ->all();

        /*
         * **数えてから、守る。**
         *
         * 守る版を先に候補から外すと、`--keep=3` が 4 件残す（3 件 + 守った 1 件）。
         * 「残す件数」と言いながら数が合わないのは、指定した人の見立てを裏切る。
         * 新しい順に N 件を残し、**そこから守るものを取り除く**。
         *
         * 古い公開版のように、N の外に在って守られる版は残る。**それは正しい** —
         * 守る理由が「新しさ」ではないからで、そのぶん残ることは説明できる。
         */
        $removable = array_slice($versions, $keep);

        $protected = array_values(array_filter([$page['draft'], $page['published']]));

        return array_values(array_diff($removable, $protected));
    }

    /**
     * 対象のページと、**そのページが指している版**。
     *
     * 素の行のまま持ち回すと、列の名前が間引きの判断の中まで漏れる
     * （`BundleJudge` で同じことをして直した — 27E-2）。ここで形に写して渡す。
     *
     * @return list<array{id: int, draft: int|null, published: int|null}>
     */
    private function pages(ConnectionInterface $connection): array
    {
        $only = $this->option('page');

        $rows = $connection->table((new Page)->getTable())
            ->select(['id', 'draft_version_id', 'published_version_id'])
            ->when($only !== null && $only !== '', fn ($query) => $query->where('id', (int) $only))
            ->orderBy('id')
            ->get();

        $pages = [];

        foreach ($rows as $row) {
            $pages[] = [
                'id' => (int) $row->id,
                'draft' => $row->draft_version_id === null ? null : (int) $row->draft_version_id,
                'published' => $row->published_version_id === null ? null : (int) $row->published_version_id,
            ];
        }

        return $pages;
    }

    private function keep(): int
    {
        $option = $this->option('keep');

        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        $configured = (int) config('agentcms.pages.autosave_keep', self::DEFAULT_KEEP);

        return $configured > 0 ? $configured : self::DEFAULT_KEEP;
    }
}
