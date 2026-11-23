<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /*
         * **これが生成直後の 1 回目か**を、ほかの seeder が動く前に測る（B-39）。
         *
         * `agentcms dev` は既存の案件でも毎回ここを回すので、あとから見ると
         * 「1 回目」と「2 回目以降」の区別がつかない。管理者がまだ 1 人も居ないことが、
         * **この db:seed が最初の 1 回である**ことの印である。
         */
        $newProject = ! User::query()->exists();

        /*
         * 既定の導線（Phase 9.9 / P0-4）。空で始めるとサイトから導線が消える。
         *
         * `ContactFormSeeder` も**毎回**呼ぶ（R-12 / 受付の一本化）。
         * 生成直後だけでなく、**既存の案件がアップデートしたとき**にも
         * `/contact` の受付フォームが用意される必要がある — 無いと管理画面の
         * 「受付」に空のフォームと旧お問い合わせが 2 つ並んだままになる。
         * 既に在れば何もしない（seeder 側で冪等）。
         */
        $seeders = [AdminUserSeeder::class, MenuSeeder::class, ContactFormSeeder::class];

        /*
         * デモの投稿は **kit の例**であって、生成 project には配られない（SPEC §C.5）。
         * 在るときだけ呼ぶ。無い環境では管理者だけを作って終わる。
         */
        if (class_exists(DemoContentSeeder::class)) {
            $seeders[] = DemoContentSeeder::class;
        }

        $this->call($seeders);

        /*
         * **中身が入ったあとに数える**（B-39）。
         * 例のコンテンツや import が入れた型は「使う型」なので、先に数えると全部外れる。
         */
        $this->call(ContentTypeMenuDefaultsSeeder::class, false, ['newProject' => $newProject]);
    }
}
