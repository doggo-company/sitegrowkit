<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 自動保存で増えた版の間引き（Phase 9.15 / STAGE 28C・SPEC §C.5〜C.7）。
 *
 * ここで固定するのは 3 つ:
 *
 * 1. **手で残した版を 1 件も消さない**
 * 2. 残すのは**新しいほうから N 件**
 * 3. **予告と実行がずれない**（`--dry-run` が別の式で数えない）
 */
final class PruneContentRevisionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_手で残した版は1件も消さない(): void
    {
        $this->revisions('news', 1, [
            ['revision' => 1, 'kind' => 'manual'],
            ['revision' => 2, 'kind' => 'autosave'],
            ['revision' => 3, 'kind' => 'autosave'],
            ['revision' => 4, 'kind' => 'manual'],
        ]);

        $this->artisan('agentcms:revision-prune', ['--keep' => 1])->assertExitCode(0);

        $this->assertSame(
            ['autosave' => 1, 'manual' => 2],
            $this->countsByKind('news', 1),
            'manual が消えた、または autosave が残りすぎた',
        );
    }

    public function test_新しいほうからn件だけ残す(): void
    {
        $this->revisions('news', 1, array_map(
            static fn (int $n): array => ['revision' => $n, 'kind' => 'autosave'],
            range(1, 5),
        ));

        $this->artisan('agentcms:revision-prune', ['--keep' => 2])->assertExitCode(0);

        $this->assertSame([4, 5], DB::table('content_entry_revisions')
            ->where('content_type', 'news')->where('content_id', 1)
            ->orderBy('revision')->pluck('revision')->map(intval(...))->all());
    }

    /** **記事をまたいで数えない。** 1 つの記事で N 件が上限である */
    public function test_記事ごとにn件を数える(): void
    {
        $this->revisions('news', 1, array_map(
            static fn (int $n): array => ['revision' => $n, 'kind' => 'autosave'],
            range(1, 3),
        ));
        $this->revisions('news', 2, array_map(
            static fn (int $n): array => ['revision' => $n, 'kind' => 'autosave'],
            range(1, 3),
        ));

        $this->artisan('agentcms:revision-prune', ['--keep' => 2])->assertExitCode(0);

        $this->assertSame(2, DB::table('content_entry_revisions')->where('content_id', 1)->count());
        $this->assertSame(2, DB::table('content_entry_revisions')->where('content_id', 2)->count());
    }

    /**
     * **予告と実行がずれない。**
     *
     * `--dry-run` が別の式で数えると、押した先で違う件数が消える。
     */
    public function test_dry_runは数えるだけで消さない(): void
    {
        $this->revisions('news', 1, array_map(
            static fn (int $n): array => ['revision' => $n, 'kind' => 'autosave'],
            range(1, 5),
        ));

        $this->artisan('agentcms:revision-prune', ['--keep' => 2, '--dry-run' => true])
            ->expectsOutputToContain('3 件が対象です')
            ->assertExitCode(0);

        $this->assertSame(5, DB::table('content_entry_revisions')->count(), 'dry-run で消えた');

        $this->artisan('agentcms:revision-prune', ['--keep' => 2])->assertExitCode(0);

        $this->assertSame(2, DB::table('content_entry_revisions')->count(), '予告と実行がずれた');
    }

    /**
     * **0 を「全部消す」にしない。**
     *
     * 打ち間違いで履歴が消える方向へ倒れる指定は受け取らない。
     */
    public function test_keepが0以下なら何もせず失敗する(): void
    {
        $this->revisions('news', 1, [['revision' => 1, 'kind' => 'autosave']]);

        $this->artisan('agentcms:revision-prune', ['--keep' => 0])->assertExitCode(1);

        $this->assertSame(1, DB::table('content_entry_revisions')->count());
    }

    public function test_型で絞れる(): void
    {
        $this->revisions('news', 1, array_map(
            static fn (int $n): array => ['revision' => $n, 'kind' => 'autosave'],
            range(1, 3),
        ));
        $this->revisions('post', 1, array_map(
            static fn (int $n): array => ['revision' => $n, 'kind' => 'autosave'],
            range(1, 3),
        ));

        $this->artisan('agentcms:revision-prune', ['--keep' => 1, '--type' => 'news'])->assertExitCode(0);

        $this->assertSame(1, DB::table('content_entry_revisions')->where('content_type', 'news')->count());
        $this->assertSame(3, DB::table('content_entry_revisions')->where('content_type', 'post')->count(),
            '絞ったはずの型まで消した');
    }

    /** @return array<string, int> */
    private function countsByKind(string $type, int $id): array
    {
        $counts = [];

        foreach (DB::table('content_entry_revisions')
            ->where('content_type', $type)->where('content_id', $id)->get() as $row) {
            $counts[(string) $row->kind] = ($counts[(string) $row->kind] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /** @param  list<array{revision: int, kind: string}>  $rows */
    private function revisions(string $type, int $id, array $rows): void
    {
        $fingerprint = str_repeat('a', 64);

        DB::table('content_schema_manifests')->insertOrIgnore([
            'content_type' => $type, 'fingerprint' => $fingerprint,
            'manifest' => '{}', 'created_at' => now(),
        ]);

        foreach ($rows as $row) {
            DB::table('content_entry_revisions')->insert([
                'content_type' => $type,
                'content_id' => $id,
                'revision' => $row['revision'],
                'kind' => $row['kind'],
                'snapshot' => '{}',
                'schema_fingerprint' => $fingerprint,
                'reason' => 'update',
                'created_interface' => 'admin',
                'created_by' => null,
                'created_at' => now(),
            ]);
        }
    }
}
