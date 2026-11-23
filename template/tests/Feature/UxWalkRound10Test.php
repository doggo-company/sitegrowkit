<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuTree;
use App\Models\User;
use App\Support\PublicationReadiness;
use App\Support\Role;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * UX 一周 第 10 周（工務店の社長の目・業種の入口から・2026-09-28）で見つけたものの回帰。
 */
final class UxWalkRound10Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    /** 工務店を選び、施工事例を公開サイトに出して 1 件公開する（第 10 周の手順） */
    private function publishFirstWork(): void
    {
        // 生成直後と同じメニュー（私たちについて・サービス・お問い合わせ）
        $this->seed(MenuSeeder::class);
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'koumuten'])->assertRedirect();
        // 本番では 1 リクエストごとに作り直される（試験の container はリクエストを跨いで残る）
        $this->app?->forgetScopedInstances();
        $this->actingAs($owner)->post('/admin/content-types/post/quick-start', ['stay' => 1])->assertRedirect();
        $this->app?->forgetScopedInstances();
        $this->actingAs($owner)
            ->post('/admin/post', ['title' => '深大寺の平屋が完成しました', 'body' => '本文', 'status' => 'published'])
            ->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
    }

    /** @return list<array<string, mixed>> */
    private function unreachable(): array
    {
        return array_values(array_filter(
            app(PublicationReadiness::class)->check(),
            fn (array $f): bool => str_contains($f['what'], '一覧へサイトのどこからも行けません'),
        ));
    }

    private function addListToHeader(): void
    {
        $menuId = Menu::query()->where('key', 'header')->value('id');
        DB::table('menu_items')->insert([
            'menu_id' => $menuId, 'parent_id' => null, 'sort_order' => 99, 'title' => null,
            'link_type' => 'content_index', 'content_type' => 'post',
            'target' => '_self', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app?->forgetScopedInstances();
    }

    /** 🔴 公開した施工事例の一覧へ、どこからも行けないことを公開前チェックが言う（店の呼び名で） */
    public function test_記事の一覧へどこからも行けないと公開前チェックが止める(): void
    {
        $this->publishFirstWork();
        // 業種選択で足した入口を利用者が外した状態でも、公開前チェックが知らせる。
        Menu::query()->where('key', 'header')->firstOrFail()->items()->where('content_type', 'post')->delete();
        $this->app?->forgetScopedInstances();

        $found = $this->unreachable();

        $this->assertCount(1, $found);
        $this->assertStringContainsString('「施工事例」', $found[0]['what']);
        $this->assertSame('/admin/menus/header', $found[0]['fix']['href']);
    }

    public function test_メニューに一覧を置けば公開前チェックは通る(): void
    {
        $this->publishFirstWork();
        $this->addListToHeader();

        $this->assertSame([], $this->unreachable());
    }

    public function test_記事を公開していなければ言わない(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/content-types/post/quick-start', ['stay' => 1]);
        $this->app?->forgetScopedInstances();

        $this->assertSame([], $this->unreachable());
    }

    /** 🔴 ナビの表示名は店の呼び名。工務店の施工事例が「投稿」と出ていた */
    public function test_メニューの一覧の表示名は店の呼び名(): void
    {
        $this->publishFirstWork();
        $this->addListToHeader();

        $titles = array_column(app(MenuTree::class)->forKey('header', '/'), 'title');
        $this->assertContains('施工事例', $titles);
        $this->assertNotContains('投稿', $titles);
    }

    public function test_メニューの画面でも店の呼び名で選べる(): void
    {
        $this->publishFirstWork();

        $this->actingAs($this->owner())->get('/admin/menus/header')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('choices.contentTypes', fn ($types) => collect($types)->contains(fn ($t) => $t['id'] === 'post' && $t['label'] === '施工事例')),
        );
    }

    /** チェックボックスの箱を 44px に伸ばさない（16×44 の縦長の棒に見えていた） */
    public function test_編集画面のチェックボックスは縦に伸ばさない(): void
    {
        $css = (string) file_get_contents(resource_path('js/pages/admin/pages/Editor.vue'));

        $this->assertStringContainsString(".page-editor :deep(button:not([role='checkbox']))", $css);
        $this->assertStringNotContainsString('.page-editor :deep(button),', $css);
    }
}
