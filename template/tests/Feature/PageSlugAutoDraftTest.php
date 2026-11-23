<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\SlugFromTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * ページの URL を、書く人に考えさせない（WP 一周 2026-09-03 / 効くもの ②）。
 *
 * WordPress から来た人はタイトルを入れて保存する。URL を先に決める習慣が無い。
 * ここで固定するのは 2 つ:
 *
 * 1. **URL を空で出しても作成できる** — タイトルから下書きする。
 *    日本語だけのタイトルは Laravel の slug 化が空を返すので、意味を持たないが
 *    一意な値へ倒す（Taxonomy の TermSlug と同じ考え方。あとから直せることが大事）
 * 2. **返す言葉が画面と揃っている** — 画面のラベルは「URL」なのに
 *    エラーだけ「slug」と返っていた。内部の語を利用者に見せない
 */
final class PageSlugAutoDraftTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_url_can_be_left_empty_and_is_drafted_from_the_title(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/pages', ['title' => 'Company Profile'])
            ->assertRedirect();

        $page = Page::query()->sole();
        $this->assertSame('company-profile', $page->slug);
    }

    public function test_japanese_only_title_still_creates_a_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/pages', ['title' => '会社概要'])
            ->assertRedirect();

        $page = Page::query()->sole();
        $this->assertNotSame('', (string) $page->slug, 'URL が空のまま保存された');
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $page->slug);
    }

    public function test_drafted_url_does_not_collide_with_an_existing_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/pages', ['title' => 'About Us'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/pages', ['title' => 'About Us'])->assertRedirect();

        $slugs = Page::query()->pluck('slug')->all();
        $this->assertCount(2, $slugs);
        $this->assertCount(2, array_unique($slugs), '同じ URL が 2 つ作られた');
    }

    public function test_validation_message_says_url_not_slug(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/pages', ['title' => 'テスト', 'slug' => 'Bad Slug!'])
            ->assertSessionHasErrors('slug');

        $messages = implode(' ', session('errors')?->get('slug') ?? []);

        $this->assertStringContainsString('URL', $messages);
        $this->assertStringNotContainsString('slug', $messages, '利用者に内部の語（slug）を見せている');
    }

    /** 🔴 UX 一周 2026-09-28。日本語の定番のページ名は、意味の分かる URL になる（/page にしない） */
    public function test_japanese_common_title_gets_a_meaningful_url(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/pages', ['title' => '会社概要'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/pages', ['title' => '料金'])->assertRedirect();
        // 定番名＋ありふれた付け足し（のご案内）も定番名の英語（UX 一周 21 周目 Y1）。店名などが付いた題は全部読む（20 周目 R1）
        $this->actingAs($admin)->post('/admin/pages', ['title' => '料金のご案内'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/pages', ['title' => '高橋料金のご案内'])->assertRedirect();

        $this->assertSame(['company', 'price', 'price-2', 'takahashi-ryoukin-no-go-annai'], Page::query()->orderBy('id')->pluck('slug')->all());
    }

    /**
     * かなはローマ字、漢字は表の読みでローマ字にする（UX 一周 3 周目 M3）。
     * 以前は漢字を当て推量しないとして空を返し、`/page` に落ちていた。熟語の読みは見ないので
     * 正しい読みとは限らない（春の庭 → shun-no-tei）が、題から見当の付く URL になる。
     * 表に無い字（記号だけ）は今までどおり空で、呼ぶ側が土台へ倒す。
     */
    public function test_kana_and_kanji_titles_become_romaji(): void
    {
        // カタカナ語は英語の表記（UX 一周 20 周目 R2。以前は shanpu）
        $this->assertSame('shampoo', SlugFromTitle::draft('シャンプー'));
        // 定番のページ名は英語の URL（お店について → o-ten-ni-tsuite だった）
        $this->assertSame('about', SlugFromTitle::draft('お店について'));
        // 漢字を含む題も、サーバーが形態素解析（IPADIC）で読む（UX 一周 19 周目。以前は 1 字ずつ読んで
        // yaki-ki-ue-ga-ri-toki-ma・kisshou-tera を作っていた。例の全部は UxRelease20261106Round19Test）
        $this->assertSame('yaki-agari-jikan', SlugFromTitle::draft('焼き上がり時間'));
        $this->assertSame('haru-no-niwa', SlugFromTitle::draft('春の庭'));
        $this->assertSame('', SlugFromTitle::draft('〒〇〇'));
    }

    public function test_drafted_url_avoids_urls_owned_by_other_screens(): void
    {
        // /contact はお問い合わせの画面が持っている。同じ URL のページは公開しても出ない
        $this->actingAs($this->admin())->post('/admin/pages', ['title' => 'お問い合わせ'])->assertRedirect();

        $this->assertSame('contact-2', Page::query()->sole()->slug);
    }

    public function test_created_message_tells_the_url(): void
    {
        $this->actingAs($this->admin())->post('/admin/pages', ['title' => '会社概要'])->assertRedirect();

        $flash = (array) session()->get(SessionKey::FLASH_DATA, []);

        $this->assertSame('ページを作成しました（URL: /company）', $flash['toast']['message'] ?? null);
    }

    /** URL を空のまま作ると、サーバーが読んで付ける。重複には番号を付ける（UX 一周 19 周目） */
    public function test_screen_reading_is_used_for_the_url(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/pages', ['title' => '焼き上がり時間'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/pages', ['title' => '焼き上がり時間'])->assertRedirect();
        // 読めない字だけの題は、黙って page にせず URL を求める
        $this->actingAs($admin)->from('/admin/pages/create')->post('/admin/pages', ['title' => '〓〓'])
            ->assertSessionHasErrors(['slug' => CreatePage::KANJI_NEEDS_SLUG]);

        $this->assertSame(['yaki-agari-jikan', 'yaki-agari-jikan-2'], Page::query()->where('title', '焼き上がり時間')->orderBy('id')->pluck('slug')->all());
    }

    public function test_long_title_with_empty_url_creates_a_valid_word_bounded_slug(): void
    {
        $title = str_repeat('a', 179).' tail';
        $expected = str_repeat('a', 179);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/pages', ['title' => $title, 'slug' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($expected, Page::query()->sole()->slug);
        $this->actingAs($admin)->post('/admin/pages', ['title' => $title, 'slug' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([$expected, $expected.'-2'], Page::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_long_title_preview_matches_setup_reading_and_is_word_bounded(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/admin/pages/slug-preview?title='.rawurlencode(str_repeat('a', 177).' tail'))
            ->assertOk()->assertJsonPath('data.slug', str_repeat('a', 177));
    }

    public function test_94_character_japanese_title_can_be_created_with_empty_url(): void
    {
        $title = str_repeat('着付け教室のご案内・', 9).'着付けの';
        $expected = 'kitsuke-kyoushitsu-no-go-annai-kitsuke-kyoushitsu-no-go-annai-kitsuke-kyoushitsu-no-go-annai-kitsuke-kyoushitsu-no-go-annai-kitsuke-kyoushitsu-no-go-annai-kitsuke-kyoushitsu-no-go';
        $this->assertSame(94, mb_strlen($title));
        $this->actingAs($this->admin())->post('/admin/pages', ['title' => $title, 'slug' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($expected, Page::query()->sole()->slug);
    }
}
