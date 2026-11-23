<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use App\Models\User;
use App\Support\Role;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 再利用ブロックの画面（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 見るもの
 *
 * - 一覧と編集の画面が出る（**しまったものは既定で出さない**）
 * - **中身は一覧に載せない**（部分木は 1 件ずつ引く）
 * - **押す前に、関わるページの数が出る**
 * - Canvas が読む preview が**下書きを描く**
 * - **編集画面の書き込み先は `/api/v1/components/reusable/`**（ページ側の口を使わない）
 */
final class ReusableComponentScreenTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function subtree(string $text): array
    {
        return [
            'id' => NodeId::generate(),
            'type' => 'section',
            'props' => [],
            'children' => [[
                'id' => NodeId::generate(),
                'type' => 'heading',
                'props' => ['text' => $text, 'level' => 2],
                'children' => [],
            ]],
        ];
    }

    private function reusable(string $key = 'header'): ReusableComponent
    {
        return ReusableComponent::query()->create([
            'key' => $key,
            'label' => 'ヘッダー',
            'draft_document' => $this->subtree('編集中の見出し'),
            'published_document' => $this->subtree('公開中の見出し'),
            'published_at' => now(),
        ]);
    }

    public function test_一覧に中身は載せない(): void
    {
        $component = $this->reusable();

        $this->actingAs($this->admin())
            ->get('/admin/reusable-components')
            ->assertInertia(fn (Assert $view) => $view
                ->component('admin/reusable-components/Index')
                ->has('components', 1)
                ->where('components.0.key', $component->key)
                ->where('components.0.published', true)
                // 部分木は一覧に載せない（参照の数だけ応答が膨らむ）
                ->missing('components.0.document'),
            );
    }

    public function test_しまったものは既定で出さない(): void
    {
        $component = $this->reusable();
        $component->update(['archived_at' => now()]);

        $this->actingAs($this->admin())
            ->get('/admin/reusable-components')
            ->assertInertia(fn (Assert $view) => $view->has('components', 0));

        $this->actingAs($this->admin())
            ->get('/admin/reusable-components?archived=1')
            ->assertInertia(fn (Assert $view) => $view->has('components', 1));
    }

    /** **押す前に、関わるページの数が出る** */
    public function test_編集画面に関わるページの数が出る(): void
    {
        $component = $this->reusable();

        $this->actingAs($this->admin())
            ->get("/admin/reusable-components/{$component->id}")
            ->assertInertia(fn (Assert $view) => $view
                ->component('admin/reusable-components/Editor')
                ->where('component.document', $component->draft_document)
                ->has('impact.publicPages')
                ->has('impact.draftPages'),
            );
    }

    /** Canvas が読む口。**下書きを描く**（preview は常に draft） */
    public function test_previewは下書きを描く(): void
    {
        $component = $this->reusable();

        $response = $this->actingAs($this->admin())
            ->get("/admin/reusable-components/{$component->id}/preview?canvas=1");

        $response->assertOk();
        $response->assertSee('編集中の見出し');
        $response->assertDontSee('公開中の見出し');
        // 管理画面の描画は検索に出さない
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_無い実体の画面は404(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/reusable-components/01ZZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();
    }

    /**
     * **編集画面の書き込み先は再利用側の口である。**
     *
     * ページ側の口（`/api/v1/pages/`）を使うと、「送信先は全部 `/api/v1/pages/`」を
     * 固定している `PageAdminTest` の守りを緩めることになる（§4.5 の「編集の口」）。
     */
    public function test_編集画面の書き込み先は再利用側の口だけ(): void
    {
        $client = (string) file_get_contents(base_path('resources/js/composables/useReusableDocument.ts'));

        /*
         * **送る先を固定する。数ではなく。**
         *
         * `fetch()` の呼び出しは URL を変数で受けるので、呼び出しの形ではなく
         * **ファイルに書かれた宛先そのもの**を見る（`PageAdminTest` と同じ考え）。
         */
        // **コメントは見ない。** docblock がページ側の口を「流用しない理由」として
        // 引いているので、そのまま探すと説明文に当たる（実際に一度当たった）
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $client);

        preg_match_all('#`(/api/v1/[^`]*)`#', $code, $targets);

        $this->assertNotEmpty($targets[1], '送信口が 1 つも無い');

        foreach ($targets[1] as $target) {
            $this->assertStringStartsWith('/api/v1/components/reusable/', $target, "{$target} は再利用側の口ではない");
        }

        $screen = (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents(base_path('resources/js/pages/admin/reusable-components/Editor.vue')),
        );

        $this->assertStringNotContainsString('fetch(', $screen, '画面が直接送信している');
        $this->assertStringNotContainsString('/api/v1/pages/', $screen, 'ページ側の口を使っている');
    }

    /** ページの編集画面は、名前を出すために一覧を受け取る（**中身は受け取らない**） */
    public function test_ページの編集画面は実体の名前だけ受け取る(): void
    {
        $component = $this->reusable();
        $page = $this->documentPage();

        $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->assertInertia(fn (Assert $view) => $view
                ->has('reusables', 1)
                ->where('reusables.0.label', $component->label)
                ->missing('reusables.0.document')
                ->has('reusableEditUrl'),
            );
    }

    /**
     * 再利用ブロックの編集は作りの画面（制作者だけ・UX 一周 2026-09-28）。
     * 店主がページを編集していて「再利用ブロックを編集する」を押すと 403 に落ちていた。
     * 開けない人には入口そのものを渡さない。
     */
    public function test_オーナーには再利用ブロックの編集の入口を渡さない(): void
    {
        $this->reusable();
        $page = $this->documentPage();
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        $this->actingAs($owner)
            ->get("/admin/pages/{$page->id}/editor")
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view
                ->has('reusables', 1)
                ->where('reusableEditUrl', null),
            );

        $this->actingAs($owner)->get('/admin/reusable-components')->assertForbidden();

        $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->assertInertia(fn (Assert $view) => $view
                ->where('reusableEditUrl', '/admin/reusable-components'),
            );
    }

    private function documentPage(): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '画面', 'slug' => 'screen'],
            OperationContext::admin($this->operatorId(), 'tester'),
        );

        return Page::query()->findOrFail($result->data['page']['id']);
    }
}
