<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use App\Support\Concerns\ChecksViews;
use App\Support\Industry\IndustrySetup;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Route;

/**
 * 同梱の見本ページの一覧と、それぞれの直し方（UX 一周 2026-09-27）。
 *
 * ## なぜ要るのか
 *
 * 🔴 公開前チェックは `/` `/about` `/service` `/contact` を「同梱の見本のまま公開されています。
 * 差し替えるか、このページを消してください」と指摘する。ところが **ページ一覧には
 * 出てこず、行を押しても何も起きず、管理画面のどこからも直せなかった。**
 * 指示に従いたくても手段が無い — WP から来た人はここで止まる。
 *
 * 4 つは作りが違うので、**直し方も 4 通り**ある。ここではそれを 1 か所で答える:
 *
 * | URL | 直し方 |
 * | --- | --- |
 * | `/` | ページを作って、サイト設定の「トップページ」で選ぶ |
 * | `/about` `/service` | 非公開にする（ファイルは残るので戻せる）／同じ URL のページを作る |
 * | `/contact` | 識別子 `contact` のフォームを作って公開する |
 *
 * ## ここは判定しない
 *
 * 「見本のまま公開されているか」を数えるのは `PublicationReadiness`（描いた HTML を見る）。
 * ここは**直す場所へ連れて行く**だけで、見本かどうかは作り（見本の目印・設定）から決める。
 */
final class SamplePages
{
    use ChecksViews;

    public function __construct(
        private readonly ViewFactory $views,
        private readonly BundledPages $bundled,
        private readonly FrontPage $frontPage,
        private readonly ImportedPages $imported,
        private readonly ContactEntry $contact,
    ) {}

    /** 公開前チェックの `where`（URL）→ 一覧の中の行（アンカー）。見本でない URL は null */
    public static function anchorFor(string $path): ?string
    {
        return match ($path) {
            '/' => 'sample-home',
            '/about' => 'sample-about',
            '/service' => 'sample-service',
            '/contact' => 'sample-contact',
            default => null,
        };
    }

    /**
     * いま直す必要がある（または非公開にしてある）見本ページ。
     *
     * 1 行は `key` `path` `title` `state`（sample|hidden）`stateLabel` `note` `actions`
     * （`kind` = link|view|hide|show・`label`・`href` か `route`）。
     *
     * @return list<array<string, mixed>>
     */
    public function forAdmin(): array
    {
        return array_values(array_filter([
            $this->home(),
            ...array_map(fn (string $route) => $this->bundledPage($route), $this->bundled->routes()),
            $this->contactPage(),
        ]));
    }

    /** @return array<string, mixed>|null */
    private function home(): ?array
    {
        if (
            $this->frontPage->resolve() !== null
            || $this->imported->owner(DocumentPageView::PRESERVED_LAYOUT_PREFIX.'home') !== null
            || ! $this->isBundledSample('public.home')
        ) {
            return null;
        }

        return [
            'key' => 'home',
            'path' => '/',
            'title' => 'トップページ',
            'state' => 'sample',
            'stateLabel' => '見本を公開中',
            'note' => 'トップ用のページを作って公開し、サイト設定の「トップページ」で選ぶと、見本のトップは出なくなります。',
            'actions' => [
                ['kind' => 'link', 'label' => 'トップ用のページを作る', 'href' => '/admin/pages/create?'.http_build_query(['title' => 'トップページ', 'slug' => 'home'])],
                ['kind' => 'link', 'label' => 'サイト設定でトップページを選ぶ', 'href' => '/admin/site#front_page_id'],
                ['kind' => 'view', 'label' => '見本を見る', 'href' => '/'],
            ],
        ];
    }

