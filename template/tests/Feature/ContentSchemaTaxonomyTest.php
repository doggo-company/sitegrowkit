<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\GetContentSchema;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Schema\TaxonomyDeclaration;
use AgentCms\Core\Taxonomy\ConsumerRegistry;
use AgentCms\Core\Taxonomy\Reference\ContentSchemaReferenceProvider;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinition;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyKey;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 3A の受け入れ。
 *
 * SPEC §AF 計画 **#9**（ContentSchema の `taxonomies[]` 宣言）と、
 * STAGE 2A で保留していた **T-2 ContentSchema Reference Provider**。
 *
 * 実際のコンテンツタイプへの適用（news.json への接続）は次 Stage であり、
 * ここでは「同梱 schema を変更していない」ことも固定する。
 */
final class ContentSchemaTaxonomyTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<string> テスト中に作った一時ファイル */
    private array $temporary = [];

    /**
     * 一時 schema の置き場（テストごとに独立）。
     *
     * **配布ディレクトリへは書かない。** 以前は `content/schemas` へ直接
     * 一時ファイルを置いていたため、並行実行中の別のテストがそれを読み、
     * 「未知の schema」として失敗することがあった（Pest は複数プロセスで走る）。
     * 配布物を複製した砂場だけで実験し、他のプロセスから見えないようにする。
     */
    private string $sandbox = '';

    /**
     * 一時 taxonomy 定義の置き場（テストごとに独立）。
     *
     * `withoutDefinitions()` は配布ディレクトリの **JSON を全部消して**いた。
     * 並行実行中の別プロセスから見ると「定義が 1 つも無い」瞬間ができ、
     * 検査コマンドや Resolver が誤った結論を出す。ここも砂場に閉じ込める。
     */
    private string $definitionSandbox = '';

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            File::delete($path);
        }

        $this->temporary = [];

        foreach ([$this->sandbox, $this->definitionSandbox] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }

        $this->sandbox = '';
        $this->definitionSandbox = '';

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // 1. taxonomies[] contract
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomiesは省略でき空listになる(): void
    {
        $schema = ContentSchema::fromArray(['id' => 'demo', 'fields' => []]);

        $this->assertSame([], $schema->taxonomies);
        $this->assertSame([], $schema->taxonomyKeys());
        $this->assertSame([], $schema->toAdminSpec()['taxonomies']);
    }

    public function test_正常な宣言を読める(): void
    {
        $schema = $this->schema([
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
        ]);

        $this->assertCount(1, $schema->taxonomies);
        $this->assertTrue($schema->hasTaxonomy('news_category'));
        $this->assertSame(['news_category'], $schema->taxonomyKeys());

        $declaration = $schema->taxonomy('news_category');
        $this->assertInstanceOf(TaxonomyDeclaration::class, $declaration);
        $this->assertTrue($declaration->multiple);
        $this->assertFalse($declaration->required);
    }

    public function test_未知のtaxonomyキーはエラーになる(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->schema([])->taxonomy('unknown');
    }

    /** taxonomies は JSON 配列のみ。associative object を配列として受け入れない */
    public function test_associative_objectを配列として受け入れない(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/taxonomies は配列/');

        ContentSchema::fromArray([
            'id' => 'demo', 'fields' => [],
            'taxonomies' => ['a' => ['key' => 'x', 'multiple' => true, 'required' => false]],
        ]);
    }

    public function test_要素はobjectのみ(): void
    {
        foreach ([['news_category'], [123], [null], [true], [[['key' => 'x']]]] as $bad) {
            try {
                ContentSchema::fromArray(['id' => 'demo', 'fields' => [], 'taxonomies' => $bad]);
                $this->fail('要素 '.json_encode($bad).' を受理した');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_同一schema内のkey重複を拒否する(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->schema([
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
            ['key' => 'news_category', 'multiple' => false, 'required' => true],
        ]);
    }

    public function test_未知キーを拒否する(): void
    {
        foreach (['id', 'taxonomyId', 'taxonomy_id', 'name', 'label', 'terms', 'managedBy', 'unknown'] as $key) {
            try {
                $this->schema([[
                    'key' => 'news_category', 'multiple' => true, 'required' => false, $key => 'v',
                ]]);
                $this->fail("{$key} を受理した");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('未知のキー', $e->getMessage());
            }
        }
    }

    public function test_keyは必須で空でない文字列(): void
    {
        foreach ([
            ['multiple' => true, 'required' => false],
            ['key' => '', 'multiple' => true, 'required' => false],
            ['key' => 123, 'multiple' => true, 'required' => false],
            ['key' => null, 'multiple' => true, 'required' => false],
        ] as $bad) {
            try {
                $this->schema([$bad]);
                $this->fail('key='.json_encode($bad['key'] ?? null).' を受理した');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** 前後空白を黙って別の値へ変換しない */
    public function test_前後空白のあるkeyを黙って通さない(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/形式が不正/');

        $this->schema([['key' => ' news_category ', 'multiple' => true, 'required' => false]]);
    }

    /** taxonomy definition と同じ文法を使う（単一情報源） */
    public function test_keyの文法はtaxonomy_definitionと同一(): void
    {
        $this->assertSame(TaxonomyKey::PATTERN, TaxonomyDefinition::KEY_PATTERN);
        $this->assertSame(TaxonomyKey::MAX_LENGTH, TaxonomyDefinition::KEY_MAX);

        foreach (['News_Category', 'news-category', '1news', 'ニュース', str_repeat('a', 65)] as $bad) {
            $this->assertFalse(TaxonomyKey::isValid($bad), "{$bad} を valid とした");

            try {
                $this->schema([['key' => $bad, 'multiple' => true, 'required' => false]]);
                $this->fail("{$bad} を受理した");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_multipleとrequiredは必須のboolean(): void
    {
        foreach ([
            ['key' => 'x', 'required' => false],                          // multiple 欠落
            ['key' => 'x', 'multiple' => true],                           // required 欠落
            ['key' => 'x', 'multiple' => 'true', 'required' => false],    // 文字列
            ['key' => 'x', 'multiple' => 1, 'required' => false],         // 数値
            ['key' => 'x', 'multiple' => true, 'required' => 0],
            ['key' => 'x', 'multiple' => true, 'required' => 'false'],
            ['key' => 'x', 'multiple' => null, 'required' => false],
        ] as $bad) {
            try {
                $this->schema([$bad]);
                $this->fail(json_encode($bad).' を受理した');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** fields とは独立した構造。Field へ taxonomy 型を足していない */
    public function test_fieldsとは独立した構造である(): void
    {
        $schema = ContentSchema::fromArray([
            'id' => 'demo',
            'fields' => [['key' => 'title', 'type' => 'text', 'label' => 'タイトル']],
            'taxonomies' => [['key' => 'news_category', 'multiple' => true, 'required' => false]],
        ]);

        $this->assertSame(['title'], $schema->fieldKeys());
        $this->assertSame(['news_category'], $schema->taxonomyKeys());
        $this->assertFalse($schema->hasField('news_category'));
        $this->assertFalse($schema->hasTaxonomy('title'));

        // Field の型一覧に taxonomy を足していない（private const なので Reflection で見る）
        $types = (new \ReflectionClass(Field::class))->getConstant('TYPES');
        $this->assertIsArray($types);
        $this->assertNotContains('taxonomy', $types);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. SchemaRegistry の base / override 統合
    // ─────────────────────────────────────────────────────────────

    public function test_taxonomiesなしの既存schemaを壊さない(): void
    {
        $registry = app(SchemaRegistry::class);

        // 宣言を持たない同梱 schema は空 list のまま（STAGE 3C で接続したのは news だけ）
        foreach (['works', 'product'] as $id) {
            $this->assertSame([], $registry->get($id)->taxonomies, "{$id} に taxonomies が入っている");
        }
    }

    public function test_baseだけにtaxonomyがある(): void
    {
        $this->writeSchema('t_base', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);

        $this->assertSame(['alpha'], $this->freshRegistry()->get('t_base')->taxonomyKeys());
    }

    public function test_overrideだけからtaxonomyを追加できる(): void
    {
        $this->writeSchema('t_add', []);
        $this->writeOverride('t_add', ['taxonomies' => [
            ['key' => 'beta', 'multiple' => false, 'required' => true],
        ]]);

        $schema = $this->freshRegistry()->get('t_add');

        $this->assertSame(['beta'], $schema->taxonomyKeys());
        $this->assertTrue($schema->taxonomy('beta')->required);
    }

    public function test_同じkeyのoverrideで完全に置換する(): void
    {
        $this->writeSchema('t_rep', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('t_rep', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => false, 'required' => true],
        ]]);

        $declaration = $this->freshRegistry()->get('t_rep')->taxonomy('alpha');

        $this->assertFalse($declaration->multiple);
        $this->assertTrue($declaration->required);
    }

    public function test_overrideに無いbase宣言は維持される(): void
    {
        $this->writeSchema('t_keep', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
            ['key' => 'beta', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('t_keep', ['taxonomies' => [
            ['key' => 'beta', 'multiple' => false, 'required' => true],
        ]]);

        $schema = $this->freshRegistry()->get('t_keep');

        $this->assertSame(['alpha', 'beta'], $schema->taxonomyKeys());
        $this->assertTrue($schema->taxonomy('alpha')->multiple);
        $this->assertTrue($schema->taxonomy('beta')->required);
    }

    public function test_複数taxonomyの順序が決定的(): void
    {
        $this->writeSchema('t_order', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
            ['key' => 'beta', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('t_order', ['taxonomies' => [
            ['key' => 'gamma', 'multiple' => true, 'required' => false],
            ['key' => 'alpha', 'multiple' => false, 'required' => false],
        ]]);

        // base の順 → override で追加された分
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            $this->freshRegistry()->get('t_order')->taxonomyKeys(),
        );
    }

    public function test_base内の重複を拒否する(): void
    {
        $this->writeSchema('t_dupb', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
            ['key' => 'alpha', 'multiple' => false, 'required' => true],
        ]]);
        $this->writeOverride('t_dupb', ['taxonomies' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/重複/');
        $this->freshRegistry()->all();
    }

    public function test_override内の重複を拒否する(): void
    {
        $this->writeSchema('t_dupo', []);
        $this->writeOverride('t_dupo', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
            ['key' => 'alpha', 'multiple' => false, 'required' => true],
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/重複/');
        $this->freshRegistry()->all();
    }

    public function test_override宣言にも3項目すべてを要求する(): void
    {
        $this->writeSchema('t_part', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        // 部分マージを許さない = multiple だけの override は不正
        $this->writeOverride('t_part', ['taxonomies' => [['key' => 'alpha', 'multiple' => false]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->freshRegistry()->all();
    }

    public function test_override側の不正な型や未知キーも拒否する(): void
    {
        $this->writeSchema('t_badov', []);
        $this->writeOverride('t_badov', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false, 'id' => '01X'],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/未知のキー/');
        $this->freshRegistry()->all();
    }

    public function test_削除構文は用意しない(): void
    {
        $this->writeSchema('t_del', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        // null / remove 等の未承認構文は「不正な要素」として弾かれる。
        // 要素の検証は TaxonomyDeclaration へ委譲しているので InvalidArgumentException になる
        $this->writeOverride('t_del', ['taxonomies' => [null]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/オブジェクトで指定/');
        $this->freshRegistry()->all();
    }

    /** 既存の field attribute patch が従来どおり動く（回帰） */
    public function test_既存のfield_overrideが回帰していない(): void
    {
        // **同梱物に override があることを前提にしない。**
        // 配布 tree には overrides/ が無く（#24）、開発機に置いてあるものは
        // 追跡対象外なので、CI や利用者の環境では存在しない。
        // ここで検証したいのは「field patch が効くこと」なので、砂場に自分で置く
        $this->writeOverride('works', ['fields' => ['body' => ['required' => false]]]);

        $works = $this->freshRegistry()->get('works');

        $this->assertFalse($works->field('body')->required, 'field override の回帰');
        $this->assertTrue($works->field('title')->required);
    }

    public function test_taxonomiesとfield_patchを同じoverrideで併用できる(): void
    {
        $this->writeSchema('t_both', [
            'fields' => [['key' => 'title', 'type' => 'text', 'label' => 'T', 'required' => true]],
            'taxonomies' => [['key' => 'alpha', 'multiple' => true, 'required' => false]],
        ]);
        $this->writeOverride('t_both', [
            'fields' => ['title' => ['required' => false]],
            'taxonomies' => [['key' => 'beta', 'multiple' => false, 'required' => false]],
        ]);

        $schema = $this->freshRegistry()->get('t_both');

        $this->assertFalse($schema->field('title')->required);
        $this->assertSame(['alpha', 'beta'], $schema->taxonomyKeys());
    }

    // ─────────────────────────────────────────────────────────────
    // 3. schema read surface
    // ─────────────────────────────────────────────────────────────

    public function test_schema_read_responseにtaxonomiesが含まれる(): void
    {
        $result = app(GetContentSchema::class)->execute('news', OperationContext::admin($this->operatorId(), 'a'));

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('taxonomies', $result->data['schema']);
        // STAGE 3C で news_category を接続した（宣言だけ。ULID や term 一覧は混ぜない）
        $this->assertSame(
            [['key' => 'news_category', 'multiple' => true, 'required' => false]],
            $result->data['schema']['taxonomies'],
        );
        $this->assertArrayHasKey('fields', $result->data['schema']);
    }

    public function test_read_responseはkey_multiple_requiredだけを返す(): void
    {
        $spec = $this->schema([
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
        ])->toAdminSpec();

        $this->assertSame([
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
        ], $spec['taxonomies']);

        // taxonomy ID や term 一覧を混ぜない
        $this->assertArrayNotHasKey('id', $spec['taxonomies'][0]);
        $this->assertArrayNotHasKey('terms', $spec['taxonomies'][0]);
    }

    public function test_schema用の新しい_operationを追加していない(): void
    {
        // STAGE 3A では schema 専用 operation を足していない。
        // #14 / #15 で増えたのは分類の 7 操作だけ（3 者一致は TaxonomySurfaceParityTest）
        $this->assertTrue(OperationCatalog::allows('content.schema.get'));

        foreach (['schema.update', 'schema.create', 'taxonomy.create'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 4. T-2 ContentSchema Reference Provider
    // ─────────────────────────────────────────────────────────────

    public function test_t2_参照が無ければ参照なし(): void
    {
        $taxonomy = $this->makeTaxonomy('unused_tax');

        $probe = $this->provider()->forTaxonomy($taxonomy);

        $this->assertSame(0, $probe->count);
        $this->assertFalse($probe->isUndetermined());
    }

    public function test_t2_base_schemaからの参照を数える(): void
    {
        $this->writeSchema('t_ref', ['label' => '参照元', 'taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $taxonomy = $this->makeTaxonomy('shared_tax');

        $probe = (new ContentSchemaReferenceProvider($this->freshRegistry()))->forTaxonomy($taxonomy);

        $this->assertSame(1, $probe->count);
        $this->assertSame(['参照元（t_ref）'], $probe->samples);
    }

    public function test_t2_overrideから追加された参照も数える(): void
    {
        $this->writeSchema('t_ovref', []);
        $this->writeOverride('t_ovref', ['taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $taxonomy = $this->makeTaxonomy('shared_tax');

        $this->assertSame(
            1,
            (new ContentSchemaReferenceProvider($this->freshRegistry()))->forTaxonomy($taxonomy)->count,
        );
    }

    public function test_t2_overrideで置換された宣言も数える(): void
    {
        $this->writeSchema('t_repref', ['taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('t_repref', ['taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => false, 'required' => true],
        ]]);
        $taxonomy = $this->makeTaxonomy('shared_tax');

        // 置換されても 1 件のまま（同じ Content Type を重複計上しない）
        $this->assertSame(
            1,
            (new ContentSchemaReferenceProvider($this->freshRegistry()))->forTaxonomy($taxonomy)->count,
        );
    }

    public function test_t2_複数_content_typeからの参照を数える(): void
    {
        $this->writeSchema('t_a', ['taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeSchema('t_b', ['taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $taxonomy = $this->makeTaxonomy('shared_tax');

        $probe = (new ContentSchemaReferenceProvider($this->freshRegistry()))->forTaxonomy($taxonomy);

        $this->assertSame(2, $probe->count);
        $this->assertCount(2, $probe->samples);
    }

    /** T-2 の対象は Taxonomy であって個別 Term ではない */
    public function test_t2_term照会では参照なし(): void
    {
        $this->writeSchema('t_term', ['taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $taxonomy = $this->makeTaxonomy('shared_tax');
        $term = $this->makeTerm($taxonomy);

        $probe = (new ContentSchemaReferenceProvider($this->freshRegistry()))->forTerm($term);

        $this->assertSame(0, $probe->count);
        $this->assertFalse($probe->isUndetermined());
    }

    public function test_t2_schema_registry読込失敗はundetermined(): void
    {
        $taxonomy = $this->makeTaxonomy('shared_tax');

        // 壊れた schema を置くと SchemaRegistry が例外を投げる
        $this->writeRaw('broken_schema.json', '{ "id": "broken_schema", ');

        $inspector = new ReferenceInspector([
            new ContentSchemaReferenceProvider($this->freshRegistry()),
        ]);
        $report = $inspector->referencesToTaxonomy($taxonomy);

        $this->assertFalse($report->isComplete(), '読込失敗を参照ゼロにした');
        $this->assertFalse($report->isDeletable());
        $this->assertStringContainsString('コンテンツタイプ定義', implode(' ', $report->undeterminedReasons()));
    }

    public function test_t2_reportの日本語summary(): void
    {
        $this->writeSchema('t_sum', ['label' => 'デモ', 'taxonomies' => [
            ['key' => 'shared_tax', 'multiple' => true, 'required' => false],
        ]]);
        $taxonomy = $this->makeTaxonomy('shared_tax');

        $report = (new ReferenceInspector([
            new ContentSchemaReferenceProvider($this->freshRegistry()),
        ]))->referencesToTaxonomy($taxonomy);

        $this->assertFalse($report->isDeletable());
        $this->assertSame(['content_schema'], $report->kinds());
        $this->assertStringContainsString('コンテンツタイプ定義 1 件', $report->summary());
    }

    public function test_t2_providerが二重登録されていない(): void
    {
        $kinds = app(ReferenceInspector::class)->kinds();

        $this->assertSame($kinds, array_unique($kinds), 'provider が二重登録されている');
        $this->assertSame(
            ['assignment', 'child_term', 'page_document', 'content_schema'],
            $kinds,
        );
    }

    /** 既存 provider の回帰がない */
    public function test_既存providerの回帰がない(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $term = $this->makeTerm($taxonomy);

        // assignment / child_term / page_document は従来どおり動く
        TaxonomyTermAssignment::query()->create([
            'term_id' => $term->id, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $report = app(ReferenceInspector::class)->referencesToTerm($term);

        $this->assertFalse($report->isDeletable());
        $this->assertSame(['assignment'], $report->kinds());
        $this->assertTrue($report->isComplete());
    }

    // ─────────────────────────────────────────────────────────────
    // 5. taxonomy-check
    // ─────────────────────────────────────────────────────────────

    public function test_check_schema参照なしdefinitionなしはsuccess(): void
    {
        // STAGE 3C で news が news_category を参照するようになったため、
        // 「参照ゼロ」の状態を作るには schema 側の宣言も外す必要がある
        $this->withoutSchemaReferences(function (): void {
            $this->withoutDefinitions(function (): void {
                // 定義も参照も 0 件のときは索引も空でなければならない
                // （STAGE 3C CORRECTION #2 / F-3C-3: この場合も索引を必ず検証する）
                $this->useMatchingRegistry();

                $this->artisan('agentcms:taxonomy-check')
                    ->expectsOutputToContain('分類の定義はありません')
                    ->assertExitCode(0);
            });
        });
    }

    /** schema 参照があるのに definition が無ければ DB 接続前に FAIL */
    public function test_check_schema参照ありdefinitionなしはfail(): void
    {
        $this->writeSchema('t_missing', ['taxonomies' => [
            ['key' => 'nonexistent_tax', 'multiple' => true, 'required' => false],
        ]]);

        $this->withoutDefinitions(function (): void {
            $this->artisan('agentcms:taxonomy-check')
                ->expectsOutputToContain('参照している分類の定義がありません')
                ->expectsOutputToContain('nonexistent_tax')
                ->doesntExpectOutputToContain('分類の定義はありません')
                ->assertExitCode(1);
        });
    }

    public function test_check_schema宣言が不正ならfail(): void
    {
        $this->writeRaw('t_badspec.json', json_encode([
            'id' => 't_badspec', 'fields' => [],
            'taxonomies' => [['key' => 'x', 'multiple' => 'true', 'required' => false]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('コンテンツタイプの定義')
            ->assertExitCode(1);
    }

    public function test_check_正常同期はsuccess(): void
    {
        $this->writeSchema('t_ok', ['taxonomies' => [
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
        ]]);
        app(TaxonomySynchronizer::class)->sync();
        $this->useMatchingRegistry();

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('コンテンツタイプからの参照')
            ->assertExitCode(0);
    }

    public function test_check_definitionあり_d_b未同期はfail(): void
    {
        $this->writeSchema('t_unsync', ['taxonomies' => [
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
        ]]);
        $this->useMatchingRegistry();

        // sync していないので DB に無い
        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('未同期')
            ->assertExitCode(1);
    }

    public function test_check_definitionと_d_bの競合はfail(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        Taxonomy::query()->where('key', 'news_category')
            ->update(['managed_by' => TaxonomyManagedBy::Cms->value]);

        $this->artisan('agentcms:taxonomy-check')->assertExitCode(1);
    }

    /** ContentSchema から参照されていない definition も従来どおり検査する */
    public function test_check_未参照definitionの未同期も見逃さない(): void
    {
        // 参照されている news_category は先に同期しておく（未同期の原因を切り分けるため）
        app(TaxonomySynchronizer::class)->sync();

        // 誰からも参照されていない定義を後から足す
        $this->writeDefinition('unused_tax');
        $this->useMatchingRegistry();

        $this->assertFalse(
            collect(app(SchemaRegistry::class)->all())
                ->contains(fn ($s) => $s->hasTaxonomy('unused_tax')),
            'unused_tax を参照している schema がある',
        );

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('未同期')
            ->assertExitCode(1);
    }

    public function test_check_実行前後で_d_b件数が変わらない(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $taxonomy = Taxonomy::query()->firstOrFail();
        $this->makeTerm($taxonomy);

        $before = [
            Taxonomy::query()->count(),
            TaxonomyTerm::query()->count(),
            TaxonomyTermAssignment::query()->count(),
        ];

        $this->artisan('agentcms:taxonomy-check');

        $this->assertSame($before, [
            Taxonomy::query()->count(),
            TaxonomyTerm::query()->count(),
            TaxonomyTermAssignment::query()->count(),
        ]);
    }

    public function test_check_d_b接続不可でも解決済みと表示しない(): void
    {
        $this->writeSchema('t_nodb', ['taxonomies' => [
            ['key' => 'news_category', 'multiple' => true, 'required' => false],
        ]]);
        $this->useMatchingRegistry();

        $original = config('database.default');
        config([
            'database.connections.stage3a_unreachable' => [
                'driver' => 'sqlite',
                'database' => '/agentcms-nonexistent-'.uniqid().'/db.sqlite',
                'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'database.default' => 'stage3a_unreachable',
        ]);

        try {
            $this->artisan('agentcms:taxonomy-check')
                ->expectsOutputToContain('データベースへ接続できないため')
                ->doesntExpectOutputToContain('すべて解決できました')
                ->assertExitCode(0);
        } finally {
            config(['database.default' => $original]);
            DB::purge('stage3a_unreachable');
        }
    }

    public function test_check_接続後のテーブル不足はfail(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('taxonomy_term_assignments');
        Schema::enableForeignKeyConstraints();

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('分類用のテーブルがありません')
            ->doesntExpectOutputToContain('データベースへ接続できないため')
            ->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION F-3A-1: 未指定と明示 null を同一視しない
    // ─────────────────────────────────────────────────────────────

    /** A: キー未指定は有効で空 list */
    public function test_c_a_taxonomiesキー未指定は空list(): void
    {
        $schema = ContentSchema::fromArray(['id' => 'demo', 'fields' => []]);

        $this->assertSame([], $schema->taxonomies);
        $this->assertSame([], $schema->toAdminSpec()['taxonomies']);
    }

    /** B: base の `taxonomies: null` は無効。空 list として受理しない */
    public function test_c_b_base_taxonomies_nullを拒否する(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/null は使えません/');

        ContentSchema::fromArray(['id' => 'demo', 'fields' => [], 'taxonomies' => null]);
    }

    /** キーが存在すれば list 以外はすべて拒否 */
    public function test_c_b2_list以外の値を拒否する(): void
    {
        foreach ([null, 'news_category', 123, true, false,
            ['a' => ['key' => 'x', 'multiple' => true, 'required' => false]]] as $bad) {
            try {
                ContentSchema::fromArray(['id' => 'demo', 'fields' => [], 'taxonomies' => $bad]);
                $this->fail('taxonomies='.json_encode($bad).' を受理した');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** C: override の `taxonomies: null` を no-op として受理しない */
    public function test_c_c_override_taxonomies_nullを拒否する(): void
    {
        $this->writeSchema('c_ovnull', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('c_ovnull', ['taxonomies' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/null は使えません/');
        $this->freshRegistry()->all();
    }

    /** D: base が null で override が正常でも、base の不正として FAIL */
    public function test_c_d_baseのnullをoverrideで隠せない(): void
    {
        $this->writeRaw('c_basenull.json', (string) json_encode([
            'id' => 'c_basenull', 'kind' => 'collection', 'fields' => [], 'taxonomies' => null,
        ]));
        $this->writeOverride('c_basenull', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Schema \[c_basenull\]/');
        $this->freshRegistry()->all();
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION: base の不正を正常な override で隠せない
    // ─────────────────────────────────────────────────────────────

    /** E: base の不正な multiple を同じ key の正常な override で置換しても FAIL */
    public function test_c_e_baseの不正multipleをoverrideで隠せない(): void
    {
        foreach (['true', 'INVALID', 1, null] as $bad) {
            $this->assertBaseRejectedDespiteOverride(
                ['key' => 'alpha', 'multiple' => $bad, 'required' => false],
                'multiple='.json_encode($bad),
            );
        }
    }

    /** F: base の不正な required も同様 */
    public function test_c_f_baseの不正requiredをoverrideで隠せない(): void
    {
        foreach (['false', 0, 1, null] as $bad) {
            $this->assertBaseRejectedDespiteOverride(
                ['key' => 'alpha', 'multiple' => true, 'required' => $bad],
                'required='.json_encode($bad),
            );
        }
    }

    /** G: base の項目欠落も同様 */
    public function test_c_g_baseの項目欠落をoverrideで隠せない(): void
    {
        $this->assertBaseRejectedDespiteOverride(
            ['key' => 'alpha', 'required' => false],
            'multiple 欠落',
        );
        $this->assertBaseRejectedDespiteOverride(
            ['key' => 'alpha', 'multiple' => true],
            'required 欠落',
        );
    }

    /** H: base の未知キーも同様 */
    public function test_c_h_baseの未知キーをoverrideで隠せない(): void
    {
        $this->assertBaseRejectedDespiteOverride(
            ['key' => 'alpha', 'multiple' => true, 'required' => false, 'label' => '不正'],
            '未知キー label',
        );
    }

    /** base の key 文法違反も同様 */
    public function test_c_h2_baseの不正keyをoverrideで隠せない(): void
    {
        $this->writeSchema('c_badkey', ['taxonomies' => [
            ['key' => 'Alpha', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('c_badkey', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/形式が不正/');
        $this->freshRegistry()->all();
    }

    /** override 側の不正も統合前に弾く */
    public function test_c_h3_overrideの不正も統合前に弾く(): void
    {
        $this->writeSchema('c_badov', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('c_badov', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => 'true', 'required' => false],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->freshRegistry()->all();
    }

    /** I: 正常な base を正常な完全 override で置換する既存 semantics は維持 */
    public function test_c_i_正常な完全置換は従来どおり成功する(): void
    {
        $this->writeSchema('c_ok', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('c_ok', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => false, 'required' => true],
        ]]);

        $declaration = $this->freshRegistry()->get('c_ok')->taxonomy('alpha');

        $this->assertFalse($declaration->multiple);
        $this->assertTrue($declaration->required);
    }

    /** J: override から新しい key を追加すると base の後ろへ付く */
    public function test_c_j_override追加分は末尾へ付く(): void
    {
        $this->writeSchema('c_add', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);
        $this->writeOverride('c_add', ['taxonomies' => [
            ['key' => 'zeta', 'multiple' => true, 'required' => false],
        ]]);

        $this->assertSame(['alpha', 'zeta'], $this->freshRegistry()->get('c_add')->taxonomyKeys());
    }

    // ─────────────────────────────────────────────────────────────
    // CORRECTION: taxonomy-check が DB 接続前に検出する
    // ─────────────────────────────────────────────────────────────

    /** K: base の `taxonomies: null` を DB 接続前に FAIL */
    public function test_c_k_checkはbaseのnullを_d_b接続前に検出する(): void
    {
        $this->writeRaw('c_chknull.json', (string) json_encode([
            'id' => 'c_chknull', 'kind' => 'collection', 'fields' => [], 'taxonomies' => null,
        ]));

        $before = $this->taxonomyCounts();

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('コンテンツタイプの定義')
            ->doesntExpectOutputToContain('データベースへ接続できないため')
            ->doesntExpectOutputToContain('未同期')
            ->assertExitCode(1);

        // DB へ write していない / sync も走っていない
        $this->assertSame($before, $this->taxonomyCounts());
    }

    /** L: 正常な override で隠されそうな base 不正も DB 接続前に FAIL */
    public function test_c_l_checkは隠されたbase不正を_d_b接続前に検出する(): void
    {
        $this->writeSchema('c_chkhidden', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => 'INVALID', 'required' => false],
        ]]);
        $this->writeOverride('c_chkhidden', ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);

        $before = $this->taxonomyCounts();

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('コンテンツタイプの定義')
            ->assertExitCode(1);

        $this->assertSame($before, $this->taxonomyCounts());
    }

    // ─────────────────────────────────────────────────────────────
    // 6. 同梱 schema を変更していないこと
    // ─────────────────────────────────────────────────────────────

    /**
     * STAGE 3C で接続したのは **news の news_category だけ**（旧ガードの後継）。
     *
     * 「まだ何も接続していない」という旧契約を消して弱めるのではなく、
     * **接続した範囲を厳密に固定する**契約へ置き換えてある。
     */
    public function test_同梱schemaはnewsのnews_categoryだけを宣言する(): void
    {
        /** @var array<string, mixed> $news */
        $news = json_decode((string) file_get_contents(base_path('content/schemas/news.json')), true);

        $this->assertSame(
            [['key' => 'news_category', 'multiple' => true, 'required' => false]],
            $news['taxonomies'],
            'news.json の宣言が確定値と違う',
        );

        // checked-in JSON へ ULID を書かない（§J.2）
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9A-HJKMNP-TV-Z]{26}/',
            (string) file_get_contents(base_path('content/schemas/news.json')),
        );

        // fields 側には news_category を足さない（分類は field ではない）
        $this->assertNotContains('news_category', app(SchemaRegistry::class)->get('news')->fieldKeys());

        // news 以外は宣言なし
        foreach (['works', 'product', 'media', 'site', 'contact'] as $id) {
            /** @var array<string, mixed> $raw */
            $raw = json_decode((string) file_get_contents(base_path("content/schemas/{$id}.json")), true);

            $this->assertArrayNotHasKey('taxonomies', $raw, "{$id}.json に taxonomies を追加している");
        }

        // works.category は select のまま
        $works = app(SchemaRegistry::class)->get('works');
        $this->assertSame('select', $works->field('category')->type);
        $this->assertContains('web', $works->field('category')->choices);
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $taxonomies */
    private function schema(array $taxonomies): ContentSchema
    {
        return ContentSchema::fromArray([
            'id' => 'demo', 'fields' => [], 'taxonomies' => $taxonomies,
        ]);
    }

    private function schemaPath(): string
    {
        if ($this->sandbox === '') {
            $this->sandbox = storage_path('framework/testing/schemas-'.uniqid('', true));
            File::ensureDirectoryExists($this->sandbox);

            // **配布 tree と同じ形にする。** base の定義だけを複製し、
            // 開発機に置いてある overrides/（追跡対象外・#24）は持ち込まない。
            // 持ち込むと「CI では存在しない状態」に依存したテストを書けてしまう
            foreach (File::files(base_path('content/schemas')) as $file) {
                File::copy($file->getPathname(), $this->sandbox.'/'.$file->getFilename());
            }

            // artisan コマンド（taxonomy-check / sync）も同じ砂場を見るようにする。
            // **instance ではなく bind**。テスト中に forgetInstance されても、
            // 作り直しでまた配布物を読みに行かないようにする
            $sandbox = $this->sandbox;
            $this->app->bind(SchemaRegistry::class, static fn (): SchemaRegistry => new SchemaRegistry($sandbox));
        }

        return $this->sandbox;
    }

    /** @param array<string, mixed> $extra */
    private function writeSchema(string $id, array $extra): void
    {
        $this->writeRaw("{$id}.json", (string) json_encode(
            array_merge(['id' => $id, 'kind' => 'collection', 'fields' => []], $extra),
            JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param array<string, mixed> $data */
    private function writeOverride(string $id, array $data): void
    {
        $path = $this->schemaPath()."/overrides/{$id}.json";

        // 配布 tree に overrides/ は無い（#24）。砂場側で作ってから書く
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode(array_merge(['id' => $id], $data), JSON_UNESCAPED_UNICODE));
        $this->temporary[] = $path;
    }

    private function writeRaw(string $name, string $contents): void
    {
        $path = $this->schemaPath()."/{$name}";
        File::put($path, $contents);
        $this->temporary[] = $path;
    }

    private function freshRegistry(): SchemaRegistry
    {
        return new SchemaRegistry($this->schemaPath());
    }

    private function provider(): ContentSchemaReferenceProvider
    {
        return new ContentSchemaReferenceProvider($this->freshRegistry());
    }

    /**
     * 一時 schema / definition に合わせた索引を用意する。
     *
     * consumer 乖離検査（STAGE 3C CORRECTION / F-3C-2）はテスト用の一時 schema も
     * 対象にするため、**このテストが見たい段階へ到達できるよう**索引を揃える。
     * 乖離検出そのものは `TaxonomyConsumerRegistryTest` の責務。
     * リポジトリの実ファイルは書き換えない（一時ファイルへ差し替える）。
     */
    private function useMatchingRegistry(): void
    {
        app()->forgetInstance(TaxonomyDefinitionRegistry::class);

        $consumers = [];

        foreach ($this->freshRegistry()->all() as $schema) {
            foreach ($schema->taxonomyKeys() as $key) {
                $consumers[$key][] = $schema->id;
            }
        }

        $taxonomies = [];

        foreach (array_keys(app(TaxonomyDefinitionRegistry::class)->all()) as $key) {
            $list = $consumers[$key] ?? [];
            sort($list, SORT_STRING);

            $taxonomies[] = [
                'key' => $key,
                'definition' => "content/taxonomies/{$key}.json",
                'consumers' => $list,
            ];
        }

        $path = sys_get_temp_dir().'/agentcms-schema-registry-'.uniqid().'.json';
        File::put($path, (string) json_encode(['taxonomies' => $taxonomies], JSON_UNESCAPED_SLASHES));
        $this->temporary[] = $path;

        config(['agentcms.taxonomy_registry_path' => $path]);
        app()->forgetInstance(ConsumerRegistry::class);
    }

    /**
     * taxonomy 定義の置き場。配布ディレクトリを複製した砂場を使う。
     *
     * 検査コマンドも Resolver も同じ砂場を見るよう、registry を bind し直す
     * （`instance` だと `forgetInstance()` のあとに配布物へ戻ってしまう）。
     */
    private function definitionPath(): string
    {
        if ($this->definitionSandbox === '') {
            $this->definitionSandbox = storage_path('framework/testing/taxonomies-'.uniqid('', true));
            File::copyDirectory(base_path('content/taxonomies'), $this->definitionSandbox);

            $dir = $this->definitionSandbox;
            $this->app->bind(
                TaxonomyDefinitionRegistry::class,
                static fn (): TaxonomyDefinitionRegistry => new TaxonomyDefinitionRegistry($dir),
            );
        }

        return $this->definitionSandbox;
    }

    /** 誰からも参照されていない定義ファイルを足す */
    private function writeDefinition(string $key): void
    {
        $path = $this->definitionPath()."/{$key}.json";
        File::put($path, (string) json_encode([
            'key' => $key, 'label' => $key, 'hierarchical' => true, 'managed_by' => 'code',
        ], JSON_UNESCAPED_UNICODE));
        $this->temporary[] = $path;

        app()->forgetInstance(TaxonomyDefinitionRegistry::class);
    }

    /** 同梱 schema の taxonomies 宣言を一時的に外す（参照ゼロの状態を作る） */
    private function withoutSchemaReferences(callable $body): void
    {
        $path = $this->schemaPath().'/news.json';
        $backup = (string) File::get($path);

        /** @var array<string, mixed> $data */
        $data = json_decode($backup, true, 32, JSON_THROW_ON_ERROR);
        unset($data['taxonomies']);

        File::put($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        app()->forgetInstance(SchemaRegistry::class);

        try {
            $body();
        } finally {
            File::put($path, $backup);
            app()->forgetInstance(SchemaRegistry::class);
        }
    }

    /** 定義ディレクトリを一時的に空にする */
    private function withoutDefinitions(callable $body): void
    {
        $dir = $this->definitionPath();
        $backup = [];

        foreach (File::glob($dir.'/*.json') ?: [] as $file) {
            $backup[$file] = (string) File::get($file);
            File::delete($file);
        }

        try {
            $body();
        } finally {
            foreach ($backup as $file => $contents) {
                File::put($file, $contents);
            }
        }
    }

    /**
     * base に不正な宣言を置き、同じ key の**正常な** override で置換しても
     * base 側の不正として FAIL することを確かめる。
     *
     * @param  array<string, mixed>  $badBaseDeclaration
     */
    private function assertBaseRejectedDespiteOverride(array $badBaseDeclaration, string $label): void
    {
        $id = 'c_hidden_'.substr(md5($label), 0, 8);

        $this->writeSchema($id, ['taxonomies' => [$badBaseDeclaration]]);
        $this->writeOverride($id, ['taxonomies' => [
            ['key' => 'alpha', 'multiple' => true, 'required' => false],
        ]]);

        try {
            $this->freshRegistry()->all();
            $this->fail("base の不正（{$label}）を override が隠してしまった");
        } catch (InvalidArgumentException|RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            File::delete($this->schemaPath()."/{$id}.json");
            File::delete($this->schemaPath()."/overrides/{$id}.json");
        }
    }

    /** @return list<int> taxonomies / terms / assignments の件数 */
    private function taxonomyCounts(): array
    {
        return [
            Taxonomy::query()->count(),
            TaxonomyTerm::query()->count(),
            TaxonomyTermAssignment::query()->count(),
        ];
    }

    private function makeTaxonomy(string $key): Taxonomy
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(), 'key' => $key, 'label' => 'demo',
            'hierarchical' => true, 'managed_by' => TaxonomyManagedBy::Code->value,
        ])->save();

        return $taxonomy;
    }

    private function makeTerm(Taxonomy $taxonomy): TaxonomyTerm
    {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(), 'taxonomy_id' => $taxonomy->id,
            'name' => 'demo', 'slug' => 'demo-'.uniqid(), 'sort_order' => 0,
        ])->save();

        return $term;
    }
}
