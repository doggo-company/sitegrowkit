<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / #14 — 分類 API **7 route**（SPEC §Q.1）。
 *
 * `api/v1` は同一オリジン + セッションの操作面であり、公開ヘッドレス API ではない。
 * ここで固定するのは **route が Application Service まで実際に到達すること**と、
 * status / error / 認証・認可の契約。
 *
 * 業務ルール（slug 正規化・階層・archive・削除制約）は
 * `TaxonomyApplicationServiceTest` の責務であり、ここでは重複させない。
 */
final class TaxonomyApiTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 明示ステップとしての同期（runtime の暗黙 write ではない）
        app(TaxonomySynchronizer::class)->sync();
    }

    // ─────────────────────────────────────────────────────────────
    // 認証 / 認可
    // ─────────────────────────────────────────────────────────────

    public function test_未認証では7つのrouteすべてが拒否される(): void
    {
        $term = $this->makeTerm('press');

        $this->getJson('/api/v1/taxonomies')->assertUnauthorized();
        $this->getJson('/api/v1/taxonomies/news_category')->assertUnauthorized();
        $this->getJson('/api/v1/taxonomies/news_category/terms')->assertUnauthorized();
        $this->postJson('/api/v1/taxonomies/news_category/terms', ['name' => 'x'])->assertUnauthorized();
        $this->postJson('/api/v1/taxonomies/news_category/terms/reorder', [
            'orderedTermIds' => [$term->id],
        ])->assertUnauthorized();
        $this->patchJson("/api/v1/terms/{$term->id}", ['name' => 'x'])->assertUnauthorized();
        $this->postJson("/api/v1/terms/{$term->id}/delete")->assertUnauthorized();

        // 未認証のリクエストで DB が変わらない
        $this->assertSame(1, TaxonomyTerm::query()->count());
        $this->assertSame('press', (string) $term->fresh()?->name);
    }

    /** 既存 api/v1 と同じ middleware 群の中にある（独自の認証を作っていない） */
    public function test_既存apiと同じ認証middlewareの中にある(): void
    {
        $names = [
            'api.v1.taxonomies.index', 'api.v1.taxonomies.show', 'api.v1.taxonomies.terms.index',
            'api.v1.taxonomies.terms.store', 'api.v1.taxonomies.terms.reorder',
            'api.v1.terms.update', 'api.v1.terms.delete',
        ];

        $content = app('router')->getRoutes()->getByName('api.v1.content.index');
        $this->assertNotNull($content);

        foreach ($names as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} が無い");
            $this->assertSame(
                $content->gatherMiddleware(),
                $route->gatherMiddleware(),
                "{$name} の middleware が既存 api/v1 と違う",
            );
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 1. taxonomy.list / 2. taxonomy.get
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomy_listが一覧を返す(): void
    {
        $this->makeTerm('press');

        $response = $this->actingAs($this->admin())->getJson('/api/v1/taxonomies');

        $response->assertOk()
            ->assertJsonPath('meta.operation', 'taxonomy.list')
            ->assertJsonPath('meta.interface', 'api')
            ->assertJsonPath('data.0.key', 'news_category')
            ->assertJsonPath('data.0.termCount', 1)
            ->assertJsonStructure(['data' => [['id', 'key', 'label', 'hierarchical', 'managedBy']]]);
    }

    public function test_taxonomy_getがkeyで1件返す(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/taxonomies/news_category');

        $response->assertOk()
            ->assertJsonPath('meta.operation', 'taxonomy.get')
            ->assertJsonPath('data.key', 'news_category')
            ->assertJsonPath('data.label', 'カテゴリ');
    }

    public function test_taxonomy_getは未知のkeyを404にする(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/taxonomies/does_not_exist');

        $response->assertNotFound()->assertJsonStructure(['message']);
        $this->assertLeakFree($response->getContent());
    }

    // ─────────────────────────────────────────────────────────────
    // 3. term.list
    // ─────────────────────────────────────────────────────────────

    public function test_term_listは既定でarchivedを含めない(): void
    {
        $active = $this->makeTerm('press');
        $archived = $this->makeTerm('old');
        $archived->forceFill(['archived_at' => now()])->save();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/taxonomies/news_category/terms');

        $response->assertOk()
            ->assertJsonPath('meta.operation', 'term.list')
            ->assertJsonPath('meta.includeArchived', false)
            ->assertJsonPath('meta.treeComplete', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_term_listはinclude_archivedでarchivedも返す(): void
    {
        $this->makeTerm('press');
        $this->makeTerm('old')->forceFill(['archived_at' => now()])->save();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/taxonomies/news_category/terms?includeArchived=1');

        $response->assertOk()
            ->assertJsonPath('meta.includeArchived', true)
            ->assertJsonPath('meta.treeComplete', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_term_listは未知のtaxonomyを404にする(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/taxonomies/does_not_exist/terms')
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // 4. term.create
    // ─────────────────────────────────────────────────────────────

    public function test_term_createが項目を作る(): void
    {
        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms',
            ['name' => 'プレスリリース', 'slug' => 'press'],
        );

        $response->assertOk()
            ->assertJsonPath('meta.operation', 'term.create')
            ->assertJsonPath('meta.taxonomyKey', 'news_category')
            ->assertJsonPath('data.name', 'プレスリリース')
            ->assertJsonPath('data.slug', 'press')
            ->assertJsonPath('data.archived', false);

        $this->assertSame(1, TaxonomyTerm::query()->count());
        $this->assertDatabaseHas('audit_logs', ['operation' => 'term.create']);
    }

    public function test_term_createは_u_r_lのkeyを分類の指定に使う(): void
    {
        $other = $this->makeTaxonomy('other_tax');

        // body の taxonomyKey ではなく **URL の {key}** が使われる
        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms',
            ['name' => 'x', 'taxonomyKey' => 'other_tax'],
        );

        $response->assertOk()->assertJsonPath('meta.taxonomyKey', 'news_category');

        $this->assertSame(0, TaxonomyTerm::query()->where('taxonomy_id', $other->id)->count());
    }

    public function test_term_createのvalidation_errorは422(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/taxonomies/news_category/terms', ['name' => '']);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->assertSame(0, TaxonomyTerm::query()->count());
        $this->assertLeakFree($response->getContent());
    }

    public function test_term_createは未知キーを拒否する(): void
    {
        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms',
            ['name' => 'x', 'sortOrder' => 3],
        );

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['sortOrder']]);
    }

    public function test_term_createはslug重複を422にする(): void
    {
        $this->makeTerm('press');

        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms',
            ['name' => '別名', 'slug' => 'press'],
        );

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['slug']]);
        $this->assertSame(1, TaxonomyTerm::query()->count());
    }

    public function test_term_createは未知のtaxonomyを404にする(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/taxonomies/does_not_exist/terms', ['name' => 'x'])
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // 5. term.update（archive / unarchive を含む）
    // ─────────────────────────────────────────────────────────────

    public function test_term_updateが名前を変更する(): void
    {
        $term = $this->makeTerm('press');

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/terms/{$term->id}", ['name' => '新しい名前']);

        $response->assertOk()
            ->assertJsonPath('meta.operation', 'term.update')
            ->assertJsonPath('data.name', '新しい名前')
            ->assertJsonPath('data.id', $term->id);
    }

    public function test_term_updateがarchiveと解除を行う(): void
    {
        $term = $this->makeTerm('press');
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/v1/terms/{$term->id}", ['archived' => true])
            ->assertOk()
            ->assertJsonPath('data.archived', true);

        $this->actingAs($admin)->patchJson("/api/v1/terms/{$term->id}", ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.archived', false);
    }

    public function test_term_updateは未知のtermを404にする(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/v1/terms/'.TaxonomyId::generate(), ['name' => 'x'])
            ->assertNotFound();
    }

    public function test_term_updateは未知キーを422にする(): void
    {
        $term = $this->makeTerm('press');

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/terms/{$term->id}", ['taxonomyKey' => 'other_tax'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['taxonomyKey']]);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. term.reorder
    // ─────────────────────────────────────────────────────────────

    public function test_term_reorderが並び順を入れ替える(): void
    {
        $a = $this->makeTerm('a', sortOrder: 0);
        $b = $this->makeTerm('b', sortOrder: 1);

        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms/reorder',
            ['orderedTermIds' => [$b->id, $a->id]],
        );

        $response->assertOk()
            ->assertJsonPath('meta.operation', 'term.reorder')
            ->assertJsonPath('data.0', $b->id)
            ->assertJsonPath('data.1', $a->id);

        $this->assertSame(0, $b->fresh()?->sort_order);
        $this->assertSame(1, $a->fresh()?->sort_order);
    }

    public function test_term_reorderは兄弟の欠落を422にする(): void
    {
        $a = $this->makeTerm('a');
        $this->makeTerm('b');

        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms/reorder',
            ['orderedTermIds' => [$a->id]],
        );

        $response->assertStatus(422)->assertJsonStructure(['errors']);
    }

    public function test_term_reorderは要素の型を422にする(): void
    {
        $this->makeTerm('a');

        $response = $this->actingAs($this->admin())->postJson(
            '/api/v1/taxonomies/news_category/terms/reorder',
            ['orderedTermIds' => [123]],
        );

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['orderedTermIds']]);
        $this->assertLeakFree($response->getContent());
    }

    // ─────────────────────────────────────────────────────────────
    // 7. term.delete（確認必須・参照ありは conflict）
    // ─────────────────────────────────────────────────────────────

    public function test_term_deleteは1回目に確認を返し削除しない(): void
    {
        $term = $this->makeTerm('press');

        $response = $this->actingAs($this->admin())->postJson("/api/v1/terms/{$term->id}/delete");

        $response->assertStatus(409)->assertJsonStructure(['confirmation' => ['token', 'summary']]);
        $this->assertNotNull($term->fresh(), '確認前に削除した');
    }

    public function test_term_deleteは確認トークンで削除する(): void
    {
        $term = $this->makeTerm('press');
        $admin = $this->admin();

        $token = $this->actingAs($admin)
            ->postJson("/api/v1/terms/{$term->id}/delete")
            ->json('confirmation.token');

        $response = $this->actingAs($admin)->postJson(
            "/api/v1/terms/{$term->id}/delete", [], ['X-Confirmation' => $token],
        );

        $response->assertOk()->assertJsonPath('meta.operation', 'term.delete');
        $this->assertNull($term->fresh());
        $this->assertDatabaseHas('audit_logs', ['operation' => 'term.delete']);
    }

    public function test_term_deleteは参照があれば409で確認を出さない(): void
    {
        $term = $this->makeTerm('press');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $response = $this->actingAs($this->admin())->postJson("/api/v1/terms/{$term->id}/delete");

        $response->assertStatus(409)->assertJsonMissingPath('confirmation');
        $this->assertNotNull($term->fresh());
        $this->assertLeakFree($response->getContent());
    }

    public function test_term_deleteは未知のtermを404にする(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/terms/'.TaxonomyId::generate().'/delete')
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // 公開範囲
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomy自体のcrud_routeは存在しない(): void
    {
        $admin = $this->admin();

        // 作成・更新・削除の入口を作っていない（Phase 9.4 へ延期 — §P.2）
        $this->actingAs($admin)->postJson('/api/v1/taxonomies', ['key' => 'x'])->assertNotFound();
        $this->actingAs($admin)->patchJson('/api/v1/taxonomies/news_category', ['label' => 'x'])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/v1/taxonomies/news_category/delete')->assertNotFound();

        // DELETE 動詞も使わない（route が存在しない）
        $this->assertContains(
            $this->actingAs($admin)->deleteJson('/api/v1/taxonomies/news_category')->status(),
            [404, 405],
        );
        $this->assertNotNull(Taxonomy::query()->where('key', 'news_category')->first());
    }

    public function test_readのapiは_d_bを書き換えない(): void
    {
        $this->makeTerm('press');
        $admin = $this->admin();

        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            $sql = strtolower(trim($query->sql));

            foreach (['insert ', 'update ', 'delete '] as $write) {
                if (str_starts_with($sql, $write)) {
                    $writes[] = $sql;
                }
            }
        });

        $this->actingAs($admin)->getJson('/api/v1/taxonomies')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/taxonomies/news_category')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/taxonomies/news_category/terms')->assertOk();

        $this->assertSame([], $writes, '読み取りで書き込みが発生した: '.implode(' / ', $writes));
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function makeTaxonomy(string $key): Taxonomy
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(), 'key' => $key, 'label' => $key,
            'hierarchical' => true, 'managed_by' => 'code',
        ])->save();

        return $taxonomy;
    }

    /** term は Application Service 経由で作る（fixture が domain 規則を迂回しない） */
    private function makeTerm(string $name, int $sortOrder = 0): TaxonomyTerm
    {
        $result = app(CreateTerm::class)->execute(
            ['taxonomyKey' => 'news_category', 'name' => $name, 'slug' => $name],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $term = TaxonomyTerm::query()->findOrFail($result->data['term']['id']);

        if ($sortOrder !== 0) {
            $term->forceFill(['sort_order' => $sortOrder])->save();
        }

        return $term;
    }

    /** 内部情報を利用者へ返していないこと */
    private function assertLeakFree(string|false $body): void
    {
        $body = (string) $body;

        foreach ([
            'SQLSTATE', 'PDO', 'select ', 'insert into', 'update ', 'password',
            base_path(), 'AgentCms\\', '#0 ', 'Stack trace',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "[{$leak}] が漏れている");
        }
    }
}
