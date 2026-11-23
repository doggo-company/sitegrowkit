<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「記事を書けるようにする」の 1 クリック（WP 一周 2026-09-03 / 効くもの ①）。
 *
 * ## B-39 は取り消さない
 *
 * 中身が空の投稿メニューを並べない（オーナー決定 2026-08-24）のはそのまま。
 * 変えるのは**出す/出さないの既定ではなく、出すまでの手数**である。
 *
 * WordPress から来た人は「投稿 → 新規追加」を探す。いまは
 * 設計 > 投稿タイプ > 設定 に入って、サイドバー・公開スラッグ・公開する・
 * 個別 URL・一覧 URL の **5 か所**を自分で組み立てないと記事が書けない。
 * それを 1 回の操作にまとめる。
 *
 * ## 既にある設定を消さない
 *
 * `display_overrides` は JSON 丸ごとの置き換えなので、素朴に
 * `show_in_menu` だけを送ると **表示名やアイコンが消える**。
 * ここで固定するのは「足すのであって、消さない」ことである。
 */
final class PostQuickStartTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * 設定は **1 リクエストに 1 回だけ**読んでメモ化される（`ContentTypeSettingsResolver`）。
     * request 中に作られた instance をそのまま覗くと、更新前の値が返る。
     * 実際の画面は次の request で読み直すので、テストでも同じように捨ててから読む。
     */
    private function settingsFor(string $type): object
    {
        $this->app?->forgetScopedInstances();

        return app(ContentTypeSettingsResolver::class)->for($type);
    }

    public function test_one_click_makes_posts_writable_and_public(): void
    {
        // B-39 の既定＝空なので隠れている、の状態から始める
        app(ContentTypeSettingsWriter::class)->save(
            'post',
            ['display_overrides' => json_encode(['show_in_menu' => false], JSON_UNESCAPED_UNICODE)],
            0,
        );

        // 押した人がやりたいのは設定ではなく記事を書くこと。**書ける場所へ連れて行く**
        $this->actingAs($this->admin())
            ->post('/admin/content-types/post/quick-start')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.post.index', absolute: false));

        $settings = $this->settingsFor('post');

        $this->assertTrue($settings->displayOverrides['show_in_menu'] ?? false, 'サイドバーに出ていない');
        $this->assertSame('blog', $settings->publicSlug, '公開スラッグが入っていない');
        $this->assertTrue($settings->registration['public'] ?? false, '公開サイトに出ない');
        $this->assertTrue($settings->registration['publicly_queryable'] ?? false, '個別ページの URL が無い');
        $this->assertTrue($settings->registration['has_archive'] ?? false, '一覧ページの URL が無い');
    }

    public function test_one_click_keeps_settings_the_owner_already_chose(): void
    {
        app(ContentTypeSettingsWriter::class)->save(
            'post',
            [
                'display_overrides' => json_encode(
                    ['show_in_menu' => false, 'label' => 'お知らせ', 'icon' => 'news'],
                    JSON_UNESCAPED_UNICODE,
                ),
                // 予約済みの語（news / blog 等）を避ける。ここで見たいのは「上書きしないこと」だけ
                'public_slug' => 'oshirase',
            ],
            0,
        );

        $this->actingAs($this->admin())
            ->post('/admin/content-types/post/quick-start')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $settings = $this->settingsFor('post');

        $this->assertSame('お知らせ', $settings->displayOverrides['label'] ?? null, '表示名が消えた');
        $this->assertSame('news', $settings->displayOverrides['icon'] ?? null, 'アイコンが消えた');
        $this->assertSame('oshirase', $settings->publicSlug, '決めてあった公開スラッグを上書きした');
        $this->assertTrue($settings->displayOverrides['show_in_menu'] ?? false);
    }

    /**
     * ダッシュボードが**押せるボタン**を持つ。
     *
     * 以前は「投稿タイプ で『管理メニューに出す』を有効にすると、ここに出ます」という
     * 案内文だけだった。読んだ人はその先で 5 か所を自分で組み立てることになる。
     */
    public function test_dashboard_offers_the_one_click_when_nothing_is_writable(): void
    {
        // kit には例の型（news / works / product）が在る。生成直後の project は post だけなので、
        // 「書ける入れ物が 1 つも無い」状態を作ってから見る
        $this->hideEveryEditorialType();

        $props = $this->actingAs($this->admin())->get('/dashboard')->inertiaProps('quickStart');

        $this->assertIsArray($props);
        $this->assertSame('post', $props['typeId']);
        $this->assertSame('/admin/content-types/post/quick-start', $props['url']);
    }

    public function test_dashboard_hides_the_one_click_once_posts_are_writable(): void
    {
        $this->actingAs($this->admin())->post('/admin/content-types/post/quick-start');

        $this->assertNull(
            $this->actingAs($this->admin())->get('/dashboard')->inertiaProps('quickStart'),
            '書けるようになったのにボタンが残っている',
        );
    }

    /** 生成直後の project（書ける入れ物が 1 つも無い）を作る */
    private function hideEveryEditorialType(): void
    {
        $writer = app(ContentTypeSettingsWriter::class);
        $schemas = app(SchemaRegistry::class);
        $types = app(ContentTypeResolver::class);

        foreach ($types->availableTypes() as $id) {
            if (! EditorialCollections::isEditorial($schemas->get($id))) {
                continue;
            }

            $writer->save(
                $id,
                ['display_overrides' => json_encode(['show_in_menu' => false], JSON_UNESCAPED_UNICODE)],
                app(ContentTypeSettingsResolver::class)->for($id)->lockVersion,
            );
        }

        $this->app?->forgetScopedInstances();
    }

    public function test_guests_cannot_use_it(): void
    {
        $this->post('/admin/content-types/post/quick-start')->assertRedirect('/login');
    }
}
