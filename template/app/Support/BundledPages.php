<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\Concerns\ChecksViews;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Route;

/**
 * kit が同梱しているページ（`/about` `/service`）を出してよいか（D-1）。
 *
 * ## なぜ 1 か所に寄せるのか
 *
 * 🔴 **同じ問いを 4 か所が別々に答えていた**（2026-08-28 実測）。
 *
 * | どこ | 何を決めていたか |
 * | --- | --- |
 * | `DefaultNavigation::declared()` | 既定の導線に出すか |
 * | `PublicSiteController::about()` | 開いたときに 404 にするか |
 * | `SitemapController` | sitemap に載せるか |
 * | `ImportMenuCommand::ROUTE_VIEWS` | メニューへ入れてよいか |
 *
 * **3 つ目のページを足す日に、4 か所を思い出さないといけない。**
 * 忘れた場所は**黙って古い答えを出し続ける**（メニューには出るのに開くと 404、など）。
 *
 * ▷ **4 つ目は 2026-08-28 に足したものだった。**数える側が増やしていた。
 *
 * ## 何を答えるのか
 *
 * **「route は在るが view が無い」を出さない。**入り口だけ残して中身を消した
 * ページ（原稿待ち）は、`Route::has()` が true を返すのに**開くと 404 になる**。
 *
 * ## ここに無いものは検査しない
 *
 * 案件が生成する view（`pages.company` 等）は入れない。
 * **名前が案件ごとに変わるうえ、在るのが普通**なので、ここで見ると
 * 「取り込み直後にまだ生成されていない」だけで外してしまう。
 */
final class BundledPages
{
    use ChecksViews;

    /**
     * 同梱ページの route 名 → その中身を描く view。
     *
     * 🔴 **足すのはここだけ。**導線・404・sitemap・メニューは、
     * すべてこの表を見る。
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'about' => 'public.about',
        'service' => 'public.service',
    ];

    /**
     * 運用者が「非公開にした」同梱ページの route 名（サイト設定の中の内部キー）。
     *
     * 🔴 **公開前チェックが「見本のまま公開されています。消してください」と言うのに、
     * 管理画面に消す手段が無かった**（UX 一周 2026-09-27）。ファイルを消すのは
     * ターミナルを開かない人にはできないので、**出さない**という状態を設定で持つ。
     * ファイルは残るので、いつでも戻せる。
     */
    public const HIDDEN_SETTING = 'bundled_pages_hidden';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly ?SettingsStore $settings = null,
    ) {}

    /**
     * 同梱ページの route 名（`about` `service`）。**見本の一覧を作る側が読む**。
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return array_keys(self::PAGES);
    }

    /** 運用者が非公開にしているか */
    public function isHidden(string $route): bool
    {
        return isset(self::PAGES[$route]) && in_array($route, $this->hidden(), true);
    }

    /** 同梱の見本のままか（view の先頭に見本の目印が残っている） */
    public function isSample(string $route): bool
    {
        $view = self::PAGES[$route] ?? null;

        return $view !== null && $this->isBundledSample($view);
    }

    /** 非公開にする／戻す。**同梱ページ以外は受け付けない** */
    public function setHidden(string $route, bool $hidden): void
    {
        if (! isset(self::PAGES[$route])) {
            throw new \InvalidArgumentException("[{$route}] は同梱ページではありません");
        }

        $current = array_values(array_diff($this->hidden(), [$route]));

        if ($hidden) {
            $current[] = $route;
        }

        $this->store()->put([self::HIDDEN_SETTING => $current]);
    }

    /** @return list<string> */
    private function hidden(): array
    {
        $value = $this->store()->get(self::HIDDEN_SETTING, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function store(): SettingsStore
    {
        return $this->settings ?? app(SettingsStore::class);
    }

    /**
     * その route を公開面に出してよいか。
     *
     * **同梱ページでないものは、ここでは判断しない**（true を返す）。
     * 判断できないものを false にすると、**案件のページが黙って消える**。
     */
    /**
     * 同じ URL のページを公開していれば、そちらが見本に代わる（UX 一周 2026-09-28）。
     *
     * 見本ページの一覧は「/about にページを作る」と案内していたのに、作って公開しても
     * **見本の /about が出続けた**（非公開にしたときだけ譲っていたため）。案内どおりに
     * やった人が、どこで止まったのか分からない。公開したページがあれば、それが答え。
     */
    public function replacedByPage(string $route): bool
    {
        if (! isset(self::PAGES[$route])) {
            return false;
        }

        $page = Page::query()
            ->where('slug', $route)
            ->where('managed_by', Page::MANAGED_BY_DOCUMENT)
            ->first();

        return $page !== null && $page->isPublicallyVisible();
    }

    public function isAvailable(string $route): bool
    {
        if (! Route::has($route)) {
            return false;
        }

        if ($this->isHidden($route)) {
            return false;
        }

        $view = self::PAGES[$route] ?? null;

        return $view === null || $this->viewExists($view);
    }

    /**
     * 出せない理由。**出せるなら null**。
     *
     * 語彙は `MenuTree::pageReason()` と揃える。同じ事情を 2 通りの言い方で
     * 説明すると、読む人は別の問題だと思う。
     */
    public function reason(string $route): ?string
    {
        if (! Route::has($route)) {
            return '行き先がありません';
        }

        if ($this->isHidden($route)) {
            return '同梱の見本ページを非公開にしています（ページ一覧から戻せます）';
        }

        $view = self::PAGES[$route] ?? null;

        return $view !== null && ! $this->viewExists($view)
            ? '中身がまだありません'
            : null;
    }

    /**
     * その route が描く view の名前。
     *
     * **呼ぶ側に綴りを書かせない** — 書かせると、同じ問いに答える場所がまた増える。
     * 同梱ページでない route を渡すのは呼び違いなので、そのまま落とす。
     */
    public function viewFor(string $route): string
    {
        return self::PAGES[$route] ?? throw new \InvalidArgumentException(
            "[{$route}] は同梱ページではありません",
        );
    }
}
