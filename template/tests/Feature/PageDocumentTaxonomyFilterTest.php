<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / #22 — PageDocument の taxonomy filter（SPEC §O / AR-5 / D-9）。
 *
 * 固定するのは 3 つ。
 *
 * 1. **`{taxonomy, terms}` は通常の `{field, op, value}` filter へ落ちない。**
 *    落ちると `field` が無いので必ず「使えないフィールド」になり、分類で絞り込めなくなる。
 * 2. **正は immutable term ID。** 名前や slug を変えても、archive しても表示は変わらない。
 * 3. **key が合っているだけで term の所属を信じない。** 別分類の term ID は保存できない。
 *
 * 参照調査（Reference Inspector）側の構造判定は `TaxonomyDomainRulesTest` の責務。
 * ここでは **保存時検証と描画**を見る。
 */
final class PageDocumentTaxonomyFilterTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private Taxonomy $category;

    /** 別分類（所属の取り違えを検出するための対照） */
    private Taxonomy $other;

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomySynchronizer::class)->sync();

        $this->category = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $this->other = $this->makeTaxonomy('unrelated_tax');
    }

    // ─────────────────────────────────────────────────────────────
    // 検証: 通る形
    // ─────────────────────────────────────────────────────────────

    public function test_有効なterm_idのtaxonomy_filterは保存できる(): void
    {
        $term = $this->makeTerm($this->category, 'press');

        $this->assertSame([], $this->validateFilter([
            'taxonomy' => 'news_category',
            'terms' => [$term->id],
        ]));
    }

    /** AR-5: archive は「新規に付けられない」であって、既存 filter を壊す操作ではない */
    public function test_archived_termのidも絞り込みに使える(): void
    {
        $term = $this->makeTerm($this->category, 'archived-one', archived: true);

        $this->assertTrue($term->isArchived());
        $this->assertSame([], $this->validateFilter([
            'taxonomy' => 'news_category',
            'terms' => [$term->id],
        ]));
    }

    public function test_複数termはorとして1つのfilterに並べられる(): void
    {
        $a = $this->makeTerm($this->category, 'press');
        $b = $this->makeTerm($this->category, 'event');

        $this->assertSame([], $this->validateFilter([
            'taxonomy' => 'news_category',
            'terms' => [$a->id, $b->id, $a->id], // 重複は結果を変えないので許す
        ]));
    }

    // ─────────────────────────────────────────────────────────────
    // 検証: 弾く形
    // ─────────────────────────────────────────────────────────────

    public function test_存在しない分類は保存できない(): void
    {
        $term = $this->makeTerm($this->category, 'press');

        $this->assertErrorContains(
            ['taxonomy' => 'no_such_taxonomy', 'terms' => [$term->id]],
            '分類 [no_such_taxonomy] は存在しません',
        );
    }

    public function test_存在しないterm_idは保存できない(): void
    {
        $ghost = TaxonomyId::generate();

        $this->assertErrorContains(
            ['taxonomy' => 'news_category', 'terms' => [$ghost]],
            "分類項目 [{$ghost}] は存在しません",
        );
    }

    /** key 一致だけで所属を信じない。ID の持ち主を必ず確認する */
    public function test_別の分類のterm_idは保存できない(): void
    {
        $foreign = $this->makeTerm($this->other, 'foreign');

        $this->assertErrorContains(
            ['taxonomy' => 'news_category', 'terms' => [$foreign->id]],
            "分類項目 [{$foreign->id}] は分類 [news_category] のものではありません",
        );
    }

    /** slug で書いても解決しない（正は ID — §O） */
    public function test_slugでは解決しない(): void
    {
        $term = $this->makeTerm($this->category, 'press');

        $this->assertErrorContains(
            ['taxonomy' => 'news_category', 'terms' => [$term->slug]],
            "分類項目 [{$term->slug}] は存在しません",
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedFilters(): iterable
    {
        yield '分類名が文字列でない' => [
            ['taxonomy' => ['news_category'], 'terms' => []],
            '絞り込み条件の分類名が文字列ではありません',
        ];
        yield '分類名が空' => [
            ['taxonomy' => '', 'terms' => []],
            '絞り込み条件の分類名が文字列ではありません',
        ];
        yield 'termsが無い' => [
            ['taxonomy' => 'news_category'],
            '絞り込み条件に terms がありません',
        ];
        yield 'termsが配列でない' => [
            ['taxonomy' => 'news_category', 'terms' => 'press'],
            '絞り込み条件の terms が配列ではありません',
        ];
        yield 'termsに文字列でない要素' => [
            ['taxonomy' => 'news_category', 'terms' => [123]],
            '絞り込み条件の terms に文字列でない要素があります',
        ];
        yield 'termsが空' => [
            ['taxonomy' => 'news_category', 'terms' => []],
            '分類 [news_category] の絞り込みに項目が 1 つも指定されていません',
        ];
        yield 'termsが連想配列' => [
            // contract は list<string>。key に意味を持たせた形を並びとして解釈しない
            ['taxonomy' => 'news_category', 'terms' => ['first' => '01KZZZZZZZZZZZZZZZZZZZZZZZ']],
            '絞り込み条件の terms は並びではありません',
        ];
    }

    /**
     * 壊れた taxonomy filter は黙って無視しない。
     *
     * 無視すると「絞り込んだつもりで全件出る」になり、
     * 出すつもりのなかった記事が公開ページに載る。
     *
     * @param  array<string, mixed>  $filter
     */
    #[DataProvider('malformedFilters')]
    public function test_壊れたtaxonomy_filterは弾かれる(array $filter, string $expected): void
    {
        $this->assertErrorContains($filter, $expected);
    }

    public function test_filters自体が配列でなければ弾かれる(): void
    {
        $errors = app(DocumentValidator::class)->validate(
            $this->documentWith(['source' => 'collection', 'type' => 'news', 'filters' => 'press']),
        );

        $this->assertContains('絞り込み条件の形式が不正です', $this->messages($errors));
    }

    public function test_filtersが連想配列なら弾かれる(): void
    {
        $term = $this->makeTerm($this->category, 'press');

        $errors = app(DocumentValidator::class)->validate(
            $this->documentWith([
                'source' => 'collection', 'type' => 'news',
                'filters' => ['first' => ['taxonomy' => 'news_category', 'terms' => [$term->id]]],
            ]),
        );

        $this->assertContains('絞り込み条件の形式が不正です', $this->messages($errors));
    }

    /** 描画側も連想配列を並びとして解釈しない（検証と同じ結論） */
    public function test_描画側も連想配列のtermsで全件を出さない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);

        $html = $this->renderCollection('assoc-terms', [
            ['taxonomy' => 'news_category', 'terms' => ['first' => $press->id]],
        ], validate: false);

        $this->assertStringNotContainsString('プレスの記事', $html);
    }

    // ─────────────────────────────────────────────────────────────
    // 通常 field filter との共存（non-regression）
    // ─────────────────────────────────────────────────────────────

    public function test_通常のfield_filterは今までどおり検証される(): void
    {
        $this->assertSame([], $this->validateFilter(['field' => 'status', 'value' => ['published']]));

        $this->assertContains(
            '絞り込みに使えないフィールドです: nope',
            $this->messages(app(DocumentValidator::class)->validate(
                $this->documentWith([
                    'source' => 'collection', 'type' => 'news',
                    'filters' => [['field' => 'nope', 'value' => ['x']]],
                ]),
            )),
        );
    }

    public function test_taxonomy_filterとfield_filterは同じbindingに共存できる(): void
    {
        $term = $this->makeTerm($this->category, 'press');

        $errors = app(DocumentValidator::class)->validate(
            $this->documentWith([
                'source' => 'collection', 'type' => 'news',
                'filters' => [
                    ['taxonomy' => 'news_category', 'terms' => [$term->id]],
                    ['field' => 'status', 'value' => ['published']],
                ],
            ]),
        );

        $this->assertSame([], $errors);
    }

    // ─────────────────────────────────────────────────────────────
    // 実経路（保存前に弾く）
    // ─────────────────────────────────────────────────────────────

    public function test_不正なfilterはpatch実経路で保存前に拒否される(): void
    {
        $page = $this->makePage('news-page', 'お知らせ');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);

        $before = $this->draftDocument($page);
        $versions = PageVersion::query()->where('page_id', $page->id)->count();

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => [
                'type' => 'collection',
                'props' => ['layout' => 'list', 'limit' => 3],
                'binding' => [
                    'source' => 'collection', 'type' => 'news',
                    'filters' => [['taxonomy' => 'news_category', 'terms' => [TaxonomyId::generate()]]],
                ],
            ],
        ], $this->admin());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        // 下書きは 1 文字も変わっていない
        $this->assertSame($before, $this->draftDocument($page));
        // 版も増えていない（検証は保存の手前）
        $this->assertSame($versions, PageVersion::query()->where('page_id', $page->id)->count());
    }

    public function test_正しいfilterはpatch実経路で保存できる(): void
    {
        $term = $this->makeTerm($this->category, 'press');
        $page = $this->makePage('news-ok', 'お知らせ');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => [
                'type' => 'collection',
                'props' => ['layout' => 'list', 'limit' => 3],
                'binding' => [
                    'source' => 'collection', 'type' => 'news',
                    'filters' => [['taxonomy' => 'news_category', 'terms' => [$term->id]]],
                ],
            ],
        ], $this->admin());

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));
    }

    // ─────────────────────────────────────────────────────────────
    // 描画
    // ─────────────────────────────────────────────────────────────

    public function test_割り当てが一致する記事だけが描画される(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $event = $this->makeTerm($this->category, 'event');

        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);
        $this->makeNews('イベントの記事', ['news_category' => [$event->id]]);
        $this->makeNews('分類なしの記事', []);

        $html = $this->renderCollection('press-only', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ]);

        $this->assertStringContainsString('プレスの記事', $html);
        $this->assertStringNotContainsString('イベントの記事', $html);
        $this->assertStringNotContainsString('分類なしの記事', $html);
    }

    public function test_複数termはorになる(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $event = $this->makeTerm($this->category, 'event');

        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);
        $this->makeNews('イベントの記事', ['news_category' => [$event->id]]);
        $this->makeNews('分類なしの記事', []);

        $html = $this->renderCollection('press-or-event', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id, $event->id]],
        ]);

        $this->assertStringContainsString('プレスの記事', $html);
        $this->assertStringContainsString('イベントの記事', $html);
        $this->assertStringNotContainsString('分類なしの記事', $html);
    }

    /** filter 同士は AND。taxonomy と field を重ねると両方を満たすものだけ残る */
    public function test_taxonomy_filterとfield_filterはandになる(): void
    {
        $press = $this->makeTerm($this->category, 'press');

        $this->makeNews('公開のプレス', ['news_category' => [$press->id]]);
        $this->makeNews('下書きのプレス', ['news_category' => [$press->id]], status: 'draft');

        $html = $this->renderCollection('press-published', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
            ['field' => 'status', 'value' => ['published']],
        ]);

        $this->assertStringContainsString('公開のプレス', $html);
        $this->assertStringNotContainsString('下書きのプレス', $html);
    }

    /** 名前と slug を変えても、archive しても、ID で指した filter は動き続ける */
    public function test_rename後もarchive後も同じidで描画結果が変わらない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);
        $this->makeNews('関係ない記事', []);

        $before = $this->renderCollection('stable', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ]);

        $press->forceFill([
            'name' => '報道発表', 'slug' => 'houdou', 'archived_at' => now(),
        ])->save();

        $after = $this->renderCollection('stable-2', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ]);

        $this->assertStringContainsString('プレスの記事', $after);
        $this->assertStringNotContainsString('関係ない記事', $after);
        $this->assertSame(
            $this->collectionTitles($before),
            $this->collectionTitles($after),
            'rename / archive で描画結果が変わった',
        );
    }

    /**
     * 正しい ID と不正な ID が混ざったとき、**正しい方だけを拾って表示しない**（レビュー 3）。
     *
     * 検証は「1 つでも不正なら error」なので、描画だけ部分的に通すと
     * 「保存できない document が公開ページには出ている」という食い違いになる。
     * 混在は published / import 済みの document で現実に起こり得る。
     */
    public function test_有効なidと存在しないidが混ざったら描画しない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);

        $ghost = TaxonomyId::generate();

        // 保存時は error
        $this->assertErrorContains(
            ['taxonomy' => 'news_category', 'terms' => [$press->id, $ghost]],
            "分類項目 [{$ghost}] は存在しません",
        );

        // 描画も 0 件（正しい方だけ拾わない）
        $html = $this->renderCollection('mixed-ghost', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id, $ghost]],
        ], validate: false);

        $this->assertStringNotContainsString('プレスの記事', $html);
    }

    public function test_有効なidと別分類のidが混ざったら描画しない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $foreign = $this->makeTerm($this->other, 'foreign');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);

        $this->assertErrorContains(
            ['taxonomy' => 'news_category', 'terms' => [$press->id, $foreign->id]],
            "分類項目 [{$foreign->id}] は分類 [news_category] のものではありません",
        );

        $html = $this->renderCollection('mixed-foreign', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id, $foreign->id]],
        ], validate: false);

        $this->assertStringNotContainsString('プレスの記事', $html);
    }

    /** filters の中の壊れた要素も「絞り込み無し」にしない */
    public function test_filters内の壊れた要素があれば描画しない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);

        foreach ([
            'null要素' => [['taxonomy' => 'news_category', 'terms' => [$press->id]], null],
            'fieldもtaxonomyも無い' => [['taxonomy' => 'news_category', 'terms' => [$press->id]], []],
        ] as $label => $filters) {
            $html = $this->renderCollection(
                'broken-'.substr(md5($label), 0, 8),
                $filters,
                validate: false,
            );

            $this->assertStringNotContainsString('プレスの記事', $html, $label);
        }
    }

    /** 描画側でも壊れた指定は「絞り込み無し」にしない（全件を公開ページへ出さない） */
    public function test_描画側は壊れたfilterで全件を出さない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);

        $html = $this->renderCollection('broken', [
            ['taxonomy' => 'news_category', 'terms' => []],
        ], validate: false);

        $this->assertStringNotContainsString('プレスの記事', $html);
    }

    /**
     * ContentSchema が宣言していない分類での絞り込み（レビュー 2 への回答）。
     *
     * `news` は `news_category` しか宣言していないので、別分類での絞り込みは
     * **assignment があり得ず、必ず 0 件**になる。
     *
     * これを保存時に error にはしない。宣言は checked-in schema 側でいつでも消せる一方、
     * term の削除と違って参照調査（RESTRICT）に守られていない。error にすると
     * 「宣言を消した瞬間、既存ページが編集不能になる」という逃げ場のない状態を作る。
     * 描画が 0 件になることは下で固定し、挙動を曖昧にしない。
     */
    public function test_schemaが宣言していない分類の絞り込みは保存できるが0件になる(): void
    {
        $foreign = $this->makeTerm($this->other, 'foreign');

        // 保存はできる（term ID と所属は正しい）
        $this->assertSame([], $this->validateFilter([
            'taxonomy' => 'unrelated_tax',
            'terms' => [$foreign->id],
        ]));

        $this->makeNews('どれかの記事', []);

        $html = $this->renderCollection('undeclared', [
            ['taxonomy' => 'unrelated_tax', 'terms' => [$foreign->id]],
        ]);

        $this->assertStringNotContainsString('どれかの記事', $html);
    }

    /**
     * 母集団がどれだけ大きくても取りこぼさない（レビュー 2）。
     *
     * 絞り込みが PHP 側だと「先に N 件読んでから絞る」になり、
     * その N を何件にしても **N より後ろにある一致は永遠に出ない**。
     * ここでは新しい側を非一致で 120 件埋め、いちばん古い 1 件だけを一致させる。
     * SQL 側で絞れていなければ絶対に通らない。
     */
    public function test_一致が母集団の遥か後ろにあっても描画される(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $event = $this->makeTerm($this->category, 'event');

        // 先に作った = 古い。これだけが press
        $this->makeNews('いちばん古いプレス', ['news_category' => [$press->id]]);

        // 新しい側を 120 件（PHP 側で読み得る上限 100 を確実に超える）
        for ($i = 0; $i < 120; $i++) {
            $this->makeNews("新しいイベント{$i}", ['news_category' => [$event->id]]);
        }

        $html = $this->renderCollection('deep-match', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ], limit: 3);

        $this->assertStringContainsString('いちばん古いプレス', $html);
        $this->assertStringNotContainsString('新しいイベント0', $html);
    }

    /** 下書きが新しい側を埋めていても、公開済みの一致は表示件数ぶん出る */
    public function test_新しい側が下書きで埋まっていても公開済みの一致が出る(): void
    {
        $press = $this->makeTerm($this->category, 'press');

        $this->makeNews('公開のプレス', ['news_category' => [$press->id]]);

        for ($i = 0; $i < 30; $i++) {
            $this->makeNews("下書き{$i}", ['news_category' => [$press->id]], status: 'draft');
        }

        $html = $this->renderCollection('drafts-on-top', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ], limit: 3);

        $this->assertStringContainsString('公開のプレス', $html);
        $this->assertStringNotContainsString('下書き0', $html);
    }

    /**
     * 分類 + field の併用でも取りこぼさない（レビュー 3）。
     *
     * 分類に一致する記事を 130 件作り、そのうち **いちばん古い 1 件だけ**が
     * field の条件にも一致するようにする。母集団を読み切っていなければ出ない。
     */
    public function test_分類とfieldの併用でも母集団の後ろの一致を取りこぼさない(): void
    {
        $press = $this->makeTerm($this->category, 'press');

        // 古い 1 件だけ slug が特別
        $this->makeNews('古い当たりの記事', ['news_category' => [$press->id]], slug: 'the-one');

        for ($i = 0; $i < 130; $i++) {
            $this->makeNews("新しいプレス{$i}", ['news_category' => [$press->id]]);
        }

        $html = $this->renderCollection('taxonomy-and-field', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
            ['field' => 'slug', 'value' => ['the-one']],
        ], limit: 3);

        $this->assertStringContainsString('古い当たりの記事', $html);
        $this->assertStringNotContainsString('新しいプレス0', $html);
    }

    /**
     * 分類だけ + 並び順の指定でも、母集団の後ろにある「本来の先頭」を取りこぼさない
     * （Director Review 4）。
     *
     * 既定の並びは id の降順（新しい順）。ここで slug の昇順を指定すると、
     * 本来いちばん上に来るのは **いちばん古い 1 件**。
     * 「先頭 N 件を取ってから並べ替える」実装だと、この記事は永遠に出てこない。
     */
    public function test_分類のみでも並び順は母集団全体に効く(): void
    {
        $press = $this->makeTerm($this->category, 'press');

        // 先に作る = 最も古い。slug が辞書順で最初に来る
        $this->makeNews('先頭に来るべき記事', ['news_category' => [$press->id]], slug: 'aaa-first');

        for ($i = 0; $i < 130; $i++) {
            $this->makeNews("新しいプレス{$i}", ['news_category' => [$press->id]], slug: "zz-{$i}");
        }

        $html = $this->renderCollection('sorted-asc', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ], limit: 3, sort: [['field' => 'slug', 'direction' => 'asc']]);

        $this->assertStringContainsString('先頭に来るべき記事', $html);
    }

    /** 並び順の形が壊れていたら、並べずに出すのではなく出さない（検証も error にする形） */
    public function test_壊れた並び順は描画しない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('プレスの記事', ['news_category' => [$press->id]]);

        $html = $this->renderCollection('broken-sort', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ], validate: false, sort: [['direction' => 'asc']]);

        $this->assertStringNotContainsString('プレスの記事', $html);
    }

    /** 検証側も壊れた並び順を黙って無視しない（描画と同じ結論） */
    public function test_並び順の形が不正なら保存できない(): void
    {
        $errors = app(DocumentValidator::class)->validate(
            $this->documentWith([
                'source' => 'collection', 'type' => 'news',
                'sort' => ['field' => 'id'], // list ではない
            ]),
        );

        $this->assertContains('並び順の形式が不正です', $this->messages($errors));
    }

    /**
     * クエリ数が記事数に比例しない（N+1 でない）。
     *
     * 分類と公開状態は SQL 側で絞るので、母集団が 20 倍になってもクエリ数は変わらない。
     * （`field` 絞り込みや並び順があるときは母集団を読み切るため、
     *   ページ数ぶんだけクエリが増える。これは意味の正しさと引き換えの既知の代償）
     */
    public function test_クエリ数は記事件数に比例しない(): void
    {
        $press = $this->makeTerm($this->category, 'press');

        for ($i = 0; $i < 5; $i++) {
            $this->makeNews("少ない{$i}", ['news_category' => [$press->id]]);
        }

        // 同じページを 2 回描画して比べる。ページ作成は数えない（差は記事件数だけ）。
        // 並び順を指定しない = 分類と公開状態を SQL で絞り切れる経路
        $this->renderCollection('q-stable', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ], limit: 3, sort: null);

        $small = $this->countQueries(fn () => $this->get('/q-stable')->assertOk());

        for ($i = 0; $i < 100; $i++) {
            $this->makeNews("多い{$i}", ['news_category' => [$press->id]]);
        }

        $large = $this->countQueries(fn () => $this->get('/q-stable')->assertOk());

        $this->assertSame($small, $large, '記事が増えるとクエリが増えている（N+1）');
    }

    /** ListContent の既定の呼び出しは 1 バイトも変わらない（API / MCP の contract） */
    public function test_list_contentの既定の応答は変わらない(): void
    {
        $press = $this->makeTerm($this->category, 'press');
        $this->makeNews('公開の記事', ['news_category' => [$press->id]]);
        $this->makeNews('下書きの記事', [], status: 'draft');

        $result = app(ListContent::class)->execute('news', $this->admin());

        $this->assertSame(OperationStatus::Success, $result->status);

        $titles = array_column($result->data['items'], 'title');

        // 絞り込みを渡していないので、下書きも分類なしもそのまま返る
        $this->assertContains('公開の記事', $titles);
        $this->assertContains('下書きの記事', $titles);
        $this->assertArrayHasKey('taxonomies', $result->data['items'][0]);
    }

    /** 絞り込んだうえで、表示件数はきちんと守る */
    public function test_絞り込んだあとも表示件数を超えない(): void
    {
        $press = $this->makeTerm($this->category, 'press');

        for ($i = 0; $i < 5; $i++) {
            $this->makeNews("プレス{$i}", ['news_category' => [$press->id]]);
        }

        $html = $this->renderCollection('limited', [
            ['taxonomy' => 'news_category', 'terms' => [$press->id]],
        ], limit: 2);

        $shown = 0;

        for ($i = 0; $i < 5; $i++) {
            $shown += substr_count($html, "プレス{$i}") > 0 ? 1 : 0;
        }

        $this->assertSame(2, $shown);
    }

    // ─────────────────────────────────────────────────────────────
    // 副作用とクエリ数
    // ─────────────────────────────────────────────────────────────

    public function test_検証はdbへ書き込まない(): void
    {
        $term = $this->makeTerm($this->category, 'press');

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        app(DocumentValidator::class)->validate(
            $this->documentWith([
                'source' => 'collection', 'type' => 'news',
                'filters' => [['taxonomy' => 'news_category', 'terms' => [$term->id]]],
            ]),
        );

        $this->assertSame([], $writes);
    }

    /**
     * 同じ分類・同じ term を何度並べてもクエリは増えない（#22-8）。
     *
     * node ごとに引く実装だと、ここが線形に増える。
     */
    public function test_同じtermを繰り返してもクエリ数は一定(): void
    {
        $term = $this->makeTerm($this->category, 'press');
        $filter = ['taxonomy' => 'news_category', 'terms' => [$term->id]];

        $one = $this->countQueries(fn () => app(DocumentValidator::class)->validate(
            $this->documentWithCollections([$filter]),
        ));

        $many = $this->countQueries(fn () => app(DocumentValidator::class)->validate(
            $this->documentWithCollections(array_fill(0, 50, $filter)),
        ));

        $this->assertSame($one, $many, 'filter の数でクエリが増えている');
        // 分類 1 本 + 項目 1 本
        $this->assertLessThanOrEqual(2, $many);
    }

    public function test_taxonomy_filterが無ければdbへ触らない(): void
    {
        $count = $this->countQueries(fn () => app(DocumentValidator::class)->validate(
            $this->documentWith([
                'source' => 'collection', 'type' => 'news',
                'filters' => [['field' => 'status', 'value' => ['published']]],
            ]),
        ));

        $this->assertSame(0, $count);
    }

    /** 長寿命プロセスで前回の索引が残らない（削除した term が有効に見えない） */
    public function test_前のvalidateの索引を次へ持ち越さない(): void
    {
        $term = $this->makeTerm($this->category, 'press');
        $filter = ['taxonomy' => 'news_category', 'terms' => [$term->id]];
        $validator = app(DocumentValidator::class);

        $this->assertSame([], $validator->validate($this->documentWithCollections([$filter])));

        $term->delete();

        $this->assertContains(
            "分類項目 [{$term->id}] は存在しません",
            $this->messages($validator->validate($this->documentWithCollections([$filter]))),
        );
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'test');
    }

    private function makeTaxonomy(string $key): Taxonomy
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(), 'key' => $key, 'label' => $key,
            'hierarchical' => false, 'managed_by' => TaxonomyManagedBy::Code->value,
        ])->save();

        return $taxonomy;
    }

    private function makeTerm(Taxonomy $taxonomy, string $slug, bool $archived = false): TaxonomyTerm
    {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomy->id,
            'name' => $slug,
            'slug' => $slug,
            'sort_order' => 0,
            'archived_at' => $archived ? now() : null,
        ])->save();

        return $term;
    }

    /** @param array<string, list<string>> $taxonomies */
    private function makeNews(
        string $title,
        array $taxonomies,
        string $status = 'published',
        ?string $slug = null,
    ): void {
        $result = app(CreateContent::class)->execute(
            'news',
            [
                'title' => $title,
                'slug' => $slug ?? 'n-'.strtolower(substr(TaxonomyId::generate(), -10)),
                'body' => '<p>本文</p>',
                'status' => $status,
                'published_at' => now()->toDateTimeString(),
            ],
            $this->admin(),
            $taxonomies === [] ? null : TaxonomyPayload::of($taxonomies),
        );

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));
    }

    /**
     * collection 1 つだけのページを公開して HTML を返す。
     *
     * `$validate` を false にすると検証を通らない document も置ける
     * （import や過去の版から来た形を描画側だけで確かめるため）。
     *
     * @param  list<array<string, mixed>>  $filters
     */
    private function renderCollection(
        string $slug,
        array $filters,
        bool $validate = true,
        int $limit = 20,
        ?array $sort = [['field' => 'id', 'direction' => 'desc']],
    ): string {
        $binding = ['source' => 'collection', 'type' => 'news', 'filters' => $filters];

        if ($sort !== null) {
            $binding['sort'] = $sort;
        }

        $page = $this->makePage($slug, $slug);
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);

        if ($validate) {
            $result = app(PatchPageDocument::class)->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => $section,
                'component' => [
                    'type' => 'collection',
                    'props' => ['layout' => 'list', 'limit' => $limit],
                    'binding' => $binding,
                ],
            ], $this->admin());

            $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));
            app(PublishPage::class)->execute($page->id, $this->admin());

            return (string) $this->get('/'.$slug)->assertOk()->getContent();
        }

        // 検証を通らない形は **公開したあとの版へ直接**置く。
        // import や過去の版から来た document が描画される状況を再現するため、
        // 保存経路（PatchPageDocument / PublishPage）は迂回する
        $result = app(PublishPage::class)->execute($page->id, $this->admin());
        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        $published = PageVersion::query()->findOrFail($page->refresh()->published_version_id);
        /** @var array<string, mixed> $document */
        $document = $published->document;
        $document['root']['children'][0]['children'][] = [
            'id' => TaxonomyId::generate(), 'key' => null, 'type' => 'collection',
            'props' => ['layout' => 'list', 'limit' => $limit],
            'binding' => $binding, 'children' => [],
        ];

        PageVersion::query()
            ->whereKey($published->id)
            ->update(['document' => json_encode($document, JSON_UNESCAPED_UNICODE)]);

        return (string) $this->get('/'.$slug)->assertOk()->getContent();
    }

    /** 一覧に出た記事タイトルの並び（描画結果の同一性を見るため） */
    private function collectionTitles(string $html): array
    {
        preg_match_all('/(プレスの記事|イベントの記事|関係ない記事|分類なしの記事)/u', $html, $matches);

        return $matches[1];
    }

    private function makePage(string $slug, string $title): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => $title, 'slug' => $slug],
            $this->admin(),
        );

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** @return array<string, mixed> */
    private function draftDocument(Page $page): array
    {
        /** @var array<string, mixed> $document */
        $document = PageVersion::query()->findOrFail($page->refresh()->draft_version_id)->document;

        return $document;
    }

    /** @param array<string, mixed> $component */
    private function insert(Page $page, array $component, ?string $parent = null): string
    {
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $parent ?? (string) $this->draftDocument($page)['root']['id'],
            'component' => $component,
        ], $this->admin());

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        $children = $result->data['document']['root']['children'];

        return (string) end($children)['id'];
    }

    /**
     * filter 1 つだけを持つ document の検証結果。
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, list<string>>
     */
    private function validateFilter(array $filter): array
    {
        return app(DocumentValidator::class)->validate(
            $this->documentWith(['source' => 'collection', 'type' => 'news', 'filters' => [$filter]]),
        );
    }

    /** @param array<string, mixed> $filter */
    private function assertErrorContains(array $filter, string $expected): void
    {
        $this->assertContains($expected, $this->messages($this->validateFilter($filter)));
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    private function documentWith(array $binding): array
    {
        return $this->document([[
            'id' => TaxonomyId::generate(), 'key' => null, 'type' => 'collection',
            'props' => ['layout' => 'list', 'limit' => 3],
            'binding' => $binding, 'children' => [],
        ]]);
    }

    /**
     * 同じ filter を持つ collection を並べた document。
     *
     * @param  list<array<string, mixed>>  $filters
     * @return array<string, mixed>
     */
    private function documentWithCollections(array $filters): array
    {
        $children = [];

        foreach ($filters as $filter) {
            $children[] = [
                'id' => TaxonomyId::generate(), 'key' => null, 'type' => 'collection',
                'props' => ['layout' => 'list', 'limit' => 3],
                'binding' => [
                    'source' => 'collection', 'type' => 'news', 'filters' => [$filter],
                ],
                'children' => [],
            ];
        }

        return $this->document($children);
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function document(array $children): array
    {
        return [
            'schemaVersion' => 1,
            'root' => [
                'id' => TaxonomyId::generate(), 'key' => 'page', 'type' => 'page',
                'props' => [], 'children' => [[
                    'id' => TaxonomyId::generate(), 'key' => null, 'type' => 'section',
                    'props' => [], 'children' => $children,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function messages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $list) {
            foreach ($list as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * クエリ数を数える。
     *
     * 数える前に **effective schema を温めておく**（Phase 9.5-A / ADR-0015）。
     * Field Group overlay は 1 リクエストにつき 1 度だけ `field_groups` を読むので、
     * 温めないと「1 回きりの定数」が filter の計測に混ざる。
     * ここで固定したいのは **filter の数で増えないこと**であって、
     * schema 読み込みの定数ではない。
     */
    private function countQueries(callable $callback): int
    {
        app(SchemaRegistry::class)->all();

        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }
}
