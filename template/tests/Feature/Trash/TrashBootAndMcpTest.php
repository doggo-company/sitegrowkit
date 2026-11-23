<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashReadinessResolver;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * boot と MCP（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2・§C.5）。
 *
 * boot で DB を読むと、DB が落ちているだけで **URL が全部消える**。
 * route の集合は DB の状態から独立していなければならない（9.7 §0.2-(1) と同じ線）。
 */
final class TrashBootAndMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_route集合はゴミ箱の対応状況に依存しない(): void
    {
        $before = $this->routeNames();

        DB::table('post')->insert([
            'title' => 'a', 'slug' => 'a', 'body' => '<p>b</p>', 'status' => 'draft',
            TrashColumns::DELETED_AT => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($before, $this->routeNames());
        // route を数えるあいだ、対応状況は 1 度も問われない
        $this->assertSame(0, app(TrashReadinessResolver::class)->lookupCount());
    }

    public function test_mcpの一覧はゴミ箱を返さない(): void
    {
        DB::table('post')->insert([
            'title' => 'のこる', 'slug' => 'kept', 'body' => '<p>b</p>', 'status' => 'draft',
            TrashColumns::DELETED_AT => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('post')->insert([
            'title' => 'すてた', 'slug' => 'gone', 'body' => '<p>b</p>', 'status' => 'draft',
            TrashColumns::DELETED_AT => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $output = $this->runOperation('content.list', ['type' => 'post']);

        $this->assertSame('success', $output['status']);
        $this->assertSame(['のこる'], array_column($output['data']['items'], 'title'));
    }

    public function test_mcpの取得はゴミ箱を見つけない(): void
    {
        $id = DB::table('post')->insertGetId([
            'title' => 'すてた', 'slug' => 'gone', 'body' => '<p>b</p>', 'status' => 'draft',
            TrashColumns::DELETED_AT => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $output = $this->runOperation('content.get', ['type' => 'post', 'id' => $id]);

        // 存在そのものを漏らさない
        $this->assertSame('not_found', $output['status']);
    }

    public function test_エージェントへ公開するのはゴミ箱への移動までである(): void
    {
        /*
         * 増えたのは**戻せる操作**だけである。
         *
         * ここには件数の pin が並んでいたが、公開範囲は `McpBoundaryTest` が
         * **名前で**固定している。数を 2 か所で持つと、**足したときに数だけ直して
         * 中身を見ない**道ができる。
         */
        $this->assertContains('content.trash', OperationCatalog::ids());
        $this->assertContains('content.delete', OperationCatalog::ids());

        /*
         * 戻す操作は **AI へ出さない**（OG3 = (a)）。完全削除は backup ができるまで
         * 作らない（OG8 = (b)）。版の復元は STAGE 21D。
         */
        foreach (['content.restore', 'content.purge', 'content.revision.restore'] as $withheld) {
            $this->assertNotContains($withheld, OperationCatalog::ids(), "{$withheld} を公開している");
        }

        /*
         * `page.trash` は **ページの**ゴミ箱で、9.8 より前から在る別系統である
         * （SPEC §A）。投稿側の operation と混同しない。
         */
        $this->assertContains('page.trash', OperationCatalog::ids());
    }

    /**
     * MCP から呼ばれたときと同じ経路（`OperationCatalog::invoke`）。
     *
     * Artisan の bridge は stdin を読むので、テストからは catalog を直接叩く。
     * bridge がやるのは「stdin を JSON にする」「Result を 1 行で出す」だけで、
     * 何を返すかを決めているのはこの経路である。
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runOperation(string $operation, array $arguments): array
    {
        $context = OperationContext::agent(OperationInterface::Mcp, 'test-agent');

        return app(OperationCatalog::class)->invoke($operation, $arguments, $context)->toArray();
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $names[] = (string) $route->uri();
        }

        sort($names);

        return $names;
    }
}
