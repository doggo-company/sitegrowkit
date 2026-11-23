<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use App\Models\ImportedPageLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * 取り込みで作ったページを、**元の URL** で出す（R03 続き (1)）。
 *
 * `routes/imported.php` の各 route（と同梱ページの `/` `/about` `/service`）は、まずここへ
 * 「この枠（`layouts.preserved.<slug>`）の元の URL を所有するページはどうなっているか」を聞く。
 *
 * ## 3 通りの答え
 *
 * | 所有者（`imported_page_layouts.owned_view`） | 答え |
 * | --- | --- |
 * | 行が無い（取り込みがページを作っていない） | `null` — 呼ぶ側が静的な Blade を出す（未変換の受け皿） |
 * | 在って公開中、slug が元のまま | ページを描く |
 * | 在って公開中、slug が変わった | **301** で新しい URL へ（元の URL の本文と canonical を食い違わせない） |
 * | 在るが未公開 / 予約中 / ゴミ箱 / 版が無い | **404** — 静的な Blade に落とすと「公開を取り下げたのに元の本文が見える」 |
 *
 * ## 所有者は 1 枚だけ
 *
 * 複製は同じ `view`（枠）を引き継ぐが `owned_view` は持たない。`view` で引くと複製へ黙って
 * 切り替わる（元を取り下げた日に複製が元の URL に出る）ので、**所有者だけを見る**。
 * 運用者が先に作っていた同じ slug のページにも行は無いので、乗っ取らない。
 */
final class ImportedPages
{
    public function __construct(private readonly DocumentPageView $documentPage) {}

    /** その枠の元の URL を所有するページ（公開状態は問わない）。**行が無ければ null** */
    public function owner(string $layoutView): ?Page
    {
        if (! str_starts_with($layoutView, DocumentPageView::PRESERVED_LAYOUT_PREFIX)) {
            return null;
        }

        $pageId = ImportedPageLayout::query()->where('owned_view', $layoutView)->value('page_id');

        if ($pageId === null) {
            return null;
        }

        // 行は在るがページが無い（結び付きが宙に浮いた）= 所有者は在るが出せない
        return Page::query()->find((int) $pageId) ?? new Page;
    }

    /**
     * 元の URL への答え。
     *
     * @param  string  $layoutView  `layouts.preserved.<元の slug>`
     * @param  string|null  $canonical  描くときの正規 URL（`/` は `url('/')`。null なら現在の URL）
     * @return View|RedirectResponse|null null = 所有者が無い（呼ぶ側が静的な Blade を出す）
     */
    public function respond(string $layoutView, ?string $canonical = null): View|RedirectResponse|null
    {
        $owner = $this->owner($layoutView);

        if ($owner === null) {
            return null;
        }

        // 所有者は在るが、いま公開面に出してよい状態ではない → 本文は出さない（静的にも落とさない）
        if (! $owner->exists || ! $owner->isPublicallyVisible() || ! $owner->isDocumentManaged()) {
            abort(404);
        }

        $version = PageVersion::query()->find($owner->published_version_id);

        if ($version === null) {
            abort(404);
        }

        /*
         * slug が変わっていたら元の URL は新しい URL へ 301。
         * 元の URL で本文を出し続けると、`/company` と `/company-2026` の 2 つが同じ本文を名乗り、
         * canonical も食い違う。通常の公開 URL（`/{slug}` の fallback）と同じ形に揃える。
         * `/`（トップ）は slug ではなく `front_page_id` で決まるので、この規則の外（canonical 指定あり）。
         */
        $originalSlug = substr($layoutView, strlen(DocumentPageView::PRESERVED_LAYOUT_PREFIX));

        if ($canonical === null && $owner->slug !== $originalSlug) {
            return redirect()->to('/'.$owner->slug, 301);
        }

        return $this->documentPage->render($owner, $version, $canonical);
    }
}
