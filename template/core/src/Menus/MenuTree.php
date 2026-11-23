<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TermLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * 保存された参照から、**そのとき出すべき導線**を作る（Phase 9.9 / STAGE 22C・SPEC §D.2）。
 *
 * ## URL はここで初めて現れる
 *
 * 保存されているのは「何を指しているか」だけである（SPEC §B.3・OG2）。
 * URL はここで作るので、slug を変えても**古くならない**。
 *
 * ## 出せない項目は落とす（OG4）
 *
 * 参照先がゴミ箱にある・下書きである・型が無効である・route が無い —
 * いずれも公開面では**項目ごと出さない**。押せないリンクや 404 を来訪者に見せない。
 * 落とした事実は編集者に見せるべきだが、それは管理画面の仕事である
 * （`unavailable()` が理由を返す）。
 *
 * ## 1 リクエスト内だけ覚える（OG6）
 *
 * ヘッダーとフッターで同じ本を 2 回引かないための memo であって、永続キャッシュではない。
 * 永続化すると、参照先の slug 変更・公開/非公開・trash のたびに無効化が要り、
 * **無効化を忘れた場所が古い URL を配る** — §B.3 で避けたはずの状態を別の形で作る。
 */
final class MenuTree
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeRegistry $types,
        private readonly ContentPermalink $permalinks,
        private readonly ContentTypeLifecyclePolicy $lifecycle,
        private readonly TermLink $terms,
        /** 組み込みページを出してよいか（project が差し込む。無ければ route の有無だけ見る） */
        private readonly ?RouteAvailability $routes = null,
        /** 店が付けた呼び名（無ければ型の名前のまま） */
        private readonly ?ContentTypeSettingsResolver $settings = null,
    ) {}

    /**
     * 描ける形の木。**出せない項目は含まれない。**
     *
     * @param  string|null  $currentPath  いま見ている path（`aria-current` の判定用）
     * @return list<array<string, mixed>>
     */
    public function forKey(string $key, ?string $currentPath = null): array
    {
        $rows = $this->memo[$key] ??= $this->build($key);

        // `current` は要求ごとに変わるので memo に載せない（SPEC §D.3）
        return $currentPath === null ? $rows : self::markCurrent($rows, self::normalize($currentPath));
    }

    /**
     * **いまいる場所までの祖先**（パンくずの元・P1 ⑤）。
     *
     * ## 木は祖先の出どころであって、パンくずそのものではない
     *
     * `PHASE9_9_SPEC.md` §A.2 が「パンくずは**メニューの木とは別の導出物**」と
     * 書いているとおり、木だけでは足りない。**記事詳細はメニューに載らない**
     * （載せる意味が無い）が、**パンくずが一番要るのはそこ**である。
     *
     * だからここが返すのは**祖先まで**で、**いまいるページ自身は呼ぶ側が足す**。
     * `/news/xxx` なら、ここは「ニュース（`/news`）」までを返す。
     *
     * ## 一致は「前方一致」で見る
     *
     * `/news/xxx` は `/news` の下に居る。**完全一致だけを見ると、
     * 記事詳細でパンくずが空になる** — 一番要る場所で出ない。
     *
     * @return list<array{title: string, url: string}> 根に近い順
     */
    public function trailFor(string $key, string $currentPath): array
    {
        return self::findTrail($this->forKey($key), self::normalize($currentPath));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{title: string, url: string}>
     */
    private static function findTrail(array $items, string $current): array
    {
        foreach ($items as $item) {
            $url = self::normalize((string) $item['url']);
            $step = ['title' => (string) $item['title'], 'url' => (string) $item['url']];

            /** @var list<array<string, mixed>> $children */
            $children = $item['children'];
            $below = self::findTrail($children, $current);

            if ($below !== []) {
                return [$step, ...$below];
            }

            /*
             * **前方一致だけを祖先とする。** `/news/xxx` は `/news` の下に居る。
             *
             * **完全一致は祖先ではない。** それは「いまいるページ自身」であり、
             * 末端は呼ぶ側が渡す約束なので、ここで返すと**末端が二重になる**
             * （`/about` が「ホーム > About > About」になっていた）。
             */
            if ($url === $current) {
                /*
                 * **打ち切らずに次を見る。**
                 *
                 * 完全一致は「いまいるページ自身」なので祖先ではない。
                 * ただし **ここで走査をやめると、後ろに居る祖先まで捨てる** —
                 * 例えば記事そのものをメニューへ載せてある場合、
                 * 先に当たったその項目で打ち切られ、**一覧（祖先）が消える**。
                 */
                continue;
            }

            if ($url !== '/' && str_starts_with($current, $url.'/')) {
                return [$step];
            }
        }

        return [];
    }

    /**
     * 出せない項目の理由（管理画面が印を付けるのに使う）。
     *
     * @return array<int, string> 項目 id → 理由
     */
    public function unavailable(string $key): array
    {
        $menu = Menu::query()->where('key', $key)->first();

        if ($menu === null) {
            return [];
        }

        $items = $this->items($menu);
        $reasons = [];

        foreach ($items as $item) {
            $reason = $this->reason($item, $this->targets($items));

            if ($reason !== null) {
                $reasons[(int) $item->getKey()] = $reason;
            }
        }

        return $reasons;
    }

    /** @return list<array<string, mixed>> */
    private function build(string $key): array
    {
        $menu = Menu::query()->where('key', $key)->first();

        if ($menu === null) {
            // 無い本は例外にしない。テーマを差し替えても落ちないほうがよい
            return [];
        }

        $items = $this->items($menu);
        $targets = $this->targets($items);

        $byParent = [];

        foreach ($items as $item) {
            $byParent[$item->parent_id ?? 0][] = $item;
        }

        return $this->branch($byParent, 0, $targets);
    }

    /**
     * @param  array<int, list<MenuItem>>  $byParent
     * @param  array<string, array<int, MenuLinkTarget>>  $targets
     * @return list<array<string, mixed>>
     */
    private function branch(array $byParent, int $parentId, array $targets): array
    {
        $out = [];

        foreach ($byParent[$parentId] ?? [] as $item) {
            if ($this->reason($item, $targets) !== null) {
                /*
                 * **親が出せないなら子も出さない。** 親を飛ばして子だけ上へ繰り上げると、
                 * 編集者が組んだ構造と違うものが表に出る。
                 */
                continue;
            }

            $url = $this->url($item, $targets);

            if ($url === null) {
                continue;
            }

            $out[] = [
                'id' => (int) $item->getKey(),
                'title' => $this->title($item, $targets),
                'url' => $url,
                'target' => $item->targetKind()->value,
                'rel' => $item->targetKind()->rel(),
                'current' => false,
                'children' => $this->branch($byParent, (int) $item->getKey(), $targets),
            ];
        }

        return $out;
    }

    /**
     * 出せない理由。出せるなら null。
     *
     * @param  array<string, array<int, MenuLinkTarget>>  $targets
     */
    private function reason(MenuItem $item, array $targets): ?string
    {
        return match ($item->linkType()) {
            MenuLinkType::Custom => null,
            MenuLinkType::Route => $this->routeReason((string) $item->link_route),
            MenuLinkType::Page => self::pageReason($targets['pages'][(int) $item->page_id] ?? null),
            MenuLinkType::Content => $this->contentReason($item, $targets),
            MenuLinkType::ContentIndex => $this->indexReason((string) $item->content_type),
            MenuLinkType::Term => $this->termReason($item),
        };
    }

    private function routeReason(string $route): ?string
    {
        if (! Route::has($route)) {
            return 'リンク先のページがありません';
        }

        return $this->routes?->reason($route);
    }

    private static function pageReason(?MenuLinkTarget $page): ?string
    {
        if ($page === null) {
            return 'リンク先のページがありません';
        }

        if ($page->trashed) {
            return 'リンク先のページがゴミ箱にあります';
        }

        if ($page->scheduled) {
            return 'リンク先のページは公開予約中です';
        }

        return $page->published ? null : 'リンク先のページは下書きです';
    }

    /** @param  array<string, array<int, MenuLinkTarget>>  $targets */
    private function contentReason(MenuItem $item, array $targets): ?string
    {
        $type = (string) $item->content_type;

        $reason = $this->indexReason($type);

        if ($reason !== null) {
            return $reason;
        }

        $row = $targets[$type][(int) $item->content_id] ?? null;

        if ($row === null) {
            return 'リンク先の投稿がありません';
        }

        if ($row->trashed) {
            return 'リンク先の投稿がゴミ箱にあります';
        }

        return $row->published ? null : 'リンク先の投稿は下書きです';
    }

    private function indexReason(string $type): ?string
    {
        if (! $this->types->has($type) || ! $this->schemas->has($type)) {
            return 'リンク先の投稿タイプがありません';
        }

        // 型名でハードコードしない。判定は 9.7 の policy が持っている
        return $this->lifecycle->isPublic($type) ? null : 'リンク先の投稿タイプは公開されていません';
    }

    /** @param  array<string, array<int, MenuLinkTarget>>  $targets */
    private function url(MenuItem $item, array $targets): ?string
    {
        return match ($item->linkType()) {
            MenuLinkType::Custom => (string) $item->link_url,
            MenuLinkType::Route => route((string) $item->link_route, [], absolute: false),
            MenuLinkType::Page => '/'.ltrim($targets['pages'][(int) $item->page_id]->slug ?? '', '/'),
            MenuLinkType::Content => $this->contentUrl($item, $targets),
            MenuLinkType::ContentIndex => $this->indexUrl((string) $item->content_type),
            MenuLinkType::Term => $this->termUrl($item),
        };
    }

    /** @param  array<string, array<int, MenuLinkTarget>>  $targets */
    private function contentUrl(MenuItem $item, array $targets): ?string
    {
        $type = (string) $item->content_type;
        $slug = $targets[$type][(int) $item->content_id]->slug ?? '';

        if ($slug === '') {
            return null;
        }

        /*
         * **named route を優先する。** module がある型（works / news）は
         * route を持っており、URL 構造を変えたときに一緒に追随する。
         * 持たない型は fallback で受けるので、公開スラッグから組む。
         */
        if (Route::has("{$type}.show")) {
            return route("{$type}.show", ['slug' => $slug], absolute: false);
        }

        $base = $this->indexUrl($type);

        return $base === null ? null : rtrim($base, '/').'/'.$slug;
    }

    /** 一覧の URL。**形を知っているのは `ContentPermalink` だけ**（P1 ③ 段 1） */
    private function indexUrl(string $type): ?string
    {
        return $this->permalinks->index($type, absolute: false);
    }

    /**
     * 分類の一覧が今も在るか（WP 一周 7 周目 2026-09-03）。
     *
     * 入口（投稿タイプ）と項目の**両方**を見る。片方だけだと、
     * 型を非公開にしたのにメニューだけ生きている、が起きる。
     */
    private function termReason(MenuItem $item): ?string
    {
        $indexReason = $this->indexReason((string) $item->content_type);

        if ($indexReason !== null) {
            return $indexReason;
        }

        return $this->terms->find((string) $item->taxonomy_term_id) === null
            ? 'リンク先の分類がありません'
            : null;
    }

    /**
     * `/{入口}/{分類のキー}/{項目の綴り}`。
     *
     * 綴りは**引くたびに読み直す**ので、あとから変えてもリンクは追従する
     *（そのために id で持っている）。
     */
    private function termUrl(MenuItem $item): ?string
    {
        $term = $this->terms->find((string) $item->taxonomy_term_id);
        $index = $this->indexUrl((string) $item->content_type);

        if ($term === null || $index === null) {
            return null;
        }

        return rtrim($index, '/').'/'.$term['taxonomyKey'].'/'.$term['slug'];
    }

    /**
     * 表示名。`title` が null なら参照先から作る（SPEC §B.8）。
     *
     * @param  array<string, array<int, MenuLinkTarget>>  $targets
     */
    private function title(MenuItem $item, array $targets): string
    {
        if (is_string($item->title) && $item->title !== '') {
            return $item->title;
        }

        $type = (string) $item->content_type;

        return match ($item->linkType()) {
            MenuLinkType::Page => $targets['pages'][(int) $item->page_id]->title ?? '',
            MenuLinkType::Content => $targets[$type][(int) $item->content_id]->title ?? '',
            // 店が付けた呼び名で出す（UX 一周 第10周: 工務店の「施工事例」がナビに「投稿」と出ていた）
            MenuLinkType::ContentIndex => $this->schemas->has($type)
                ? ($this->settings?->for($type)->labelOr($this->schemas->get($type)->label) ?? $this->schemas->get($type)->label)
                : $type,
            MenuLinkType::Term => $this->terms->find((string) $item->taxonomy_term_id)['name'] ?? '',
            // custom / route は保存時に必須なのでここへ来ない
            default => '',
        };
    }

    /** @return Collection<int, MenuItem> */
    private function items(Menu $menu): Collection
    {
        return MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * 参照先を **型ごとにまとめて 1 回ずつ**引く（項目ごとに引かない）。
     *
     * ゴミ箱の行も引く。落とすかどうかは `reason()` が決めるので、
     * ここで見えなくすると「無い」と「ゴミ箱にある」を区別できなくなる。
     *
     * @param  Collection<int, MenuItem>  $items
     * @return array<string, array<int, MenuLinkTarget>>
     */
    private function targets(Collection $items): array
    {
        $pageIds = [];
        $contentIds = [];

        foreach ($items as $item) {
            if ($item->linkType() === MenuLinkType::Page && $item->page_id !== null) {
                $pageIds[] = (int) $item->page_id;
            }

            if ($item->linkType() === MenuLinkType::Content && $item->content_id !== null) {
                $contentIds[(string) $item->content_type][] = (int) $item->content_id;
            }
        }

        $targets = ['pages' => []];

        if ($pageIds !== []) {
            /*
             * **公開条件は Page 自身に聞く。** `PublicPageController` は
             * `isPublicallyVisible()` で 404 を決めているので、ここで別の条件を
             * 書くと「メニューには出るのに開くと 404」が起きる。
             */
            foreach (Page::query()->whereIn('id', array_unique($pageIds))->get() as $page) {
                $targets['pages'][(int) $page->getKey()] = new MenuLinkTarget(
                    slug: (string) $page->slug,
                    title: $page->label(),
                    trashed: $page->trashed_at !== null,
                    published: $page->isPublicallyVisible(),
                    scheduled: $page->isScheduled(),
                );
            }
        }

        foreach ($contentIds as $type => $ids) {
            if (! $this->schemas->has($type)) {
                continue;
            }

            $table = $this->schemas->get($type)->table;

            // 型ごとに列が違う。無い列を選ぶと SQL ごと落ちる
            $has = static fn (string $column): bool => Schema::hasColumn($table, $column);
            $columns = array_values(array_filter(
                ['id', 'slug', 'title', 'status', 'published_at', 'deleted_at'],
                $has,
            ));

            $rows = DB::table($table)->whereIn('id', array_unique($ids))->get($columns);

            foreach ($rows as $row) {
                $targets[$type][(int) $row->id] = new MenuLinkTarget(
                    slug: (string) ($row->slug ?? ''),
                    title: (string) ($row->title ?? ''),
                    trashed: $has('deleted_at') && $row->deleted_at !== null,
                    published: self::isPublished($row, $has),
                );
            }
        }

        return $targets;
    }

    /**
     * 公開面から見えるか（`SchemaResourceController::isPublicallyVisible()` と同じ規則）。
     *
     * 公開状態を持たない型は**常に見える**。「status が無い = 非公開」にすると、
     * 状態を持たない型への導線が丸ごと消える。
     *
     * @param  callable(string): bool  $has
     */
    private static function isPublished(object $row, callable $has): bool
    {
        if ($has('status') && ($row->status ?? null) !== 'published') {
            return false;
        }

        if ($has('published_at')) {
            $at = $row->published_at ?? null;

            return $at === null || strtotime((string) $at) <= time();
        }

        return true;
    }

    /**
     * いま見ている path と一致する項目に印を付ける。
     *
     * 子が current なら親も current にする（直書きの `routeIs('works.*')` と同じ挙動）。
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function markCurrent(array $items, string $current): array
    {
        return array_map(static function (array $item) use ($current): array {
            $children = self::markCurrent($item['children'], $current);

            $item['children'] = $children;
            // 子が current なら親も current（直書きの `routeIs('works.*')` と同じ挙動）
            $item['current'] = self::normalize((string) $item['url']) === $current
                || self::anyCurrent($children);

            return $item;
        }, $items);
    }

    /** @param  list<array<string, mixed>>  $items */
    private static function anyCurrent(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['current'] === true) {
                return true;
            }
        }

        return false;
    }

    /** 比較のために path だけを取り出す（末尾の / と query を落とす） */
    private static function normalize(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);

        return '/'.trim($path, '/');
    }
}
