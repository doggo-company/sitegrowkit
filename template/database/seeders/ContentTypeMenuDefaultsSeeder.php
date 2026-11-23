<?php

declare(strict_types=1);

namespace Database\Seeders;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * **中身が空の投稿メニューを、納品時に並べない**（B-39・オーナー決定 2026-08-24）。
 *
 * 一次情報はオーナーの原文である —「**中身が空の投稿メニューが並んでいると、
 * どちらに書くのか分からない**」。だから **0 件の同梱型は既定では出さず**、
 * 使う案件で有効にする（投稿タイプ一覧の「サイドバーに出す」）。
 *
 * ## 生成直後の 1 回だけ（`$newProject`）
 *
 * `agentcms dev` は**既存の案件でも毎回** `db:seed` を回す
 * （`packages/cli/src/commands/dev.ts`）。「行が無い＝まだ決めていない」だけを見て
 * 既定を書くと、**運用中の案件から項目が急に消える**。
 * 判断は呼び出し側（`DatabaseSeeder`）が**ほかの seeder が動く前に**測る。
 *
 * ## 既定を変えるのであって、判定は増やさない
 *
 * 「出すか」を決めるのは `ContentTypeLifecyclePolicy::showInMenu()` **1 か所**のままである
 * （SPEC §J.4）。ここがするのは**その 1 か所が読む行を、生成時に 1 度だけ書く**ことだけ。
 * 面ごとに「0 件なら隠す」を書かない — 書いた面と書かない面で答えが割れる。
 *
 * ## 数えるのは中身が入ったあと
 *
 * 例のコンテンツや import が入れた型は「**使う型**」である。
 * 先に数えると、これから入る中身が見えず**全部外してしまう**。
 *
 * ## 隠すのではない
 *
 * サイドバーから外すだけで、**URL では開ける**（B-39 裁定: route は閉じない）。
 */
class ContentTypeMenuDefaultsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(bool $newProject = false): void
    {
        // 既存の案件では**何もしない**。ここが「急にメニューが消えた」を防いでいる
        if (! $newProject) {
            return;
        }

        $types = app(ContentTypeResolver::class);
        $settings = app(ContentTypeSettingsResolver::class);
        $writer = app(ContentTypeSettingsWriter::class);

        $editorial = [];
        $hide = [];

        foreach ($types->availableTypes() as $id) {
            // 投稿タイプだけ。メディア / 受信箱 / サイト設定は「空でも使う」ものである
            if (! EditorialCollections::isEditorial($types->schema($id))) {
                continue;
            }

            $editorial[] = $id;

            // 既に決めてある型は触らない（MenuSeeder と同じ作法）
            if ($settings->for($id)->stored) {
                continue;
            }

            if ($types->newModel($id)->newQuery()->exists()) {
                continue;
            }

            $hide[] = $id;
        }

        /*
         * 🔴 **書ける入れ物を、1 つは残す**（オーナー決定 2026-09-06「A で進めて」）。
         *
         * 元の決定（2026-08-24）は「**中身が空の投稿メニューが並んでいると、
         * どちらに書くのか分からない**」だった。当時は空の型が複数あった。
         *
         * ところが**利用者へ配られる投稿タイプは「投稿」1 つだけ**である（配布境界）。
         * 全部隠すと**メニューから記事を書く道が消える** — ダッシュボードは
         * 「コンテンツを編集する」と案内しているのに、行き先が無い状態になっていた
         * （2026-08-30 に実測）。
         *
         * **「並べない」の意図は残す。**空の型が複数あるときは、やはり並べない。
         * 消えるのが最後の 1 つになるときだけ、それを残す。
         */
        if ($hide !== [] && count($hide) === count($editorial)) {
            array_shift($hide);
        }

        foreach ($hide as $id) {
            $writer->save(
                $id,
                ['display_overrides' => json_encode(['show_in_menu' => false], JSON_UNESCAPED_UNICODE)],
                0,
            );
        }
    }
}
