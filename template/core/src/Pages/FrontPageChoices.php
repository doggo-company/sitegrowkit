<?php

declare(strict_types=1);

namespace AgentCms\Core\Pages;

/**
 * **トップページに指定できるページ**の一覧（R-5）。
 *
 * ## 汎用の「ページ一覧」ではない
 *
 * 名前をわざと限定してある。ここが返す集合は `FrontPage::resolve()` が
 * **実際に出せる**ものと同じでなければならず、その条件（公開中・ゴミ箱でない・
 * ビジュアルエディタ管理）は**トップページ固有**だからである。
 *
 * `page` 型そのものはもっと広い（公開中のページなら何でも指せる。`SchemaRules`）。
 * 別の場所で `page` 型を使うときは、**その場所の条件で候補を作る** —
 * この一覧を流用すると、選べるはずのものが黙って消える。
 *
 * ## 候補と、出せるものと、保存できるものを一致させる
 *
 * 下書きやゴミ箱を候補に出すと、**選べたのに公開面へ出ない**指定を作らせてしまう。
 * 一致は `FrontPageTest` が固定する — 人の記憶に預けない。
 *
 * ## 値は id だけ
 *
 * `label` と `slug` は**画面に見せるため**に添えるが、保存されるのは id である。
 * 画面がこの 2 つを保存し始めると、ページ名を変えた日に古い名前が残る。
 */
final class FrontPageChoices
{
    /**
     * @return list<array{id: int, label: string, slug: string}>
     */
    public function all(): array
    {
        $rows = Page::query()
            ->where('managed_by', Page::MANAGED_BY_DOCUMENT)
            ->whereNull('trashed_at')
            ->orderBy('slug')
            ->get()
            ->filter(static fn (Page $page): bool => $page->isPublicallyVisible())
            ->map(static fn (Page $page): array => [
                'id' => (int) $page->getKey(),
                'label' => $page->label(),
                'slug' => (string) $page->slug,
            ])
            ->all();

        // `filter` が抜いた添字を詰め直す（画面へは順番のある一覧として渡す）
        return array_values($rows);
    }
}
