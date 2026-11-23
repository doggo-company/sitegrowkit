<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 記事の一覧の URL を業種の呼び名に合わせる（UX 一周 2026-09-28: 工務店の施工事例が /blog だった）。
 *
 * 公開中に変わるときは、旧 URL から新しい URL への転送（301）を必ず残す。
 */
final class IndustryListUrlTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    private function pick(string $industry): void
    {
        $this->actingAs($this->owner)->post('/admin/industry', ['industry' => $industry])->assertRedirect();
        $this->app?->forgetScopedInstances();
    }

    private function publishPostType(): void
    {
        $this->actingAs($this->owner)->post('/admin/content-types/post/quick-start', ['stay' => 1])->assertRedirect();
        $this->app?->forgetScopedInstances();
    }

    private function writePost(): string
    {
        $this->actingAs($this->owner)
            ->post('/admin/post', ['title' => '年末調整の書類のご案内', 'body' => '本文', 'status' => 'published'])
            ->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();

        return (string) DB::table('post')->value('slug');
    }

    private function slug(): ?string
    {
        $this->app?->forgetScopedInstances();

        return app(ContentTypeSettingsResolver::class)->for('post')->publicSlug;
    }

    public function test_公開する前に業種を選べばその呼び名の_ur_lで始まる(): void
    {
        $this->pick('zeirishi');
        $this->assertSame('column', $this->slug());

        $this->publishPostType();
        $this->writePost();

        $this->get('/column')->assertOk()->assertSee('お知らせ・コラム');
    }

    /** 🔴 公開中の /blog から変わるときは、一覧も記事も転送で辿り着ける */
    public function test_公開中に_ur_lが変わったら旧_ur_lから転送する(): void
    {
        $this->publishPostType();
        $post = $this->writePost();
        $this->assertSame('blog', $this->slug());
        $this->get('/blog')->assertOk();

        $this->pick('zeirishi');

        $this->assertSame('column', $this->slug());
        $this->get('/column')->assertOk();
        $this->get('/blog')->assertStatus(301)->assertRedirect('/column');
        $this->get('/blog/'.$post)->assertStatus(301)->assertRedirect('/column/'.$post);
    }

    /** 選び直すと、次の業種の URL へ移り、最初の /blog からも辿り着ける */
    public function test_業種を選び直すと_ur_lも替わり古い_ur_lはどれも辿り着ける(): void
    {
        $this->publishPostType();
        $post = $this->writePost();

        $this->pick('zeirishi');
        $this->pick('sakka');

        $this->assertSame('exhibitions', $this->slug());
        $this->get('/exhibitions/'.$post)->assertOk();
        $this->get('/column/'.$post)->assertStatus(301)->assertRedirect('/exhibitions/'.$post);

        // /blog → /column → /exhibitions（転送を辿れば記事に着く）
        $url = '/blog/'.$post;
        for ($hops = 0; $hops < 3; $hops++) {
            $response = $this->get($url);
            if ($response->getStatusCode() !== 301) {
                break;
            }
            $url = (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
        }
        $this->assertSame('/exhibitions/'.$post, $url);
        $this->get($url)->assertOk();
    }

    public function test_自分で付けた_ur_lは業種を選んでも変えない(): void
    {
        $current = app(ContentTypeSettingsResolver::class)->for('post');
        app(ContentTypeSettingsWriter::class)->save('post', ['public_slug' => 'diary'], $current->lockVersion);

        $this->pick('zeirishi');

        $this->assertSame('diary', $this->slug());
    }

    /** ほかの画面と重なる URL は採番し、記事の一覧を業種に合わせる */
    public function test_使えない_ur_lなら採番して業種に合わせる(): void
    {
        $this->publishPostType();

        // kit の試験環境には同梱の Works（/works）がある。工務店の /works はそれと重なる
        $this->assertTrue(Route::has('works.index'), '前提: /works が別の画面で使われている');

        $this->actingAs($this->owner)->post('/admin/industry', ['industry' => 'koumuten'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('works-2', $this->slug());
        $this->get('/works-2')->assertOk()->assertSee('施工事例');
        $this->get('/blog')->assertStatus(301)->assertRedirect('/works-2');
    }
}
