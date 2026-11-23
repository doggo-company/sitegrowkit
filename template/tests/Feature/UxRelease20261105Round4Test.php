<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use App\Support\LinkTargets;
use App\Support\SamplePages;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開前の UX 一周 4 周目（2026-11-05・ヘアサロンの店長・スマホ中心・rc8）で迷ったところの回帰。
 *
 * - R4-5: 見本と同じ名前のページ（メニューと料金）を作ると別の URL になり、見本が公開のまま並んだ
 * - R4-6: スマホでも「右の『画像を選択』」と言っていた
 *
 * R4-1〜R4-4 は画面の動きなので `tests/e2e/ux-round4.spec.ts` と
 * `resources/js/lib/__tests__/sample-text.test.ts` が見る。
 */
final class UxRelease20261105Round4Test extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);
    }

    public function test_見本と同じ名前で作ると見本のurlで作り公開すると見本と入れ替わる(): void
    {
        $title = app(SamplePages::class)->replaceable()['service'] ?? null;
        $this->assertIsString($title, '前提: /service の見本が置き換えられる状態にない');

        // 作成の画面は、同じ名前を打ったら言えるように見本の名前を受け取る
        $this->actingAs($this->operator())->get('/admin/pages/create')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('samples', fn ($samples) => collect($samples)->contains(fn ($s) => $s['path'] === '/service' && $s['title'] === $title)));

        $this->actingAs($this->operator())->post('/admin/pages', ['title' => $title])->assertRedirect();

        $page = Page::query()->where('title', $title)->sole();
        $this->assertSame('service', $page->slug, '見本と別の URL で作った（見本が公開のまま並ぶ）');
        $flash = (array) session()->get(SessionKey::FLASH_DATA, []);
        $this->assertStringContainsString('見本の代わり', (string) ($flash['toast']['message'] ?? ''));

        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'heading', 'props' => ['text' => 'カットとカラーの料金', 'level' => 1]]]],
        ], $context);
        $this->assertTrue(app(PublishPage::class)->execute($page->id, $context)->isSuccess());

        $this->get('/service')->assertOk()->assertSee('カットとカラーの料金');
        // 一覧の見本の行は消え、リンク先の候補に同じ名前は 1 つだけ
        $this->assertArrayNotHasKey('service', app(SamplePages::class)->replaceable());
        $this->assertSame(1, collect(app(LinkTargets::class)->all())->where('label', $title)->count());
    }

    public function test_urlを自分で書いたときと見本と違う名前は今までどおり(): void
    {
        $title = app(SamplePages::class)->replaceable()['service'];

        $this->actingAs($this->operator())->post('/admin/pages', ['title' => $title, 'slug' => 'price-list'])->assertRedirect();
        $this->actingAs($this->operator())->post('/admin/pages', ['title' => 'ご予約について'])->assertRedirect();

        $this->assertSame(['price-list', 'reservation'], Page::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_画像の案内は置き場所を言わない(): void
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $this->actingAs($this->operator())->post('/admin/pages', ['title' => '画像の確認', 'slug' => 'image-check']);
        $page = Page::query()->where('slug', 'image-check')->sole();
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'image', 'props' => []]]],
        ], $context);
        $this->assertTrue($result->isSuccess());

        $canvas = (string) $this->actingAs($this->operator())->get("/admin/pages/{$page->id}/preview?canvas=1")->getContent();
        $this->assertStringContainsString('まだ画像を選んでいません', $canvas);
        $this->assertStringNotContainsString('右の「画像を選択」', $canvas, 'スマホでは右に無い');
    }
}
