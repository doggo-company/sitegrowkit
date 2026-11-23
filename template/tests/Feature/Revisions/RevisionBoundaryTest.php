<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\RestoreContent;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Revisions\RevisionSnapshot;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 版を **作る境界と作らない境界**（Phase 9.8 / STAGE 21D・OG7 = (c)）。
 *
 * 守りたいのは 1 つの約束だけである。
 *
 *   **現在の内容 = 常に最新の版**
 *
 * これが成り立っていれば「戻す前の状態」は既に記録されているので、
 * 復元の前に版を作る必要が無い。崩れると、復元が信用できなくなる。
 */
final class RevisionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_作成で1件できる(): void
    {
        $id = $this->create('はじめて');

        $rows = $this->revisions('post', $id);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->revision);
        $this->assertSame('create', $rows[0]->reason);
    }

    public function test_中身が変わった更新で1件増える(): void
    {
        $id = $this->create('もと');

        $this->update($id, ['title' => 'あと']);

        $rows = $this->revisions('post', $id);

        $this->assertCount(2, $rows);
        $this->assertSame('update', $rows[1]->reason);
    }

    public function test_中身が同じ更新では増えない(): void
    {
        $id = $this->create('そのまま');

        // 「保存を押しただけ」で版を作らない（OG7 = (c)）
        $this->update($id, ['title' => 'そのまま']);

        $this->assertCount(1, $this->revisions('post', $id));
    }

    public function test_公開したら理由がpublishになる(): void
    {
        $id = $this->create('公開する', 'draft');

        $this->update($id, ['status' => 'published', 'published_at' => now()->subDay()->toDateTimeString()]);

        $rows = $this->revisions('post', $id);

        $this->assertCount(2, $rows);
        $this->assertSame('publish', $rows[1]->reason);
    }

    public function test_下書きへ戻したら理由がunpublishになる(): void
    {
        $id = $this->create('下書きへ', 'published');

        $this->update($id, ['status' => 'draft']);

        $rows = $this->revisions('post', $id);

        $this->assertSame('unpublish', end($rows)->reason);
    }

    public function test_ゴミ箱へ移しても版は増えない(): void
    {
        $id = $this->create('捨てる');

        $this->trash($id);

        // 保管状態の変化は内容の変化ではない。履歴に混ぜない
        $this->assertCount(1, $this->revisions('post', $id));
    }

    public function test_公開状態が変わらない復元では版は増えない(): void
    {
        $id = $this->create('もともと下書き', 'draft');
        $this->trash($id);

        app(RestoreContent::class)->execute('post', $id, $this->context(), 1);

        // 既に `draft` だったので、戻しても中身は変わらない
        $this->assertCount(1, $this->revisions('post', $id));
    }

    public function test_下書きへ戻す復元では版が1件増える(): void
    {
        $id = $this->create('公開中だった', 'published');
        $this->trash($id);

        $result = app(RestoreContent::class)->execute('post', $id, $this->context(), 1);

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $rows = $this->revisions('post', $id);

        // `status` は field なので、`draft` へ戻すのは内容の変化である
        $this->assertCount(2, $rows);
        $this->assertSame('unpublish', $rows[1]->reason);
    }

    public function test_失敗した操作では版を作らない(): void
    {
        $id = $this->create('失敗しても');
        $before = count($this->revisions('post', $id));

        // 権限なし
        $viewer = User::factory()->create(['role' => 'viewer']);
        app(UpdateContent::class)->execute('post', $id, ['title' => 'x'],
            OperationContext::admin((int) $viewer->getKey(), (string) $viewer->name), partial: true);

        // 古い版（競合）
        app(UpdateContent::class)->execute('post', $id, ['title' => 'y'], $this->context(), partial: true, lockVersion: 99);

        // 入力エラー
        app(UpdateContent::class)->execute('post', $id, ['status' => 'ありえない値'], $this->context(), partial: true);

        // 確認待ち（ゴミ箱への移動の 1 回目）
        app(TrashContent::class)->execute('post', $id, $this->context());

        $this->assertCount($before, $this->revisions('post', $id));
    }

    public function test_いつでも現在の内容と最新版が一致する(): void
    {
        $id = $this->create('不変条件');

        $this->update($id, ['title' => '1 回目']);
        $this->update($id, ['title' => '2 回目']);
        $this->update($id, ['status' => 'published', 'published_at' => now()->subDay()->toDateTimeString()]);

        $this->assertLatestMatchesCurrent('post', $id);
    }

    public function test_分類の割り当ても版に含まれる(): void
    {
        $id = DB::table('news')->insertGetId([
            'title' => '分類つき', 'slug' => 'with-terms', 'body' => '<p>b</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app('db')->table('taxonomies')->insert([
            'id' => '01JTAXONOMYAAAAAAAAAAAAAAA', 'key' => 'news_category', 'label' => '分類',
            'hierarchical' => true, 'managed_by' => 'code', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxonomy_terms')->insert([
            'id' => '01JTERMAAAAAAAAAAAAAAAAAAA', 'taxonomy_id' => '01JTAXONOMYAAAAAAAAAAAAAAA',
            'name' => 'お知らせ', 'slug' => 'oshirase', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => '01JTERMAAAAAAAAAAAAAAAAAAA', 'content_type' => 'news', 'content_id' => $id,
            'sort_order' => 0, 'created_at' => now(),
        ]);

        $this->artisan('agentcms:content-baseline', ['--type' => 'news'])->assertSuccessful();

        $snapshot = json_decode((string) $this->revisions('news', $id)[0]->snapshot, true);

        $this->assertSame(
            ['news_category' => ['01JTERMAAAAAAAAAAAAAAAAAAA']],
            $snapshot['taxonomies'],
        );
    }

    public function test_保存済みのキー順が違っても同じ内容として扱う(): void
    {
        $id = $this->create('キー順');

        /*
         * **MySQL の `json` 型はキー順を並べ替えて保存する。** 読み戻した snapshot を
         * そのまま比べると「内容が変わった」と誤判定し、保存を押すたびに
         * 中身の同じ版が増えていく。SQLite では起きないので、ここで作って確かめる。
         */
        $row = $this->revisions('post', $id)[0];
        $snapshot = json_decode((string) $row->snapshot, true);

        $columns = $snapshot['columns'];
        $keys = array_keys($columns);
        $keys = array_reverse($keys);
        $snapshot['columns'] = array_merge(array_flip($keys), $columns);

        DB::table(RevisionWriter::TABLE)->where('id', $row->id)
            ->update(['snapshot' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);

        // 中身は同じなので版は増えない
        $this->update($id, ['title' => 'キー順']);

        $this->assertCount(1, $this->revisions('post', $id));
    }

    public function test_snapshotに秘密や個人情報を入れない(): void
    {
        $id = $this->create('内緒');

        $snapshot = (string) $this->revisions('post', $id)[0]->snapshot;

        foreach ([(string) $this->owner->email, '127.0.0.1', 'password', 'token', 'secret'] as $leak) {
            $this->assertStringNotContainsString($leak, $snapshot);
        }

        $decoded = json_decode($snapshot, true);

        /*
         * 入っているのは内容と、表示用の時刻だけ。
         *
         * **鍵の集合を見る。並びは見ない** — MySQL の JSON 列はキー順を正規化するので、
         * 保存した順では返らない。ここが決めているのは「**何を入れるか**」である。
         */
        $this->assertEqualsCanonicalizing(
            ['snapshot_version', 'content_type', 'columns', 'data', 'taxonomies', 'entry_created_at', 'entry_updated_at'],
            array_keys($decoded),
        );
    }

    /** 現在の内容と最新の版が一致していること（列・data・分類まで） */
    private function assertLatestMatchesCurrent(string $type, int $id): void
    {
        $schema = app(SchemaRegistry::class)->get($type);
        $model = app(ContentTypeResolver::class)->newModel($type)->newQuery()->find($id);

        $this->assertNotNull($model);

        $latest = app(RevisionWriter::class)->latest($type, $id);

        $this->assertNotNull($latest);
        $this->assertTrue(
            RevisionSnapshot::sameContent($latest['snapshot'], RevisionSnapshot::of($schema, $type, $model)),
            '現在の内容と最新版が食い違っている',
        );
    }

    /** @return list<object> */
    private function revisions(string $type, int $id): array
    {
        return DB::table(RevisionWriter::TABLE)
            ->where('content_type', $type)->where('content_id', $id)
            ->orderBy('revision')->get()->all();
    }

    private function create(string $title, string $status = 'draft'): int
    {
        $result = app(CreateContent::class)->execute('post', [
            'title' => $title, 'body' => '<p>本文</p>', 'status' => $status,
            'published_at' => $status === 'published' ? now()->subDay()->toDateTimeString() : null,
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return (int) $result->data['item']['id'];
    }

    /** @param array<string, mixed> $attributes */
    private function update(int $id, array $attributes): void
    {
        $result = app(UpdateContent::class)->execute('post', $id, $attributes, $this->context(), partial: true);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function trash(int $id): void
    {
        $token = (string) app(TrashContent::class)->execute('post', $id, $this->context())->confirmation?->token;
        $result = app(TrashContent::class)->execute('post', $id, $this->context()->withConfirmationToken($token));

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertNotNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }
}
