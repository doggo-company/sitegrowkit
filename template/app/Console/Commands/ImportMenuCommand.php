<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\Menus\SaveMenu;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuLinkType;
use App\Console\Concerns\SpeaksJson;
use App\Support\BundledPages;
use Illuminate\Console\Command;

/**
 * 取り込んだナビを、CMS のメニューとして起こす（B-56）。
 *
 * ## なぜ要るのか
 *
 * ナビを CMS から描く形に変えても、**メニューが空なら原本の並びが出るだけ**で、
 * 運用者は何も編集できない。原本に書かれていた項目を 1 回だけデータにする。
 *
 * ## 行き先を止める 2 つの検査
 *
 * 🔴 **route が無いものは入れない。**`route()` を呼んだ瞬間に例外になる。
 *
 * 🔴 **route は在るが view が無いものも入れない。**開くと 404 になる。
 * 入り口だけ残してあるページ（原稿待ちで route は残す）が実際にあり、
 * **メニューに出すと「押したら 404」になる**（2026-08-28・実案件の
 * `/about` `/service` で実測）。**弾いた項目は名前を返す。黙って落とさない。**
 *
 * ▷ **その判断は `BundledPages` が持つ**（D-1）。ここに表を持たない —
 * 同じ問いに答える場所が 4 つあった（導線 / 404 / sitemap / メニュー）ので、
 * **1 つに寄せた**。**3 つ目のページを足すときは `BundledPages` だけを触る。**
 *
 * ## 既に在るなら触らない
 *
 * そのメニューに項目が 1 つでも在れば **何もしない**（`action: kept`）。
 * `agentcms:import-form` / `import-page` と同じ作法で、
 * **運用者が組み替えたものを import が上書きしてよい理由が無い。**
 *
 * ## AI へは公開しない
 *
 * CLI 専用の内部経路で、`OperationCatalog` にも MCP tool にも API にも足さない。
 * 作成そのものは `SaveMenu` を通り、validation / policy / 監査を迂回しない。
 *
 * ## 入出力
 *
 * 入力は **stdin の JSON** のみ。
 *
 * ```json
 * {"menu": "header", "items": [
 *   {"title": "HOME", "link_type": "custom", "link_url": "/#home"},
 *   {"title": "COMPANY", "link_type": "route", "link_route": "company"}
 * ]}
 * ```
 */
