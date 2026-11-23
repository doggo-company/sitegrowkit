<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * 最後のオーナーを **同時に** 消せないことの実証（STAGE #18A / Correction 2-1）。
 *
 * ## sqlite では証明にならない
 *
 * 既定のテストは sqlite の in-memory で走る。`lockForUpdate()` は無視され、
 * そもそも 2 接続を持てない。だから「順番に呼べば片方が拒否される」ことしか言えず、
 * **本当に同時に来たとき**どうなるかは分からなかった。
 * 直したのは行ロックの取り方（逆順による deadlock）なので、
 * 実際の MySQL 8.4 で 2 プロセスをぶつけない限り回帰にならない。
 *
 * ## 隔離した MySQL で走らせる
 *
 * 開発用の DB は使わない（`migrate:fresh` を投げないため、そして
 * このテストが所有者を削るため）。使い捨ての MySQL 8.4 を別に立て、
 * その接続情報を環境変数で渡したときだけ走る。
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
 *   app php artisan test --filter=LastOwnerConcurrencyTest
 * ```
 *
 * 環境変数が無ければ skip する。**skip を合格と読ませない**ため、
 * `verify` の一部としてではなく、明示的な検証手順として実行する。
 *
 * ## 何を確かめるか
 *
 * オーナーがちょうど 2 人（A / B）居る状態で、**互いを対象にした**降格を同時に投げる。
 *
 * | 見るもの | 期待 |
 * | --- | --- |
 * | 結末 | 片方だけ成功、もう片方は conflict |
 * | 例外 | 生の `QueryException` / deadlock が 1 件も漏れない |
 * | 最終状態 | 有効なオーナーが 1 人以上残る |
 */
final class LastOwnerConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutIsolatedMysql();
        $this->useIsolatedMysql();
        $this->useSharedFileCache();
        $this->migrateIsolatedDatabase();
    }

    /** ロール降格を同時に投げても、片方だけが通る */
    public function test_2人のownerを同時に降格しても片方だけ通る(): void
    {
        [$a, $b] = $this->twoOwners();

        // 互いを対象にした確認トークンを **先に** 取る（actor ごとに束縛される）
        $tokenForB = $this->issueToken('user.role.change', ['id' => $b, 'role' => Role::Viewer->value], $a);
        $tokenForA = $this->issueToken('user.role.change', ['id' => $a, 'role' => Role::Viewer->value], $b);

        $results = $this->runSimultaneously([
            ['role', $b, $a, $tokenForB],
            ['role', $a, $b, $tokenForA],
        ]);

        $this->assertOneSucceededOneConflicted($results);

        $remaining = DB::table('users')->where('role', Role::Owner->value)->where('is_active', true)->count();
        $this->assertSame(1, $remaining, "有効な owner が {$remaining} 人になった");
    }

    /** 無効化を同時に投げても、片方だけが通る */
    public function test_2人のownerを同時に無効化しても片方だけ通る(): void
    {
        [$a, $b] = $this->twoOwners();

        $tokenForB = $this->issueToken('user.deactivate', ['id' => $b], $a);
        $tokenForA = $this->issueToken('user.deactivate', ['id' => $a], $b);

        $results = $this->runSimultaneously([
            ['deactivate', $b, $a, $tokenForB],
            ['deactivate', $a, $b, $tokenForA],
        ]);

        $this->assertOneSucceededOneConflicted($results);

        $remaining = DB::table('users')->where('role', Role::Owner->value)->where('is_active', true)->count();
        $this->assertSame(1, $remaining, "有効な owner が {$remaining} 人になった");
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: string}>  $jobs
     * @return list<array<string, mixed>>
     */
    private function runSimultaneously(array $jobs): array
    {
        $script = base_path('tests/Support/concurrency-worker.php');

        // 起動のばらつきを吸収するため、全員が同じ時刻まで待ってから走る
        $startAt = microtime(true) + 1.5;

        $processes = [];
        $pipes = [];

        foreach ($jobs as $index => [$operation, $targetId, $actorId, $token]) {
            $command = [
                PHP_BINARY, $script, $operation,
                (string) $targetId, (string) $actorId, $token, (string) $startAt,
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
    private function assertOneSucceededOneConflicted(array $results): void
    {
        foreach ($results as $index => $result) {
            $this->assertTrue(
                (bool) $result['ok'],
                "worker {$index} が例外で落ちた: ".json_encode($result, JSON_UNESCAPED_UNICODE),
            );
        }

        $statuses = array_map(static fn (array $r): string => (string) $r['status'], $results);
        sort($statuses);

        $this->assertSame(
            [OperationStatus::Conflict->value, OperationStatus::Success->value],
            $statuses,
            '同時実行の結末が「片方成功・片方競合」になっていない: '.implode(' / ', $statuses),
        );

        // 競合側は **利用者に読める日本語**で返る（deadlock がそのまま出ていない）
        $conflict = array_values(array_filter(
            $results,
            static fn (array $r): bool => $r['status'] === OperationStatus::Conflict->value,
        ))[0];

        $this->assertStringContainsString('最後のオーナー', (string) $conflict['message']);
    }

    /**
     * 有効なオーナーをちょうど 2 人だけにする。
     *
     * @return array{0: int, 1: int}
     */
    private function twoOwners(): array
    {
        DB::table('users')->delete();

        $a = User::factory()->create(['name' => 'owner-a', 'email' => 'owner-a@example.test']);
        $b = User::factory()->create(['name' => 'owner-b', 'email' => 'owner-b@example.test']);

        $this->assertSame(
            2,
            DB::table('users')->where('role', Role::Owner->value)->where('is_active', true)->count(),
            'owner が 2 人ちょうどになっていない',
        );

        return [(int) $a->getKey(), (int) $b->getKey()];
    }

    /**
     * actor へ束縛された確認トークンを **本物のゲート**から取る。
     *
     * @param  array<string, mixed>  $arguments
     */
    private function issueToken(string $operationId, array $arguments, int $actorId): string
    {
        $confirmation = app(ConfirmationGate::class)->require(
            $operationId,
            OperationClassification::Destructive,
            OperationContext::admin($actorId, 'worker'),
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
            'database' => (string) env('CONCURRENCY_DB_DATABASE', 'concurrency'),
            'username' => (string) env('CONCURRENCY_DB_USERNAME', 'root'),
            'password' => (string) env('CONCURRENCY_DB_PASSWORD', ''),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
    }

    /**
     * 確認トークンは cache に載る。子プロセスからも読めないと
     * 「トークン無し = 確認要求」で終わってしまい、競合を再現できない。
     *
     * 既定のテストは array store（プロセス内だけ）なので、
     * **ファイルへ落ちる store**へ切り替えて親子で同じ場所を見る。
     * 置き場はアプリの既定（`storage/framework/cache/data`）のままにして、
     * 子プロセスへは `CACHE_STORE=file` だけを渡す。
     */
    private function useSharedFileCache(): void
    {
        Config::set('cache.default', 'file');

        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        app()->forgetInstance(ConfirmationGate::class);
    }

    /** 使い捨て DB なので、素の `migrate` で足りる（fresh は投げない） */
    private function migrateIsolatedDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('users'),
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
