<?php

declare(strict_types=1);

namespace AgentCms\Core\Components;

use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Illuminate\Support\Facades\DB;

/**
 * 誰がこの再利用 component を使っているか（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 走査は 1 か所だけ
 *
 * 「参照が在るか」（archive の拒否）と「何件か」（影響数）は**同じ問い**である。
 * 別々に書くと「削除は 3 件・publish は 5 件」のずれが必ず起き、
 * **どちらが本当かを利用者が決められない**。だから歩くのはここだけにして、
 * **数え方は消費する側の filter で分ける**:
 *
 * | 誰が | 何を見るか | なぜ |
 * | --- | --- | --- |
 * | `publish` の確認 | **公開中のページを主に**、下書きは分けて添える | 問いは「いま公開されている見た目がいくつ変わるか」。過大な警告は読まれなくなる |
 * | `archive` の拒否 | **下書きでもゴミ箱でも、参照は参照** | 問いは「消してよいか」。1 つでも残っていれば断る |
 *
 * ## 参照表は持たない（Owner Gate V12）
 *
 * 正本を 1 つにするため、document を走査する。件数が問題になってから index を足す。
 *
 * ## いま効いている版だけを見る
 *
 * 各ページの draft と published の 2 行だけ。**過去の版は数えない** —
 * 問いは「この変更が**どのページに出るか**」なので、出ない版を数えると嘘になる。
 */
final class ReusableReferences
{
    /**
     * その再利用 component を参照しているページ。
     *
     * @return list<Reference>
     */
    public function pagesUsing(string $reusableId): array
    {
        $references = [];

        foreach ($this->candidatePages($reusableId) as $page) {
            $inPublished = $this->references($page->published_version_id, $reusableId);
            $inDraft = $this->references($page->draft_version_id, $reusableId);

            if (! $inPublished && ! $inDraft) {
                continue;
            }

            $references[] = new Reference(
                pageId: (int) $page->id,
                title: (string) $page->title,
                inPublished: $inPublished,
                inDraft: $inDraft,
                trashed: $page->trashed_at !== null,
            );
        }

        return $references;
    }

    /**
     * 走査する前に**粗く絞る**。
     *
     * `LIKE` は index の代わりで、**正しさは木を歩く側が持つ**（JSON の中の文字列が
     * たまたま一致することはあり得るので、これだけでは決めない）。
     *
     * @return list<Page>
     */
    private function candidatePages(string $reusableId): array
    {
        $needle = '%'.$reusableId.'%';

        $versionIds = DB::table('page_versions')
            ->where('document', 'like', $needle)
            ->pluck('id')
            ->all();

        if ($versionIds === []) {
            return [];
        }

        return array_values(
            Page::query()
                ->where(function ($query) use ($versionIds): void {
                    $query->whereIn('draft_version_id', $versionIds)
                        ->orWhereIn('published_version_id', $versionIds);
                })
                ->get()
                ->all()
        );
    }

    /** その版の木が、その再利用 component を指しているか */
    private function references(?int $versionId, string $reusableId): bool
    {
        if ($versionId === null) {
            return false;
        }

        $version = PageVersion::query()->find($versionId);

        if (! $version instanceof PageVersion) {
            return false;
        }

        return in_array($reusableId, self::instanceIds($version->document), true);
    }

    /**
     * その木が指している再利用 component の id（**重複は畳む**）。
     *
     * 描画側もこれを使う。**node ごとに引かず、1 度歩いて集めてから 1 回で引く**ため。
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public static function instanceIds(array $document): array
    {
        $ids = [];

        /*
         * **document でも node でも受ける。** 呼ぶ側は「ページの document」と
         * 「実体の部分木」の両方を渡してくる（実体は `root` を持たない）。
         * ここで揃えないと、node を渡した側が**黙って 0 件になる**。
         */
        $wrapped = isset($document['root']) ? $document : ['root' => $document];

        DocumentTree::walk($wrapped, static function (array $node) use (&$ids): void {
            $instanceOf = $node['instanceOf'] ?? null;

            if (is_string($instanceOf) && $instanceOf !== '') {
                $ids[$instanceOf] = true;
            }
        });

        return array_keys($ids);
    }
}
