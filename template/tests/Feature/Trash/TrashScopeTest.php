<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashNotSupported;
use AgentCms\Core\Trash\TrashReadinessResolver;
use AgentCms\Core\Trash\TrashReadinessUnavailable;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Post\Models\Post;
use RuntimeException;
use Tests\TestCase;

/**
 * 既定除外は **モデルの側で 1 回**効く（Phase 9.8 / STAGE 21B・SPEC §C.4.1.1）。
 *
 * 面ごとに `whereNull('deleted_at')` を書かない。書けば必ずどこかで忘れ、
 * 忘れた面からゴミ箱の中身が漏れる。
 *
 * この Stage は **read だけ**である。ゴミ箱へ入れる操作はまだ無いので、
 * 「入っている状態」は列へ直接書いて作る。
 */
final class TrashScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_既定ではゴミ箱の投稿を返さない(): void
    {
        $active = $this->createPost('active-post');
        $trashed = $this->createPost('trashed-post');
        $this->putInTrash($trashed);

        $ids = Post::query()->pluck('id')->all();

        $this->assertContains($active, $ids);
        $this->assertNotContains($trashed, $ids);
    }

    public function test_件数もゴミ箱を数えない(): void
    {
        $this->createPost('one');
        $this->putInTrash($this->createPost('two'));

        // `count()` がそのまま正しい（join を足さない — SPEC §C.3）
        $this->assertSame(1, Post::query()->count());
        $this->assertSame(2, DB::table('post')->count());
    }

    public function test_主キーで直接引いてもゴミ箱は見つからない(): void
    {
        $trashed = $this->createPost('gone');
        $this->putInTrash($trashed);

        // 「ID を知っていれば読める」を残さない
        $this->assertNull(Post::query()->find($trashed));
    }

    public function test_明示すればゴミ箱も読める(): void
    {
        $trashed = $this->createPost('kept');
        $this->putInTrash($trashed);

        $found = Post::query()->withTrashed()->find($trashed);

        $this->assertNotNull($found);
        $this->assertNotNull($found->getAttribute(TrashColumns::DELETED_AT));
    }

    public function test_ゴミ箱だけを読める(): void
    {
        $active = $this->createPost('still-here');
        $trashed = $this->createPost('in-trash');
        $this->putInTrash($trashed);

        $ids = Post::query()->onlyTrashed()->pluck('id')->all();

        $this->assertSame([$trashed], $ids);
        $this->assertNotContains($active, $ids);
    }

    public function test_ゴミ箱を含めた読みから既定へ戻せる(): void
    {
        $active = $this->createPost('a');
        $this->putInTrash($this->createPost('b'));

        $ids = Post::query()->withTrashed()->activeOnly()->pluck('id')->all();

        $this->assertSame([$active], $ids);
    }

    public function test_未対応の型では通常の読み取りが素通りする(): void
    {
        $id = $this->createPost('before-migration');
        $this->dropTrashColumns();
        $this->app->forgetScopedInstances();

        // 列が無い以上ゴミ箱の行は存在し得ない。一覧を落とさない（SPEC §C.4.1）
        $this->assertSame([$id], Post::query()->pluck('id')->all());
    }

    public function test_未対応の型でゴミ箱一覧は空を返さず未対応と言う(): void
    {
        $this->createPost('before-migration');
        $this->dropTrashColumns();
        $this->app->forgetScopedInstances();

        /*
         * 「ゴミ箱は空だった」と「この型にゴミ箱はまだ無い」は別のことである。
         * 空の一覧を返すと、利用者は前者だと読む。
         */
        $this->expectException(TrashNotSupported::class);
        Post::query()->onlyTrashed()->get();
    }

    public function test_判定不能なら読み取りを止める(): void
    {
        $this->createPost('unknown-state');
        $this->breakSchemaInspection();

        $this->expectException(TrashReadinessUnavailable::class);
        Post::query()->get();
    }

    public function test_判定不能ならゴミ箱を含む読みも止める(): void
    {
        $this->createPost('unknown-state');
        $this->breakSchemaInspection();

        $this->expectException(TrashReadinessUnavailable::class);
        Post::query()->withTrashed()->get();
    }

    public function test_ゴミ箱の対象でない型には列も除外も無い(): void
    {
        // media / contact / site はゴミ箱を持たない（SPEC §A）
        foreach (['media', 'contact_submissions', 'site_settings'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (TrashColumns::ALL as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "{$table} にゴミ箱の列 {$column} が増えている",
                );
            }
        }
    }

    private function createPost(string $slug): int
    {
        $post = Post::query()->create([
            'title' => $slug,
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        return (int) $post->getKey();
    }

    /** ゴミ箱へ入れる操作は STAGE 21C。ここでは状態だけを作る */
    private function putInTrash(int $id): void
    {
        DB::table('post')->where('id', $id)->update([
            TrashColumns::DELETED_AT => now(),
            TrashColumns::TRASHED_BY => 1,
        ]);
    }

    private function dropTrashColumns(): void
    {
        Schema::table('post', function ($table): void {
            $table->dropIndex(['deleted_at']);
        });

        Schema::table('post', function ($table): void {
            $table->dropColumn(TrashColumns::ALL);
        });
    }

    /** 列の検査だけを失敗させる（他のテーブルは読めるまま） */
    private function breakSchemaInspection(): void
    {
        $schema = new class(app('db')->connection()) extends SchemaBuilder
        {
            public function hasColumn($table, $column)
            {
                throw new RuntimeException('接続できません');
            }
        };

        $this->app->instance(TrashReadinessResolver::class, new TrashReadinessResolver(
            app(SchemaRegistry::class),
            $schema,
        ));
    }
}
