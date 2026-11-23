<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\Reading\TitleReading;
use App\Models\User;
use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * 公開前の UX 一周 19 周目（2026-11-06・rc24 の採点 round19-grade.md）の回帰。
 *
 * - 読みはサーバー（PHP・IPADIC の Viterbi）で出す。setup（Node・kuromoji）と同じ例で同じ候補になる
 * - U3: 使われている URL は、候補に出す前に -2 を付けて見せる。作った URL が見せた候補と違えば正直に言う
 * - U1: 公開中のページの URL が仮の形（page・page-日付）のままなら、公開前チェックが拾う
 */
final class UxRelease20261106Round19Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_今夜の例をサーバーで読む_setupと同じ表(): void
    {
        /** @var list<array{0: string, 1: string}> $cases */
        $cases = json_decode((string) File::get(base_path('tests/fixtures/reading-examples.json')), true);

        $this->assertGreaterThanOrEqual(30, count($cases));

        foreach ($cases as [$title, $expected]) {
            $this->assertSame($expected, TitleReading::slug($title), $title);
        }
    }

    public function test_候補は使われているurlに番号を付けてから見せる(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/pages/slug-preview?title='.rawurlencode('料金表'))
            ->assertOk()->assertJsonPath('data.slug', 'price');

        $this->actingAs($admin)->post('/admin/pages', ['title' => '料金表'])->assertRedirect();

        $this->actingAs($admin)->getJson('/admin/pages/slug-preview?title='.rawurlencode('料金表'))
            ->assertOk()->assertJsonPath('data.slug', 'price-2');
        $this->actingAs($admin)->getJson('/admin/pages/slug-preview?title='.rawurlencode('焼き上がり時間'))
            ->assertOk()->assertJsonPath('data.slug', 'yaki-agari-jikan');
    }

    public function test_urlを空のまま作ると読みで付く_mcpも同じ(): void
    {
        $this->actingAs($this->admin())->post('/admin/pages', ['title' => '大宮佐藤歯科医院'])->assertRedirect();

        $this->assertSame('oomiya-satou-shika-iin', Page::query()->where('title', '大宮佐藤歯科医院')->value('slug'));

        // MCP・API は CreatePage を直接通る（同じ読み）
        $this->assertSame('shinryou-jikan', app(CreatePage::class)->previewSlug('診療時間'));
    }

    public function test_作ったurlが見せた候補と違えば正直に言う(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/pages', ['title' => '料金表'])->assertRedirect();

        // 画面は /price と見せていたが、その間に /price が使われた
        $this->actingAs($admin)->post('/admin/pages', ['title' => '料金表', 'slug_preview' => 'price'])->assertRedirect();

        $this->assertStringContainsString(
            'URL は /price-2 です（画面に出ていた /price は、ほかのページが使っていました）',
            (string) json_encode(session()->get(SessionKey::FLASH_DATA, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_公開中のページのurlが仮の形なら公開前チェックが拾う(): void
    {
        $this->actingAs($this->admin())->post('/admin/pages', ['title' => '診療時間', 'slug' => 'page-20261106'])->assertRedirect();
        $page = Page::query()->where('slug', 'page-20261106')->firstOrFail();
        // 公開のしかたは別の試験が見る。ここは「公開中で URL が仮の形」の状態だけ作る
        Page::query()->whereKey($page->getKey())->update(['status' => PageStatus::Published->value]);

        $findings = collect(app(PublicationReadiness::class)->check())
            ->filter(static fn (array $f): bool => str_contains($f['what'], '仮の形'))
            ->values();

        $this->assertCount(1, $findings);
        $this->assertSame('/page-20261106', $findings[0]['where']);
        $this->assertSame("/admin/pages/{$page->getKey()}/edit#page-slug", $findings[0]['fix']['href']);
    }
}
