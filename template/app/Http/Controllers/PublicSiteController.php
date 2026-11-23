<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Ordering\ContentOrder;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\PageVersion;
use App\Support\BundledPages;
use App\Support\DocumentPageView;
use App\Support\ImportedPages;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

/**
 * ページ（Project 層）。モジュール横断の集約はここで行う。
 */
final class PublicSiteController
{
    /**
     * 同梱サンプルのトップページ。
     *
     * `news` / `works` は **kit の例**であって、新しく生成するサイトには配られない
     * （Phase 9.7 / STAGE 20B・SPEC §C）。無い環境では**空で描く** — 500 にしない。
     *
     * 型は**登録簿に問い合わせて**取る。`Modules\News\Models\News` のような
     * クラスを直接書くと、配られていない環境では静的解析が通らなくなる。
     * 名前が残るのは、この 2 つの変数を持っているのが**同梱サンプルの view 自身**
     * だからで、view には `@if (Route::has(...))` の守りがある。
     */
    public function home(
        ContentTypeResolver $types,
        FrontPage $frontPage,
        DocumentPageView $documentPage,
    ): View {
        /*
         * **トップにページが指定されていれば、そちらを出す**（R-5）。
         *
         * route は `home` のまま増やさない。`route('home')` を呼んでいる場所
         * （ヘッダーのロゴ・パンくずの起点・sitemap・フォームの完了画面）は
         * **1 バイトも変わらない**。
         *
         * 指定が無い・公開が取り消された・ゴミ箱へ入った、のいずれでも
         * `resolve()` は null を返し、**これまでどおりテーマのトップ**が出る。
         * ここで 404 や 500 にしない — **トップが消えるのが一番悪い**。
         * 何が起きているかは管理画面が `FrontPage::reason()` で伝える。
         */
        $page = $frontPage->resolve();

        if ($page !== null) {
            $version = PageVersion::query()->find($page->published_version_id);

            if ($version !== null) {
                return $documentPage->render($page, $version, url('/'), isFront: true);
            }
        }

        /*
         * **取り込みがトップのページを作っていれば、その所有者が `/` の答え**（R03 続き）。
         * 公開中なら描く。公開を取り下げた・ゴミ箱・予約中なら **404** — 静的な `public.home`
         * （取り込んだ元の本文）に落とすと「取り下げたのに元の本文が見える」。
         * 取り込みが作っていない（所有者が無い）サイトは、これまでどおり同梱のトップ。
         */
        $imported = app(ImportedPages::class)->respond(DocumentPageView::PRESERVED_LAYOUT_PREFIX.'home', url('/'));

        if ($imported instanceof View) {
            return $imported;
        }

        return view('public.home', [
            'news' => $this->preview($types, 'news', 3),
            'works' => $this->preview($types, 'works', 4, 'mainImage.variants'),
        ]);
    }

    /**
     * 公開済みの上位数件。その型がこのサイトに無ければ空を返す。
     *
     * @return Collection<int, Model>
     */
    private function preview(ContentTypeResolver $types, string $id, int $limit, ?string $with = null): Collection
    {
        if (! $types->knows($id)) {
            return new Collection;
        }

        $model = $types->newModel($id);
        $query = $model->newQuery()->scopes('published');

        // eager load は関係を持つ型にだけ効かせる（無い型では例外になる）
        if ($with !== null && method_exists($model, explode('.', $with)[0])) {
            $query->with($with);
        }

        ContentOrder::apply($query, $model->getTable());

        /** @var Collection<int, Model> $entries */
        $entries = $query->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();

        return $entries;
    }

    public function about(): View|RedirectResponse
    {
        return $this->bundled('about');
    }

    public function service(): View|RedirectResponse
    {
        return $this->bundled('service');
    }

    /**
     * 同梱ページを描く。**出してよいかは `BundledPages` が決める**（D-1）。
     *
     * Project isolation（ADR-0010 Rev.1）: import されていない標準ページは公開しない。
     * **どの view を描くかも `BundledPages` が持つ** — ここに綴りを置くと、
     * 同じ問いに答える場所がまた 2 つになる。
     */
    private function bundled(string $route): View|RedirectResponse
    {
        $pages = app(BundledPages::class);

        /*
         * **見本を非公開にしたら、同じ URL のページに譲る**（UX 一周 2026-09-27）。
         *
         * `/about` は固定の route なので、運用者が slug `about` のページを作っても
         * fallback まで届かず、見本が出続ける（または 404 になる）。非公開にしたときだけ
         * ページの描画へ回す — 無ければ `PublicPageController` がそのまま 404 を返す。
         *
         * **同じ URL のページを公開したときも譲る**（UX 一周 2026-09-28）。
         * 見本ページの一覧が「/about にページを作る」と案内しているので、
         * 作って公開した時点でそれが出るのが、案内どおりの結果である。
         */
        if ($pages->isHidden($route) || $pages->replacedByPage($route)) {
            /** @var View */
            return app()->call(PublicPageController::class);
        }

        abort_unless($pages->isAvailable($route), 404);

        /*
         * 取り込みがこの URL のページを作って公開していれば、そちらを出す（R03 続き (1)）。
         * 結び付き（`imported_page_layouts`）で引くので、運用者が作った同名ページは乗っ取らない。
         * 無ければこれまでどおり静的な Blade。
         */
        $imported = app(ImportedPages::class)->respond(DocumentPageView::PRESERVED_LAYOUT_PREFIX.$route);

        return $imported ?? view($pages->viewFor($route));
    }
}
