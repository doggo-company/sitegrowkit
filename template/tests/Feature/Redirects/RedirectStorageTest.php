<?php

declare(strict_types=1);

namespace Tests\Feature\Redirects;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Redirects\DeleteRedirect;
use AgentCms\Core\Application\Redirects\GetRedirect;
use AgentCms\Core\Application\Redirects\ListRedirects;
use AgentCms\Core\Application\Redirects\SaveRedirect;
use AgentCms\Core\Application\Redirects\SimulateRedirect;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Redirects\RedirectDecision;
use AgentCms\Core\Redirects\RedirectMatchType;
use AgentCms\Core\Redirects\RedirectPath;
use AgentCms\Core\Redirects\RedirectResolver;
use AgentCms\Core\Redirects\RedirectRule;
use App\Models\User;
use App\Support\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

use function PHPUnit\Framework\assertTrue;

/**
 * 転送ルールの storage と決め方（Phase 9.10 / STAGE 23B）。
 *
 * 実際に 404 から転送するところは 23C。ここで固定するのは
 * **保存される形**と、**どこへ送ると決めるか**である。
 */
final class RedirectStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    // ── 表の形 ─────────────────────────────────────────────

    public function test_表と索引ができている(): void
    {
        $this->assertTrue(Schema::hasTable('redirect_rules'));
        $this->assertTrue(Schema::hasTable('not_found_logs'));

        $rules = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('redirect_rules'),
        );

        $this->assertContains('uq_redirect_source', $rules);
        $this->assertContains('idx_redirect_lookup', $rules);

        $logs = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('not_found_logs'),
        );

        $this->assertContains('uq_not_found_path', $logs);
        $this->assertContains('idx_not_found_open', $logs);
    }

    public function test_downは行が残っていれば止まる(): void
    {
        $this->rule('/old', '/new');

        $this->assertThrows(fn () => $this->migration()->down(), \RuntimeException::class);

        $this->assertTrue(Schema::hasTable('redirect_rules'));
        $this->assertTrue(Schema::hasTable('not_found_logs'));
    }

    public function test_downは空なら通って戻せる(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('redirect_rules'));
        $this->assertFalse(Schema::hasTable('not_found_logs'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('redirect_rules'));
    }

    // ── 正規化 ─────────────────────────────────────────────

    public function test_パスの正規化(): void
    {
        $cases = [
            'about' => '/about',
            '/about/' => '/about',
            '/about?x=1#y' => '/about',
            '/a//b' => '/a/b',
            '/' => '/',
            'https://example.com/a/' => '/a',
            '/%E6%97%A5' => '/日',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, RedirectPath::normalize($input), $input);
        }
    }

    public function test_扱えないパスは断る(): void
    {
        // `.` / `..` は解決せずに断る（正規化で通すと外へ出る道を作る）
        foreach (['', '   ', '/a/../b', '/a/./b', "/a\x00b"] as $input) {
            $this->assertNull(RedirectPath::normalize($input), $input);
        }
    }

    public function test_大小文字は畳まない(): void
    {
        // URL は大小を区別する。畳むと別ページへ飛ばしてしまう
        $this->assertSame('/About', RedirectPath::normalize('/About'));
        $this->assertNotSame(
            RedirectPath::hash(RedirectMatchType::Exact, '/About'),
            RedirectPath::hash(RedirectMatchType::Exact, '/about'),
        );
    }

    public function test_照合方法が違えば鍵も違う(): void
    {
        // `exact:/a` と `prefix:/a` は別のルールとして共存してよい
        $this->assertNotSame(
            RedirectPath::hash(RedirectMatchType::Exact, '/a'),
            RedirectPath::hash(RedirectMatchType::Prefix, '/a'),
        );
    }

    // ── 決め方 ─────────────────────────────────────────────

    public function test_完全一致で転送する(): void
    {
        $this->rule('/old', '/new');

        $decision = $this->resolve('/old');

        $this->assertNotNull($decision);
        $this->assertSame('/new', $decision->target);
        $this->assertSame(301, $decision->status()->value);
    }

    public function test_末尾のスラッシュは同じものとして扱う(): void
    {
        $this->rule('/old', '/new');

        $this->assertNotNull($this->resolve('/old/'));
    }

    public function test_前方一致は残りを継ぎ足す(): void
    {
        $this->rule('/old', '/new', type: RedirectMatchType::Prefix);

        $this->assertSame('/new/a/b', $this->resolve('/old/a/b')?->target);
    }

    public function test_前方一致は境界で切る(): void
    {
        $this->rule('/old', '/new', type: RedirectMatchType::Prefix);

        // `/older` は `/old` の続きではない
        $this->assertNull($this->resolve('/older'));
    }

    public function test_長い前方一致が先に当たる(): void
    {
        $this->rule('/old', '/new', type: RedirectMatchType::Prefix);
        $this->rule('/old/sub', '/special', type: RedirectMatchType::Prefix);

        $this->assertSame('/special/x', $this->resolve('/old/sub/x')?->target);
    }

    public function test_完全一致が前方一致より先に当たる(): void
    {
        $this->rule('/old', '/prefix-target', type: RedirectMatchType::Prefix);
        $this->rule('/old', '/exact-target', type: RedirectMatchType::Exact);

        $this->assertSame('/exact-target', $this->resolve('/old')?->target);
    }

    public function test_無効なルールは当たらない(): void
    {
        $this->rule('/old', '/new', enabled: false);

        $this->assertNull($this->resolve('/old'));
    }

    public function test_queryを引き継ぐ(): void
    {
        $this->rule('/old', '/new');

        $this->assertSame('/new?a=1', $this->resolve('/old', 'a=1')?->target);
    }

    public function test_転送先のqueryが勝つ(): void
    {
        $this->rule('/old', '/new?a=2');

        // 転送先の指定のほうが新しい意図である
        $this->assertSame('/new?a=2', $this->resolve('/old', 'a=1')?->target);
    }

    public function test_queryを引き継がない設定(): void
    {
        $this->rule('/old', '/new', keepQuery: false);

        $this->assertSame('/new', $this->resolve('/old', 'a=1')?->target);
    }

    public function test_連鎖は最終地点へ1回で送る(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', '/c');

        $decision = $this->resolve('/a');

        $this->assertSame('/c', $decision?->target, '途中の /b へ送っている');
        $this->assertSame(2, $decision?->hops);
    }

    public function test_輪になっていたら転送しない(): void
    {
        // 保存経路を通さず**直接**入れて、要求時の保険が効くことを見る
        $this->rule('/a', '/b');
        $this->rule('/b', '/a');

        // ブラウザに「リダイレクトが多すぎます」を出させない
        $this->assertNull($this->resolve('/a'));
    }

    public function test_連鎖が長すぎたら転送しない(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', '/c');
        $this->rule('/c', '/d');
        $this->rule('/d', '/e');

        // 途中まで送ると、利用者は知らない中継地点へ落ちる
        $this->assertNull($this->resolve('/a'));
    }

    public function test_自分自身へは送らない(): void
    {
        $this->rule('/a', '/a/');

        $this->assertNull($this->resolve('/a'));
    }

    public function test_管理画面のパスは転送しない(): void
    {
        foreach (['/admin', '/admin/menus', '/api/v1/x', '/login', '/healthz', '/up'] as $path) {
            // ルールがあっても効かない
            DB::table('redirect_rules')->insert([
                'match_type' => 'exact', 'source_path' => $path,
                'source_hash' => hash('sha256', 'exact|'.$path),
                'target_url' => '/somewhere', 'status_code' => 301,
                'is_enabled' => true, 'keep_query' => true, 'created_reason' => 'manual',
                'hit_count' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->assertNull($this->resolve($path), $path);
            $this->assertTrue(RedirectResolver::isProtected($path), $path);
        }
    }

    public function test_転送してよいのはgetとheadだけ(): void
    {
        $this->assertTrue(RedirectResolver::isAllowedMethod('GET'));
        $this->assertTrue(RedirectResolver::isAllowedMethod('head'));

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertFalse(RedirectResolver::isAllowedMethod($method), $method);
        }
    }

    // ── 保存 ────────────────────────────────────────────────

    public function test_作れる(): void
    {
        $result = $this->save(['source_path' => '/old/', 'target_url' => '/new']);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        $rule = RedirectRule::query()->firstOrFail();

        // 正規化して保存される
        $this->assertSame('/old', $rule->source_path);
        // 鍵は保存時に作り直す
        $this->assertSame(RedirectPath::hash(RedirectMatchType::Exact, '/old'), $rule->source_hash);
        $this->assertSame('manual', $rule->created_reason);
    }

    public function test_渡された鍵は使わない(): void
    {
        $this->save([
            'source_path' => '/old',
            'target_url' => '/new',
            // 嘘の鍵を渡しても無視される（登録内容と鍵がずれた行を作らせない）
            'source_hash' => str_repeat('f', 64),
        ]);

        $this->assertSame(
            RedirectPath::hash(RedirectMatchType::Exact, '/old'),
            RedirectRule::query()->value('source_hash'),
        );
    }

    public function test_同じ転送元は2つ作れない(): void
    {
        $this->save(['source_path' => '/old', 'target_url' => '/new']);

        // 末尾スラッシュ違いも同じものとして弾く
        $result = $this->save(['source_path' => '/old/', 'target_url' => '/another']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(1, RedirectRule::query()->count());
    }

    public function test_自己転送は保存できない(): void
    {
        $result = $this->save(['source_path' => '/a', 'target_url' => '/a']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('target_url', $result->errors);
    }

    public function test_輪になるルールは保存できない(): void
    {
        $this->save(['source_path' => '/a', 'target_url' => '/b']);

        // /b → /a を足すと輪になる
        $result = $this->save(['source_path' => '/b', 'target_url' => '/a']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('target_url', $result->errors);
        $this->assertSame(1, RedirectRule::query()->count());
    }

    public function test_危険な転送先は保存できない(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>',
            'ftp://example.com/a',
            '//example.com/a',
            'example.com/a',
        ] as $target) {
            $result = $this->save(['source_path' => '/x-'.md5($target), 'target_url' => $target]);

            $this->assertSame(OperationStatus::ValidationError, $result->status, $target);
            $this->assertArrayHasKey('target_url', $result->errors, $target);
        }
    }

    public function test_外部への転送は保存できる(): void
    {
        $result = $this->save(['source_path' => '/moved', 'target_url' => 'https://example.com/new']);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame('https://example.com/new', $this->resolve('/moved')?->target);
    }

    public function test_管理画面を転送元にも転送先にもできない(): void
    {
        $source = $this->save(['source_path' => '/admin/x', 'target_url' => '/new']);
        $target = $this->save(['source_path' => '/old', 'target_url' => '/admin/x']);

        $this->assertSame(OperationStatus::ValidationError, $source->status);
        $this->assertArrayHasKey('source_path', $source->errors);
        $this->assertSame(OperationStatus::ValidationError, $target->status);
        $this->assertArrayHasKey('target_url', $target->errors);
    }

    public function test_知らないstatus_codeは断る(): void
    {
        $result = $this->save(['source_path' => '/a', 'target_url' => '/b', 'status_code' => 200]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('status_code', $result->errors);
    }

    public function test_regexは選べない(): void
    {
        // OG3: 列にも enum にも入れていない
        $result = $this->save(['source_path' => '/a', 'target_url' => '/b', 'match_type' => 'regex']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('match_type', $result->errors);
        $this->assertNull(RedirectMatchType::tryFrom('regex'));
    }

    // ── 版と削除 ────────────────────────────────────────────

    public function test_更新には版が要る(): void
    {
        $this->save(['source_path' => '/a', 'target_url' => '/b']);
        $id = (int) RedirectRule::query()->value('id');

        $missing = app(SaveRedirect::class)->execute(
            ['source_path' => '/a', 'target_url' => '/c', 'match_type' => 'exact', 'status_code' => 301],
            $this->context(),
            $id,
        );

        $this->assertSame(OperationStatus::ValidationError, $missing->status);
        $this->assertArrayHasKey('lock_version', $missing->errors);
    }

    public function test_古い版では更新できない(): void
    {
        $this->save(['source_path' => '/a', 'target_url' => '/b']);
        $id = (int) RedirectRule::query()->value('id');

        $this->update($id, ['source_path' => '/a', 'target_url' => '/c'], 0);
        $stale = $this->update($id, ['source_path' => '/a', 'target_url' => '/d'], 0);

        $this->assertSame(OperationStatus::Conflict, $stale->status);
        $this->assertSame('/c', RedirectRule::query()->value('target_url'));
    }

    public function test_削除は確認を取ってから(): void
    {
        $this->rule('/old', '/new', hits: 42);
        $id = (int) RedirectRule::query()->value('id');

        $first = app(DeleteRedirect::class)->execute($id, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        // 「どれだけ踏まれているか」を出す（消す判断が変わる）
        $impact = implode(' ', $first->confirmation?->impact ?? []);
        $this->assertStringContainsString('42 回', $impact);
        $this->assertStringContainsString('無効', $impact);

        $token = (string) $first->confirmation?->token;
        $second = app(DeleteRedirect::class)->execute($id, $this->context()->withConfirmationToken($token));

        $this->assertTrue($second->isSuccess());
        $this->assertSame(0, RedirectRule::query()->count());
    }

    // ── 一覧・取得・シミュレータ ────────────────────────────

    public function test_一覧はよく踏まれている順(): void
    {
        $this->rule('/a', '/1', hits: 5);
        $this->rule('/b', '/2', hits: 99);

        $result = app(ListRedirects::class)->execute($this->context());

        $this->assertSame('/b', $result->data['rules'][0]['source_path']);
        $this->assertSame(2, $result->meta['total']);
    }

    public function test_一覧は鍵を出さない(): void
    {
        $this->rule('/a', '/1');

        $row = app(ListRedirects::class)->execute($this->context())->data['rules'][0];

        // 照合の鍵は人にも AI にも用が無い
        $this->assertArrayNotHasKey('source_hash', $row);
    }

    public function test_無いidはnot_found(): void
    {
        $this->assertSame(
            OperationStatus::NotFound,
            app(GetRedirect::class)->execute(999999, $this->context())->status,
        );
    }

    public function test_シミュレータは踏まずに結果を返す(): void
    {
        $this->rule('/old', '/new');

        $result = app(SimulateRedirect::class)->execute('/old?x=1', $this->context());

        $this->assertTrue($result->data['matched']);
        $this->assertSame('/new?x=1', $result->data['target']);
        $this->assertSame(301, $result->data['status_code']);
        // **数えない**（試したことがヒット数に混ざらない）
        $this->assertSame(0, (int) RedirectRule::query()->value('hit_count'));
    }

    public function test_シミュレータは当たらないことも言う(): void
    {
        $result = app(SimulateRedirect::class)->execute('/nope', $this->context());

        $this->assertFalse($result->data['matched']);
        $this->assertSame('/nope', $result->data['path']);
    }

    public function test_シミュレータは保護されたパスだと理由を言う(): void
    {
        $result = app(SimulateRedirect::class)->execute('/admin/menus', $this->context());

        $this->assertTrue($result->data['protected']);
        $this->assertFalse($result->data['matched']);
    }

    public function test_書き込みは監査に残る(): void
    {
        $this->save(['source_path' => '/a', 'target_url' => '/b']);

        $row = DB::table('audit_logs')->where('operation', 'redirect.save')->first();

        $this->assertNotNull($row);
        $this->assertSame('redirect', $row->resource_type);
    }

    // ── 権限 ────────────────────────────────────────────────

    public function test_編集者は保存できないが読める(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);
        $context = OperationContext::admin((int) $editor->getKey(), (string) $editor->name);

        $this->assertTrue(app(ListRedirects::class)->execute($context)->isSuccess());

        $save = app(SaveRedirect::class)->execute(
            ['source_path' => '/a', 'target_url' => '/b', 'match_type' => 'exact', 'status_code' => 301],
            $context,
        );

        $this->assertSame(OperationStatus::Forbidden, $save->status);
    }

    public function test_管理者は保存できる(): void
    {
        $admin = User::factory()->create(['role' => Role::Administrator->value]);
        $context = OperationContext::admin((int) $admin->getKey(), (string) $admin->name);

        $result = app(SaveRedirect::class)->execute(
            ['source_path' => '/a', 'target_url' => '/b', 'match_type' => 'exact', 'status_code' => 301],
            $context,
        );

        assertTrue($result->isSuccess());
    }

    // ── helpers ────────────────────────────────────────────

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    /** @param  array<string, mixed>  $input */
    private function save(array $input): Result
    {
        return app(SaveRedirect::class)->execute(
            $input + ['match_type' => 'exact', 'status_code' => 301],
            $this->context(),
        );
    }

    /** @param  array<string, mixed>  $input */
    private function update(int $id, array $input, int $lockVersion): Result
    {
        return app(SaveRedirect::class)->execute(
            $input + ['match_type' => 'exact', 'status_code' => 301],
            $this->context(),
            $id,
            $lockVersion,
        );
    }

    private function resolve(string $path, string $query = ''): ?RedirectDecision
    {
        return app(RedirectResolver::class)->resolve($path, $query);
    }

    /** 保存経路を通さずに 1 行入れる（要求時の保険を見るため） */
    private function rule(
        string $source,
        string $target,
        RedirectMatchType $type = RedirectMatchType::Exact,
        bool $enabled = true,
        bool $keepQuery = true,
        int $hits = 0,
    ): void {
        DB::table('redirect_rules')->insert([
            'match_type' => $type->value,
            'source_path' => $source,
            'source_hash' => RedirectPath::hash($type, $source),
            'target_url' => $target,
            'status_code' => 301,
            'is_enabled' => $enabled,
            'keep_query' => $keepQuery,
            'created_reason' => 'manual',
            'hit_count' => $hits,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('core/database/migrations/2026_08_20_000010_create_redirect_tables.php');

        return $migration;
    }
}
