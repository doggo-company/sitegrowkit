<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\News\Models\News;
use Modules\Works\Models\Work;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / #16 — Content 編集フォームの分類選択（SPEC §R / §I.6 / §K）。
 *
 * ここで固定するのは **管理画面のフォーム経路**（Admin route → Application Service）での
 * 契約: 候補は active のみ / 既存 archived assignment を黙って解除しない /
 * taxonomies は通常 field と分離される / 未宣言 schema は非回帰。
 *
 * 差分 semantics（AS-1〜AS-12）そのものは `ContentTaxonomyTest` の責務であり、
 * ここでは **フォームから同じ結論に到達すること**だけを見る。
 */
final class ContentTaxonomyFormTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private ?string $schemaPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomySynchronizer::class)->sync();
    }

    protected function tearDown(): void
    {
        if ($this->schemaPath !== null && is_dir($this->schemaPath)) {
            File::deleteDirectory($this->schemaPath);
        }

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // 未宣言 schema の非回帰（要件 16）
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomy未宣言schemaのフォームは何も変わらない(): void
    {
        $admin = $this->admin();

        // works は taxonomies を宣言していない（works.category は select のまま）
        $response = $this->actingAs($admin)->get('/admin/works/create');
        $response->assertOk();

        $this->assertSame([], $response->inertiaProps('taxonomyOptions'));
        $this->assertSame([], $response->inertiaProps('schema.taxonomies'));

        $this->actingAs($admin)->post('/admin/works', [
            'title' => '実績',
            'slug' => 'work-1',
            'category' => 'web',
            'body' => '<p>本文</p>',
            'status' => 'draft',
        ])->assertRedirect('/admin/works');

        $this->assertSame(1, Work::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    public function test_未宣言schemaのフォームはtaxonomyのためにdbへ触らない(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($this->admin())->get('/admin/works/create')->assertOk();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('taxonomy_terms', $sql, '未宣言 schema で項目を読んだ');
        }

        /*
         * 関連付け（Phase 9.7 / STAGE 20D）は「どの型に分類が付いているか」を
         * 知るために必ず 1 回引く。**型ごとには引かない**ことをここで固定する。
         */
        $associationReads = array_values(array_filter(
            $queries,
            // 表の有無を確かめる問い合わせ（sqlite_master / information_schema）は数えない。
            // 数えたいのは **関連付けそのものを読んだ回数**である
            static fn (string $sql): bool => str_contains($sql, 'from "content_type_taxonomies"')
                || str_contains($sql, 'from `content_type_taxonomies`'),
        ));

        $this->assertLessThanOrEqual(1, count($associationReads), '関連付けを型の数だけ引いている');
    }

    // ─────────────────────────────────────────────────────────────
    // 選択候補（要件 17・19・22）
    // ─────────────────────────────────────────────────────────────

    public function test_選択候補はactive_termのみ(): void
    {
        $active = $this->makeTerm('press');
        $archived = $this->makeTerm('old');
        $this->archive($archived);

        $response = $this->actingAs($this->admin())->get('/admin/news/create');
        $response->assertOk();

        /** @var list<array<string, mixed>> $options */
        $options = $response->inertiaProps('taxonomyOptions');

        $this->assertCount(1, $options);
        $this->assertSame('news_category', $options[0]['key']);
        $this->assertSame('カテゴリ', $options[0]['label']);
        $this->assertTrue($options[0]['multiple']);
        $this->assertFalse($options[0]['required']);
        $this->assertTrue($options[0]['available']);

        $ids = array_column($options[0]['terms'], 'id');
        $this->assertSame([$active->id], $ids, 'archived が候補に出ている');
    }

    public function test_active_termを新規付与できる(): void
    {
        $term = $this->makeTerm('press');

        $this->actingAs($this->admin())->post('/admin/news', [
            ...$this->newsAttributes(),
            'taxonomies' => ['news_category' => [$term->id]],
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        $news = News::query()->firstOrFail();
        $this->assertSame([$term->id], $this->assignedIds((int) $news->id));
    }

    public function test_archived_termは新規付与できない(): void
    {
        $archived = $this->makeTerm('old');
        $this->archive($archived);

        $this->actingAs($this->admin())
            ->from('/admin/news/create')
            ->post('/admin/news', [
                ...$this->newsAttributes(),
                'taxonomies' => ['news_category' => [$archived->id]],
                'taxonomies_managed' => '1',
            ])
            ->assertSessionHasErrors('taxonomies.news_category');

        $this->assertSame(0, News::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    // ─────────────────────────────────────────────────────────────
    // 既存 archived assignment（要件 19・20・21・23）
    // ─────────────────────────────────────────────────────────────

    public function test_既存のarchived_assignmentを選択済みとして表示する(): void
    {
        $archived = $this->makeTerm('old');
        $id = $this->createNews([$archived->id]);
        $this->archive($archived);

        $response = $this->actingAs($this->admin())->get("/admin/news/{$id}/edit");
        $response->assertOk();

        /** @var array<string, mixed> $block */
        $block = $response->inertiaProps('item.taxonomies.news_category');

        // AS-12: 選択肢から消して黙って解除しない
        $this->assertSame([$archived->id], array_column($block['terms'], 'id'));
        $this->assertTrue($block['terms'][0]['archived']);

        // 候補（新規に選べるもの）からは外れている（AR-3）
        /** @var list<array<string, mixed>> $options */
        $options = $response->inertiaProps('taxonomyOptions');
        $this->assertSame([], $options[0]['terms']);

        // 画面は「アーカイブ済み」と明示する
        $component = (string) File::get(base_path('resources/js/components/schema/SchemaTaxonomyInput.vue'));
        $this->assertStringContainsString('（アーカイブ済み）', $component);
    }

    /** AS-3 / AS-9 / AS-11: 読んだ内容をそのまま書き戻せる。行も created_at も変わらない */
    public function test_archived_assignmentのround_tripで行が変わらない(): void
    {
        $archived = $this->makeTerm('old');
        $id = $this->createNews([$archived->id]);
        $this->archive($archived);

        $before = TaxonomyTermAssignment::query()->where('content_id', $id)->firstOrFail();

        $response = $this->actingAs($this->admin())->get("/admin/news/{$id}/edit");
        /** @var array<string, mixed> $block */
        $block = $response->inertiaProps('item.taxonomies.news_category');

        // 読んだ payload をそのまま書き戻す
        $this->actingAs($this->admin())->put("/admin/news/{$id}", [
            ...$this->newsAttributes(),
            'slug' => (string) News::query()->findOrFail($id)->slug,
            'taxonomies' => ['news_category' => array_column($block['terms'], 'id')],
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        $after = TaxonomyTermAssignment::query()->where('content_id', $id)->firstOrFail();

        $this->assertSame($before->getKey(), $after->getKey(), 'DELETE→INSERT で作り直した');
        $this->assertSame(
            $before->created_at?->toIso8601String(),
            $after->created_at?->toIso8601String(),
            'created_at が変わった（監査が壊れる）',
        );
    }

    public function test_archivedを維持したままactiveを追加できる(): void
    {
        $archived = $this->makeTerm('old');
        $active = $this->makeTerm('press');
        $id = $this->createNews([$archived->id]);
        $this->archive($archived);

        $this->actingAs($this->admin())->put("/admin/news/{$id}", [
            ...$this->newsAttributes(),
            'slug' => (string) News::query()->findOrFail($id)->slug,
            'taxonomies' => ['news_category' => [$archived->id, $active->id]],
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        $this->assertEqualsCanonicalizing([$archived->id, $active->id], $this->assignedIds($id));
    }

    public function test_archived_assignmentを解除できる(): void
    {
        $archived = $this->makeTerm('old');
        $id = $this->createNews([$archived->id]);
        $this->archive($archived);

        $this->actingAs($this->admin())->put("/admin/news/{$id}", [
            ...$this->newsAttributes(),
            'slug' => (string) News::query()->findOrFail($id)->slug,
            'taxonomies' => ['news_category' => []],
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        $this->assertSame([], $this->assignedIds($id));
    }

    /**
     * multipart（画像添付）では空配列がキーごと消える。
     * それでも「すべて外す」が黙って無視されないこと。
     */
    public function test_空配列がmultipartで消えても全解除できる(): void
    {
        $term = $this->makeTerm('press');
        $id = $this->createNews([$term->id]);

        // multipart 相当: taxonomies キー自体が届かない + 管理していた印だけがある
        $this->actingAs($this->admin())->put("/admin/news/{$id}", [
            ...$this->newsAttributes(),
            'slug' => (string) News::query()->findOrFail($id)->slug,
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        $this->assertSame([], $this->assignedIds($id));
    }

    /** 印が無ければ従来どおり「触れない」（既存フォームの非回帰） */
    public function test_印もキーも無ければassignmentを変更しない(): void
    {
        $term = $this->makeTerm('press');
        $id = $this->createNews([$term->id]);

        $this->actingAs($this->admin())->put("/admin/news/{$id}", [
            ...$this->newsAttributes('更新後'),
            'slug' => (string) News::query()->findOrFail($id)->slug,
        ])->assertRedirect('/admin/news');

        $this->assertSame([$term->id], $this->assignedIds($id));
        $this->assertSame('更新後', (string) News::query()->findOrFail($id)->title);
    }

    // ─────────────────────────────────────────────────────────────
    // single / multiple / required（要件 18）
    // ─────────────────────────────────────────────────────────────

    public function test_宣言のsingle_multiple_requiredがフォームへ渡る(): void
    {
        $this->useNewsTaxonomies([
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
            ['key' => 'news_single', 'multiple' => false, 'required' => false],
            ['key' => 'news_required', 'multiple' => true, 'required' => true],
        ]);
        $this->makeTaxonomy('news_single');
        $this->makeTaxonomy('news_required');

        $response = $this->actingAs($this->admin())->get('/admin/news/create');
        $response->assertOk();

        /** @var list<array<string, mixed>> $options */
        $options = $response->inertiaProps('taxonomyOptions');
        $byKey = array_column($options, null, 'key');

        $this->assertTrue($byKey['news_category']['multiple']);
        $this->assertFalse($byKey['news_category']['required']);
        $this->assertFalse($byKey['news_single']['multiple']);
        $this->assertTrue($byKey['news_required']['required']);
    }

    public function test_single_taxonomyへ複数指定すると拒否される(): void
    {
        $this->useNewsTaxonomies([
            ['key' => 'news_single', 'multiple' => false, 'required' => false],
        ]);
        $taxonomy = $this->makeTaxonomy('news_single');
        $a = $this->makeTerm('a', $taxonomy);
        $b = $this->makeTerm('b', $taxonomy);

        $this->actingAs($this->admin())
            ->from('/admin/news/create')
            ->post('/admin/news', [
                ...$this->newsAttributes(),
                'taxonomies' => ['news_single' => [$a->id, $b->id]],
                'taxonomies_managed' => '1',
            ])
            ->assertSessionHasErrors('taxonomies.news_single');

        $this->assertSame(0, News::query()->count());
    }

    public function test_required_taxonomyが空なら拒否される(): void
    {
        $this->useNewsTaxonomies([
            ['key' => 'news_required', 'multiple' => true, 'required' => true],
        ]);
        $this->makeTaxonomy('news_required');

        $this->actingAs($this->admin())
            ->from('/admin/news/create')
            ->post('/admin/news', [
                ...$this->newsAttributes(),
                'taxonomies' => ['news_required' => []],
                'taxonomies_managed' => '1',
            ])
            ->assertSessionHasErrors('taxonomies.news_required');

        $this->assertSame(0, News::query()->count());
    }

    // ─────────────────────────────────────────────────────────────
    // payload の分離（要件 24）
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomies_payloadが通常fieldから分離される(): void
    {
        $term = $this->makeTerm('press');

        $this->actingAs($this->admin())->post('/admin/news', [
            ...$this->newsAttributes(),
            'taxonomies' => ['news_category' => [$term->id]],
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        $news = News::query()->firstOrFail();
        $attributes = $news->getAttributes();

        // 通常 field としては保存されない
        $this->assertArrayNotHasKey('taxonomies', $attributes);
        $this->assertArrayNotHasKey('taxonomies_managed', $attributes);

        // 分類は assignment 表にだけ入る
        $this->assertSame([$term->id], $this->assignedIds((int) $news->id));

        // schema 側も別ブロックとして返る
        $response = $this->actingAs($this->admin())->get("/admin/news/{$news->id}/edit");
        $this->assertSame(
            [['key' => 'news_category', 'multiple' => true, 'required' => false]],
            $response->inertiaProps('schema.taxonomies'),
        );
        $this->assertNull($response->inertiaProps('schema.fields.taxonomies'));
    }

    /** フォーム側も data と分類を混ぜない */
    public function test_フォームが分類を別キーで送る(): void
    {
        $code = (string) File::get(base_path('resources/js/pages/admin/resource/Form.vue'));

        $this->assertStringContainsString("normalized.taxonomies_managed = '1';", $code);
        $this->assertStringContainsString('SchemaTaxonomyInput', $code);
        // 宣言が無ければキーごと送らない
        $this->assertStringContainsString('hasTaxonomies.value', $code);
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function newsAttributes(string $title = 'テスト記事'): array
    {
        return [
            'title' => $title,
            'slug' => 'test-news',
            'body' => '<p>本文</p>',
            'status' => 'draft',
        ];
    }

    /** @param  list<string>  $termIds */
    private function createNews(array $termIds): int
    {
        $this->actingAs($this->admin())->post('/admin/news', [
            ...$this->newsAttributes(),
            'taxonomies' => ['news_category' => $termIds],
            'taxonomies_managed' => '1',
        ])->assertRedirect('/admin/news');

        return (int) News::query()->firstOrFail()->id;
    }

    /** @return list<string> */
    private function assignedIds(int $contentId): array
    {
        /** @var list<string> $ids */
        $ids = TaxonomyTermAssignment::query()
            ->where('content_type', 'news')
            ->where('content_id', $contentId)
            ->orderBy('sort_order')
            ->pluck('term_id')
            ->all();

        return $ids;
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
    private function makeTerm(string $name, ?Taxonomy $taxonomy = null): TaxonomyTerm
    {
        $result = app(CreateTerm::class)->execute(
            [
                'taxonomyKey' => $taxonomy?->key ?? 'news_category',
                'name' => $name,
                'slug' => $name,
            ],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return TaxonomyTerm::query()->findOrFail($result->data['term']['id']);
    }

    private function archive(TaxonomyTerm $term): void
    {
        $term->forceFill(['archived_at' => now()])->save();
    }

    /**
     * 同梱 schema を複製し、news の `taxonomies` 宣言だけ差し替える。
     * **リポジトリのファイルは触らない。**
     *
     * @param  list<array<string, mixed>>  $taxonomies
     */
    private function useNewsTaxonomies(array $taxonomies): void
    {
        $source = base_path('content/schemas');
        $this->schemaPath = sys_get_temp_dir().'/agentcms-form-schemas-'.uniqid();

        mkdir($this->schemaPath.'/overrides', 0777, true);

        foreach (glob($source.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) File::get($file), true, 32, JSON_THROW_ON_ERROR);

            if (($data['id'] ?? '') === 'news') {
                $data['taxonomies'] = $taxonomies;
            }

            File::put(
                $this->schemaPath.'/'.basename($file),
                (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }

        foreach (glob($source.'/overrides/*.json') ?: [] as $file) {
            File::copy($file, $this->schemaPath.'/overrides/'.basename($file));
        }

        config(['agentcms.schema_path' => $this->schemaPath]);
        app()->forgetInstance(SchemaRegistry::class);
    }
}
