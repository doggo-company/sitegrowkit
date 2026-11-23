<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\OperationContext;
use App\Http\Controllers\Admin\RelationOptionController;
use App\Models\User;
use App\Support\OperationCapabilities;
use App\Support\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Phase 9.5-C — relation の候補取得（ADR-0017 §6）。
 *
 * **管理画面の内部 route だけ**。公開 API にも MCP にも足さない。
 * 候補は投稿数だけ増えるので、全件を初期 HTML へ入れず検索付きで取りに来る。
 */
final class RelationOptionRouteTest extends FieldGroupTestCase
{
    private function seedWorks(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            app(CreateContent::class)->execute('works', [
                'title' => "実績 {$i}", 'slug' => "work-{$i}", 'body' => '本文',
                'category' => 'web', 'status' => 'draft',
            ], OperationContext::cli());
        }
    }

    public function test_未認証はloginへ送られる(): void
    {
        $this->get('/admin/relations/works/options')->assertRedirect('/login');
        $this->get('/admin/relations/works/options?ids=1')->assertRedirect('/login');
    }

    public function test_閲覧権限があれば候補を取得できる(): void
    {
        $this->seedWorks(2);

        $this->actingAs(User::factory()->create(['role' => Role::Viewer->value]))
            ->getJson('/admin/relations/works/options')
            ->assertOk()
            ->assertJsonStructure(['options' => [['id', 'title']], 'limit'])
            // 並び順は一覧の既定に従う。ここで見たいのは **表示名が schema から出ている**こと
            ->assertJsonCount(2, 'options')
            ->assertJsonFragment(['title' => '実績 1'])
            ->assertJsonFragment(['title' => '実績 2']);
    }

    public function test_参照先に選べない型は404(): void
    {
        $user = User::factory()->create(['role' => Role::Owner->value]);

        // 存在しない型
        $this->actingAs($user)->getJson('/admin/relations/no_such_type/options')->assertNotFound();
        // collection ではない型（設定・問い合わせ）
        $this->actingAs($user)->getJson('/admin/relations/site/options')->assertNotFound();
        $this->actingAs($user)->getJson('/admin/relations/contact/options')->assertNotFound();
    }

    public function test_件数に上限がある(): void
    {
        $this->seedWorks(RelationOptionController::MAX_PER_PAGE + 5);

        $response = $this->actingAs(User::factory()->create(['role' => Role::Owner->value]))
            ->getJson('/admin/relations/works/options?perPage=1000')
            ->assertOk();

        $this->assertCount(RelationOptionController::MAX_PER_PAGE, $response->json('options'));
        $this->assertSame(RelationOptionController::MAX_PER_PAGE, $response->json('limit'));
    }

    public function test_検索できる(): void
    {
        $this->seedWorks(3);

        $response = $this->actingAs(User::factory()->create(['role' => Role::Owner->value]))
            ->getJson('/admin/relations/works/options?q='.urlencode('実績 2'))
            ->assertOk();

        $titles = array_column($response->json('options'), 'title');

        $this->assertContains('実績 2', $titles);
        $this->assertNotContains('実績 1', $titles);
    }

    /** 検索語は binding で渡す（文字列連結で SQL を組み立てない） */
    public function test_検索語でsql_injectionできない(): void
    {
        $this->seedWorks(1);

        $payload = "' OR 1=1 --";

        $response = $this->actingAs(User::factory()->create(['role' => Role::Owner->value]))
            ->getJson('/admin/relations/works/options?q='.urlencode($payload))
            ->assertOk();

        // 一致しないので 0 件。テーブルも無事
        $this->assertSame([], $response->json('options'));
        $this->assertSame(1, DB::table('works')->count());
    }

    // ── ?ids= — 既存の選択の名前解決 ──────────────────────

    public function test_先頭20件の外にある選択も名前を引ける(): void
    {
        $this->seedWorks(RelationOptionController::MAX_PER_PAGE + 5);

        // 一番古い = 候補一覧（新しい順）には出てこない
        $oldest = (int) DB::table('works')->orderBy('id')->value('id');

        $listed = $this->actingAs(User::factory()->create(['role' => Role::Owner->value]))
            ->getJson('/admin/relations/works/options')
            ->assertOk()
            ->json('options');

        $this->assertNotContains($oldest, array_column($listed, 'id'), '前提が崩れている');

        $this->actingAs(User::factory()->create(['role' => Role::Owner->value]))
            ->getJson("/admin/relations/works/options?ids={$oldest}")
            ->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.id', $oldest)
            ->assertJsonPath('options.0.title', '実績 1');
    }

    public function test_idsも20件で頭を打つ(): void
    {
        $this->seedWorks(RelationOptionController::MAX_PER_PAGE + 5);

        $ids = DB::table('works')->orderBy('id')->pluck('id')->all();

        $response = $this->actingAs(User::factory()->create(['role' => Role::Owner->value]))
            ->getJson('/admin/relations/works/options?ids='.implode(',', $ids))
            ->assertOk();

        $this->assertCount(RelationOptionController::MAX_PER_PAGE, $response->json('options'));
    }

    public function test_idsの重複と不正値と存在しないidを安全に扱う(): void
    {
        $this->seedWorks(2);

        $ids = DB::table('works')->orderBy('id')->pluck('id')->all();
        $user = User::factory()->create(['role' => Role::Owner->value]);

        // 重複は 1 件に畳む
        $this->actingAs($user)
            ->getJson("/admin/relations/works/options?ids={$ids[0]},{$ids[0]}")
            ->assertOk()
            ->assertJsonCount(1, 'options');

        // 存在しない ID は黙って 0 件（他人の ID を推測させない）
        $this->actingAs($user)
            ->getJson('/admin/relations/works/options?ids=999999')
            ->assertOk()
            ->assertJsonCount(0, 'options');

        // 数値でない値は受け取らない。SQL も壊れない
        $this->actingAs($user)
            ->getJson('/admin/relations/works/options?ids='.urlencode("1' OR 1=1 --,abc,-5,0"))
            ->assertOk()
            ->assertJsonCount(0, 'options');

        $this->assertSame(2, DB::table('works')->count());
    }

    public function test_idsも権限を要求する(): void
    {
        $this->seedWorks(1);

        $id = (int) DB::table('works')->value('id');

        // 閲覧権限があれば引ける（未認証は上の test が固定している）
        $this->actingAs(User::factory()->create(['role' => Role::Viewer->value]))
            ->getJson("/admin/relations/works/options?ids={$id}")
            ->assertOk()
            ->assertJsonCount(1, 'options');
    }

    public function test_公開apiとmcpには出ていない(): void
    {
        $routes = array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_contains($route->uri(), 'relations/'),
        );

        foreach ($routes as $route) {
            $this->assertStringStartsWith('admin/', $route->uri(), '公開面に候補 route が出ている');
        }

        foreach (OperationCapabilities::operationIds() as $id) {
            $this->assertStringStartsNotWith('relation', $id);
        }
    }
}
