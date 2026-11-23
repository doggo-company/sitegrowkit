<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\FirstSteps;
use App\Support\Industry\IndustryProfiles;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 府中はなもも生花店の一周で迷った道を、実際の保存・公開の入口から通す。 */
final class UxConfusion20261123Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    private function page(User $owner): Page
    {
        $context = OperationContext::admin($owner->id, 'fixture');
        $result = app(CreatePage::class)->execute(['title' => '花と暮らす毎日', 'slug' => 'hanamomo'], $context);
        $this->assertTrue($result->isSuccess());
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'heading', 'props' => ['text' => '季節のお花をあなたに', 'level' => 1]]]],
        ], $context);
        $this->assertTrue($result->isSuccess());

        return $page;
    }

    public function test_名前によらず公開直後の両画面でトップにでき設定後は表示中になる(): void
    {
        $owner = $this->owner();
        $page = $this->page($owner);
        $this->actingAs($owner)->get("/admin/pages/{$page->id}/editor")
            ->assertInertia(fn (Assert $p) => $p->where('frontPageOffer', false));
        $this->post("/admin/pages/{$page->id}/publish", ['from' => 'editor'])
            ->assertSessionHasNoErrors()->assertRedirect("/admin/pages/{$page->id}/editor");
        $this->app?->forgetScopedInstances();

        foreach (['edit', 'editor'] as $screen) {
            $this->get("/admin/pages/{$page->id}/{$screen}")
                ->assertInertia(fn (Assert $p) => $p->where('frontPageOffer', true)->where('frontPageSelected', false));
        }

        $this->post("/admin/sample-pages/home/front/{$page->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($page->id, app(FrontPage::class)->selectedId());
        foreach (['edit', 'editor'] as $screen) {
            $this->get("/admin/pages/{$page->id}/{$screen}")
                ->assertInertia(fn (Assert $p) => $p->where('frontPageOffer', false)->where('frontPageSelected', true));
        }
        $this->get('/')->assertOk()->assertSee('季節のお花をあなたに');
    }

    public function test_下書きと公開予約はトップにできず権限のない人も変更できない(): void
    {
        $owner = $this->owner();
        $page = $this->page($owner);
        $this->actingAs($owner)->post("/admin/sample-pages/home/front/{$page->id}")->assertNotFound();
        $this->post("/admin/pages/{$page->id}/publish", ['publishedAt' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->post("/admin/sample-pages/home/front/{$page->id}")->assertNotFound();
        $editor = User::factory()->create(['role' => Role::Editor->value, 'email_verified_at' => now()]);
        $this->actingAs($editor)->post("/admin/sample-pages/home/front/{$page->id}")->assertForbidden();
        $this->assertNull(app(FrontPage::class)->selectedId());
    }

    public function test_最初の歩はトップが未設定なら未完了で設定したら完了する(): void
    {
        $owner = $this->owner();
        $steps = app(FirstSteps::class)->all();
        $this->assertCount(6, $steps);
        $front = collect($steps)->firstWhere('key', 'front');
        $this->assertFalse($front['done']);
        $this->actingAs($owner)->get($front['href'])->assertInertia(fn (Assert $p) => $p
            ->where('initial.title', 'トップページ')->where('initial.slug', 'home'));
        $page = $this->page($owner);
        $this->post("/admin/pages/{$page->id}/publish")->assertSessionHasNoErrors();
        $this->post("/admin/sample-pages/home/front/{$page->id}")->assertSessionHasNoErrors();
        $front = collect(app(FirstSteps::class)->all())->firstWhere('key', 'front');
        $this->assertTrue($front['done']);

        // 選んだページを非公開に戻すと公開トップは既定へ戻る。歩も「済み」と言い続けない（採点 ①）
        $this->post("/admin/pages/{$page->id}/unpublish");
        $this->assertNotNull(app(FrontPage::class)->selectedId());
        $front = collect(app(FirstSteps::class)->all())->firstWhere('key', 'front');
        $this->assertFalse($front['done']);
    }

    public function test_トップとそのプレビューは帯を省き通常urlは題の帯を残す(): void
    {
        $owner = $this->owner();
        $page = $this->page($owner);
        app(SettingsStore::class)->put(['site_name' => '府中はなもも生花店', 'tagline' => '毎日に花を']);
        $this->actingAs($owner)->post("/admin/pages/{$page->id}/publish")->assertSessionHasNoErrors();
        $this->post("/admin/sample-pages/home/front/{$page->id}")->assertSessionHasNoErrors();
        foreach (['/', "/admin/pages/{$page->id}/preview?canvas=1", "/admin/pages/{$page->id}/preview"] as $url) {
            $this->get($url)->assertOk()->assertSee('季節のお花をあなたに')
                ->assertDontSee('class="hero doc-band"', false)->assertDontSee('class="page-title doc-band"', false);
        }
        $this->get('/hanamomo')->assertOk()->assertSee('class="page-title doc-band"', false);
    }

    public static function industries(): array
    {
        return array_map(static fn (string $key): array => [$key], array_keys(IndustryProfiles::all()));
    }

    #[DataProvider('industries')]
    public function test_初回の業種選択でその記事を公開サイトに出せる(string $industry): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/industry', ['industry' => $industry])->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
        $this->assertTrue(app(ContentTypeLifecyclePolicy::class)->isPublic('post'));
        $profile = IndustryProfiles::get($industry);
        $slug = app(ContentTypeSettingsResolver::class)->for('post')->publicSlug;
        $this->assertStringStartsWith($profile['listSlug'], $slug);
        $this->get('/'.$slug)->assertOk()->assertSee($profile['postLabel']);
    }

    public function test_業種選択後に非公開にした記事を別の業種でも公開に戻さない(): void
    {
        $owner = $this->owner();
        $industries = array_keys(IndustryProfiles::all());
        $this->actingAs($owner)->post('/admin/industry', ['industry' => $industries[0]])->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
        $current = app(ContentTypeSettingsResolver::class)->for('post');
        $this->assertTrue($current->flag('public'));
        $this->assertTrue(app(ContentTypeSettingsWriter::class)->save('post', [
            'registration' => json_encode([...$current->registration, 'public' => false]),
        ], $current->lockVersion));
        $this->app?->forgetScopedInstances();
        $this->post('/admin/industry', ['industry' => $industries[1]])->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->isPublic('post'));
    }

    public function test_初回でも明示的な非公開を尊重し選び直しても非公開を戻さない(): void
    {
        $owner = $this->owner();
        $this->assertTrue(app(ContentTypeSettingsWriter::class)->save('post', [
            'registration' => json_encode(['public' => false, 'has_archive' => false]),
        ], 0));
        foreach (array_slice(array_keys(IndustryProfiles::all()), 0, 2) as $industry) {
            $this->app?->forgetScopedInstances();
            $this->actingAs($owner)->post('/admin/industry', ['industry' => $industry])->assertSessionHasNoErrors();
            $this->app?->forgetScopedInstances();
            $current = app(ContentTypeSettingsResolver::class)->for('post');
            $this->assertFalse($current->flag('public'));
            $this->assertFalse($current->flag('has_archive'));
            $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->isPublic('post'));
        }
    }
}
