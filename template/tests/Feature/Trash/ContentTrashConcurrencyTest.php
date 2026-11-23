<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * **本当に同時に来たとき**どうなるか（Phase 9.8 / STAGE 21C・SPEC §G.5.1・OG9・OG11）。
 *
 * ## sqlite では証明にならない
 *
 * 既定のテストは sqlite の in-memory で走る。`lockForUpdate()` は無視され、
 * 2 接続も持てない。「順番に呼べば片方が拒否される」ことしか言えず、
 * **並びが決まっていないと deadlock する**という話は再現できない。
 * mock で「検証済み」にはしない。
 *
 * ## 隔離した MySQL で走らせる
 *
 * 開発用の DB は使わない。使い捨ての MySQL 8.4 を別に立て、接続情報を
 * 環境変数で渡したときだけ走る（`LastOwnerConcurrencyTest` と同じ手順）。
 *
 * ```
 * docker run --rm -d --name agentcms-concurrency-mysql \
 *   --network agentcms_default \
 *   -e MYSQL_ROOT_PASSWORD=concurrency -e MYSQL_DATABASE=concurrency mysql:8.4
 *
 * docker compose exec \
 *   -e CONCURRENCY_DB_HOST=agentcms-concurrency-mysql \
 *   -e CONCURRENCY_DB_DATABASE=concurrency \
 *   -e CONCURRENCY_DB_USERNAME=root \
 *   -e CONCURRENCY_DB_PASSWORD=concurrency \
 *   app php artisan test --filter=ContentTrashConcurrencyTest
 * ```
 *
 * 環境変数が無ければ skip する。**skip を合格と読ませない**ため、
 * `verify` の一部ではなく明示的な検証手順として実行する。
 */
final class ContentTrashConcurrencyTest extends TestCase
{
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutIsolatedMysql();
        $this->useIsolatedMysql();
        $this->useSharedFileCache();
        $this->migrateIsolatedDatabase();

        /*
         * 使い捨ての DB を**毎回同じ地点から**始める。`RefreshDatabase` は使えない
         * （transaction で包むと、別プロセスの worker から見えない）ので、
         * 触る表だけを空にする。開発用の DB では走らないことは setUp の先頭で確かめている。
         */
        foreach (['post', 'products', 'works', 'audit_logs', 'users'] as $table) {
            DB::table($table)->delete();
        }

