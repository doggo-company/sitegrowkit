<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Revisions\SchemaManifest;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * DB が本当に守ってくれるか（Phase 9.8 / STAGE 21D）。
 *
 * ## SQLite では証明にならない
 *
 * 複合外部キー・複合主キー・unique の効き方は driver で違う。
 * 既定のテストは in-memory の SQLite なので、「MySQL でも同じ」とは言えない。
 * **mock で「検証済み」にしない。**
 *
 * 使い捨ての MySQL 8.4 を別に立て、接続情報を環境変数で渡したときだけ走る。
 *
 * ```
 * docker run --rm -d --name agentcms-21d-mysql --network agentcms_default \
 *   -e MYSQL_ROOT_PASSWORD=revisions -e MYSQL_DATABASE=revisions mysql:8.4
 *
 * docker compose exec \
 *   -e REVISIONS_DB_HOST=agentcms-21d-mysql \
 *   -e REVISIONS_DB_DATABASE=revisions \
 *   -e REVISIONS_DB_USERNAME=root \
 *   -e REVISIONS_DB_PASSWORD=revisions \
 *   app php artisan test --filter=RevisionMysqlTest
 * ```
 *
 * 環境変数が無ければ skip する。**skip を合格と読ませない**ため、
 * `verify` の一部ではなく明示的な検証手順として実行する。
 */
