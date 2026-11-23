<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\Industry\IndustryProfiles;
use App\Support\Industry\IndustrySetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開前の UX 一周 7 周目（2026-11-05・司法書士事務所の事務員・rc11）の回帰。
 *
 * - R7-2: 設定の行がまだ無いときに同じ設定を 2 回書くと、主キー重複で 500 になった
 * - R7-4: トップ用のページを公開しても、トップにする一手が画面に無かった
 * - R7-5: 「税理士・士業の事務所」を選ぶと、相談の種類が税理士の言葉だけだった
 *
 * R7-1（メニューのダブルクリック）・R7-3（予約の表示）は `tests/e2e/ux-round7.spec.ts`
 * と `resources/js/lib/__tests__/content-state.test.ts`。
 */
final class UxRelease20261105Round7Test extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** 書く文は 1 つ（無ければ作る・在れば替える）。読んでから書く updateOrInsert に戻すと落ちる */
    public function test_設定は読まずに1文で書く(): void
    {
        DB::enableQueryLog();
        app(SettingsStore::class)->put(['bundled_pages_hidden' => ['about']]);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, '"settings"'));

        $this->assertCount(1, $queries, '設定を書く前に読んでいる（同時に 2 回書くと主キー重複になる）: '.$queries->implode(' / '));
        $this->assertStringContainsString('insert', strtolower((string) $queries->first()));

        app(SettingsStore::class)->put(['bundled_pages_hidden' => ['about', 'service']]);
        $this->assertSame(['about', 'service'], app(SettingsStore::class)->get('bundled_pages_hidden'));
    }

    public function test_公開したトップ用のページは画面からトップにできる(): void
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => 'トップページ', 'slug' => 'home'], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'heading', 'props' => ['text' => 'ようこそ', 'level' => 1]]]],
        ], $context);
        $this->assertTrue(app(PublishPage::class)->execute($page->id, $context)->isSuccess());

        $this->actingAs($this->operator())->get("/admin/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('frontPageOffer', true));

        $this->actingAs($this->operator())->post("/admin/sample-pages/home/front/{$page->id}")->assertRedirect();

        $this->assertSame($page->id, app(FrontPage::class)->selectedId());
        $this->get('/')->assertOk()->assertSee('ようこそ');
        $this->actingAs($this->operator())->get("/admin/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('frontPageOffer', false));
    }

    public function test_下書きのページはトップにできない(): void
    {
        $result = app(CreatePage::class)->execute(['title' => 'トップページ', 'slug' => 'home'], OperationContext::admin($this->operatorId(), 'fixture'));

        $this->actingAs($this->operator())->post("/admin/sample-pages/home/front/{$result->data['page']['id']}")->assertNotFound();
        $this->assertNull(app(FrontPage::class)->selectedId());
    }

    public function test_士業の事務所は税理士の言葉にしない(): void
    {
        $profile = IndustryProfiles::get('shigyo');
        $this->assertNotNull($profile, '士業の事務所を選べない');
        $this->assertSame('税理士・会計事務所', IndustryProfiles::get('zeirishi')['label']);

        $words = json_encode(IndustrySetup::contactFields($profile), JSON_UNESCAPED_UNICODE);
        foreach (['記帳', '確定申告', '創業', '事業の形態'] as $taxWord) {
            $this->assertStringNotContainsString($taxWord, (string) $words);
        }
    }
}
