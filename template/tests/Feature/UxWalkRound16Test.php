<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Role;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UX 一周 第 16 周（陶芸作家の目・業種の入口から・2026-10-04）で見つけたものの回帰。
 */
final class UxWalkRound16Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    private function sakka(User $owner): void
    {
        $this->seed(MenuSeeder::class);
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'sakka'])->assertRedirect();
        app(SettingsStore::class)->put(['site_name' => '器の工房 つちと', 'tagline' => '土と火でつくる、毎日の器']);
        $this->app?->forgetScopedInstances();
    }

    // ── 1. 編集画面の Canvas は、公開ページと同じ帯（ヒーロー／題の帯）を描く ──

    /** 🔴 Canvas に帯が無く、公開して初めてヒーローと本文の見出しが 2 回並んだ */
    public function test_canvasは公開ページと同じ題の帯を描く(): void
    {
        $owner = $this->owner();
        $this->sakka($owner);
        $page = $this->makePage($owner, 'about', '私たちについて');
        $section = $this->addSection($owner, $page);
        $this->insert($owner, $page, $section, ['type' => 'heading', 'props' => ['text' => 'つくり手の紹介', 'level' => 1]]);
        $this->publish($owner, $page);
        $this->app?->forgetScopedInstances();

        $public = (string) $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('class="page-title doc-band"', $public);

        $canvas = (string) $this->actingAs($owner)->get("/admin/pages/{$page->id}/preview?canvas=1")->assertOk()->getContent();
        $this->assertStringContainsString('class="page-title doc-band"', $canvas);
        $this->assertStringContainsString('<p class="doc-band__title">私たちについて</p>', $canvas);
    }

    public function test_トップに選んだページのcanvasは本文から始める(): void
    {
        $owner = $this->owner();
        $this->sakka($owner);
        $page = $this->makePage($owner, 'home', 'トップページ');
        $section = $this->addSection($owner, $page);
        $this->insert($owner, $page, $section, ['type' => 'heading', 'props' => ['text' => 'ようこそ', 'level' => 1]]);
        $this->publish($owner, $page);
        app(SettingsStore::class)->put(['front_page_id' => $page->id]);
        $this->app?->forgetScopedInstances();

        $public = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('doc-band', $public);
        $this->assertStringContainsString('土と火でつくる、毎日の器', $public);

        $canvas = (string) $this->actingAs($owner)->get("/admin/pages/{$page->id}/preview?canvas=1")->assertOk()->getContent();
        $this->assertStringNotContainsString('doc-band', $canvas);
    }

    // ── 2. 本文が帯の題と同じ見出しで始まるなら、帯は出さない（トップも） ──

    /** 🔴 キャッチコピーと同じ見出しで本文を始めたら、ヒーローと見出しで同じ文言が 2 回並んだ */
    public function test_キャッチコピーと同じ見出しで始まるトップはヒーローを出さない(): void
    {
        $owner = $this->owner();
        $this->sakka($owner);
        $page = $this->makePage($owner, 'home', 'トップページ');
        $section = $this->addSection($owner, $page);
        $this->insert($owner, $page, $section, ['type' => 'heading', 'props' => ['text' => '土と火でつくる、毎日の器', 'level' => 1]]);
        $this->publish($owner, $page);
        app(SettingsStore::class)->put(['front_page_id' => $page->id]);
        $this->app?->forgetScopedInstances();

        $public = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('doc-band__title--hero', $public);
        // 本文（<body> 以降）に出るのは本文の見出しの 1 回だけ（<title> は数えない）
        $body = strip_tags(substr($public, (int) strpos($public, '<body')));
        $this->assertSame(1, substr_count($body, '土と火でつくる、毎日の器'), '見出しは本文の 1 回だけ');

        $canvas = (string) $this->actingAs($owner)->get("/admin/pages/{$page->id}/preview?canvas=1")->assertOk()->getContent();
        $this->assertStringNotContainsString('doc-band__title--hero', $canvas);
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

    private function addSection(User $owner, Page $page): string
    {
        /** @var array<string, mixed> $document */
        $document = PageVersion::query()->findOrFail($page->refresh()->draft_version_id)->document;
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $document['root']['id'],
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
}
