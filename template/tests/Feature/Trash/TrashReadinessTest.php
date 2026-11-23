<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashNotSupported;
use AgentCms\Core\Trash\TrashReadiness;
use AgentCms\Core\Trash\TrashReadinessResolver;
use AgentCms\Core\Trash\TrashReadinessUnavailable;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Post\Models\Post;
use RuntimeException;
use Tests\TestCase;

/**
 * ゴミ箱の対応状況を **1 か所で** 3 値に解決する（Phase 9.8 / STAGE 21B・SPEC §C.4.1）。
 *
 * ここで守りたいのは「分からない」を「対応していない」に倒さないことである。
 * 倒すと、ゴミ箱に入れたはずの投稿を公開してしまう。
 */
final class TrashReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_列があれば対応済みとして扱う(): void
    {
        $resolver = app(TrashReadinessResolver::class);

        $this->assertTrue(Schema::hasColumn('post', TrashColumns::DELETED_AT));
        $this->assertSame(TrashReadiness::Ready, $resolver->readiness('post'));
        $this->assertTrue($resolver->supports('post'));
    }

    public function test_列が無いと確認できた型は未対応として扱う(): void
    {
        $this->dropTrashColumns();

        $readiness = app(TrashReadinessResolver::class)->readiness('post');

        $this->assertSame(TrashReadiness::Unmigrated, $readiness);
        // 読み取りは続く（trashed の行が存在し得ないため）
        $this->assertTrue($readiness->allowsReads());
        // 書き込みは止める
        $this->assertFalse($readiness->allowsWrites());
    }

    public function test_確認に失敗したら判定不能にする(): void
    {
        $readiness = $this->withFailingSchemaInspection()->readiness('post');

        $this->assertSame(TrashReadiness::Indeterminate, $readiness);
        // **未対応に倒さない。** 倒すとゴミ箱の投稿を公開してしまう
        $this->assertNotSame(TrashReadiness::Unmigrated, $readiness);
        $this->assertFalse($readiness->allowsReads());
        $this->assertFalse($readiness->allowsWrites());
    }

    public function test_投稿タイプでないものは対象にしない(): void
    {
        $resolver = app(TrashReadinessResolver::class);

        // media / contact / site はゴミ箱の対象外（SPEC §A）。DB を見に行きもしない
        foreach (['media', 'contact', 'site'] as $facility) {
            $this->assertSame(TrashReadiness::Unmigrated, $resolver->readiness($facility));
        }

        $this->assertSame(0, $resolver->lookupCount(), '対象外の型で DB を見に行っている');
    }

    public function test_1つの要求では型ごとに1度だけ確かめる(): void
    {
        $resolver = app(TrashReadinessResolver::class);

        for ($i = 0; $i < 5; $i++) {
            $resolver->readiness('post');
        }

        $this->assertSame(1, $resolver->lookupCount());

        $resolver->readiness('news');
        $this->assertSame(2, $resolver->lookupCount());
    }

    public function test_要求をまたいで覚えない(): void
    {
        $first = app(TrashReadinessResolver::class);
        $first->readiness('post');

        // 要求の切れ目（Octane や queue worker がここを呼ぶ）
        $this->app->forgetScopedInstances();

        $second = app(TrashReadinessResolver::class);

        // singleton にすると、migration で列が増えても古い判定を返し続ける
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $second->lookupCount(), '前の要求の記憶が残っている');
    }

    public function test_route登録では対応状況を確かめない(): void
    {
        /*
         * boot 中に DB を読むと、DB が落ちているだけで **全 URL が消える**。
         * route を作り直しても検査が 1 回も走らないことを実測する。
         */
        $resolver = app(TrashReadinessResolver::class);

        Route::getRoutes()->refreshNameLookups();
        $this->assertSame(0, $resolver->lookupCount());
    }

    public function test_ゴミ箱を扱う入口は未対応と判定不能を区別して止める(): void
    {
        $resolver = app(TrashReadinessResolver::class);
        $resolver->assertUsable('post');

        $this->dropTrashColumns();

        // 判定は要求のあいだだけ覚える。次の要求では列が消えたことに気づく
        $this->app->forgetScopedInstances();

        $this->expectException(TrashNotSupported::class);
        app(TrashReadinessResolver::class)->assertUsable('post');
    }

    public function test_判定不能ならゴミ箱の入口は別の例外で止まる(): void
    {
        $this->expectException(TrashReadinessUnavailable::class);

        $this->withFailingSchemaInspection()->assertUsable('post');
    }

    public function test_利用者へ出す説明に原因の詳細を含めない(): void
    {
        foreach ([TrashReadiness::Unmigrated, TrashReadiness::Indeterminate] as $readiness) {
            $message = (string) $readiness->message();

            $this->assertNotSame('', $message);

            foreach (['SQLSTATE', 'select ', 'PDO', '/var/www', 'password'] as $leak) {
                $this->assertStringNotContainsString($leak, $message);
            }
        }
    }

    public function test_判定不能の投稿でも一覧の既定除外は空を返さない(): void
    {
        Post::query()->create($this->postAttributes());

        $this->app->forgetInstance(TrashReadinessResolver::class);
        $this->withFailingSchemaInspection(bind: true);

        // **0 件ではなく例外**。空の成功は「中身が消えた」に見える
        $this->expectException(TrashReadinessUnavailable::class);
        Post::query()->count();
    }

    /**
     * `post` からゴミ箱の列を落とす（migration 前の site を再現する）。
     *
     * index を先に外す。SQLite は index の張られた列をそのままでは落とせない。
     */
    private function dropTrashColumns(): void
    {
        Schema::table('post', function ($table): void {
            $table->dropIndex(['deleted_at']);
        });

        Schema::table('post', function ($table): void {
            $table->dropColumn(TrashColumns::ALL);
        });
    }

    /** @return array<string, mixed> */
    private function postAttributes(): array
    {
        return [
            'title' => 'テスト投稿',
            'slug' => 'test-post',
            'body' => '<p>本文</p>',
            'status' => 'draft',
        ];
    }

    /**
     * 列の検査が必ず失敗する resolver。
     *
     * 実際に DB を落とす代わりに、検査だけを失敗させる（他のテーブルは読めるまま）。
     */
    private function withFailingSchemaInspection(bool $bind = false): TrashReadinessResolver
    {
        $schema = new class(app('db')->connection()) extends SchemaBuilder
        {
            public function hasColumn($table, $column)
            {
                throw new RuntimeException('接続できません');
            }
        };

        $resolver = new TrashReadinessResolver(app(SchemaRegistry::class), $schema);

        if ($bind) {
            $this->app->instance(TrashReadinessResolver::class, $resolver);
        }

        return $resolver;
    }
}
