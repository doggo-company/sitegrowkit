<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Render\DocumentCss;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Theme\ThemeTokens;
use App\Models\ImportedPageLayout;
use App\Support\Concerns\ChecksViews;
use App\Support\SiteTemplates\StarterSite;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

/**
 * PageDocument で管理されたページを描く、**1 か所**（R-5）。
 *
 * `/page-slug`（`PublicPageController`）と、トップに指定されたときの `/`
 * （`PublicSiteController::home`）は**同じページを描く**。
 * 2 か所に書くと、片方だけ theme CSS を渡し忘れる・片方だけ canonical を出す、
 * といった食い違いが**画面からは見えない形**で入る。
 */
final class DocumentPageView
{
    use ChecksViews;

    /**
     * 原本まるごとを保った公開 layout の名前の頭（`layouts.preserved.<名前>`）。
     *
     * 🔴 **どのページに当てるかは、名前やファイルの有無では決めない。**
     * `imported_page_layouts` に**そのページの id で行があるとき**だけ載る。行を書くのは
     * `agentcms:import-page` で、ページの作成と同じ取引で入る。だから
     * - 取り込みの前から在った同名のページには当たらない（`kept` の「触らない」が守られる）
     * - slug を変えても外れない（id で結ぶ）
     * - 管理画面で作ったページ・WordPress から移したページ・複製したページには当たらない
     *
     * 🔴 **同梱の `layouts.imported` とは別物。**あちらは layout モードの生成物で、
     * header / footer を**抽出できた**案件のためのもの。こちらは抽出できず
     * standalone（preserve）で取り込んだ案件のために、**原本の枠をそのまま**持つ。
     *
     * 綴りは CLI 側（`importer/imported-layout.ts` の `importedLayoutTarget()`）と揃える。
     */
    public const PRESERVED_LAYOUT_PREFIX = 'layouts.preserved.';

    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly SettingsStore $settings,
        private readonly DocumentCss $documentCss,
        private readonly ViewFactory $views,
    ) {}

    /**
     * @param  string|null  $canonical  正規 URL。**null なら現在の URL**（既存の振る舞い）
     */
    public function render(Page $page, PageVersion $version, ?string $canonical = null, bool $isFront = false): View
    {
        $context = new RenderContext($page, $this->settings->all());
        $layout = $this->layoutFor($page);

        /*
         * トップとして出すときは、ページの題（「トップページ」）を名乗らない（UX 一周 2026-09-28）。
         * 検索結果とタブに「トップページ | 事務所名」と出ていた。kit 既定の枠は題が無ければ
         * 「サイト名 | キャッチコピー」を名乗る。取り込んだ枠は題をそのまま使うので変えない。
         */

        $site = $this->settings->all();
        $siteName = (string) ($site['site_name'] ?? '');
        $onSiteLayout = $layout === self::SITE_LAYOUT;

        return $this->views->make('public.document', [
            /*
             * サイトの枠は題を「そのまま」出すので、ここで「ページ名 | サイト名」に組む。
             * トップはサイト名とキャッチコピー（kit 既定の枠と同じ名乗り方）。
             */
            'title' => match (true) {
                $onSiteLayout && $isFront => trim($siteName.' | '.(string) ($site['tagline'] ?? ''), ' |'),
                $onSiteLayout => $page->title.' | '.$siteName,
                $isFront && $layout === 'layouts.public' => null,
                default => $page->title,
            },
            // トップは本文から始め、通常の固定ページには題の帯を出す
            'band' => $this->bandFor($page, $version->document, $isFront),
            // kit 既定の枠に載っているか。トップは帯を出さないので、帯の有無では見分けられない
            'siteFrame' => $onSiteLayout,
            'metaDescription' => $page->seo['description'] ?? null,
            // 保存した検索除外を実際の公開 HTML に反映する（取り込み済み layout でも共通）。
            'noindex' => (bool) ($page->seo['noindex'] ?? false),
            'canonical' => $canonical,
            'document' => $this->renderer->render($version->document, $context),
            'themeCss' => $this->documentCss->build(),
            /*
             * サイトの枠に載せるときは、ボタンなどの主な色を枠の色に合わせる（UX 一周 2026-09-28:
             * 本文のボタンだけ kit 既定の青緑で、見本の緑と違って見えた）。枠が色を持たなければ既定のまま。
             */
            'siteAccent' => $onSiteLayout ? (app(ThemeTokens::class)->resolve('{colors.primary}') ?? '#0f4c46') : null,
            'layout' => $layout,
        ]);
    }

    /**
     * 載せる layout（B-38 の 2 本立て）。
     *
     * **そのページに結び付けられた**原本の枠が在ればそちら、無ければ kit 既定。
     *
     * ▷ **`layouts.public` は「意匠を持っていない案件」の既定**であって、
     * 取り込んだページにとっては**意匠を全部落とす道**になる。
     * 実案件で実測すると `<style>` 58,751 字・ナビ・フッター・`<script>` 3 本が消えた。
     *
     * ▷ 行はあるのにファイルが無い（別テンプレートの再取り込みで枠が消えた等）ときは
     *   kit 既定に落とす — 500 にしない。無くならないようにするのは apply 側（prune の保護）。
     */
    public function layoutFor(Page $page): string
    {
        $bound = ImportedPageLayout::query()->where('page_id', $page->id)->value('view');

        return is_string($bound)
            && str_starts_with($bound, self::PRESERVED_LAYOUT_PREFIX)
            && $this->viewExists($bound)
            ? $bound
            : (app(StarterSite::class)->page()?->id === $page->id
                ? 'layouts.starter-a'
                : $this->siteLayout());
    }

    /** サイトの見た目の枠（見本や取り込んだテーマ）。ページもこれに載せる */
    public const SITE_LAYOUT = 'layouts.imported';

    /**
     * ページを載せる既定の枠（UX 一周 2026-09-28・メンテナの方針）。
     *
     * 以前は kit 既定の素朴な枠（`layouts.public`）に載せていたので、見本のトップを
     * ページに切り替えた途端、**店の色とヒーローが消えて別のサイトに見えた**。
     * サイトの枠（見本・取り込んだテーマ）があれば、ページもそれに載せる。
     *
     * 枠がページを載せられる形（`@stack('head')` と `@yield('content')`）で
     * なければ、今までどおり kit 既定に落とす（ブロックの CSS が読まれなくなるため）。
     */
    public function siteLayout(): string
    {
        if (! $this->viewExists(self::SITE_LAYOUT)) {
            return 'layouts.public';
        }

        try {
            $source = (string) file_get_contents(app('view')->getFinder()->find(self::SITE_LAYOUT));
        } catch (\Throwable) {
            return 'layouts.public';
        }

        return str_contains($source, "@stack('head')") && str_contains($source, "@yield('content')")
            ? self::SITE_LAYOUT
            : 'layouts.public';
    }

    /**
     * 通常の固定ページの題の帯。トップは本文のブロックから始めるため出さない。
     * 公開と編集画面の Canvas が同じ判定を使う。通常ページの見出し重複の判定は維持する。
     *
     * @param  array<string, mixed>  $document
     * @return array{front: bool, title: string, sub: string}|null
     */
    public function bandFor(Page $page, array $document, bool $isFront): ?array
    {
        // トップの見せ方は本文のブロックが持つ。サイト名だけの帯を本文の前に足さない。
        if ($isFront || $this->layoutFor($page) !== self::SITE_LAYOUT) {
            return null;
        }

        $title = $page->title;

        if ($this->startsWithTitle($document, $title)) {
            return null;
        }

        return [
            'front' => false,
            'title' => $title,
            'sub' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function startsWithTitle(array $document, string $title): bool
    {
        $first = $this->firstHeading($document['root'] ?? null);

        return $first !== null && trim($first) === trim($title);
    }

    private function firstHeading(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['type'] ?? null) === 'heading') {
            $text = $node['props']['text'] ?? null;

            return is_string($text) ? $text : null;
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            $found = $this->firstHeading($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
