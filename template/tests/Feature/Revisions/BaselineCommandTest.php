<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Revisions\RevisionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 版が無い既存の投稿へ初版を作る（Phase 9.8 / STAGE 21D・SPEC §F.3.2）。
 */
final class BaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_版が無い投稿へ初版を作る(): void
    {
        $id = $this->entry('first');

        $this->assertSame(0, $this->revisionCount('post', $id));

        $this->artisan('agentcms:content-baseline')->assertSuccessful();

        $row = DB::table(RevisionWriter::TABLE)->where('content_type', 'post')->where('content_id', $id)->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->revision);
        // **`create` と書かない。** 実際の作成操作ではない
        $this->assertSame('baseline', $row->reason);
        $this->assertSame('manual', $row->kind);
        $this->assertSame('cli', $row->created_interface);
    }

    public function test_何度流しても壊れない(): void
    {
        $id = $this->entry('idempotent');

        $this->artisan('agentcms:content-baseline')->assertSuccessful();
        $this->artisan('agentcms:content-baseline')->assertSuccessful();
        $this->artisan('agentcms:content-baseline')->assertSuccessful();

        $this->assertSame(1, $this->revisionCount('post', $id));
    }

    public function test_既に版がある投稿は飛ばす(): void
    {
        $id = $this->entry('has-history');

        // 通常の更新が先に版を作っている状態
        $this->artisan('agentcms:content-baseline')->assertSuccessful();
        $before = DB::table(RevisionWriter::TABLE)->where('content_id', $id)->value('id');

        $this->artisan('agentcms:content-baseline')->assertSuccessful();

        $this->assertSame(1, $this->revisionCount('post', $id));
        $this->assertSame($before, DB::table(RevisionWriter::TABLE)->where('content_id', $id)->value('id'));
    }

    public function test_件数を区切って再開できる(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->entry('bounded-'.$i);
        }

        $this->artisan('agentcms:content-baseline', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(2, DB::table(RevisionWriter::TABLE)->count());

        // 再開位置は **型と ID の組**。ID だけだと型が変わったときに低い ID を飛ばす
        $this->artisan('agentcms:content-baseline', [
            '--after-type' => 'post', '--after-id' => $ids[1], '--limit' => 10,
        ])->assertSuccessful();

        $this->assertSame(5, DB::table(RevisionWriter::TABLE)->count());
    }

    public function test_型を絞れる(): void
    {
        $post = $this->entry('only-post');
        $news = DB::table('news')->insertGetId([
            'title' => 'ニュース', 'slug' => 'news-1', 'body' => '<p>b</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('agentcms:content-baseline', ['--type' => 'post'])->assertSuccessful();

        $this->assertSame(1, $this->revisionCount('post', $post));
        $this->assertSame(0, $this->revisionCount('news', $news));
    }

    public function test_残り件数を書き込まずに数えられる(): void
    {
        $this->entry('status-check');

        $this->artisan('agentcms:content-baseline', ['--status' => true])
            ->expectsOutputToContain('版が無い投稿')
            ->assertSuccessful();

        // **1 行も書かない**
        $this->assertSame(0, DB::table(RevisionWriter::TABLE)->count());
    }

    public function test_完了すると残りが0になる(): void
    {
        $this->entry('done-1');
        $this->entry('done-2');

        $this->artisan('agentcms:content-baseline')
            ->expectsOutputToContain('残り 0 件（完了）')
            ->assertSuccessful();
    }

    public function test_ゴミ箱の投稿にも初版を作る(): void
    {
        $id = $this->entry('trashed');
        DB::table('post')->where('id', $id)->update(['deleted_at' => now()]);

        $this->artisan('agentcms:content-baseline')->assertSuccessful();

        // 戻したときに履歴が無い、を作らない
        $this->assertSame(1, $this->revisionCount('post', $id));
    }

    public function test_監査には件数と範囲だけを残す(): void
    {
        $this->entry('audited');

        $this->artisan('agentcms:content-baseline')->assertSuccessful();

        $row = DB::table('audit_logs')->where('operation', 'content.revision.baseline')->first();

        $this->assertNotNull($row);

        $metadata = (string) $row->metadata;

        // snapshot の中身を監査へ入れない（版と監査を混ぜない）
        $this->assertStringNotContainsString('snapshot', $metadata);
        $this->assertStringNotContainsString('本文', $metadata);
        $this->assertStringContainsString('written', $metadata);
        $this->assertStringContainsString('remaining', $metadata);
    }

    private function revisionCount(string $type, int $id): int
    {
        return DB::table(RevisionWriter::TABLE)
            ->where('content_type', $type)->where('content_id', $id)->count();
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug, 'slug' => $slug, 'body' => '<p>本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
