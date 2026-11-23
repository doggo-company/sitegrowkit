<?php

namespace Database\Seeders;

use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuItem;
use AgentCms\Core\Menus\MenuTarget;
use App\Support\DefaultNavigation;
use Illuminate\Database\Seeder;

/**
 * 既定の導線を入れる（Phase 9.9 / STAGE 22C・SPEC §H.1・OG11）。
 *
 * 直書きだったヘッダーとフッターの 5 項目を、そのままデータにする。
 * 空で始めると、生成直後のサイトから**導線が消える**（いまは直書きで出ている）。
 *
 * ## 冪等
 *
 * 既に同じ `key` の本があれば**何もしない**。名前も項目も触らない —
 * 利用者が組み替えたものを seeder が上書きしてよい理由が無い。
 *
 * ## 無いものは入れない
 *
 * `works` / `news` の module が無い生成物では、その 2 項目を入れない。
 * 直書き時代の `@if (Route::has(...))` ガードは `DefaultNavigation` が持つ（SPEC §0.2）。
 *
 * ## 項目そのものは持たない
 *
 * 順序も表示名も **`App\Support\DefaultNavigation` が正本**（B-28）。
 * メニューが空のときの逃げ道も同じ宣言を読むので、片方だけ育つことがない。
 *
 * ## managed_by
 *
 * `header` / `footer` は `layouts/public.blade.php` が名前で参照しているので
 * **code 管理**にする（消せない・key を変えられない。名前と中身は編集できる — OG1）。
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // 既定の項目は **1 か所の宣言**から来る（B-28）。ここでは組み立てない
        $items = DefaultNavigation::seedItems();

        foreach (['header' => 'ヘッダー', 'footer' => 'フッター'] as $key => $name) {
            if (Menu::query()->where('key', $key)->exists()) {
                continue;
            }

            $menu = new Menu;
            $menu->key = $key;
            $menu->name = $name;
            $menu->managed_by = Menu::MANAGED_BY_CODE;
            $menu->save();

            foreach ($items as $order => $item) {
                $row = new MenuItem;
                // menu_id は fillable ではない（項目を別の本へ移す操作は無い）
                $row->menu_id = (int) $menu->getKey();
                $row->fill($item + ['sort_order' => $order, 'target' => MenuTarget::Self->value]);
                $row->save();
            }
        }
    }
}