    /**
     * 見本と同じ名前のページを作ろうとしているなら、その見本の URL（`about` / `service`）。
     *
     * UX 一周 4 周目 R4-5: 「メニューと料金」を作ると `/price` になり、同じ名前の見本（`/service`）が
     * 公開のまま残った。一覧にもリンク先の候補にも同じ名前が 2 つ並び、メニューは見本を指したまま。
     * URL を空のまま作るときは、見本の URL で作る。公開すると見本の代わりに出る（`replacedByPage`）ので、
     * メニューもそのままこのページを指す。
     *
     * 見本でないとき（取り込み済み・同じ URL のページがある・非公開にした）は null。
     */
    public function replaceableRouteFor(string $title): ?string
    {
        $wanted = (string) preg_replace('/[\s　]+/u', '', $title);

        if ($wanted === '') {
            return null;
        }

        foreach ($this->replaceable() as $route => $sampleTitle) {
            if ((string) preg_replace('/[\s　]+/u', '', $sampleTitle) === $wanted) {
                return $route;
            }
        }

        return null;
    }

    /**
     * ページで置き換えられる見本（URL → 見本の名前）。作成の画面が、同じ名前を打ったときに言うため。
     *
     * @return array<string, string>
     */
    public function replaceable(): array
    {
        $out = [];

        foreach ($this->bundled->routes() as $route) {
            if (! Route::has($route) || $this->bundled->isHidden($route) || ! $this->bundled->isSample($route)) {
                continue;
            }

            if ($this->imported->owner(DocumentPageView::PRESERVED_LAYOUT_PREFIX.$route) !== null
                || Page::query()->where('slug', $route)->exists()) {
                continue;
            }

            $out[$route] = $this->titleOf($route);
        }

        return $out;
    }

    private function titleOf(string $route): string
    {
        return ['about' => '私たちについて', 'service' => app(IndustrySetup::class)->serviceNoun()][$route] ?? $route;
    }

    /** @return array<string, mixed>|null */
    private function bundledPage(string $route): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        $hidden = $this->bundled->isHidden($route);
        $owned = $this->imported->owner(DocumentPageView::PRESERVED_LAYOUT_PREFIX.$route) !== null;

        // 取り込みや運用者が同じ URL のページを公開していれば、見本は出ていない
        if (! $hidden && ($owned || ! $this->bundled->isSample($route) || $this->bundled->replacedByPage($route))) {
            return null;
        }

        $path = '/'.$route;
        $title = $this->titleOf($route);
        $replacement = Page::query()->where('slug', $route)->first();

        $actions = $hidden
            ? [['kind' => 'show', 'label' => '見本を公開に戻す', 'route' => $route]]
            : [['kind' => 'hide', 'label' => '非公開にする', 'route' => $route]];

        $actions[] = $replacement !== null
            ? ['kind' => 'link', 'label' => "{$path} のページを編集する", 'href' => "/admin/pages/{$replacement->getKey()}/edit"]
            : ['kind' => 'link', 'label' => "{$path} にページを作る", 'href' => '/admin/pages/create?'.http_build_query(['title' => $title, 'slug' => $route])];

        if (! $hidden) {
            $actions[] = ['kind' => 'view', 'label' => '見本を見る', 'href' => $path];
        }

