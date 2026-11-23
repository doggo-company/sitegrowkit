<?php

namespace Tests\Feature;

use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Forms\SubmissionStatus;
use App\Models\User;
use App\Support\ContactEntry;
use Database\Seeders\ContactFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Contact\Models\ContactSubmission;
use Modules\News\Models\News;
use Modules\Works\Models\Work;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_renders_cms_overview_with_real_counts_and_recent_news(): void
    {
        $this->actingAs(User::factory()->create());

        News::query()->create([
            'title' => '公開済みのお知らせ',
            'slug' => 'published-news',
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        News::query()->create([
            'title' => '下書きのお知らせ',
            'slug' => 'draft-news',
            'body' => '<p>本文</p>',
            'status' => 'draft',
        ]);
        Work::query()->create([
            'title' => '制作実績',
            'slug' => 'sample-work',
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        ContactSubmission::query()->forceCreate([
            'name' => 'テスト太郎',
            'email' => 'test@example.com',
            'subject' => 'other',
            'message' => 'テスト',
            'status' => 'received',
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                // 管理 route を持つ投稿タイプだけが並ぶ（`product` は route が無い）
                ->has('collections', 3)
                ->where('collections.0.id', 'news')
                ->where('collections.0.label', 'ニュース')
                ->where('collections.0.count', 2)
                ->where('collections.1.id', 'works')
                ->where('collections.1.count', 1)
                ->where('collections.2.id', 'post')
                ->where('media.count', 0)
                ->where('contact.total', 1)
                ->where('contact.received', 1)
                // 「最近の投稿」は **最初の投稿タイプ**から取る（型名を焼き込まない）
                ->has('recent.items', 2)
                ->where('recent.items.0.title', '公開済みのお知らせ')
                ->where('recent.items.0.status', 'published')
                ->where('recent.items.1.title', '下書きのお知らせ')
                ->where('recent.items.1.status', 'draft')
                ->has('siteSettingsUrl'),
            );
    }

    public function test_recent_entries_match_publication_date_when_sort_order_ties(): void
    {
        $this->actingAs(User::factory()->create());
        $newer = News::query()->create([
            'title' => '公開日の新しい記事', 'slug' => 'newer-date', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        $older = News::query()->create([
            'title' => '後から作った古い記事', 'slug' => 'backdated', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDays(3),
        ]);
        $this->get('/news')->assertOk()->assertSeeInOrder(['公開日の新しい記事', '後から作った古い記事']);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('recent.items.0.id', $newer->getKey())
            ->where('recent.items.1.id', $older->getKey())
        );
    }

    public function test_recent_entries_follow_manual_publication_order(): void
    {
        $this->actingAs(User::factory()->create());
        $first = News::query()->create([
            'title' => '掲載順先頭', 'slug' => 'order-first', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDays(3),
        ]);
        $second = News::query()->create([
            'title' => '掲載順末尾', 'slug' => 'order-last', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        $this->postJson('/api/v1/content/news/'.$second->getKey().'/reorder', ['direction' => 'down'])->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('recent.items.0.id', $first->getKey())
            ->where('recent.items.1.id', $second->getKey())
        );
    }

    public function test_dashboard_renders_with_empty_database(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('collections.0.count', 0)
                ->where('collections.1.count', 0)
                ->where('media.count', 0)
                ->where('contact.total', 0)
                ->has('recent.items', 0),
            );
    }

    /**
     * **数える先と開く先が、窓口と揃っている**（R-12 / 受付の一本化）。
     *
     * 🔴 受付をフォームへ一本化したので、届いた問い合わせは `form_submissions` に入る。
     * ダッシュボードが旧 `contact_submissions` を数え続けると、
     * **画面には 0 件と出たまま問い合わせだけが溜まる。**エラーは出ない。
     * 一本化する前と同じ読み違いを、ダッシュボードで作り直すことになる。
     *
     * **押した先も見る。** 数だけ直しても、開いた先が旧画面なら同じことである。
     */
    public function test_お問い合わせの枠がフォームの受信を数えて開く(): void
    {
        $this->seed(ContactFormSeeder::class);

        $form = app(ContactEntry::class)->publishedForm();
        $this->assertNotNull($form, '前提: 受付フォームが入っていない');

        // 旧側に 3 件（移していない）。**こちらを数えてはいけない**
        for ($i = 0; $i < 3; $i++) {
            ContactSubmission::query()->forceCreate([
                'name' => '旧 受信'.$i,
                'email' => 'old'.$i.'@example.test',
                'subject' => 'other',
                'message' => '一本化の前に届いていた問い合わせです。',
                'status' => 'received',
            ]);
        }

        FormSubmission::query()->create([
            'form_id' => $form->getKey(),
            'data' => [
                'name' => '受付 太郎',
                FormSubmission::LABELS_KEY => [['name', 'お名前']],
            ],
            'status' => SubmissionStatus::Received->value,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contact.total', 1)
                ->where('contact.received', 1)
                ->where('contact.indexUrl', '/admin/forms/'.$form->key.'/submissions'));
    }

    /** **窓口が無いなら、これまでどおり旧側を指す**（開ける先が消えない） */
    public function test_受付フォームが無ければ旧側を指す(): void
    {
        for ($i = 0; $i < 2; $i++) {
            ContactSubmission::query()->forceCreate([
                'name' => '旧 受信'.$i,
                'email' => 'old'.$i.'@example.test',
                'subject' => 'other',
                'message' => '一本化の前に届いていた問い合わせです。',
                'status' => 'received',
            ]);
        }

        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contact.total', 2)
                ->where('contact.indexUrl', '/admin/contact'));
    }

    public function test_dashboard_page_has_no_starter_placeholder(): void
    {
        $page = file_get_contents(resource_path('js/pages/Dashboard.vue'));

        $this->assertStringNotContainsString('PlaceholderPattern', $page);
        $this->assertFileDoesNotExist(resource_path('js/components/PlaceholderPattern.vue'));
    }

    public function test_admin_navigation_has_no_upstream_starter_links(): void
    {
        foreach (['js/components/AppSidebar.vue', 'js/components/AppHeader.vue'] as $rel) {
            $source = file_get_contents(resource_path($rel));

            $this->assertStringNotContainsString('vue-starter-kit', $source, $rel);
            $this->assertStringNotContainsString('laravel.com/docs/starter-kits', $source, $rel);
            // 置換リンクを置く場合は実在する customer-facing URL であること（dead link 禁止）。
            // 現時点では外部リンク自体を置かない。
            $this->assertStringNotContainsString('github.com/laravel', $source, $rel);
        }
    }
}