        $this->actor = (int) User::factory()->create([
            'name' => 'owner', 'email' => 'owner@example.test',
        ])->getKey();
    }

    /** 同時にゴミ箱へ入れても、通るのは片方だけ */
    public function test_同時にゴミ箱へ入れても片方だけ通る(): void
    {
        $id = $this->entry('race');

        // 2 つの worker が **同じ版**を見て確認を取る
        $tokenA = $this->issueToken('content.trash', ['type' => 'post', 'id' => $id, 'lock_version' => 0]);
        $tokenB = $this->issueToken('content.trash', ['type' => 'post', 'id' => $id, 'lock_version' => 0]);

        $results = $this->runSimultaneously([
            ['content.trash', $id, $tokenA, ['type' => 'post', 'lock_version' => 0]],
            ['content.trash', $id, $tokenB, ['type' => 'post', 'lock_version' => 0]],
        ]);

        $this->assertNoRawExceptions($results);

        $statuses = $this->statuses($results);
        sort($statuses);

        $this->assertSame(
            [OperationStatus::Conflict->value, OperationStatus::Success->value],
            $statuses,
            '同時実行の結末が「片方成功・片方競合」になっていない: '.implode(' / ', $statuses),
        );

        // 版は 1 つだけ進む（両方が書いていない）
        $this->assertSame(1, (int) DB::table('post')->where('id', $id)->value('lock_version'));
        $this->assertNotNull(DB::table('post')->where('id', $id)->value('deleted_at'));
    }

    /**
     * **逆順で来る 2 つの transaction**（SPEC §G.5.1）。
     *
     * 参照先を「見つけた順」にロックすると、A が X → Y、B が Y → X の順で掴み、
     * 互いを待って止まる。`(content_type, id)` の昇順に揃えてあれば起きない。
     */
    public function test_逆順の参照更新でも部分適用を残さない(): void
    {
        // 参照される側（`product.related_items` は `works` を指す）
        $x = $this->work('target-x');
        $y = $this->work('target-y');

        // 参照する側
        $a = $this->product('editor-a');
        $b = $this->product('editor-b');

        $results = $this->runSimultaneously([
            ['content.update', $a, '', [
                'type' => 'product', 'lock_version' => 0,
                'data' => ['title' => 'A が更新', 'related_items' => [$x, $y]],
            ]],
            ['content.update', $b, '', [
                'type' => 'product', 'lock_version' => 0,
                // **逆順**に並べて渡す
                'data' => ['title' => 'B が更新', 'related_items' => [$y, $x]],
            ]],
        ]);

        // deadlock が生の例外として漏れない（掴んだら中で処理する）
        $this->assertNoRawExceptions($results);

        /*
         * **どちらも通ってよい。** 更新している entry が別なので、
         * 並びさえ揃っていれば互いを待つだけで、止まらない。
         * 止まるのは順序が決まっていないときで、それがこのテストの見張るところである。
         */
        foreach ($results as $index => $result) {
            $this->assertSame(
                OperationStatus::Success->value,
                (string) $result['status'],
                "worker {$index} が通らなかった: ".json_encode($result, JSON_UNESCAPED_UNICODE),
            );
        }

        // 途中まで書けた、という状態を残さない
        foreach ([$a => 'A が更新', $b => 'B が更新'] as $id => $title) {
            $row = DB::table('products')->where('id', $id)->first();

            $this->assertSame(1, (int) $row->lock_version, '版が進んでいない');
            $this->assertSame($title, (string) $row->title);

            $data = json_decode((string) $row->data, true);
            $this->assertEqualsCanonicalizing([$x, $y], $data['related_items'] ?? []);
        }
    }

    private function work(string $slug): int
    {
        return DB::table('works')->insertGetId([
            'title' => $slug, 'slug' => $slug, 'category' => 'web', 'body' => '<p>本文</p>',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $slug): int
    {
        return DB::table('products')->insertGetId([
            'title' => $slug, 'slug' => $slug,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug, 'slug' => $slug, 'body' => '<p>本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string, 3: array<string, mixed>}>  $jobs
     * @return list<array<string, mixed>>
     */
    private function runSimultaneously(array $jobs): array
    {
        $script = base_path('tests/Support/concurrency-worker.php');

        // 起動のばらつきを吸収するため、全員が同じ時刻まで待ってから走る
        $startAt = microtime(true) + 1.5;

        $processes = [];
        $pipes = [];

        foreach ($jobs as $index => [$operation, $targetId, $token, $payload]) {
            $command = [
                PHP_BINARY, $script, $operation,
                (string) $targetId, (string) $this->actor, $token, (string) $startAt,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            ];

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $descriptors, $processPipes, base_path(), $this->workerEnvironment());

            $this->assertIsResource($process, 'worker を起動できなかった');

            $processes[$index] = $process;
            $pipes[$index] = $processPipes;
        }

        $results = [];

        foreach ($processes as $index => $process) {
            $stdout = (string) stream_get_contents($pipes[$index][1]);
            $stderr = (string) stream_get_contents($pipes[$index][2]);

            foreach ($pipes[$index] as $pipe) {
                fclose($pipe);
            }

            $exit = proc_close($process);
            $decoded = json_decode(trim($stdout), true);

            $this->assertIsArray(
                $decoded,
                "worker {$index} が結果を返さなかった（exit={$exit}）\nstdout: {$stdout}\nstderr: {$stderr}",
            );

            $results[] = $decoded;
        }

        return $results;
    }

    /** @param  list<array<string, mixed>>  $results */
    private function assertNoRawExceptions(array $results): void
    {
        foreach ($results as $index => $result) {
            $this->assertTrue(
                (bool) $result['ok'],
                "worker {$index} が例外で落ちた: ".json_encode($result, JSON_UNESCAPED_UNICODE),
            );

            $message = (string) ($result['message'] ?? '');

            // deadlock や SQL がそのまま利用者へ出ていない
            foreach (['SQLSTATE', 'Deadlock', 'deadlock'] as $leak) {
                $this->assertStringNotContainsString($leak, $message);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    private function statuses(array $results): array
    {
        return array_map(static fn (array $r): string => (string) $r['status'], $results);
    }

    /** @param array<string, mixed> $arguments */
    private function issueToken(string $operationId, array $arguments): string
    {
        $confirmation = app(ConfirmationGate::class)->require(
            $operationId,
            OperationClassification::Destructive,
            OperationContext::admin($this->actor, 'worker'),
            $arguments,
            summary: 'concurrency test',
            impact: [],
        );

        $this->assertNotNull($confirmation, '確認トークンが発行されなかった');

        return $confirmation->token;
    }

    private function skipWithoutIsolatedMysql(): void
    {
        if ((string) env('CONCURRENCY_DB_HOST', '') === '') {
            $this->markTestSkipped(
                '隔離 MySQL の接続情報（CONCURRENCY_DB_*）が無いため skip。'
                .'クラス docblock の手順で使い捨ての MySQL 8.4 を立てて実行すること。',
            );
        }
    }

    private function useIsolatedMysql(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            ...(array) config('database.connections.mysql'),
            'driver' => 'mysql',
            'host' => (string) env('CONCURRENCY_DB_HOST'),
            'port' => (string) env('CONCURRENCY_DB_PORT', '3306'),
            'database' => (string) env('CONCURRENCY_DB_DATABASE'),
            'username' => (string) env('CONCURRENCY_DB_USERNAME'),
            'password' => (string) env('CONCURRENCY_DB_PASSWORD'),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
    }

    private function useSharedFileCache(): void
    {
        Config::set('cache.default', 'file');

        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        app()->forgetInstance(ConfirmationGate::class);
    }

    private function migrateIsolatedDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('post'),
            'migrate が通っていない: '.Artisan::output(),
        );
    }

    /**
     * 子プロセスへ渡す環境。**接続先と cache を親と一致させる**。
     *
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        return [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];
    }
}