        return [
            'key' => $route,
            'path' => $path,
            'title' => $title,
            'state' => $hidden ? 'hidden' : 'sample',
            'stateLabel' => $hidden ? '非公開' : '見本を公開中',
            'note' => $hidden
                ? "見本は出していません。{$path} に同じ URL のページを作って公開すると、そちらが出ます（無ければ「見つかりません」になります）。メニューとサイトマップからも外れています。"
                : "{$path} にページを作って公開すると、見本の代わりにそちらが出ます。要らなければ非公開にすると {$path} は出なくなり、メニューとサイトマップからも外れます（ファイルは消さないので、あとから戻せます）。",
            'actions' => $actions,
        ];
    }

    /**
     * 非公開にする（`about` `service`）／見本の意匠をやめる（`contact`）。戻すのも同じ口。
     *
     * @return bool 受け付けたか（見本でない名前は false）
     */
    public function setHidden(string $key, bool $hidden): bool
    {
        if (in_array($key, $this->bundled->routes(), true)) {
            $this->bundled->setHidden($key, $hidden);

            return true;
        }

        if ($key === 'contact' && $this->contact->hasImportedView() && ! $this->contact->hasOwnImportedView()) {
            $this->contact->setSampleViewOff($hidden);

            return true;
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function contactPage(): ?array
    {
        if (! Route::has('contact.show')) {
            return null;
        }

        if ($this->contact->usesForm()) {
            return $this->contactForm();
        }

        // フォームを下書きにして閉じている（6 周目 R6-1・R6-2）。見本は出ていない
        if ($this->contact->closed()) {
            return [
                'key' => 'contact',
                'path' => '/contact',
                'title' => 'お問い合わせ',
                'state' => 'hidden',
                'stateLabel' => '非公開',
                'note' => 'お問い合わせのフォームを下書きにしているので、/contact は出ていません（メニューからも外れます）。',
                'actions' => [
                    ['kind' => 'link', 'label' => 'フォーム「お問い合わせ」を開く', 'href' => '/admin/forms/'.ContactEntry::RESERVED_KEY],
                ],
            ];
        }

        $existing = Form::query()->where('key', ContactEntry::RESERVED_KEY)->exists();

        return [
            'key' => 'contact',
            'path' => '/contact',
            'title' => 'お問い合わせ',
            'state' => 'sample',
            'stateLabel' => '見本を公開中',
            'note' => (string) $this->contact->reason(),
            'actions' => [
                $existing
                    ? ['kind' => 'link', 'label' => 'フォーム「contact」を開いて公開する', 'href' => '/admin/forms/'.ContactEntry::RESERVED_KEY]
                    : ['kind' => 'link', 'label' => 'お問い合わせフォームを作る', 'href' => '/admin/forms/new?'.http_build_query(['key' => ContactEntry::RESERVED_KEY, 'name' => 'お問い合わせ'])],
                ['kind' => 'view', 'label' => '見本を見る', 'href' => '/contact'],
            ],
        ];
    }

    /**
     * `/contact` はフォームで出ているが、**描く枠が見本の意匠**のとき。
     *
     * 中身（項目・通知先・自動返信）は運用者のもので、フォームの画面で直せる。
     * 見本なのは外側（見本サイトのヘッダー・脚注）だけなので、そこだけを外せるようにする。
     *
     * @return array<string, mixed>|null
     */
    private function contactForm(): ?array
    {
        $off = $this->contact->hasImportedView()
            && ! $this->contact->hasOwnImportedView()
            && $this->contact->sampleViewOff();

        if (! $off && ! $this->contact->rendersSampleView()) {
            return null;
        }

        $edit = ['kind' => 'link', 'label' => 'フォームの中身を直す', 'href' => '/admin/forms/'.ContactEntry::RESERVED_KEY];
        // /contact そのものを出さない道（UX 一周 6 周目 R6-2: 公開前チェックは「非公開にしてください」と言うのに、
        // /contact には非公開にするボタンが無かった）。フォームを下書きにすると閉じる（`ContactEntry::closed`）
        $close = ['kind' => 'link', 'label' => '/contact を非公開にする（フォームを下書きにする）', 'href' => '/admin/forms/'.ContactEntry::RESERVED_KEY.'#status'];

        return [
            'key' => 'contact',
            'path' => '/contact',
            'title' => 'お問い合わせ',
            'state' => $off ? 'hidden' : 'sample',
            'stateLabel' => $off ? '標準の表示' : '見本のデザインで公開中',
            'note' => $off
                ? 'お問い合わせフォームを、見本のデザインではなく標準の表示で出しています。'
                // 「同梱のサンプルサイトです」の脚注は、店が作ったフォームの /contact には出ない（3 周目に止めた）。
                // 出ていないものを「出ています」と書いていた（UX 一周 9 周目 R9-4）。実際に変わるのは枠の作りだけ
                : 'フォームの項目や通知先は「フォーム」で直せます。/contact は見本サイトのデザインの枠で出ています。見本のデザインをやめると、/forms/contact と同じ標準の表示に切り替わります（入力項目・通知先・自動返信は変わりません。見た目の違いは小さいです）。',
            'actions' => $off
                ? [['kind' => 'show', 'label' => '見本のデザインに戻す', 'route' => 'contact'], $edit, $close]
                : [['kind' => 'hide', 'label' => '見本のデザインをやめる', 'route' => 'contact'], $edit, $close, ['kind' => 'view', 'label' => '見本を見る', 'href' => '/contact']],
        ];
    }
}
