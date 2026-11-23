<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Html\Purifier;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Wxr\Status\ContentStatus;
use AgentCms\Core\Wxr\Status\WxrStatus;
use AgentCms\Core\Wxr\WxrItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * WXR のページを、こちらの Page / PageVersion の形へ（release-20260909 / wxr-pages）。
 *
 * ## 対応表を 2 か所に持たない
 *
 * `plan` は「入るか・何が変わるか」をここから読み、`apply` は「実際に何を書くか」を
 * ここから読む（`ItemFields` と同じ理由）。片方だけ直すと plan が言ったのと違うものが入る。
 *
 * ## 本文は `section` の中の `richtext` 部品 1 つ
 *
 * Page の本文は HTML の列ではなく PageDocument（部品の木）である。
 * WordPress の本文は 1 つの `richtext` 部品として入れる — 編集画面で人が
 * 開いて直せる形で、描画は既存の renderer（Purifier 通過後の `{!! !!}`）を通る。
 * `richtext` は `section` の下にしか置けない（`allowedParents`）ので、`section` で包む。
 * `html` 部品は使わない（生の HTML を通す部品で、sanitize の外にある）。
 *
 * ## 危険な HTML はここで落とす
 *
 * 記事は `ContentValues` が richtext を Purifier に通す。ページは
 * `CreateContent` を通らないので、**同じ Purifier を書く前に通す**。
 * 描画側でも通るが、保存された版に `<script>` を残さない（版の履歴・API に出る）。
 *
 * ## shortcode は落とさない
 *
 * `[gallery]` のような shortcode は、こちらでは展開できない。**本文から消さない** —
 * 文字列のまま残し、plan が `PageShortcodeKept` で件数と id を出す。
 * 消すと本文が黙って欠け、残せば人が編集画面で見つけて直せる。
 */
final class PageFields
{
    /** `richtext.html` の上限（`content/components/richtext.json` の `max`） */
    public const BODY_MAX_BYTES = 50000;

    /** `pages.title` の上限（`CreatePage` の validation と同値） */
    public const TITLE_MAX = 200;

    /** `pages.seo.description` の上限（`UpdatePageMetadata` の validation と同値） */
    public const SEO_DESCRIPTION_MAX = 200;

    /** WordPress の予約状態（`wp:status = future`）。公開日時が未来の公開として入れる */
    public const SCHEDULED = 'future';

    /** `pages.slug` の形（`CreatePage` の validation と同値） */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** `pages.slug` の上限（`CreatePage` の validation と同値） */
    private const SLUG_MAX = 190;

    /** 題名からも作れないときの土台（`CreatePage::FALLBACK_SLUG` と同値） */
    private const FALLBACK_SLUG = 'page';

    /** 土台の長さ（`-50` を足しても 190 に収まる。`CreatePage::draftSlug()` と同値） */
    private const SLUG_BASE_MAX = 180;

    /** 連番で探す上限（`CreatePage::MAX_SUFFIX` と同値） */
    private const MAX_SUFFIX = 50;

    /** 本文の部品の key（編集画面で人が見る名前） */
    private const BODY_KEY = 'body';

    /** shortcode の形（`[name ...]`・`[/name]`）。展開はしない、数えるだけ */
    private const SHORTCODE_PATTERN = '/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i';

    public function __construct(
        private readonly Purifier $purifier,
        private readonly DocumentValidator $validator,
        private readonly PageSlugReservation $reservation,
    ) {}

    /** 題名。空なら `(無題)`（§F・`ItemFields` と同じ） */
    public static function title(WxrItem $item): string
    {
        return $item->title === '' ? ItemFields::UNTITLED : $item->title;
    }

    /** 題名が `pages.title` に収まるか */
    public static function titleFits(WxrItem $item): bool
    {
        return mb_strlen(self::title($item)) <= self::TITLE_MAX;
    }

    /**
     * 元の slug をそのまま使えるか。
     *
     * 形が合わない（日本語・大文字・長すぎ）か、既にある Page（ゴミ箱も含む）と
     * ぶつかるか、URL が既存の route に取られている（`PageSlugReservation`）なら使えない。
     * **ここでは DB を読むだけ**で書かない（plan の約束）。
     */
    public function slugUsable(WxrItem $item): bool
    {
        $slug = $item->slug;

        return self::slugWellFormed($slug) && ! $this->slugTaken($slug);
    }

    /**
     * 元の slug の URL が既存の route・投稿タイプの公開 slug に取られているか。
     *
     * 形が合っていて DB にも無いのに読めない、という**黙った上書き**を避けるため、
     * plan はこれを `PageSlugReserved` として別に言う（`PageSlugGenerated` とは分ける）。
     */
    public function slugReserved(WxrItem $item): bool
    {
        return self::slugWellFormed($item->slug) && $this->reservation->reserved($item->slug);
    }

