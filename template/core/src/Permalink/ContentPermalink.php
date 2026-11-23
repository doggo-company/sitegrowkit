<?php

declare(strict_types=1);

namespace AgentCms\Core\Permalink;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\Eloquent\Model;

/**
 * 公開 URL の形を知っている、**唯一の場所**（P1 ③ 段 1）。
 *
 * ## なぜ 1 つにするのか
 *
 * 実測したら、URL の形を知っている場所が **6 つ**あった:
 *
 * | 場所 | 何をしていたか |
 * | --- | --- |
 * | `routes/public.php` | `/news/{slug}` を**解く**（名前付き route） |
 * | `PublicContentController` | `/{publicSlug}/{slug}` を**解く**（fallback） |
 * | `SchemaResourceController` | `route("{type}.show")` で**作る** |
 * | `SitemapController` | **同じ物を 2 通りで作っていた**（`route()` と `url('/'.publicSlug.'/'.slug)`） |
 * | Blade（home / news 一覧） | `route('news.show', slug)` で作る |
 * | `MenuTree` | 一覧の URL を publicSlug から作る |
 *
 * この状態で「URL の構造を設定にする」と、**6 か所が別々の構造を信じる**。
 * 管理画面のリンクと sitemap と公開ページが食い違い、しかも
 * **どれが正しいかを誰も言えない**。だから構造を触る前に口を 1 つにする。
 *
 * ## 世界が 2 つあることは、いまは畳まない
 *
 * `news` / `works` は名前付き route が受け、CMS が作った型は `publicSlug` から
 * 実行時に解く。**畳むには `public_slug` の backfill が要り、URL を作る仕組みごと
 * 入れ替えることになる**（`docs/BACKLOG.md` B-18）。
 * ここでは**両方の世界を同じ口から呼べるようにする**だけで、形は 1 バイトも変えない。
 *
 * ## 状態は見ない
 *
 * 「公開されているか」「ゴミ箱にあるか」は**呼ぶ側が見る**。
 * ここが答えるのは **URL の形**だけである。混ぜると、
 * 「下書きの URL を知りたい」（preview）が通らなくなる。
 */
final class ContentPermalink
{
    public function __construct(
        private readonly ContentTypeSettingsResolver $settings,
        private readonly Registrar $router,
    ) {}

    /**
     * 記事 1 件の URL。**作れなければ null**（無効なリンクを出さないため）。
     *
     * `$absolute` の既定が `true` なのは、`route()` の既定に合わせるためである。
     * 相対で欲しい面（管理画面の行）だけが `false` を渡す。
     */
    public function entry(string $type, Model $entry, bool $absolute = true): ?string
    {
        /*
         * **名前付き route が在ればそちらが勝つ。**
         *
         * `news` / `works` の URL は route が決めており、**既に配ったサイトの URL** である。
         * 構造の設定は fallback の型にだけ効く（`docs/BACKLOG.md` B-18）。
         */
        if ($this->router->has("{$type}.show")) {
            $slug = $entry->getAttribute('slug');

            return is_string($slug) && $slug !== ''
                ? route("{$type}.show", ['slug' => $slug], $absolute)
                : null;
        }

        $structure = $this->structureFor($type);
        $base = $this->settings->for($type)->publicSlug;

        if ($structure->usesBase() && ($base === null || $base === '')) {
            return null;
        }

        $key = $entry->getAttribute($structure->keyColumn());

        if (! is_scalar($key) || (string) $key === '') {
            // slug（あるいは id）を持たない行は、個別ページの作りようがない
            return null;
        }

        $segments = $structure->usesBase() ? [(string) $base] : [];

        if ($structure->usesDate()) {
            $date = $this->publishedAt($entry);

            if ($date === null) {
                /*
                 * **公開日が無ければ日付 URL を作らない。**
                 *
                 * `created_at` で代用すると、**公開した瞬間に URL が変わる** —
                 * 公開前に配った URL が死ぬ。日付が確定していない構造で日付 URL を約束しない。
                 */
                return null;
            }

            $segments[] = $date->format('Y');
            $segments[] = $date->format('m');
        }

        $segments[] = (string) $key;

        return $this->path('/'.implode('/', $segments), $absolute);
    }

    /** その型の構造。**書かれていない / 読めないなら既定** */
    public function structureFor(string $type): PermalinkStructure
    {
        return $this->settings->for($type)->permalink();
    }

    /**
     * URL から「どの型の、どの記事か」を読み取る。**形が違えば null**。
     *
     * @param  list<string>  $segments
     */
    public function matchEntry(array $segments): ?PermalinkEntryRef
    {
        $count = count($segments);

        if ($count === 0) {
            return null;
        }

        $type = $this->typeForBase($segments[0]);

        if ($type !== null) {
            $structure = $this->structureFor($type);

            if (! $structure->usesBase() || $structure->segmentCount() !== $count) {
                return null;
            }

            return $structure->usesDate()
                ? new PermalinkEntryRef($type, 'slug', $segments[3], $segments[1].'-'.$segments[2])
                : new PermalinkEntryRef($type, $structure->keyColumn(), $segments[1]);
        }

        /*
         * 入口を持たない構造（`slug`）。**選べるのは 1 型だけ**なので、
         * 見つけた最初の型で決まる（2 型目は保存時に断っている）。
         */
        if ($count !== 1) {
            return null;
        }

        $top = $this->topLevelType();

        return $top === null ? null : new PermalinkEntryRef($top, 'slug', $segments[0]);
    }

    /** `slug` 構造を名乗っている型（無ければ null）。**1 型しか居ない** */
    public function topLevelType(): ?string
    {
        foreach ($this->settings->stored() as $id => $setting) {
            if ($setting->permalink() === PermalinkStructure::Slug) {
                return $id;
            }
        }

        return null;
    }

    private function publishedAt(Model $entry): ?\DateTimeInterface
    {
        $value = $entry->getAttribute('published_at');

        return $value instanceof \DateTimeInterface ? $value : null;
    }

    /** 一覧の URL。**作れなければ null** */
    public function index(string $type, bool $absolute = true): ?string
    {
        if ($this->router->has("{$type}.index")) {
            return route("{$type}.index", [], $absolute);
        }

        $base = $this->settings->for($type)->publicSlug;

        return $base === null ? null : $this->path('/'.$base, $absolute);
    }

    /**
     * その先頭 segment を名乗っている投稿タイプ（無ければ null）。
     *
     * **解く側の入口。** 名前付き route の世界はここを通らない（route が先に受ける）。
     */
    public function typeForBase(string $base): ?string
    {
        foreach ($this->settings->stored() as $id => $setting) {
            if ($setting->publicSlug === $base) {
                return $id;
            }
        }

        return null;
    }

    private function path(string $path, bool $absolute): string
    {
        return $absolute ? url($path) : $path;
    }
}
