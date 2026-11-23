<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 使われなくなった項目定義の片付け（Phase 9.8 / STAGE 21D・SPEC §H.4）。
 *
 * **消すのは定義だけ**である。版そのものの間引きも投稿の完全削除も、
 * この Stage では作らない（backup ができるまで、戻せない口を増やさない）。
 */
final class ManifestPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_既定では何も消さない(): void
    {
        $this->orphan();

        $this->artisan('agentcms:manifest-prune')
            ->expectsOutputToContain('--apply')
            ->assertSuccessful();

        // 「流したら消えていた」を作らない
        $this->assertSame(1, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_明示すれば消す(): void
    {
        $this->orphan();

        $this->artisan('agentcms:manifest-prune', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_版から参照されている定義は消さない(): void
    {
        $fingerprint = $this->orphan();
        $this->revision('post', 1, $fingerprint);

        $this->artisan('agentcms:manifest-prune', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_外部キーが最後の安全網になっている(): void
    {
        $fingerprint = $this->orphan();
        $this->revision('post', 1, $fingerprint);

        // アプリ側の確認をすり抜けても、DB が止める
        $this->assertThrows(
            fn () => DB::table(SchemaManifestRepository::TABLE)->delete(),
            QueryException::class,
        );
    }

    public function test_件数を区切って見る(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->orphan(str_pad((string) $i, 64, 'f'));
        }

        $this->artisan('agentcms:manifest-prune', ['--apply' => true, '--limit' => 2])->assertSuccessful();

        $this->assertSame(1, DB::table(SchemaManifestRepository::TABLE)->count());
    }

    public function test_監査には件数と鍵だけを残す(): void
    {
        $this->orphan();

        $this->artisan('agentcms:manifest-prune', ['--apply' => true])->assertSuccessful();

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.revision.manifest.prune')
            ->value('metadata');

        $this->assertStringContainsString('deleted', $metadata);
        $this->assertStringContainsString('fingerprint', $metadata);
        // 定義そのもの（manifest 本文）は監査へ書かない
        $this->assertStringNotContainsString('identity', $metadata);
        $this->assertStringNotContainsString('"manifest"', $metadata);
    }

    public function test_途中で失敗したら全部戻す(): void
    {
        $first = $this->orphan(str_repeat('a', 64));
        $second = $this->orphan(str_repeat('b', 64));

        $this->assertSame(2, DB::table(SchemaManifestRepository::TABLE)->count());

        /*
         * 2 件目の削除で落ちる状況を作る。**1 件目だけ消えた状態を残さない**
         * ことが見たいこと — 途中で止まった掃除は、報告のしようがない。
         */
        DB::listen(function ($query) use ($second): void {
            if (str_contains($query->sql, 'delete from') && in_array($second, $query->bindings, true)) {
                throw new \RuntimeException('意図的な失敗');
            }
        });

        try {
            $this->artisan('agentcms:manifest-prune', ['--apply' => true]);
            $this->fail('落ちるはずが通ってしまった');
        } catch (\Throwable) {
            // 続く
        }

        // **2 件とも残っている**
        $this->assertSame(2, DB::table(SchemaManifestRepository::TABLE)->count());
        $this->assertNotNull(DB::table(SchemaManifestRepository::TABLE)->where('fingerprint', $first)->first());
    }

    private function orphan(?string $fingerprint = null): string
    {
        $fingerprint ??= str_repeat('a', 64);

        DB::table(SchemaManifestRepository::TABLE)->insert([
            'content_type' => 'post',
            'fingerprint' => $fingerprint,
            'manifest' => '[{"identity":"title","key":"title"}]',
            'created_at' => now(),
        ]);

        return $fingerprint;
    }

    private function revision(string $type, int $id, string $fingerprint): void
    {
        DB::table(RevisionWriter::TABLE)->insert([
            'content_type' => $type, 'content_id' => $id, 'revision' => 1,
            'kind' => 'manual', 'snapshot' => '{}', 'schema_fingerprint' => $fingerprint,
            'reason' => 'baseline', 'created_interface' => 'cli', 'created_at' => now(),
        ]);
    }
}