    /**
     * `CreatePage` へ渡す slug。**必ず読める URL** にしてから渡す。
     *
     * 元が使えなければ、題名（`Str::slug`）か元の綴りを土台に `-2` `-3` … と探す
     * （`CreatePage::firstUnusedSlug()` と同じ流儀）。`CreatePage` の生成は DB しか見ないので、
     * route に取られた URL を避けるにはこちらで決めてから渡す必要がある。
     */
    public function slug(WxrItem $item): string
    {
        if ($this->slugUsable($item)) {
            return $item->slug;
        }

        $base = self::slugWellFormed($item->slug) ? $item->slug : Str::slug(self::title($item));

        if ($base === '') {
            $base = self::FALLBACK_SLUG;
        }

        $base = Str::limit($base, self::SLUG_BASE_MAX, '');
        $candidate = $base;

        for ($n = 2; $this->slugTaken($candidate); $n++) {
            if ($n > self::MAX_SUFFIX) {
                return $base.'-'.Str::lower(Str::random(6));
            }

            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    /** `pages` にあるか、URL が route に取られているか */
    private function slugTaken(string $slug): bool
    {
        return Page::query()->where('slug', $slug)->exists() || $this->reservation->reserved($slug);
    }

    private static function slugWellFormed(string $slug): bool
    {
        return $slug !== '' && strlen($slug) <= self::SLUG_MAX && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /** 文字が無くても「中身がある」とみなす要素（画像だけのページは空ではない） */
    private const CONTENT_TAGS = '<img><iframe><video><audio><embed><object><hr>';

    /** 本文が空か（空白と `<p></p>` の類だけなら空とみなす。画像だけなら空ではない） */
    public static function bodyIsEmpty(WxrItem $item): bool
    {
        return trim(strip_tags($item->content, self::CONTENT_TAGS)) === '';
    }

    /** 本文が `richtext` の上限に収まるか（置き換え前の長さで見る。plan は URL を持たない） */
    public static function bodyFits(WxrItem $item): bool
    {
        return strlen($item->content) <= self::BODY_MAX_BYTES;
    }

    /** 本文に shortcode が残るか（展開できないので、そのまま残して言う） */
    public static function hasShortcode(WxrItem $item): bool
    {
        return preg_match(self::SHORTCODE_PATTERN, $item->content) === 1;
    }

    /** 親を持つか（こちらの Page は階層を持たないので、平らに入れて言う） */
    public static function hasParent(WxrItem $item): bool
    {
        return $item->parentId !== null && $item->parentId !== '' && $item->parentId !== '0';
    }

    /**
     * 公開で入れられるか。
     *
     * 公開（`publish`）と予約（`future`）のページを公開として入れる。
     * ただし本文が空ならこちらでは公開できない（`PublishPage` の PB 規則）。
     * その場合は下書きで入れて `PageEmptyKeptDraft` を言う。
     */
    public static function publishes(WxrItem $item): bool
    {
        return self::wantsPublish($item) && ! self::bodyIsEmpty($item);
    }

    /** 元が公開または予約か（本文の有無は見ない） */
    public static function wantsPublish(WxrItem $item): bool
    {
        return WxrStatus::canPreserve($item->status)
            && (WxrStatus::map($item->status) === ContentStatus::Published || $item->status === self::SCHEDULED);
    }

    /**
     * 公開日時。**予約（`future`）で日時が読めなければ `null`** — plan が
     * `PageScheduleUnreadable` で止める。`publish` で日時が無ければ「いま」（apply が決める）。
     */
    public static function publishedAt(WxrItem $item): ?CarbonImmutable
    {
        if ($item->publishedAt === null || $item->publishedAt === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($item->publishedAt);
        } catch (Throwable) {
            return null;
        }
    }

    /** 予約なのに日時が読めない（**止める** — 公開日を捏造しない） */
    public static function scheduleUnreadable(WxrItem $item): bool
    {
        return $item->status === self::SCHEDULED && self::publishedAt($item) === null;
    }

    /**
     * SEO。こちらの Page が持つのは `description` と `noindex` だけ。
     *
     * WordPress の抜粋（`excerpt:encoded`）を description に使う。
     * SEO プラグインの meta（`_yoast_wpseo_*`）は `_` 始まりで Reader が捨てるので届かない。
     * 上限を超える抜粋は**切り詰めず落として言う**（`PageSeoDescriptionDropped`）。
     *
     * @return array<string, mixed>|null 持つものが無ければ `null`（列を触らない）
     */
    public static function seo(WxrItem $item): ?array
    {
        $description = trim(strip_tags($item->excerpt));

        if ($description === '' || ! self::seoDescriptionFits($item)) {
            return null;
        }

        return ['description' => $description];
    }

    /** 抜粋が SEO の description に収まるか（空なら収まる扱い） */
    public static function seoDescriptionFits(WxrItem $item): bool
    {
        return mb_strlen(trim(strip_tags($item->excerpt))) <= self::SEO_DESCRIPTION_MAX;
    }

    /**
     * 本文を PageDocument にする。**Purifier を通した後の HTML** を持つ。
     *
     * @param  array<string, string>  $mediaUrls  元の URL => こちら側の URL（取り込めたものだけ）
     * @return array<string, mixed>
     */
    public function document(WxrItem $item, array $mediaUrls = []): array
    {
        $document = DocumentTree::empty();

        if (self::bodyIsEmpty($item)) {
            return $document;
        }

        $html = $item->content;

        if ($mediaUrls !== []) {
            // 取り込めた URL だけを置き換える（`ContentImporter::attributes()` と同じ）
            $html = strtr($html, $mediaUrls);
        }

        $document['root']['children'][] = [
            'id' => NodeId::generate(),
            'key' => self::BODY_KEY,
            'type' => 'section',
            'props' => [],
            'children' => [[
                'id' => NodeId::generate(),
                'key' => self::BODY_KEY.'-text',
                'type' => 'richtext',
                'props' => ['html' => $this->purifier->clean($html)],
                'children' => [],
            ]],
        ];

        return $document;
    }

    /**
     * 本文を PageDocument として検証したときの誤り。**空なら入れられる。**
     *
     * plan はここを見て `PageBodyUnrepresentable` を出し、apply は同じ document を書く。
     * 検証は部品の登録（allowlist）と props の schema を見る — `richtext` が
     * 登録から外されている project では、ここで止まる。
     *
     * @return list<string> 誤りの場所（本文は含まない）
     */
    public function bodyErrors(WxrItem $item): array
    {
        if (self::bodyIsEmpty($item)) {
            return [];
        }

        return array_keys($this->validator->validate($this->document($item)));
    }
}