final class ImportMenuCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:import-menu';

    protected $description = 'Template Import 用: 取り込んだナビを CMS のメニューとして起こす（既に在れば触らない）';

    /** 1 つのメニューに入れられる項目の上限（暴走した plan を止める） */
    private const MAX_ITEMS = 50;

    private const ALLOWED_ROOT_KEYS = ['menu', 'items'];

    private const ALLOWED_ITEM_KEYS = ['title', 'link_type', 'link_url', 'link_route'];

    /** ここで作ってよい種類。**page / content は id が要る**ので取り込みでは使わない */
    private const ALLOWED_TYPES = ['custom', 'route'];

    /**
     * 監査に残る呼び出し元。**外部入力にしない**（入力で偽装できてしまう）。
     */
    private const ACTOR = 'template-import';

    public function handle(SaveMenu $save, BundledPages $pages): int
    {
        $raw = $this->readStdin();
        $input = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($input)) {
            return $this->refuse('標準入力の JSON を読み取れませんでした');
        }

        $unknown = array_diff(array_keys($input), self::ALLOWED_ROOT_KEYS);

        if ($unknown !== []) {
            return $this->refuse('受け付けないキーがあります: '.implode(', ', $unknown));
        }

        $menu = $input['menu'] ?? null;

        if (! is_string($menu) || $menu === '') {
            return $this->refuse('menu は必須です');
        }

        $validated = $this->validatedItems($input['items'] ?? null, $pages);

        if (is_string($validated)) {
            return $this->refuse($validated);
        }

        [$items, $skipped] = $validated;

        if ($items === []) {
            return $this->refuse(
                '入れられる項目が 1 つもありません'
                    .($skipped === [] ? '' : '（外した: '.implode(', ', $skipped).'）'),
            );
        }

        /*
         * **既に項目が在れば触らない。**
         *
         * `SaveMenu` は渡した項目で**丸ごと置き換える**ので、
         * 先に見ないと運用者が組み替えた並びを消してしまう。
         */
        $existing = Menu::query()->where('key', $menu)->first();

        /*
         * **器が無いなら、取り込みの問題ではない。**
         *
         * `header` / `footer` は同梱の seeder が作る。無い状態は
         * 「まだ setup が済んでいない」なので、**ここで作らずに言う**
         * （作ると、器の出どころが 2 通りになる）。
         */
        if ($existing === null) {
            return $this->refuse(
                "メニュー [{$menu}] がありません（先に `php artisan db:seed --class=MenuSeeder` を通してください）",
            );
        }

        if ($existing->items()->exists()) {
            return $this->emit([
                'status' => 'success',
                'action' => 'kept',
                'menu' => $menu,
                'items' => 0,
                'skipped' => $skipped,
                'message' => "メニュー [{$menu}] には既に項目があるので触っていません",
            ], failure: false);
        }

        /*
         * **読んだ版をそのまま渡す。**`SaveMenu` は版が合ったときだけ書く（CAS）。
         * 省略すると「版の指定が必要です」で断られる。
         * 直前に読んだ 1 行なので、合わなければ**誰かが先に保存している**。
         */
        $result = $save->execute(
            $menu,
            [],
            $items,
            OperationContext::cli(self::ACTOR),
            (int) $existing->lock_version,
        );

        if ($result->status !== OperationStatus::Success) {
            return $this->emit([
                'status' => $result->status->value,
                'action' => 'none',
                'message' => "メニュー [{$menu}] を作成できませんでした",
                'reason' => $result->message,
                /*
                 * **直し方の分からない断りにしない。**`Result::validationError` の
                 * message には「何が悪かったか」が入らないので、`errors` を落とすと
                 * 利用者は原因を知る手段が無くなる。
                 */
                'errors' => $result->errors,
            ], failure: true);
        }

        return $this->emit([
            'status' => 'success',
            'action' => 'created',
            'menu' => $menu,
            'items' => count($items),
            'skipped' => $skipped,
        ], failure: false);
    }

    /**
     * 項目を検査する。**駄目なら理由の文字列**、通れば `[入れる項目, 外した名前]`。
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}|string
     */
    private function validatedItems(mixed $items, BundledPages $pages): array|string
    {
        if (! is_array($items) || $items === []) {
            return 'items には 1 つ以上の項目が要ります';
        }

        if (count($items) > self::MAX_ITEMS) {
            return '項目が多すぎます（上限 '.self::MAX_ITEMS.' 個）';
        }

        $valid = [];
        $skipped = [];

        foreach (array_values($items) as $index => $item) {
            $nth = $index + 1;

            if (! is_array($item)) {
                return "{$nth} 個目の項目はオブジェクトで渡してください";
            }

            $unknown = array_diff(array_keys($item), self::ALLOWED_ITEM_KEYS);

            if ($unknown !== []) {
                return "{$nth} 個目の項目に受け付けないキーがあります: ".implode(', ', $unknown);
            }

            $title = $item['title'] ?? null;
            $type = $item['link_type'] ?? null;

            if (! is_string($title) || $title === '') {
                return "{$nth} 個目の項目に title がありません";
            }

            if (! is_string($type) || ! in_array($type, self::ALLOWED_TYPES, true)) {
                return "{$nth} 個目の項目 [{$title}] の link_type は "
                    .implode(' / ', self::ALLOWED_TYPES).' のいずれかです';
            }

            if ($type === MenuLinkType::Route->value) {
                $route = $item['link_route'] ?? null;

                if (! is_string($route) || $route === '') {
                    return "{$nth} 個目の項目 [{$title}] に link_route がありません";
                }

                $why = $this->routeUnusable($route, $pages);

                if ($why !== null) {
                    // **黙って落とさない。**何を外したかを返す
                    $skipped[] = "{$title}（{$why}）";

                    continue;
                }

                $valid[] = ['title' => $title, 'link_type' => $type, 'link_route' => $route];

                continue;
            }

            $url = $item['link_url'] ?? null;

            if (! is_string($url) || $url === '') {
                return "{$nth} 個目の項目 [{$title}] に link_url がありません";
            }

            $valid[] = ['title' => $title, 'link_type' => $type, 'link_url' => $url];
        }

        return [$valid, $skipped];
    }

    /**
     * その route をメニューに出してよいか。**駄目なら理由**、よければ null。
     *
     * 🔴 **route が在ることと、開けることは別**である。
     * 入り口だけ残して view を消したページは、`Route::has()` は true を返すのに
     * **開くと 404 になる**。メニューに出すと「押したら 404」になる。
     */
    private function routeUnusable(string $route, BundledPages $pages): ?string
    {
        return $pages->reason($route);
    }
}
