<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Trash\TrashColumns;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 面ごとの契約（Phase 9.8 / STAGE 21C）。
 *
 * 管理画面・API・MCP は**同じ Application Service** を通る。ここで見るのは
 * 「入口が在るか」と「入口が余計なことをしていないか」である。
 * 画面（Vue）は STAGE 21E なので、まだ 1 つも作らない。
 */
final class TrashSurfaceContractTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_apiからゴミ箱へ入れて戻せる(): void
    {
        $id = $this->entry('api');

        /*
         * 確認は既存と同じヘッダー（`X-Confirmation`）で受ける。面ごとに別の確認を作らない。
         * 確認待ちを **409** で返すのも既存の API 契約のままである（`ApiResponse`）。
         */
        $first = $this->actingAs($this->owner)->postJson("/api/v1/content/post/{$id}/trash");
        $first->assertStatus(409);

        $token = (string) $first->json('confirmation.token');
        $this->assertNotSame('', $token);

        $trashed = $this->actingAs($this->owner)
            ->withHeader('X-Confirmation', $token)
            ->postJson("/api/v1/content/post/{$id}/trash", ['lock_version' => 0]);

        $trashed->assertOk();
        $this->assertSame('trashed', $trashed->json('data.state'));
        $this->assertSame(1, $trashed->json('data.lock_version'));

        // 戻すのに確認は要らない
        $restored = $this->actingAs($this->owner)
            ->postJson("/api/v1/content/post/{$id}/restore", ['lock_version' => 1]);

        $restored->assertOk();
        $this->assertSame('active', $restored->json('data.state'));
        $this->assertSame(2, $restored->json('data.lock_version'));
    }

    public function test_apiは古い版を409で断る(): void
    {
        $id = $this->entry('api-stale');
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 4]);

        $response = $this->actingAs($this->owner)
            ->patchJson("/api/v1/content/post/{$id}", ['data' => ['title' => 'x'], 'lock_version' => 1]);

        $response->assertStatus(409);
        $this->assertSame('locked', DB::table('post')->where('id', $id)->value('title'));
    }

    public function test_apiは見つからないものを404で断る(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/content/post/999999/trash')
            ->assertNotFound();
    }

    public function test_apiは他の型のidを覗かせない(): void
    {
        $id = $this->entry('idor');

        // `news` の ID として post の ID を当てる
        $this->actingAs($this->owner)
            ->postJson("/api/v1/content/news/{$id}/trash")
            ->assertNotFound();
    }

    public function test_apiに完全削除の入口は無い(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('purge', $uri, "完全削除の route がある: {$uri}");
            $this->assertStringNotContainsString('force-delete', $uri);
        }
    }

    public function test_管理画面の裏側にゴミ箱の入口がある(): void
    {
        $id = $this->entry('admin');

        foreach (['post.trash', 'post.restore'] as $name) {
            $this->assertTrue(Route::has("admin.{$name}"), "admin.{$name} が無い");
        }

        $first = $this->actingAs($this->owner)->post("/admin/post/{$id}/trash");
        $first->assertRedirect();

        // 1 回目は確認を返して止まる（画面は既存の flash 契約をそのまま使う）
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));

        $token = (string) app(TrashContent::class)
            ->execute('post', $id, $this->context())->confirmation?->token;

        $this->actingAs($this->owner)
            ->post("/admin/post/{$id}/trash", ['confirmation_token' => $token])
            ->assertRedirect();

        $this->assertNotNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));

        // 戻すのに確認は要らない
        $this->actingAs($this->owner)->post("/admin/post/{$id}/restore")->assertRedirect();
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_画面に完全削除の導線は無い(): void
    {
        /*
         * ゴミ箱の一覧・履歴・復元の画面は **STAGE 21E で作った**。
         * ここで見張り続けるのは、**完全削除だけは無い**ことである
         * （backup ができるまで作らない — OG8）。
         */
        $pages = array_merge(
            glob(resource_path('js/pages/admin/resource/*.vue')) ?: [],
            glob(resource_path('js/components/Revision*.vue')) ?: [],
        );

        $this->assertNotSame([], $pages);

        foreach ($pages as $page) {
            $source = (string) file_get_contents($page);

            foreach (['purge', 'forceDelete', 'force-delete', '完全に削除'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($page));
            }
        }
    }

    public function test_mcpにはゴミ箱への移動だけを出す(): void
    {
        $this->assertTrue(OperationCatalog::allows('content.trash'));
        $this->assertTrue(OperationCatalog::allows('content.delete'));

        // 戻す操作は出さない（OG3 = (a)）
        $this->assertFalse(OperationCatalog::allows('content.restore'));
        $this->assertFalse(OperationCatalog::allows('content.purge'));
    }

    public function test_mcpのdeleteはゴミ箱へ移す(): void
    {
        $id = $this->entry('mcp');
        $agent = OperationContext::agent(
            OperationInterface::Mcp, 'test-agent',
        );

        $token = (string) app(OperationCatalog::class)
            ->invoke('content.delete', ['type' => 'post', 'id' => $id], $agent)
            ->confirmation?->token;

        $result = app(OperationCatalog::class)->invoke(
            'content.delete',
            ['type' => 'post', 'id' => $id],
            $agent->withConfirmationToken($token),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame('trashed', $result->data['state']);
        $this->assertDatabaseHas('post', ['id' => $id]);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug === 'api-stale' ? 'locked' : $slug,
            'slug' => $slug, 'body' => '<p>本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
