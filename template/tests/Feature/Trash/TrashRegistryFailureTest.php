<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Schema\SchemaFieldOverlay;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use AgentCms\Core\Trash\TrashReadiness;
use AgentCms\Core\Trash\TrashReadinessResolver;
use App\Models\User;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * DB 障害は **どこで起きても** typed な「判定不能」になる（Phase 9.8 / STAGE 21B 是正）。
 *
 * 列の検査だけを守っても足りない。**その手前**で effective schema を組み立てる
 * ときに Field Group の overlay が DB を読み、そこで落ちることがある。
 * そのまま素通りさせると、503 でも service-unavailable でもない **500** になり、
 * 「今は読めない。少ししてから」と伝える経路を丸ごと迂回してしまう。
 *
 * 分類そのものは **ファイル宣言だけ**で決められる（overlay は型を増やさない）ので、
 * 投稿タイプでない型では DB を 1 度も見ない、という契約も同時に守る。
 */
final class TrashRegistryFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlayが落ちても判定はできる(): void
    {
        $this->breakOverlay();

        /*
         * **ここが落ちないことが要点である。**
         *
         * 以前は `SchemaRegistry::has()` / `get()`（= effective schema、overlay 込み）を
         * try/catch の外で呼んでいたので、overlay の DB 障害がそのまま素通りし、
         * typed な readiness に一度も到達しないまま generic 500 になり得た。
         *
         * 分類をファイル宣言だけで行うようにしたので、overlay が落ちていても
         * 判定そのものは成立する（列は実在するので `ready`）。
         */
        $readiness = app(TrashReadinessResolver::class)->readiness('post');

        $this->assertSame(TrashReadiness::Ready, $readiness);
    }

    public function test_判定はoverlayを一度も呼ばない(): void
    {
        $overlay = new class implements SchemaFieldOverlay
        {
            public int $calls = 0;

            public function fieldsFor(string $schemaId): array
            {
                $this->calls++;

                return [];
            }
        };

        $this->app->forgetScopedInstances();
        $this->app->instance(SchemaFieldOverlay::class, $overlay);
        $this->app->forgetInstance(SchemaRegistry::class);

        $resolver = app(TrashReadinessResolver::class);

        foreach (['post', 'media', 'contact', 'news'] as $type) {
            $resolver->readiness($type);
        }

        // overlay は DB を読む。判定のためにそこへ触れない
        $this->assertSame(0, $overlay->calls, '判定が effective schema を組み立てている');
    }

    public function test_列の検査が落ちたときだけ判定不能になる(): void
    {
        $this->breakColumnInspection();

        $readiness = app(TrashReadinessResolver::class)->readiness('post');

        $this->assertSame(TrashReadiness::Indeterminate, $readiness);
        // 「対応していない」に倒すとゴミ箱の投稿を公開してしまう
        $this->assertNotSame(TrashReadiness::Unmigrated, $readiness);
    }

    public function test_ファイル宣言の読み取りが落ちても判定不能で受け止める(): void
    {
        // 壊れた定義ファイルを置いた場所を見せる（`declared()` がそこで投げる）
        $broken = sys_get_temp_dir().'/agentcms-broken-schemas-'.bin2hex(random_bytes(4));
        mkdir($broken);
        file_put_contents($broken.'/post.json', '{ this is not json');

        try {
            $resolver = new TrashReadinessResolver(
                new SchemaRegistry($broken),
                app('db')->connection()->getSchemaBuilder(),
            );

            // 判定の**全体**が守られている（列の検査だけではない）
            $this->assertSame(TrashReadiness::Indeterminate, $resolver->readiness('post'));
        } finally {
            unlink($broken.'/post.json');
            rmdir($broken);
        }
    }

    public function test_投稿タイプでない型はoverlayが落ちても影響を受けない(): void
    {
        $this->breakOverlay();

        $resolver = app(TrashReadinessResolver::class);

        /*
         * 分類はファイル宣言だけで決まる（overlay は型を増やさない）。
         * だから overlay が落ちていても、対象外の判定はそのまま返せる。
         */
        foreach (['media', 'contact', 'site'] as $facility) {
            $this->assertSame(TrashReadiness::Unmigrated, $resolver->readiness($facility));
        }

        $this->assertSame(0, $resolver->lookupCount(), '対象外の型で DB を見に行っている');
    }

    public function test_判定不能でも同じ答えを返し続ける(): void
    {
        $this->breakColumnInspection();

        $resolver = app(TrashReadinessResolver::class);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(TrashReadiness::Indeterminate, $resolver->readiness('post'));
        }

        // 判定不能でも要求のあいだは 1 回しか確かめない（毎回 DB を叩かない）
        $this->assertSame(1, $resolver->lookupCount());
    }

    public function test_列の検査が落ちれば公開面もapiも503になる(): void
    {
        $user = User::factory()->create();
        $this->entry('kept');
        $this->breakColumnInspection();

        $public = $this->get('/');
        $public->assertStatus(503);
        $public->assertHeader('Retry-After');

        $api = $this->actingAs($user)->getJson('/api/v1/content/post');
        $api->assertStatus(503);
        $api->assertHeader('Retry-After');
        $this->assertStringNotContainsString('SQLSTATE', (string) $api->getContent());
    }

    /** 列の検査だけを失敗させる（DB そのものは読めるまま） */
    private function breakColumnInspection(): void
    {
        $schema = new class(app('db')->connection()) extends Builder
        {
            public function hasColumn($table, $column)
            {
                throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }
        };

        $this->app->instance(TrashReadinessResolver::class, new TrashReadinessResolver(
            app(SchemaRegistry::class),
            $schema,
        ));
    }

    /**
     * Field Group の overlay が DB 障害を投げる状態を作る。
     *
     * `SchemaRegistry` は effective schema を組み立てるときに overlay を呼ぶので、
     * `has()` / `get()` がそのまま落ちるようになる。
     */
    private function breakOverlay(): void
    {
        $this->app->forgetScopedInstances();

        $this->app->bind(SchemaFieldOverlay::class, fn () => new class implements SchemaFieldOverlay
        {
            public function fieldsFor(string $schemaId): array
            {
                throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }
        });

        // registry も作り直させる（既に組み立て済みの effective schema を捨てる）
        $this->app->forgetInstance(SchemaRegistry::class);
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug,
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            TrashColumns::DELETED_AT => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
