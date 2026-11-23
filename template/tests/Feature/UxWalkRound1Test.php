<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Document\AccessibilityCheck;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Theme\StyleProperties;
use AgentCms\Core\Theme\ThemeTokens;
use App\Models\User;
use App\Support\LinkTargets;
use App\Support\PublicationReadiness;
use App\Support\SamplePages;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * UX 一周 第 1 周（トリミングサロンの店主の目・2026-09-28）で止まった・迷った所の回帰。
 */
final class UxWalkRound1Test extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function publishedPage(string $slug, string $title, string $heading): Page
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => $title, 'slug' => $slug], $context);
        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);

        $insert = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [
                ['type' => 'heading', 'props' => ['text' => $heading, 'level' => 1]],
            ]],
        ], $context);
        $this->assertSame(OperationStatus::Success, $insert->status, (string) json_encode($insert->errors));

        app(PublishPage::class)->execute($page->id, $context);

        return $page->refresh();
    }

    // ── 1. 見本の /about に同じ URL のページを作って公開すると、そちらが出る ──

    public function test_同じ_url_のページを公開すると見本の代わりにそれが出る(): void
    {
        $this->seed(TemplateContentSeeder::class);
        $this->get('/about')->assertOk()->assertSee('About');

        $this->publishedPage('about', '私たちについて', 'ポポについて');

        $this->get('/about')->assertOk()->assertSee('ポポについて')->assertDontSee('同梱のサンプルサイト');
    }

    public function test_差し替えた見本は見本の一覧と公開前チェックから消える(): void
    {
        $this->seed(TemplateContentSeeder::class);
        $this->publishedPage('about', '私たちについて', 'ポポについて');

        $keys = array_column(app(SamplePages::class)->forAdmin(), 'key');
        $this->assertNotContains('about', $keys);

        $aboutFindings = array_filter(
            app(PublicationReadiness::class)->check(),
            // 見本の電話番号（075-000-0000）など、サイト設定の仮の値はここでは見ない
            fn (array $f): bool => $f['where'] === '/about' && str_starts_with($f['what'], '同梱の見本のまま'),
        );
        $this->assertSame([], array_values($aboutFindings));
    }

    public function test_下書きのままなら見本が出続ける(): void
    {
        $this->seed(TemplateContentSeeder::class);
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        app(CreatePage::class)->execute(['title' => '私たちについて', 'slug' => 'about'], $context);

        $this->get('/about')->assertOk()->assertSee('About');
    }

    // ── 4. 選択肢の表示名 ──

    public function test_見た目の選択肢に表示名がある(): void
    {
        $specs = StyleProperties::specs(app(ThemeTokens::class));

        $this->assertSame('左寄せ', $specs['textAlign']['valueLabels']['left'] ?? null);
        $this->assertSame('隠す', $specs['visibility']['valueLabels']['hidden'] ?? null);
        $this->assertSame('狭い', $specs['spaceTop']['valueLabels']['gapSmall'] ?? null);
    }

    // ── 5. ボタンのリンク先の候補 ──

    public function test_リンク先の候補は公開中のページだけ(): void
    {
        $this->publishedPage('price', '料金', '料金のご案内');
        app(CreatePage::class)->execute(['title' => '下書き', 'slug' => 'draft-only'], OperationContext::admin($this->operatorId(), 'fixture'));

        $hrefs = array_column(app(LinkTargets::class)->all(), 'href');

        $this->assertContains('/price', $hrefs);
        $this->assertNotContains('/draft-only', $hrefs);
    }

    // ── 12. 置いた直後は「要修正」ではなく「未設定」 ──

    public function test_画像を選ぶ前は代替テキストを求めず未設定と言う(): void
    {
        $findings = app(AccessibilityCheck::class)->inspect(['root' => ['id' => 'r', 'type' => 'page', 'children' => [
            ['id' => 's', 'type' => 'section', 'children' => [
                ['id' => 'i', 'type' => 'image', 'props' => []],
            ]],
        ]]]);

        $this->assertSame(['image-missing'], array_column($findings, 'rule'));
        $this->assertSame([AccessibilityCheck::TODO], array_column($findings, 'level'));
    }

    public function test_リンク先が未設定のボタンは未設定と言う(): void
    {
        $findings = app(AccessibilityCheck::class)->inspect(['root' => ['id' => 'r', 'type' => 'page', 'children' => [
            ['id' => 's', 'type' => 'section', 'children' => [
                ['id' => 'b', 'type' => 'button', 'props' => ['label' => 'ご予約', 'href' => '#']],
            ]],
        ]]]);

        $this->assertSame(['control-destination'], array_column($findings, 'rule'));
        $this->assertSame([AccessibilityCheck::TODO], array_column($findings, 'level'));
    }

    // ── 10. 画像を選ぶ画面からそのままアップロードできる ──

    public function test_画像を選ぶ画面からのアップロードは上げたものを返す(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/admin/media', [
                'file' => UploadedFile::fake()->image('dog.png', 400, 300),
                'alt' => '店内のトリミング台',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.media.alt', '店内のトリミング台');
        $this->assertIsInt($response->json('data.media.id'));
    }

    public function test_画像でないものを上げると理由を返す(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/media', ['file' => UploadedFile::fake()->createWithContent('broken.png', 'not an image')])
            ->assertStatus(422);
    }

    // ── 2. テキストの改行 ──

    public function test_テキストの改行は公開面でも改行のまま(): void
    {
        $css = (string) file_get_contents(resource_path('css/document.css'));
        $this->assertMatchesRegularExpression('/\.doc-text\s*\{[^}]*white-space:\s*pre-line/', $css);

        $html = view('components.document.text', [
            'nodeId' => 'n', 'classes' => '', 'style' => '', 'props' => ['text' => "一行目\n二行目"],
        ])->render();

        // 本文の前後に空白を入れると、それも改行として出てしまう
        $this->assertStringContainsString(">一行目\n二行目</p>", $html);
    }
}
