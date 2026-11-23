<?php

declare(strict_types=1);

namespace Tests\Feature\Menus;

use AgentCms\Core\Menus\MenuTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * メニューから分類の一覧を指す（WP 一周 7 周目 2026-09-03 / 効くもの ③）。
 *
 * ## なぜ URL 直接指定では足りないか
 *
 * 分類ごとの一覧は入れたのに、メニューの種類は 5 つのままだった。
 * 「カテゴリをナビに出す」には `URL を直接指定` で手打ちするしかなく、
 * それだと **項目の綴りを変えた瞬間に、メニューが黙って 404 になる**。
 * 綴りは**あとから変えられる**ので、これは「そのうち必ず起きる」形である。
 *
 * だから **id で持ち、綴りは描くたびに読み直す**。
 */
final class MenuTermLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_分類の一覧へのリンクを組み立てる(): void
    {
        $term = $this->menuPointingAtTerm();

        $this->assertNotSame('', $term);
        $this->assertSame('/blog/shinryo_kamoku/term-1', $this->firstUrl());
    }

    /** 名前を省略したら、項目の名前が出る（SPEC §B.8 の導出） */
    public function test_名前は項目から導出される(): void
    {
        $this->menuPointingAtTerm(title: null);

        $this->assertSame('予防接種', $this->firstItem()['title'] ?? null);
    }

    /**
     * 🔴 **これがこの変更の理由。**
     *
     * 綴りを変えても、リンクは追従する。URL を直接指定で持っていたら 404 になる。
     */
    public function test_綴りを変えてもリンクが追従する(): void
    {
        $term = $this->menuPointingAtTerm();

        DB::table('taxonomy_terms')->where('id', $term)->update(['slug' => 'yobou']);

        $this->assertSame('/blog/shinryo_kamoku/yobou', $this->firstUrl());
    }

    /** 🔴 アーカイブした項目は、メニューからも消える（公開面と同じ条件） */
    public function test_アーカイブした項目はメニューから消える(): void
    {
        $term = $this->menuPointingAtTerm();

        DB::table('taxonomy_terms')->where('id', $term)->update(['archived_at' => now()]);

        $this->assertSame([], app(MenuTree::class)->forKey('header'));
    }

    /** 消えた理由は、管理側で読めるようにしておく */
    public function test_消えた項目は理由つきで出る(): void
    {
        $term = $this->menuPointingAtTerm();

        DB::table('taxonomy_terms')->where('id', $term)->update(['archived_at' => now()]);

        // `unavailable()` は [項目 id => 理由] を返す
        $reasons = array_values(app(MenuTree::class)->unavailable('header'));

        $this->assertContains('リンク先の分類がありません', $reasons);
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function firstItem(): array
    {
        $items = app(MenuTree::class)->forKey('header');

        $this->assertNotSame([], $items, 'メニューに項目が 1 つも出ていない');

        return $items[0];
    }

    private function firstUrl(): string
    {
        return (string) ($this->firstItem()['url'] ?? '');
    }

    /** 分類・項目・投稿タイプ・メニューを作り、term を指す項目を 1 つ置く */
    private function menuPointingAtTerm(?string $title = '予防接種のお知らせ'): string
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'status' => 'active',
                'lifecycle' => 'ready',
                'public_slug' => 'blog',
                'registration' => json_encode(['public' => true], JSON_UNESCAPED_UNICODE),
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $taxonomyId = (string) Str::ulid();
        $termId = (string) Str::ulid();

        DB::table('taxonomies')->insert([
            'id' => $taxonomyId,
            'key' => 'shinryo_kamoku',
            'label' => '診療科目',
            'hierarchical' => false,
            'status' => 'active',
            'managed_by' => 'cms',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'id' => $termId,
            'taxonomy_id' => $taxonomyId,
            'name' => '予防接種',
            'slug' => 'term-1',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = DB::table('menus')->insertGetId([
            'key' => 'header',
            'name' => 'ヘッダー',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menu_items')->insert([
            'menu_id' => $menuId,
            'sort_order' => 0,
            'title' => $title,
            'link_type' => 'term',
            'content_type' => 'post',
            'taxonomy_term_id' => $termId,
            'target' => '_self',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->forgetScopedInstances();

        return $termId;
    }
}
