<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuLinkType;
use App\Models\User;
use App\Support\FirstSteps;
use App\Support\PublicationReadiness;
use App\Support\Role;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UxRound2NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function header(): Menu
    {
        $this->seed(MenuSeeder::class);
        $this->actingAs(User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]));

        return Menu::query()->where('key', 'header')->firstOrFail();
    }

    private function choose(string $industry): void
    {
        $this->app?->forgetScopedInstances();
        $this->post('/admin/industry', ['industry' => $industry])->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
    }

    public function test_未保存のヘッダーに記事一覧を問い合わせの前へ足しても歩は済みにならない(): void
    {
        $header = $this->header();
        $this->choose('trimming');
        $items = $header->items()->whereNull('parent_id')->orderBy('sort_order')->get();
        $post = $items->firstWhere('content_type', 'post');
        $this->assertNotNull($post);
        $this->assertSame(MenuLinkType::ContentIndex->value, $post->link_type);
        $this->assertNull($post->link_url);
        $this->assertSame('お知らせ', $post->title);
        $this->assertSame('contact.show', $items->last()->link_route);
        $this->assertSame('post', $items[$items->count() - 2]->content_type);
        $this->assertSame(0, $header->fresh()->lock_version);
        $step = collect(app(FirstSteps::class)->all())->firstWhere('key', 'menu');
        $this->assertFalse($step['done']);
        // 同梱ニュースの一覧と post は別の内容。行き先の重複も起こさない。
        $this->assertCount(1, $items->where('content_type', 'news'));
        $slug = app(ContentTypeSettingsResolver::class)->for('post')->publicSlug;
        $this->assertSame('news-2', $slug);
        $this->post('/admin/post', ['title' => '今週の花束', 'body' => '季節の花をご用意しました。', 'status' => 'published'])->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
        $this->get('/')->assertOk()->assertSee('/'.$slug)->assertSee('お知らせ');
        $this->get('/'.$slug)->assertOk()->assertSee('今週の花束');
        $this->assertSame([], array_values(array_filter(app(PublicationReadiness::class)->check(),
            fn (array $finding): bool => str_contains($finding['what'], '一覧へサイトのどこからも行けません')
                && str_contains($finding['what'], 'お知らせ'))));
        $this->choose('koumuten');
        $this->assertSame('施工事例', $post->fresh()->title);
        $this->assertFalse(collect(app(FirstSteps::class)->all())->firstWhere('key', 'menu')['done']);
        $this->assertSame(1, $header->items()->where('content_type', 'post')->count());
    }

    public function test_保存したメニューから外した記事一覧を戻さない(): void
    {
        $header = $this->header();
        $this->choose('trimming');
        $header->items()->where('content_type', 'post')->delete();
        $header->forceFill(['lock_version' => 1])->save();
        $before = $header->items()->get()->toArray();
        $this->choose('koumuten');
        // service の業種名更新は既存の仕様。今回の post 導線は増やさない。
        $this->assertSame(0, $header->items()->where('content_type', 'post')->count());
        $this->assertCount(count($before), $header->items()->get());
    }

    public function test_保存した記事一覧の表示名は選び直しても変えない(): void
    {
        $header = $this->header();
        $this->choose('trimming');
        $header->forceFill(['lock_version' => 1])->save();
        $post = $header->items()->where('content_type', 'post')->firstOrFail();
        $this->choose('koumuten');
        $this->assertSame('お知らせ', $post->fresh()->title);
    }

    public function test_既存の記事一覧の名前と件数は変えない(): void
    {
        $header = $this->header();
        $post = $header->items()->create(['title' => '店の便り', 'link_type' => 'content_index', 'content_type' => 'post', 'sort_order' => 9]);
        $this->choose('trimming');
        $this->choose('koumuten');
        $this->assertSame('店の便り', $post->fresh()->title);
        $this->assertSame(1, $header->items()->where('content_type', 'post')->count());
    }

    public function test_明示的な非公開では導線を足さない(): void
    {
        $header = $this->header();
        $this->assertTrue(app(ContentTypeSettingsWriter::class)->save('post', ['registration' => json_encode(['public' => false])], 0));
        $this->choose('trimming');
        $this->assertSame(0, $header->items()->where('content_type', 'post')->count());
    }
}
