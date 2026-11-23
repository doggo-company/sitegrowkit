<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuTree;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\LinkTargets;
use App\Support\PublicationReadiness;
use App\Support\Role;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * UX 一周 第 15 周（カフェの店主の目・業種の入口から・2026-10-04）で見つけたものの回帰。
 *
 * 見つけたのは 7 つ。どれも「止まる」ではなく「迷う・怖い」の類で、
 * 一番大きいのは **公開中のページを直しても、まだ公開に出ていないことをどこも言わない** こと。
 */
final class UxWalkRound15Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    private function choose(User $owner, string $industry): void
    {
        $this->actingAs($owner)->post('/admin/industry', ['industry' => $industry])->assertRedirect();
        // 本番では 1 リクエストごとに作り直される（試験の container はリクエストを跨いで残る）
        $this->app?->forgetScopedInstances();
    }

    /** @return list<string|null> */
    private function headerTitles(): array
    {
        return array_column(app(MenuTree::class)->forKey('header', '/'), 'title');
    }

    // ── 1. ナビ・見本の一覧・リンク先の候補は、店の呼び名（カフェなら「メニュー」） ──

    /** 🔴 /service の題は「メニュー」なのに、ナビ・フッター・ページの一覧・メニューの画面は「サービス」のままだった */
    public function test_カフェを選ぶとナビと見本の呼び名はメニューになる(): void
    {
        $this->seed(MenuSeeder::class);
        $owner = $this->owner();
        $this->choose($owner, 'cafe');

        $this->assertContains('メニュー', $this->headerTitles());
        $this->assertNotContains('サービス', $this->headerTitles());
        $this->assertContains('メニュー', array_column(app(MenuTree::class)->forKey('footer', '/'), 'title'));

        $this->actingAs($owner)->get('/admin/pages')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('samples', fn ($samples) => collect($samples)->contains(
                fn ($s) => $s['path'] === '/service' && $s['title'] === 'メニュー',
            )),
        );

        $this->actingAs($owner)->get('/admin/menus/header')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('choices.routes', fn ($routes) => collect($routes)->contains(
                fn ($r) => $r['name'] === 'service' && $r['label'] === 'メニュー',
            )),
        );

        $targets = app(LinkTargets::class)->all();
        $this->assertContains(['href' => '/service', 'label' => 'メニュー'], $targets);
    }

    public function test_自分で付けたメニューの名前は業種で上書きしない(): void
    {
        $this->seed(MenuSeeder::class);
        $menuId = Menu::query()->where('key', 'header')->value('id');
        DB::table('menu_items')->where('menu_id', $menuId)->where('link_route', 'service')->update(['title' => '料理とお酒']);

        $this->choose($this->owner(), 'cafe');

        $this->assertContains('料理とお酒', $this->headerTitles());
        $this->assertNotContains('メニュー', $this->headerTitles());
    }

    public function test_業種を選び直すと業種で入れた呼び名は替わる(): void
    {
        $this->seed(MenuSeeder::class);
        $owner = $this->owner();
        $this->choose($owner, 'cafe');
        $this->assertContains('メニュー', $this->headerTitles());

        $this->choose($owner, 'sakka');
        $this->assertContains('作品', $this->headerTitles());
        $this->assertNotContains('メニュー', $this->headerTitles());
    }

    // ── 2. 記事の管理画面も店の呼び名 ──

    /** 🔴 サイドバーは「お知らせ」なのに、一覧・新規作成・保存の知らせ・公開前チェックは「投稿」だった */
    public function test_記事の管理画面は店の呼び名で言う(): void
    {
        $this->seed(MenuSeeder::class);
        $owner = $this->owner();
        $this->choose($owner, 'cafe');

        $this->actingAs($owner)->get('/admin/post')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('schema.label', 'お知らせ'),
        );
        $this->actingAs($owner)->get('/admin/post/create')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('schema.label', 'お知らせ'),
        );

        $this->actingAs($owner)
            ->post('/admin/post', ['title' => '秋の新作ケーキが始まりました', 'body' => '本文', 'status' => 'published'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'お知らせを作成しました');
        $this->app?->forgetScopedInstances();

        // 業種の初回選択では公開で始まる。非公開の警告を試すため、利用者が明示的に非公開にする。
        $current = app(ContentTypeSettingsResolver::class)->for('post');
        $this->assertTrue(app(ContentTypeSettingsWriter::class)->save('post', [
            'registration' => json_encode([...$current->registration, 'public' => false]),
        ], $current->lockVersion));
        $this->app?->forgetScopedInstances();

        // 公開サイトに出さない設定のまま「公開」にした記事の指摘も、店の呼び名で
        $what = array_column(app(PublicationReadiness::class)->check(), 'what');
        $this->assertNotEmpty(array_filter($what, fn (string $w): bool => str_contains($w, '「お知らせ」を公開サイトに出さない設定')));
        $this->assertEmpty(array_filter($what, fn (string $w): bool => str_contains($w, '「投稿」')));
    }

    // ── 3. サイト設定の「コメントを管理メニューに出す」は、出ている状態と同じ向きで描く ──

    /** 🔴 未設定だと欄は未チェックなのに、サイドバーには「コメント」が出ていた */
    public function test_サイト設定のコメントの欄は出す側が既定(): void
    {
        $this->actingAs($this->owner())->get('/admin/site')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('schema.fields', fn ($fields) => collect($fields)->contains(
                fn ($f) => $f['key'] === 'show_comments_menu' && ($f['default'] ?? null) === true,
            )),
        );

        // 画面は、値が無ければ schema の既定で描く
        $vue = (string) file_get_contents(resource_path('js/pages/admin/site/Edit.vue'));
        $this->assertStringContainsString('f.default', $vue);
    }

    // ── 4. 見本トップの案内文 ──

    /** 🔴 キャッチコピーを入れた後も「ここはトップの見出しです。…キャッチコピーと説明を入れると」と出ていた */
    public function test_見本トップはキャッチコピーを入れたら見出しの案内をやめる(): void
    {
        $this->seed(MenuSeeder::class);
        $this->choose($this->owner(), 'cafe');

        $before = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('ここはトップの見出しです', $before);

        app(SettingsStore::class)->put(['tagline' => '自家焙煎コーヒーと手作りケーキの店']);
        $this->app?->forgetScopedInstances();

        $after = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('ここはトップの見出しです', $after);
        $this->assertStringContainsString('「サイト説明」を入れると、ここに出ます', $after);
    }

    // ── 5. ページの「ページの構成」 ──

    /** 🔴 置いたテキストと営業時間が「ページの構成」に出ず、消えたように見えた */
    public function test_ページの構成は見出し以外のブロックの数も言う(): void
    {
        $owner = $this->owner();
        $page = $this->makePage($owner, 'about', '私たちについて');
        $section = $this->addSection($owner, $page);
        $this->insert($owner, $page, $section, ['type' => 'heading', 'props' => ['text' => 'こもれびカフェについて', 'level' => 1]]);
        $this->insert($owner, $page, $section, ['type' => 'text', 'props' => ['text' => '金沢のカフェです。']]);
        $this->insert($owner, $page, $section, ['type' => 'text', 'props' => ['text' => '毎朝ケーキを焼いています。']]);

        $this->actingAs($owner)->get("/admin/pages/{$page->id}/edit")->assertOk()->assertInertia(
            fn (Assert $p) => $p
                ->where('summary.others', [['type' => 'text', 'label' => 'テキスト', 'count' => 2]])
                ->where('summary.outlineNote', '見出しの骨組みだけを出しています。本文・画像・表などは「ブロックを編集」で確かめられます。'),
        );
    }

    // ── 6. 依頼文 ──

    /** 🔴 題が何でも「見出し「私たちについて」」で、公開中でも「下書きのまま保存して」だった */
    public function test_依頼文は題を言い直さず公開中なら公開を頼まない(): void
    {
        $owner = $this->owner();
        $page = $this->makePage($owner, 'menu', 'メニュー');
        $section = $this->addSection($owner, $page);
        $this->insert($owner, $page, $section, ['type' => 'heading', 'props' => ['text' => 'メニュー', 'level' => 1]]);

        $draft = $this->promptOf($owner, $page);
        $this->assertStringNotContainsString('私たちについて', $draft);
        $this->assertStringEndsWith("\nこのページに、上の title と同じ文言の見出しと本文を追加して、下書きのまま保存して", $draft);

        $this->publish($owner, $page);

        $live = $this->promptOf($owner, $page);
        $this->assertStringEndsWith(
            "\nこのページに、上の title と同じ文言の見出しと本文を追加して、保存して。公開はしないで（直した内容を公開に出すかは、管理画面で決めます）",
            $live,
        );
    }

    // ── 7. 公開中のページを直したら、まだ公開に出ていないと言う ──

    /** 🔴 直しても公開ページは旧のまま。編集画面もページ設定も「まだ出ていない」と言わなかった */
    public function test_公開中のページを直すと版が公開版より進む(): void
    {
        $owner = $this->owner();
        $page = $this->makePage($owner, 'about', '私たちについて');
        $section = $this->addSection($owner, $page);
        $this->insert($owner, $page, $section, ['type' => 'heading', 'props' => ['text' => 'こもれびカフェについて', 'level' => 1]]);
        $this->publish($owner, $page);

        $this->actingAs($owner)->get("/admin/pages/{$page->id}/edit")->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('page.publishState', 'published')
                ->where('page.draftVersionId', $page->refresh()->published_version_id),
        );

        $this->insert($owner, $page, $section, ['type' => 'text', 'props' => ['text' => '直した本文']]);

        $this->actingAs($owner)->get("/admin/pages/{$page->id}/edit")->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('page.publishState', 'published')
                ->where('page.publishedVersionId', $page->refresh()->published_version_id)
                ->where('page.draftVersionId', fn ($id) => $id !== $page->published_version_id),
        );

        // 公開ページは旧のまま（直した本文は出ていない）— これが「言わないと怖い」理由
        $this->assertStringNotContainsString('直した本文', (string) $this->get('/about')->assertOk()->getContent());

        $edit = (string) file_get_contents(resource_path('js/pages/admin/pages/Edit.vue'));
        $this->assertStringContainsString('まだ公開ページには出ていません', $edit);
        $this->assertStringContainsString('直した内容を公開する', $edit);

        $editor = (string) file_get_contents(resource_path('js/pages/admin/pages/Editor.vue'));
        $this->assertStringContainsString('保存済み（公開ページにはまだ出ていません）', $editor);
    }

    // ── 道具 ──

    private function context(User $user): OperationContext
    {
        return OperationContext::admin($user->id, $user->email);
    }

    private function makePage(User $owner, string $slug, string $title): Page
    {
        $result = app(CreatePage::class)->execute(['title' => $title, 'slug' => $slug], $this->context($owner));
        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** @return array<string, mixed> */
    private function draftDocument(Page $page): array
    {
        /** @var array<string, mixed> $document */
        $document = PageVersion::query()->findOrFail($page->refresh()->draft_version_id)->document;

        return $document;
    }

    private function addSection(User $owner, Page $page): string
    {
        $root = (string) $this->draftDocument($page)['root']['id'];
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $root,
            'component' => ['type' => 'section', 'children' => []],
        ], $this->context($owner));
        $this->assertTrue($result->isSuccess(), json_encode($result->errors));

        $children = $result->data['document']['root']['children'];

        return (string) end($children)['id'];
    }

    /** @param  array<string, mixed>  $component */
    private function insert(User $owner, Page $page, string $parent, array $component): void
    {
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $parent,
            'component' => $component,
        ], $this->context($owner));
        $this->assertTrue($result->isSuccess(), json_encode($result->errors));
    }

    private function publish(User $owner, Page $page): void
    {
        $result = app(PublishPage::class)->execute($page->id, $this->context($owner));
        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function promptOf(User $owner, Page $page): string
    {
        $response = $this->actingAs($owner)->get("/admin/pages/{$page->id}/edit")->assertOk();
        $props = $response->viewData('page')['props'] ?? [];

        return (string) ($props['summary']['aiPrompt'] ?? '');
    }
}
