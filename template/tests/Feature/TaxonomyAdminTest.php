<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\Reference\ReferenceProbe;
use AgentCms\Core\Taxonomy\Reference\ReferenceProvider;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / #16 — 分類の管理画面（SPEC §R / §I.5 / §I.7 / §AB）。
 *
 * ここで固定するのは **Admin route が Application Service まで到達すること**、
 * 認証・CSRF・認可、archive / hierarchy の表示境界、削除の 3 状態
 * （confirmation / conflict / fail closed）の区別。
 *
 * 業務ルールそのもの（slug 正規化・階層不変条件・参照判定）は
 * `TaxonomyApplicationServiceTest` / `TaxonomyDomainRulesTest` の責務であり重複させない。
 */
final class TaxonomyAdminTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<string> */
    private const MUTATION_OPERATIONS = ['term.create', 'term.update', 'term.reorder', 'term.delete'];

    protected function setUp(): void
    {
        parent::setUp();

        // 明示ステップとしての同期（runtime の暗黙 write ではない）
        app(TaxonomySynchronizer::class)->sync();
    }

    // ─────────────────────────────────────────────────────────────
    // 認証 / verified / CSRF（要件 1・26）
    // ─────────────────────────────────────────────────────────────

    public function test_未認証では管理画面の全routeが拒否される(): void
    {
        $term = $this->makeTerm('press');

        $this->get('/admin/taxonomies')->assertRedirect('/login');
        $this->get('/admin/taxonomies/news_category')->assertRedirect('/login');
        $this->post('/admin/taxonomies/news_category/terms', ['name' => 'x'])->assertRedirect('/login');
        $this->post('/admin/taxonomies/news_category/terms/reorder', [
            'orderedTermIds' => [$term->id],
        ])->assertRedirect('/login');
        $this->patch("/admin/terms/{$term->id}", ['name' => 'x'])->assertRedirect('/login');
        $this->post("/admin/terms/{$term->id}/delete")->assertRedirect('/login');

        // 未認証のリクエストで DB が変わらない
        $this->assertSame(1, TaxonomyTerm::query()->count());
        $this->assertSame('press', (string) $term->fresh()?->name);
    }

    /**
     * 未 verified の利用者を **実際に拒否する**（本番の `App\Models\User` で確認する）。
     *
     * `verified` は `MustVerifyEmail` を実装した利用者にだけ確認を要求するため、
     * テスト専用の差し替えではなく **本番モデルそのもの**で拒否されることを固定する。
     */
    public function test_未verifiedでは管理画面の全routeが拒否される(): void
    {
        $term = $this->makeTerm('press');
        $user = User::factory()->unverified()->create();

        foreach ([
            fn () => $this->actingAs($user)->get('/admin/taxonomies'),
            fn () => $this->actingAs($user)->get('/admin/taxonomies/news_category'),
            fn () => $this->actingAs($user)->post('/admin/taxonomies/news_category/terms', ['name' => 'x']),
            fn () => $this->actingAs($user)->post('/admin/taxonomies/news_category/terms/reorder', [
                'orderedTermIds' => [$term->id],
            ]),
            fn () => $this->actingAs($user)->patch("/admin/terms/{$term->id}", ['name' => 'x']),
            fn () => $this->actingAs($user)->post("/admin/terms/{$term->id}/delete"),
        ] as $call) {
            $call()->assertRedirect(route('verification.notice', absolute: false));
        }

        // 拒否されたリクエストで DB が変わらない
        $this->assertSame(1, TaxonomyTerm::query()->count());
        $this->assertSame('press', (string) $term->fresh()?->name);
    }

    /**
     * 既存の管理画面も同じく拒否される（分類だけ緩い / 厳しいを作らない）。
     *
     * ここで既存 route（`/admin/news`）も明示的に確認することで、
     * 「新しい route だけ verified が効いている」状態を作っていないことを示す。
     */
    public function test_verified_gateが既存管理画面でも実効している(): void
    {
        $user = User::factory()->unverified()->create();
        $notice = route('verification.notice', absolute: false);

        $this->actingAs($user)->get('/admin/news')->assertRedirect($notice);
        $this->actingAs($user)->get('/admin/works')->assertRedirect($notice);
        $this->actingAs($user)->get('/admin/media')->assertRedirect($notice);
        $this->actingAs($user)->get('/admin/taxonomies')->assertRedirect($notice);

        // 確認を済ませれば同じ利用者が通る（gate が常時ブロックではない）
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh())->get('/admin/taxonomies')->assertOk();
    }

    /** `verified` が実効する前提（本番モデルが契約を実装している）を固定する */
    public function test_本番userがmust_verify_emailを実装している(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
        $this->assertFalse(User::factory()->unverified()->create()->hasVerifiedEmail());
        $this->assertTrue(User::factory()->create()->hasVerifiedEmail());
    }

    /** 既存の管理画面と同じ middleware 群（web / CSRF / auth / verified）の中にある */
    public function test_既存管理画面と同じmiddlewareの中にある(): void
    {
        $reference = app('router')->getRoutes()->getByName('admin.news.index');
        $this->assertNotNull($reference);

        foreach ([
            'admin.taxonomies.index', 'admin.taxonomies.show',
            'admin.taxonomies.terms.store', 'admin.taxonomies.terms.reorder',
            'admin.terms.update', 'admin.terms.delete',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} が無い");
            $this->assertSame(
                $reference->gatherMiddleware(),
                $route->gatherMiddleware(),
                "{$name} の middleware が既存管理画面と違う",
            );
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
            // web group が CSRF 検証を含む（管理画面の mutation は同一オリジン前提）
            $this->assertContains('web', $route->middleware());
        }
    }

    public function test_validation_errorは既存契約どおり画面へ戻る(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/taxonomies/news_category')
            ->post('/admin/taxonomies/news_category/terms', ['name' => ''])
            ->assertRedirect('/admin/taxonomies/news_category')
            ->assertSessionHasErrors('name');

        $this->assertSame(0, TaxonomyTerm::query()->count());
    }

    /**
     * 管理画面は **Application Service の受理キーだけ**を渡す。
     *
     * web フォームには `_token` / `_method` が必ず付くため、`$request->all()` をそのまま渡すと
     * Application Service の「未知キーは全体を拒否」に必ず引っかかる。
     * そこで adapter 側で受理キーへ絞る。**その一覧が service の contract と一致していること**を固定し、
     * adapter が独自に検証・変換していないことを保証する。
     */
    public function test_受理キーがapplication_serviceのcontractと一致する(): void
    {
        $code = (string) File::get(base_path('app/Http/Controllers/Admin/TaxonomyAdminController.php'));

        foreach ([CreateTerm::class, UpdateTerm::class] as $operation) {
            $reflection = new ReflectionClass($operation);
            /** @var list<string> $allowed */
            $allowed = $reflection->getConstant('ALLOWED_KEYS');

            // taxonomyKey は URL から与えるので、body の受理キーからは外れる
            $expected = array_values(array_diff($allowed, ['taxonomyKey']));
            $needle = "only(['".implode("', '", $expected)."'])";

            $this->assertStringContainsString(
                $needle,
                $code,
                $operation.' の受理キーと管理画面の受け渡しがずれている',
            );
        }
    }

    public function test_不正な値はvalidation_errorとして返る(): void
    {
        $term = $this->makeTerm('press');
        $other = $this->makeTerm('other');

        // 既存の slug と重複する slug は Application Service が拒否する
        $this->actingAs($this->admin())
            ->from('/admin/taxonomies/news_category')
            ->patch("/admin/terms/{$other->id}", ['slug' => (string) $term->slug])
            ->assertSessionHasErrors('slug');

        $this->assertSame('other', (string) $other->fresh()?->slug, '一部だけ更新された');
    }

    // ─────────────────────────────────────────────────────────────
    // Application Service への到達（要件 2・3）
    // ─────────────────────────────────────────────────────────────

    public function test_全mutationがadmin_contextでapplication_serviceへ到達する(): void
    {
        $admin = $this->admin();
        $a = $this->makeTerm('a');
        $b = $this->makeTerm('b');

        $this->actingAs($admin)
            ->post('/admin/taxonomies/news_category/terms', ['name' => '新規'])
            ->assertRedirect();
        $this->actingAs($admin)
            ->patch("/admin/terms/{$a->id}", ['name' => '変更後'])
            ->assertRedirect();
        $this->actingAs($admin)->post('/admin/taxonomies/news_category/terms/reorder', [
            'parentId' => null,
            'orderedTermIds' => TaxonomyTerm::query()
                ->orderBy('sort_order')->orderBy('id')->pluck('id')->all(),
        ])->assertRedirect();

        $token = $this->confirmationTokenFor($admin, $b->id);
        $this->actingAs($admin)
            ->post("/admin/terms/{$b->id}/delete", ['confirmation_token' => $token])
            ->assertRedirect();

        foreach (self::MUTATION_OPERATIONS as $operation) {
            // AD-2: Admin 操作が interface: "api" として記録されないこと
            $this->assertDatabaseHas('audit_logs', ['operation' => $operation, 'interface' => 'admin']);
            $this->assertDatabaseMissing('audit_logs', ['operation' => $operation, 'interface' => 'api']);
        }
    }

    /** AD-6: 管理画面は API を保存経路にしない */
    public function test_adminはtaxonomy_api_routeを呼んでいない(): void
    {
        $code = (string) File::get(base_path('app/Http/Controllers/Admin/TaxonomyAdminController.php'));

        // 文章としての言及ではなく、**実際の呼び出し**が無いことを見る
        foreach ([
            '#/api/v1#',            // URL 直書き
            "#route\('api\.v1#",    // 名前付き route
            '#TaxonomyApiController#',
            '#Http::#',             // HTTP クライアント
            '#ApiResponse#',
            '#OperationContext::api#',
        ] as $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), "Admin が [{$pattern}] を使っている");
        }

        $this->assertStringContainsString('OperationContext::admin(', $code);

        // 業務ロジックを adapter へ複製していない
        foreach (['TaxonomyTerm::', 'TaxonomyTermAssignment', 'DB::', 'lockForUpdate'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "adapter に [{$forbidden}] がある");
        }
    }

    public function test_画面側にも業務ロジックを複製しない(): void
    {
        foreach ([
            'resources/js/pages/admin/taxonomy/Show.vue',
            'resources/js/components/taxonomy/TermTree.vue',
            'resources/js/components/schema/SchemaTaxonomyInput.vue',
        ] as $path) {
            $code = (string) File::get(base_path($path));

            foreach (['/api/v1', 'fetch(', 'axios'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$path} が [{$forbidden}] を使っている");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 公開範囲（要件 4）
    // ─────────────────────────────────────────────────────────────

    /**
     * 分類の定義は管理できるが、**コード管理の定義は触れない**
     * （Phase 9.7 / STAGE 20D）。
     *
     * 定義ファイルが正の分類を管理画面から変えると、git の内容と実行時の内容が食い違う。
     * DELETE 動詞の route は 1 本も置かない — 「削除」は一度も使っていない定義に限る
     * `POST .../remove` だけである。
     */
    public function test_コード管理の分類は管理画面から変えられない(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch('/admin/taxonomies/news_category', ['label' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post('/admin/taxonomies/news_category/archive')->assertForbidden();
        $this->actingAs($admin)->post('/admin/taxonomies/news_category/remove')->assertForbidden();
        $this->actingAs($admin)->get('/admin/taxonomies/news_category/edit')->assertNotFound();

        $this->assertContains(
            $this->actingAs($admin)->delete('/admin/taxonomies/news_category')->status(),
            [404, 405],
        );

        $taxonomy = Taxonomy::query()->where('key', 'news_category')->first();

        $this->assertNotNull($taxonomy);
        $this->assertSame('カテゴリ', $taxonomy->label, '定義ファイルの表示名が書き換わっている');
    }

    /** 分類そのものを消す DELETE の route は 1 本も無い */
    public function test_分類のdelete_routeは存在しない(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.taxonomies.')) {
                continue;
            }

            $this->assertNotContains('DELETE', $route->methods(), '分類に DELETE の route がある');
        }
    }

    /** UI 側にも分類そのものを追加・削除する導線を置かない */
    /**
     * 画面が **業務判断を持たない**（Phase 9.7 / STAGE 20D）。
     *
     * 分類の追加・削除の導線は置くようになったが、
     * 「消してよいか」を画面が決めることはない — サーバーが最終判定を持つ。
     */
    public function test_分類の画面は削除の可否を自分で決めない(): void
    {
        foreach (['Index', 'Show', 'Create', 'Edit'] as $page) {
            $code = (string) File::get(base_path("resources/js/pages/admin/taxonomy/{$page}.vue"));

            foreach (['taxonomies/delete', 'router.delete', 'confirm(', 'v-html'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$page}.vue に [{$forbidden}] がある");
            }
        }
    }

    public function test_一覧画面はcode管理を変更不可として示す(): void
    {
        $this->makeTerm('press');

        $this->actingAs($this->admin())->get('/admin/taxonomies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/taxonomy/Index')
                ->has('taxonomies', 1)
                ->where('taxonomies.0.key', 'news_category')
                ->where('taxonomies.0.managedBy', 'code')
                ->where('taxonomies.0.activeTermCount', 1),
            );

        $index = (string) File::get(base_path('resources/js/pages/admin/taxonomy/Index.vue'));
        $this->assertStringContainsString('コード管理（変更不可）', $index);
    }

    // ─────────────────────────────────────────────────────────────
    // hierarchy / archive の表示境界（要件 5・6・7）
    // ─────────────────────────────────────────────────────────────

    public function test_管理treeはarchived_termを含む(): void
    {
        $active = $this->makeTerm('press');
        $archived = $this->makeTerm('old');
        $this->archive($archived);

        $response = $this->actingAs($this->admin())->get('/admin/taxonomies/news_category');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/taxonomy/Show')
            ->has('terms', 2)
            // HR-3: includeArchived=true。tree を再構成できる完全な集合
            ->where('treeComplete', true)
            ->where('taxonomy.key', 'news_category'),
        );

        $ids = array_column($this->termsOf($response), 'id');
        $this->assertContains($active->id, $ids);
        $this->assertContains($archived->id, $ids);
    }

    public function test_archived親の配下のactive子がparent_idを保つ(): void
    {
        $parent = $this->makeTerm('parent');
        $child = $this->makeTerm('child', parentId: $parent->id);
        $this->archive($parent);

        $response = $this->actingAs($this->admin())->get('/admin/taxonomies/news_category');
        $response->assertOk();

        $terms = collect($this->termsOf($response));

        // HR-6: parentId を null へ潰さない（孤児 root にしない材料）
        $this->assertSame($parent->id, $terms->firstWhere('id', $child->id)['parentId']);
        $this->assertFalse($terms->firstWhere('id', $child->id)['archived']);
        $this->assertTrue($terms->firstWhere('id', $parent->id)['archived']);
    }

    /**
     * 「アーカイブ済みを表示」の切り替えは画面側の状態。
     * サーバーは常に完全な集合を渡し、**既定は active のみ**表示にする（AR-1 / AR-2）。
     */
    public function test_一覧の既定はactiveのみで切替できる(): void
    {
        $code = (string) File::get(base_path('resources/js/pages/admin/taxonomy/Show.vue'));

        $this->assertStringContainsString('const showArchived = ref(false);', $code, '既定が active のみでない');
        $this->assertStringContainsString('アーカイブ済みを表示', $code, '切り替えが無い');
        // HR-4: フィルタ中でも子孫を持つ archived な親は階層のために残す
        $this->assertStringContainsString('keptForHierarchy', $code);
    }

    // ─────────────────────────────────────────────────────────────
    // term の作成 / 更新 / archive（要件 8・9）
    // ─────────────────────────────────────────────────────────────

    public function test_termを作成できる(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/taxonomies/news_category/terms', [
                'name' => 'プレスリリース',
                'slug' => 'press',
            ])
            ->assertRedirect('/admin/taxonomies/news_category');

        $term = TaxonomyTerm::query()->firstOrFail();
        $this->assertSame('プレスリリース', (string) $term->name);
        $this->assertSame('press', (string) $term->slug);
        $this->assertNull($term->archived_at);
    }

    public function test_termの名前とslugと親を変更できる(): void
    {
        $parent = $this->makeTerm('parent');
        $term = $this->makeTerm('child');

        $this->actingAs($this->admin())->patch("/admin/terms/{$term->id}", [
            'name' => '変更後',
            'slug' => 'renamed',
            'parentId' => $parent->id,
        ])->assertRedirect();

        $fresh = $term->fresh();
        $this->assertSame('変更後', (string) $fresh?->name);
        $this->assertSame('renamed', (string) $fresh?->slug);
        $this->assertSame($parent->id, $fresh?->parent_id);
    }

    public function test_archiveと解除ができる(): void
    {
        $admin = $this->admin();
        $term = $this->makeTerm('press');

        $this->actingAs($admin)->patch("/admin/terms/{$term->id}", ['archived' => true])->assertRedirect();
        $this->assertNotNull($term->fresh()?->archived_at);

        $this->actingAs($admin)->patch("/admin/terms/{$term->id}", ['archived' => false])->assertRedirect();
        $this->assertNull($term->fresh()?->archived_at);
    }

    /** AR-10: 子を自動 archive しない */
    public function test_親をarchiveしても子はactiveのまま(): void
    {
        $parent = $this->makeTerm('parent');
        $child = $this->makeTerm('child', parentId: $parent->id);

        $this->actingAs($this->admin())
            ->patch("/admin/terms/{$parent->id}", ['archived' => true])
            ->assertRedirect();

        $this->assertNotNull($parent->fresh()?->archived_at);
        $this->assertNull($child->fresh()?->archived_at, '子まで archive された');
    }

    // ─────────────────────────────────────────────────────────────
    // 並べ替え（要件 10・11）
    // ─────────────────────────────────────────────────────────────

    public function test_reorderは同一parentの全termを扱う(): void
    {
        $a = $this->makeTerm('a');
        $b = $this->makeTerm('b');
        $c = $this->makeTerm('c');
        $this->archive($b);

        $this->actingAs($this->admin())->post('/admin/taxonomies/news_category/terms/reorder', [
            'parentId' => null,
            // archived な b を含む全件
            'orderedTermIds' => [$c->id, $b->id, $a->id],
        ])->assertRedirect();

        $this->assertSame(0, $c->fresh()?->sort_order);
        $this->assertSame(1, $b->fresh()?->sort_order);
        $this->assertSame(2, $a->fresh()?->sort_order);
    }

    public function test_reorderでarchivedを欠落させると拒否される(): void
    {
        $a = $this->makeTerm('a');
        $b = $this->makeTerm('b');
        $this->archive($b);

        $this->actingAs($this->admin())
            ->from('/admin/taxonomies/news_category')
            ->post('/admin/taxonomies/news_category/terms/reorder', [
                'parentId' => null,
                'orderedTermIds' => [$a->id],
            ])
            ->assertRedirect('/admin/taxonomies/news_category')
            ->assertSessionHasErrors('orderedTermIds');

        // 部分集合での上書きを許さない
        $this->assertSame(0, $a->fresh()?->sort_order);
        $this->assertSame(1, $b->fresh()?->sort_order);
    }

    /** §AB: drag だけに依存せず、キーボードで操作できる上 / 下移動を用意する */
    public function test_並べ替えがキーボードで操作できる(): void
    {
        $code = (string) File::get(base_path('resources/js/components/taxonomy/TermTree.vue'));

        // button 要素は既定でフォーカス可能・Enter / Space で発火する
        $this->assertStringContainsString('を上へ移動', $code);
        $this->assertStringContainsString('を下へ移動', $code);
        $this->assertStringContainsString("emit('move-up', node.term)", $code);
        $this->assertStringContainsString("emit('move-down', node.term)", $code);

        // drag 専用実装になっていない
        $this->assertStringNotContainsString('draggable', $code);
        $this->assertStringNotContainsString('dragstart', $code);
    }

    // ─────────────────────────────────────────────────────────────
    // 使用件数（要件 12）
    // ─────────────────────────────────────────────────────────────

    public function test_使用件数は保存値ではなく導出値(): void
    {
        $term = $this->makeTerm('press');
        $admin = $this->admin();
        $updatedAt = $term->updated_at?->toIso8601String();

        $this->actingAs($admin)->get('/admin/taxonomies/news_category')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("usage.{$term->id}", 0));

        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        // term 行に触れていないのに件数が変わる = 導出値
        $this->assertSame($updatedAt, $term->fresh()?->updated_at?->toIso8601String());
        $this->actingAs($admin)->get('/admin/taxonomies/news_category')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("usage.{$term->id}", 1));
    }

    public function test_termテーブルに件数のキャッシュ列を持たない(): void
    {
        $columns = app('db')->getSchemaBuilder()->getColumnListing('taxonomy_terms');

        foreach (['count', 'usage_count', 'assignment_count', 'terms_count'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $migration = (string) File::get(
            base_path('core/database/migrations/2026_08_14_000001_create_taxonomy_tables.php'),
        );
        $this->assertStringNotContainsString('usage_count', $migration);
    }

    // ─────────────────────────────────────────────────────────────
    // 削除の 3 状態（要件 13・14・15）
    // ─────────────────────────────────────────────────────────────

    public function test_参照ゼロのtermは確認を経て削除できる(): void
    {
        $admin = $this->admin();
        $term = $this->makeTerm('press');

        // 1 回目: 確認内容だけ返り、削除しない
        $this->actingAs($admin)
            ->from('/admin/taxonomies/news_category')
            ->post("/admin/terms/{$term->id}/delete")
            ->assertRedirect('/admin/taxonomies/news_category');

        $this->assertNotNull($term->fresh(), '確認前に削除した');

        $flash = $this->inertiaFlash();
        $this->assertArrayHasKey('confirmation', $flash);
        $this->assertArrayNotHasKey('termDeleteConflict', $flash);
        $this->assertStringContainsString('アーカイブ', implode(' ', $flash['confirmation']['impact']));

        // 2 回目: token 付きで削除される
        $this->actingAs($admin)->post("/admin/terms/{$term->id}/delete", [
            'confirmation_token' => $flash['confirmation']['token'],
        ])->assertRedirect();

        $this->assertNull($term->fresh());
    }

    public function test_参照があるtermは理由つきで削除できない(): void
    {
        $term = $this->makeTerm('press');
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $this->actingAs($this->admin())
            ->from('/admin/taxonomies/news_category')
            ->post("/admin/terms/{$term->id}/delete")
            ->assertRedirect('/admin/taxonomies/news_category');

        $flash = $this->inertiaFlash();

        // conflict を confirmation へ変換しない
        $this->assertArrayNotHasKey('confirmation', $flash);
        $this->assertArrayHasKey('termDeleteConflict', $flash);

        $conflict = $flash['termDeleteConflict'];
        $this->assertSame($term->id, $conflict['termId']);
        $this->assertStringContainsString('コンテンツ 1 件', (string) $conflict['message']);
        // 削除できないときは archive を代替として案内する（§I.5）
        $this->assertStringContainsString('アーカイブ', (string) $conflict['message']);

        $this->assertTrue($conflict['references']['complete']);
        $this->assertFalse($conflict['references']['deletable']);
        $this->assertSame(1, $conflict['references']['total_count']);
        $this->assertSame(['assignment'], $conflict['references']['kinds']);
        $this->assertSame('コンテンツ', $conflict['references']['references'][0]['label']);

        $this->assertNotNull($term->fresh());
    }

    /** 子を持つ term は child_term 参照で止まる（archive しても RESTRICT は緩まない — AR-8） */
    public function test_archiveしても削除のrestrictは緩まない(): void
    {
        $parent = $this->makeTerm('parent');
        $this->makeTerm('child', parentId: $parent->id);
        $this->archive($parent);

        $this->actingAs($this->admin())
            ->from('/admin/taxonomies/news_category')
            ->post("/admin/terms/{$parent->id}/delete")
            ->assertRedirect();

        $flash = $this->inertiaFlash();
        $this->assertArrayNotHasKey('confirmation', $flash);
        $this->assertSame(['child_term'], $flash['termDeleteConflict']['references']['kinds']);
        $this->assertNotNull($parent->fresh());
    }

    /** fail closed（調査未完了）を「使用中」と偽装せず、確認へも変換しない */
    public function test_調査未完了は使用中と偽装せず確認へも変換しない(): void
    {
        $term = $this->makeTerm('press');

        // 参照調査そのものが確定できない状態（timeout / 破損 / DB エラー相当）を作る
        app()->instance(ReferenceInspector::class, new ReferenceInspector([
            new class implements ReferenceProvider
            {
                public function kind(): string
                {
                    return 'page_document';
                }

                public function forTerm(TaxonomyTerm $term): ReferenceProbe
                {
                    return ReferenceProbe::undetermined('ページからの参照を調べきれませんでした。');
                }

                public function forTaxonomy(Taxonomy $taxonomy): ReferenceProbe
                {
                    return ReferenceProbe::undetermined('ページからの参照を調べきれませんでした。');
                }
            },
        ]));

        $this->actingAs($this->admin())
            ->from('/admin/taxonomies/news_category')
            ->post("/admin/terms/{$term->id}/delete")
            ->assertRedirect();

        $flash = $this->inertiaFlash();

        $this->assertArrayNotHasKey('confirmation', $flash, 'fail closed を確認へ変換した');
        $this->assertArrayHasKey('termDeleteConflict', $flash);

        $references = $flash['termDeleteConflict']['references'];
        $this->assertFalse($references['complete']);
        $this->assertFalse($references['deletable']);
        // 「使用中」と断定しない（参照 0 件のまま未確定として扱う）
        $this->assertSame(0, $references['total_count']);
        $this->assertSame([], $references['kinds']);
        $this->assertNotSame([], $references['undetermined']);
        $this->assertStringContainsString('安全のため削除しません', (string) $flash['termDeleteConflict']['message']);

        $this->assertNotNull($term->fresh());
    }

    // ─────────────────────────────────────────────────────────────
    // 出力の安全性（要件 25）
    // ─────────────────────────────────────────────────────────────

    /**
     * term 名 / 説明に HTML を入れても実行されないこと。
     *
     * Inertia は props を `<script type="application/json">` の中へ埋める。
     * この文脈から抜け出せるのは **`</script>` だけ**なので、
     * 「閉じタグが必ずエスケープされていること」と「描画側が raw HTML を使わないこと」を固定する。
     */
    public function test_term名のhtmlが実行されない(): void
    {
        // script 要素から**脱出して新しい script を開く**ことを狙う payload
        $breakout = '</script><script>window.__xss__=1;</script>';
        $term = $this->makeTerm($breakout, slug: 'xss-term');
        $term->forceFill([
            'description' => '</SCRIPT ><img src=x onerror="window.__xss2__=1">',
        ])->save();

        $body = (string) $this->actingAs($this->admin())
            ->get('/admin/taxonomies/news_category')
            ->assertOk()
            ->getContent();

        $payload = $this->inertiaPayload($body);

        // 値は失われていない（表示は維持する）
        $this->assertStringContainsString('window.__xss__=1;', $payload);
        $this->assertStringContainsString('window.__xss2__=1', $payload);

        // が、script ブロックを閉じられない。
        // HTML パーサが script を終端するのは `</script` + 空白/`>`/`/` の並びだけなので、
        // 大文字小文字・空白を含めた全パターンが残っていないことを見る
        $this->assertSame(
            0,
            preg_match('#</\s*script[\s/>]#i', $payload),
            'script ブロックを閉じられる（脱出可能）',
        );
        $this->assertStringContainsString('<\/script>', $payload);

        // 注入した本文が **JSON payload の中にしか存在しない**
        // （実行可能な位置へコピーが漏れていない）
        $this->assertSame(1, substr_count($body, 'window.__xss__=1;'));
        $this->assertSame(1, substr_count($payload, 'window.__xss__=1;'));
        $this->assertSame(1, substr_count($body, 'window.__xss2__=1'));
        $this->assertSame(1, substr_count($payload, 'window.__xss2__=1'));

        // 描画側が raw HTML を使わない（Vue の既定エスケープに委ねる）
        foreach ([
            'resources/js/pages/admin/taxonomy/Show.vue',
            'resources/js/pages/admin/taxonomy/Index.vue',
            'resources/js/components/taxonomy/TermTree.vue',
            'resources/js/components/schema/SchemaTaxonomyInput.vue',
        ] as $path) {
            $code = (string) File::get(base_path($path));

            $this->assertStringNotContainsString('v-html', $code, "{$path} が raw HTML を出している");
            $this->assertStringNotContainsString('innerHTML', $code, "{$path} が raw HTML を出している");
        }
    }

    public function test_エラー表示に内部情報を出さない(): void
    {
        $body = (string) $this->actingAs($this->admin())
            ->get('/admin/taxonomies/does_not_exist')
            ->assertNotFound()
            ->getContent();

        foreach (['SQLSTATE', 'PDO', 'select ', 'AgentCms\\', 'Stack trace', base_path()] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "[{$leak}] が漏れている");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 移植性 / 副作用なし（要件 27・28）
    // ─────────────────────────────────────────────────────────────

    /**
     * 生成プロジェクト単体で動く（親リポジトリのパスを参照しない）。
     *
     * 自分自身も対象に含めるため、判定は **正規表現**で書く
     * （禁止したい文字列をそのままリテラルで置くと自己一致してしまう）。
     */
    public function test_管理画面のコードが親リポジトリを参照しない(): void
    {
        $patterns = [
            // 「base_path に相対の親パスを渡す」形
            '#base_path\(\s*[\'"]\.\.#',
            '#dirname\(\s*base_path#',
            '#packages\/cli#',
            '#examples\/html-template#',
        ];

        foreach ([
            'app/Http/Controllers/Admin/TaxonomyAdminController.php',
            'core/src/Taxonomy/Reference/TermUsageCounter.php',
            'core/src/Admin/SchemaResourceController.php',
            'routes/admin.php',
            'tests/Feature/TaxonomyAdminTest.php',
            'tests/Feature/ContentTaxonomyFormTest.php',
        ] as $path) {
            $code = (string) File::get(base_path($path));

            foreach ($patterns as $pattern) {
                $this->assertSame(0, preg_match($pattern, $code), "{$path} が親を参照している");
            }
        }
    }

    /** taxonomy を 1 つも使っていないプロジェクトでも管理画面が開ける */
    public function test_taxonomy未使用でも一覧が開ける(): void
    {
        Taxonomy::query()->delete();

        $this->actingAs($this->admin())->get('/admin/taxonomies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/taxonomy/Index')
                ->has('taxonomies', 0),
            );
    }

    /** fixture が checked-in の定義 / 索引を書き換えない */
    public function test_テスト実行で定義ファイルと索引が変わらない(): void
    {
        $paths = [
            base_path('content/taxonomies/news_category.json'),
            base_path('.agentcms/registry/taxonomies.json'),
        ];

        $before = array_map(static fn (string $path): string => (string) hash_file('sha256', $path), $paths);

        $admin = $this->admin();
        $this->actingAs($admin)
            ->post('/admin/taxonomies/news_category/terms', ['name' => '新規'])
            ->assertRedirect();
        $this->actingAs($admin)->get('/admin/taxonomies/news_category')->assertOk();

        $after = array_map(static fn (string $path): string => (string) hash_file('sha256', $path), $paths);

        $this->assertSame($before, $after, 'checked-in の定義 / 索引が変わった');
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /** term は Application Service 経由で作る（fixture が domain 規則を迂回しない） */
    private function makeTerm(string $name, ?string $slug = null, ?string $parentId = null): TaxonomyTerm
    {
        $input = ['taxonomyKey' => 'news_category', 'name' => $name];

        if ($slug !== null) {
            $input['slug'] = $slug;
        }

        if ($parentId !== null) {
            $input['parentId'] = $parentId;
        }

        $result = app(CreateTerm::class)->execute($input, OperationContext::admin($this->operatorId(), 'fixture'));

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return TaxonomyTerm::query()->findOrFail($result->data['term']['id']);
    }

    private function archive(TaxonomyTerm $term): void
    {
        $term->forceFill(['archived_at' => now()])->save();
    }

    /** @return array<string, mixed> */
    private function inertiaFlash(): array
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);

        return $flash;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function termsOf($response): array
    {
        /** @var list<array<string, mixed>> $terms */
        $terms = $response->inertiaProps('terms');

        return $terms;
    }

    /** Inertia が埋め込む JSON payload だけを取り出す */
    private function inertiaPayload(string $body): string
    {
        $this->assertSame(1, preg_match(
            '#<script data-page="app" type="application/json">(.*?)</script><div id="app">#s',
            $body,
            $matches,
        ), 'Inertia の payload を見つけられなかった');

        return $matches[1];
    }

    private function confirmationTokenFor(User $admin, string $termId): string
    {
        $this->actingAs($admin)
            ->from('/admin/taxonomies/news_category')
            ->post("/admin/terms/{$termId}/delete");

        return (string) $this->inertiaFlash()['confirmation']['token'];
    }
}