final class RevisionMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutIsolatedMysql();
        $this->useIsolatedMysql();
        $this->migrateIsolatedDatabase();

        foreach ([RevisionWriter::TABLE, SchemaManifestRepository::TABLE, 'post', 'audit_logs'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_必要な制約が実際に作られている(): void
    {
        $this->assertTrue(Schema::hasTable(SchemaManifestRepository::TABLE));
        $this->assertTrue(Schema::hasTable(RevisionWriter::TABLE));

        $indexes = collect(Schema::getIndexes(RevisionWriter::TABLE))
            ->mapWithKeys(static fn (array $i): array => [(string) $i['name'] => $i])
            ->all();

        $this->assertArrayHasKey('content_entry_revisions_entry_revision_unique', $indexes);
        $this->assertTrue($indexes['content_entry_revisions_entry_revision_unique']['unique']);
        $this->assertArrayHasKey('content_entry_revisions_entry_kind_index', $indexes);
        $this->assertArrayHasKey('content_entry_revisions_manifest_index', $indexes);

        // 定義の側は **複合主キー**（型 + 指紋）。指紋だけにすると型を取り違える
        $manifestIndexes = collect(Schema::getIndexes(SchemaManifestRepository::TABLE))
            ->firstWhere('primary', true);

        $this->assertNotNull($manifestIndexes);
        $this->assertEqualsCanonicalizing(['content_type', 'fingerprint'], $manifestIndexes['columns']);
    }

    public function test_定義の無い版は入れられない(): void
    {
        // 複合 FK が効いている（片方だけ合っていても通らない）
        $this->assertThrows(fn () => $this->insertRevision('post', 1, 1, str_repeat('z', 64)), QueryException::class);
    }

    public function test_版から参照されている定義は消せない(): void
    {
        $fingerprint = $this->insertManifest('post');
        $this->insertRevision('post', 1, 1, $fingerprint);

        $this->assertThrows(
            fn () => DB::table(SchemaManifestRepository::TABLE)->delete(),
            QueryException::class,
        );
    }

    public function test_同じ型で同じ版番号を2つ作れない(): void
    {
        $fingerprint = $this->insertManifest('post');
        $this->insertRevision('post', 1, 1, $fingerprint);

        // 採番が競合しても、DB が最後に止める
        $this->assertThrows(
            fn () => $this->insertRevision('post', 1, 1, $fingerprint),
            QueryException::class,
        );
    }

    public function test_型が違えば同じ指紋を別々に持てる(): void
    {
        $fingerprint = str_repeat('c', 64);

        $this->insertManifest('post', $fingerprint);
        $this->insertManifest('news', $fingerprint);

        $this->assertSame(2, DB::table(SchemaManifestRepository::TABLE)->count());

        // 版は **型ごと**に正しい定義を指す
        $this->insertRevision('post', 1, 1, $fingerprint);
        $this->insertRevision('news', 1, 1, $fingerprint);

        $this->assertSame(2, DB::table(RevisionWriter::TABLE)->count());
    }

    public function test_baselineが実dbでも動く(): void
    {
        DB::table('post')->insert([
            'title' => '実 DB', 'slug' => 'mysql-baseline', 'body' => '<p>本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('agentcms:content-baseline', ['--type' => 'post']);

        $row = DB::table(RevisionWriter::TABLE)->first();

        $this->assertNotNull($row);
        $this->assertSame('baseline', $row->reason);
        $this->assertSame(1, (int) $row->revision);

        // 何度流しても増えない
        Artisan::call('agentcms:content-baseline', ['--type' => 'post']);
        $this->assertSame(1, DB::table(RevisionWriter::TABLE)->count());
    }

    /**
     * **同じ定義を同時に置いても、負けた側が落ちない**（Phase 9.8 / STAGE 21F）。
     *
     * REPEATABLE READ では transaction を始めた時点の snapshot を見る。
     * 相手が後から commit した行は、重複で INSERT に失敗したあとでも
     * 普通の SELECT では見えない。読み直しを snapshot 読みでやると
     * 「重複したのに読めない」になり、利用者には競合として返ってしまう。
     *
     * 実際に MySQL 8.4 の並走で再現したので、ここで固定する。
     */
    public function test_同じ定義を同時に置いても負けた側が落ちない(): void
    {
        $schema = app(SchemaRegistry::class)->get('post');
        $repository = new SchemaManifestRepository;

        DB::beginTransaction();

        try {
            // ここで snapshot が確定する（この時点では定義の行は無い）
            $this->assertSame(0, DB::table(SchemaManifestRepository::TABLE)->count());

            // **別の接続**が先に同じ定義を置いて commit する
            $this->otherConnection()->table(SchemaManifestRepository::TABLE)->insert([
                'content_type' => 'post',
                'fingerprint' => hash('sha256', SchemaManifest::encode(SchemaManifest::canonical($schema))),
                'manifest' => SchemaManifest::encode(SchemaManifest::canonical($schema)),
                'created_at' => now(),
            ]);

            // 負けた側。**例外にせず**、同じ指紋を返して続行できること
            $fingerprint = $repository->materialize('post', $schema);

            $this->assertSame(
                hash('sha256', SchemaManifest::encode(SchemaManifest::canonical($schema))),
                $fingerprint,
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->assertSame(1, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_downは版が残っていれば止まる(): void
    {
        $fingerprint = $this->insertManifest('post');
        $this->insertRevision('post', 1, 1, $fingerprint);

        $this->assertThrows(fn () => $this->migration()->down(), \RuntimeException::class);

        // **1 つも落ちていない**
        $this->assertTrue(Schema::hasTable(RevisionWriter::TABLE));
        $this->assertTrue(Schema::hasTable(SchemaManifestRepository::TABLE));
    }

    public function test_downは空なら通る(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable(RevisionWriter::TABLE));
        $this->assertFalse(Schema::hasTable(SchemaManifestRepository::TABLE));

        // 戻せることまで見る（片道にしない）
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable(RevisionWriter::TABLE));
    }

    /**
     * 同じ隔離 DB を指す **別の接続**（自分の transaction の外側で書く相手役）。
     */
    private function otherConnection(): Connection
    {
        Config::set('database.connections.mysql_other', (array) config('database.connections.mysql'));

        DB::purge('mysql_other');

        return DB::connection('mysql_other');
    }

    private function insertManifest(string $type, ?string $fingerprint = null): string
    {
        $fingerprint ??= str_repeat('a', 64);

        DB::table(SchemaManifestRepository::TABLE)->insert([
            'content_type' => $type, 'fingerprint' => $fingerprint,
            'manifest' => '[]', 'created_at' => now(),
        ]);

        return $fingerprint;
    }

    private function insertRevision(string $type, int $id, int $revision, string $fingerprint): void
    {
        DB::table(RevisionWriter::TABLE)->insert([
            'content_type' => $type, 'content_id' => $id, 'revision' => $revision,
            'kind' => 'manual', 'snapshot' => '{}', 'schema_fingerprint' => $fingerprint,
            'reason' => 'baseline', 'created_interface' => 'cli', 'created_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'core/database/migrations/2026_08_18_000010_create_content_revision_tables.php',
        );

        return $migration;
    }

    private function skipWithoutIsolatedMysql(): void
    {
        if ((string) env('REVISIONS_DB_HOST', '') === '') {
            $this->markTestSkipped(
                '隔離 MySQL の接続情報（REVISIONS_DB_*）が無いため skip。'
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
            'host' => (string) env('REVISIONS_DB_HOST'),
            'port' => (string) env('REVISIONS_DB_PORT', '3306'),
            'database' => (string) env('REVISIONS_DB_DATABASE'),
            'username' => (string) env('REVISIONS_DB_USERNAME'),
            'password' => (string) env('REVISIONS_DB_PASSWORD'),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        app()->forgetInstance('cache');
        app()->forgetInstance(ConfirmationGate::class);
    }

    private function migrateIsolatedDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $this->assertTrue(
            Schema::hasTable(RevisionWriter::TABLE),
            'migrate が通っていない: '.Artisan::output(),
        );
    }
}
