<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * パンくず（P1 ⑤）。
 *
 * ## 一番要るのは記事詳細である
 *
 * `/about` のようなページはメニューに載っているが、**記事詳細は載らない**
 * （載せる意味が無い）。**木を辿るだけだと、一番要る所で出ない。**
 *
 * だから木が返すのは**祖先まで**で、**末端はページが渡す**。
 */
final class BreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 既定メニューを入れる。
     *
     * **祖先はメニューの木から来る**ので、木が空なら道は「ホーム > 記事名」に縮む。
     * それは実装の誤りではなく**前提が無い**状態なので、前提を作ってから見る。
     */
    private function seedMenu(): void
    {
        /*
         * **リンク先が公開されていなければ、メニューはその項目を出さない**
         * （`MenuTree::indexReason()`）。守りとして正しいので、
         * 前提の側（型が公開されている状態）を作る。
         */
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active', 'lifecycle' => 'ready', 'lock_version' => 0,
                // **公開の旗と slug が要る**（`ContentTypeLifecyclePolicy::isPublic()`）
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'public_slug' => 'news',
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        $menuId = DB::table('menus')->insertGetId([
            'key' => 'header', 'name' => 'ヘッダー', 'managed_by' => 'admin',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 一覧は **content_index**（route から URL が決まる）。記事詳細はここに載らない
        DB::table('menu_items')->insert([
            'menu_id' => $menuId, 'parent_id' => null, 'sort_order' => 0,
            'title' => 'ニュース', 'link_type' => 'content_index', 'content_type' => 'news',
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function news(string $title, string $slug): void
    {
        DB::table('news')->insert([
            'title' => $title, 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_記事詳細に道が出る(): void
    {
        $slug = 'bc-'.uniqid();
        $this->news('記事のタイトル', $slug);

        $html = (string) $this->get("/news/{$slug}")->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="パンくず"', $html);
        $this->assertStringContainsString('ホーム', $html);
        // **末端は記事名**（木には載っていない）
        $this->assertStringContainsString('記事のタイトル', $html);
    }

    /**
     * **一覧が祖先として出る。**
     *
     * `/news/xxx` は `/news` の下に居る。完全一致だけを見ていると
     * **祖先が空になり、道が「ホーム > 記事名」に縮む**。
     */
    public function test_記事詳細の祖先に一覧が入る(): void
    {
        $slug = 'bc-'.uniqid();
        $this->seedMenu();
        $this->news('記事のタイトル', $slug);

        $html = (string) $this->get("/news/{$slug}")->assertOk()->getContent();

        preg_match('#<nav class="breadcrumb".*?</nav>#s', $html, $m);

        $this->assertNotEmpty($m, 'パンくずが出ていない');
        $this->assertStringContainsString('/news', $m[0], '一覧への道が無い');
    }

    /**
     * **並び順で祖先が消えない**（F-32）。
     *
     * 記事そのものをメニューへ載せてあると、**その項目が先に当たる**。
     * そこで走査を打ち切ると、**後ろに居る一覧（祖先）まで捨てる**。
     *
     * 前の fixture は 1 階層 1 件で、**この順序を 1 度も通していなかった**。
     */
    public function test_自分自身が先に載っていても祖先は消えない(): void
    {
        $slug = 'bc-'.uniqid();
        $this->seedMenu();
        $this->news('記事のタイトル', $slug);

        $menuId = (int) DB::table('menus')->where('key', 'header')->value('id');

        // **記事そのもの**を、一覧より前に置く
        DB::table('menu_items')->insert([
            'menu_id' => $menuId, 'parent_id' => null, 'sort_order' => -1,
            'title' => '注目の記事', 'link_type' => 'custom', 'link_url' => "/news/{$slug}",
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = (string) $this->get("/news/{$slug}")->assertOk()->getContent();

        preg_match('#<nav class="breadcrumb".*?</nav>#s', $html, $m);

        $this->assertNotEmpty($m, 'パンくずが出ていない');
        $this->assertStringContainsString('/news', $m[0], '一覧への道が消えた');
    }

    /** **ホームでは出さない。** 根だけなら道にならない */
    public function test_ホームには出さない(): void
    {
        $this->assertStringNotContainsString(
            'aria-label="パンくず"',
            (string) $this->get('/')->assertOk()->getContent(),
        );
    }

    /**
     * 構造化データ（`PHASE9_SPEC.md` §104 で MUST）。
     *
     * `application/ld+json` は**実行されないデータブロック**なので、
     * CSP の `script-src` には当たらない。
     */
    public function test_構造化データが出る(): void
    {
        $slug = 'bc-'.uniqid();
        $this->news('記事のタイトル', $slug);

        $html = (string) $this->get("/news/{$slug}")->assertOk()->getContent();

        // 記事のJSON-LDと共存するため、順番ではなく種類でパンくずを検証する。
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $entries = array_map(static fn (string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $m[1]);
        $breadcrumbs = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['@type'] ?? null) === 'BreadcrumbList'));
        $this->assertCount(1, $breadcrumbs, 'パンくずのJSON-LDが一つ必要');
        $data = $breadcrumbs[0];

        // **壊れた唯一のキーが、assert していない唯一のキーだった**（F-28）。
        // `'@context'` を Blade に直書きすると `@context` ディレクティブとして食われ、
        // **キーが PHP のソースに化けて schema.org として成立しない**
        // `?? null` にして、**鍵が無いときも「読める失敗」**にする
        // （配列添字の error ではなく、期待と実際が並ぶ形で出したい）
        $this->assertSame('https://schema.org', $data['@context'] ?? null);
        $this->assertSame('BreadcrumbList', $data['@type'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($data['itemListElement']));
        $this->assertSame(1, $data['itemListElement'][0]['position']);

        // **末端に item（URL）を付けない** — いま居る場所へのリンクは道ではない
        $last = $data['itemListElement'][count($data['itemListElement']) - 1];
        $this->assertArrayNotHasKey('item', $last);
    }

    /**
     * **本文から script の外へ出られない**（F-29）。
     *
     * `JSON_UNESCAPED_SLASHES` を付けると、記事タイトルの `</script>` が
     * **そのまま出て script を閉じられる**。**CSP は最後の砦**であって、
     * 出どころで塞ぐのが先である。
     */
    public function test_タイトルからscriptを閉じられない(): void
    {
        $slug = 'bc-'.uniqid();
        $this->seedMenu();
        $this->news('</script><script>alert(1)</script>', $slug);

        $html = (string) $this->get("/news/{$slug}")->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        // **特定の綴りを見張らない。** JSON-LD の中に生の `</script` が 1 つも無いこと
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
        $this->assertNotEmpty($ld[1], 'JSON-LD が無い');
        foreach ($ld[1] as $json) {
            $this->assertStringNotContainsString('</script', $json, 'JSON-LD の中から脱出できる');
            $this->assertStringNotContainsString('<', $json, '生の < が残っている');
        }
    }

    /**
     * **メニューに載っているページで、末端が二重にならない**（F-30）。
     *
     * `trailFor` が「祖先まで」と言いながら**一致した項目自身**を返していた。
     * 末端は呼ぶ側が渡す約束なので、`/about` が
     * **「ホーム > About > About」**になっていた。
     *
     * 前の fixture は一覧 1 件だけで、**普通の運用（ページがメニューに載る）を
     * 1 度も通っていなかった**。
     */
    public function test_メニューに載るページでも末端は1つ(): void
    {
        $menuId = DB::table('menus')->insertGetId([
            'key' => 'header', 'name' => 'ヘッダー', 'managed_by' => 'admin',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('menu_items')->insert([
            'menu_id' => $menuId, 'parent_id' => null, 'sort_order' => 0,
            'title' => 'About', 'link_type' => 'route', 'link_route' => 'about',
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = (string) $this->get('/about')->assertOk()->getContent();

        preg_match('#<nav class="breadcrumb".*?</nav>#s', $html, $m);

        $this->assertNotEmpty($m);
        $this->assertSame(2, substr_count($m[0], '<li'), '道の段が 2 つ（ホーム > About）でない');
        $this->assertSame(1, substr_count($m[0], 'About'), 'About が二重に出ている');
    }

    /**
     * **手書きの道が 1 枚も残っていない。**
     *
     * ## 一覧を手で持たない
     *
     * 前の形は `/about` `/service` `/works` を並べて見ていた。
     * その一覧に **`/contact` が入っておらず、手書きが 1 枚残っていた**
     * （Home の表記まで違っていた）。**数え漏らしは、いずれまた起きる。**
     *
     * だから見るのは**画面ではなく形**にした — 「`class="breadcrumb"` を書いた
     * blade が、component 以外に 1 つも無い」。**ページが増えても効く**し、
     * 新しいページが手書きで足された日に、その場で赤くなる。
     *
     * 走査の前に **Blade のコメントを落とす**（説明文に出てくる綴りを
     * 実体と数えない）。
     */
    public function test_手書きのパンくずが残っていない(): void
    {
        $component = realpath(resource_path('views/components/breadcrumb.blade.php'));

        $roots = [resource_path('views'), base_path('modules')];
        $offenders = [];
        $reached = false;

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                if (realpath($file->getPathname()) === $component) {
                    $reached = true;

                    continue; // 道を書いてよい唯一の場所
                }

                // **コメントは実体ではない**（説明文の綴りを数えない）
                $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

                if (str_contains((string) $source, 'class="breadcrumb"')) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        // **空振りを緑と読まない。** 走査が component にも届いていないなら、根が違う
        $this->assertTrue($reached, '走査が component へ届いていない（見ている場所が違う）');

        $this->assertSame(
            [],
            $offenders,
            "component 以外に手書きのパンくずが在る: \n".implode("\n", $offenders),
        );
    }

    /** 出口の側でも確かめる（**component へ置き換えた結果、道は 1 本**） */
    public function test_公開ページの道は1本だけ(): void
    {
        foreach (['/about', '/service', '/works', '/contact'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, 'aria-label="パンくず"'),
                "{$path}: パンくずが 1 本でない",
            );
        }
    }
}
