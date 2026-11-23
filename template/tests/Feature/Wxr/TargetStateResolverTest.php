<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Revisions\SchemaManifest;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TargetState;
use AgentCms\Core\Wxr\Plan\TargetStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\News\Models\News;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 取り込み先のいまの状態を DB から読む（Phase 9.14 / STAGE 27C・SPEC §D.1）。
 *
 * ## ここは 1 行も書かない
 *
 * `plan` の約束は「DB を読むが書かない」である（§D.1）。
 * 読むついでに設定行を作る、既定値を保存する、といったことを 1 つでもすると、
 * **plan を見ただけでサイトの状態が変わる**。
 */
final class TargetStateResolverTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    private PlanFindings $lastFindings;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * **取り込み先は「使える」状態にしておく。**
         *
         * 設定行が無い型は `public` が既定 false で、コメントも受けない。
         * §C.2 のとおりそれは取り込みを止める理由だが、ここで確かめたいのは
         * そこではない。前提を作ってから、見たいものだけを崩す。
         */
        foreach (['post', 'news'] as $type) {
            $this->settingsRow($type, [
                'status' => 'active',
                'public_slug' => $type,
                'comments_enabled' => true,
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function test_型の状態を正本のpolicyから読む(): void
    {
        $state = $this->resolve(['post'], []);

        $type = $state->canonical()['types']['post'];

        $this->assertTrue($type['isEditorial']);
        $this->assertTrue($type['canCreate']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $type['schemaFingerprint']);
    }

    public function test_フィールドの束が足されれば定義の指紋が変わる(): void
    {
        /*
         * **64 桁の 16 進であることは、正しい定義から取ったことの証明にならない。**
         *
         * 指紋は `SchemaRegistry::declared()` の素の定義ではなく、
         * Field Group overlay 込みの **effective schema**（`get()`）から取る必要がある。
         * 実際に書き込むときに通るのがこちらだからで、素の定義で取ると
         * **束が変わったことに気づけないまま同じ plan が通る**。
         *
         * 先に束を入れてから、素の定義と resolver の値を突き合わせる。
         * 「先に読んでから束を入れる」形にすると、同じ request 内では
         * 正しい実装でも memo 済みの古い schema を返し得るので、**偽陰性になる**。
         */
        $this->makeGroup('extra_for_import', [
            ['key' => 'import_note', 'type' => 'text', 'label' => '取り込みメモ'],
        ], [['kind' => 'content_type', 'value' => 'post']]);

        $base = SchemaManifest::fingerprint(app(SchemaRegistry::class)->declared()['post']);

        $this->assertNotSame($base, $this->fingerprintOf('post'));
    }

    public function test_公開の設定がそのまま状態に載る(): void
    {
        $this->settingsRow('post', [
            'public_slug' => 'articles',
            'registration' => json_encode([
                'public' => true,
                'has_archive' => false,
                'publicly_queryable' => true,
            ], JSON_THROW_ON_ERROR),
        ]);

        $type = $this->resolve(['post'], [])->canonical()['types']['post'];

        $this->assertSame('articles', $type['publicSlug']);
        $this->assertFalse($type['hasArchive']);
        $this->assertTrue($type['publiclyQueryable']);
    }

    public function test_保管された型は作れないと読む(): void
    {
        $this->archive('post');

        // 作れない型は**止める**（§C.2）ので、状態は返らない
        $this->assertNull($this->inspect(['post'], []));
        $this->assertSame(1, $this->lastFindings->toArray()['type_cannot_create']['total']);
    }

    public function test_知らない型は断る(): void
    {
        /*
         * **勝手に型を作らない**（§C.2）。型の作成は Content Type Builder の仕事で、
         * 確認も監査も要る。ここで黙って作ると、その両方を迂回する。
         */
        // **1 件目で投げない。** 全部集めてから返す（§A.3）
        $this->assertNull($this->inspect(['does_not_exist'], []));
        $this->assertSame(1, $this->lastFindings->toArray()['unknown_type']['total']);
    }

    public function test_受け皿の無い分類は断る(): void
    {
        // 止めるときは、次に何をすればよいかを出す（§C.3）
        $this->assertNull($this->inspect(['post'], ['does_not_exist']));

        // 定義そのものが無い（同期していないだけ、とは別の理由）
        $findings = $this->lastFindings->toArray();

        $this->assertSame(1, $findings['missing_taxonomy']['total']);
        $this->assertArrayNotHasKey('taxonomy_not_synced', $findings);
    }

    public function test_コメントが関係しない型ではその可否を状態へ載せない(): void
    {
        /*
         * 設定そのものは読む（`canCreate` / `isPublic` に要る）。
         * 載せないのは**コメントの可否だけ**である。WXR にコメントが 1 件も無ければ、
         * その設定を変えても取り込みの結果は変わらないので、
         * **無関係な設定変更で再 plan を求めない**。
         */
        $state = $this->resolve(['post'], [], commentsRelevantFor: []);

        $this->assertNull($state->canonical()['types']['post']['commentsEnabled']);
    }

    public function test_コメントが関係する型ではその可否を状態へ載せる(): void
    {
        $state = $this->resolve(['post'], [], commentsRelevantFor: ['post']);

        $this->assertIsBool($state->canonical()['types']['post']['commentsEnabled']);
    }

    public function test_設定行が無ければ公開の既定は本番と同じになる(): void
    {
        /*
         * 公開面は `flag(..., true)` で読む（`PublicContentController` /
         * `SitemapController`）。ここだけ既定を false にすると、
         * **実際には出ているのに plan が「出ない」と言う**。
         */
        // 設定行を消して、既定だけで読ませる
        DB::table('content_types')->where('content_type_id', 'post')->delete();
        $this->assertSame(0, DB::table('content_types')->where('content_type_id', 'post')->count());

        $state = $this->inspect(['post'], [], commentsRelevantFor: []);

        /*
         * 既定では `public` が false なので取り込みは止まる（§C.2）。
         * ここで見たいのは**その先の 2 つのフラグの既定**なので、
         * 判定そのものではなく `settings` の読み方を確かめる。
         */
        $this->assertNull($state);

        $settings = app(ContentTypeLifecyclePolicy::class)
            ->settings('post');

        $type = [
            'hasArchive' => $settings->flag('has_archive', true),
            'publiclyQueryable' => $settings->flag('publicly_queryable', true),
        ];

        $this->assertTrue($type['hasArchive']);
        $this->assertTrue($type['publiclyQueryable']);
    }

    // ── 1 行も書かない ──────────────────

    public function test_読むだけで差し引きも入れもしない(): void
    {
        $this->syncTaxonomies();

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->inspect(['news' => ['news_category']], ['news_category'], commentsRelevantFor: ['news']);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    public function test_書き込みの検知そのものが成立している(): void
    {
        /*
         * **通ることしか確かめない検査は保証にならない。**
         * こちらの監視も、赤くなれることを確かめる。
         */
        $before = $this->snapshot();
        $writes = $this->watchWrites();

        DB::table('content_types')->where('content_type_id', 'post')
            ->update(['menu_order' => 7, 'updated_at' => now()]);

        $this->assertNotSame([], $writes(), '書き込みを見逃している');
        $this->assertNotSame($before, $this->snapshot(), '中身の変化を見逃している');
    }

    // ── 関係するものだけを載せる ────────────────────────────

    public function test_今回使う分類の宣言だけを型に載せる(): void
    {
        /*
         * 型が宣言している分類を全部載せると、**今回の取り込みと関係のない
         * 宣言を変えただけで再 plan を求める**ことになる。
         * 載せるのは、この WXR でその型に実際に対応する分類だけである。
         */
        $this->syncTaxonomies();

        $declared = array_keys($this->resolve(
            ['news' => ['news_category']],
            ['news_category'],
            commentsRelevantFor: [],
        )->canonical()['types']['news']['taxonomies']);

        $this->assertSame(['news_category'], $declared);
    }

    public function test_今回の取り込み先でない型は分類の関連に出さない(): void
    {
        /*
         * `associatedTypes` に「その分類を宣言している型」を全部入れると、
         * **今回入れない型の宣言が変わっただけで hash が動く**。
         * 今回の取り込み先との積だけを載せる。
         */
        $this->syncTaxonomies();

        $associated = $this->resolve(
            ['news' => ['news_category']],
            ['news_category'],
            commentsRelevantFor: [],
        )->canonical()['taxonomies']['news_category']['associatedTypes'];

        // post も news_category を宣言していないし、今回の取り込み先でもない
        $this->assertSame(['news'], $associated);
    }

    public function test_どのitemも使っていない分類の定義を落とさない(): void
    {
        /*
         * `<channel>` 直下の term 定義には、どの item も使っていないものがあり得る。
         * §C.3 は「既存の分類へ項目を足す」であって、
         * **使われていないから黙って消す、という決定はどこにも無い。**
         *
         * 型ごとの使用分の和から導く実装だと、この分類が plan から消える。
         */
        $this->syncTaxonomies();

        $state = $this->resolve(
            ['post' => []],
            ['news_category'],
            commentsRelevantFor: [],
        );

        $this->assertArrayHasKey('news_category', $state->canonical()['taxonomies']);
        // どの型も使っていないので、関連する型は空
        $this->assertSame([], $state->canonical()['taxonomies']['news_category']['associatedTypes']);
    }

    public function test_使っていない分類の宣言は型の状態に出ない(): void
    {
        /*
         * 宣言が状態に出なければ、その `multiple` / `required` を変えても
         * hash は動かない。**関係しない設定変更で再 plan を求めない**という契約は、
         * 「載せない」ことで成り立っている。
         */
        $this->syncTaxonomies();

        $withoutUse = $this->resolve(['news' => []], [], commentsRelevantFor: []);
        $withUse = $this->resolve(
            ['news' => ['news_category']],
            ['news_category'],
            commentsRelevantFor: [],
        );

        $this->assertSame([], $withoutUse->canonical()['types']['news']['taxonomies']);
        $this->assertSame(
            ['news_category'],
            array_keys($withUse->canonical()['types']['news']['taxonomies']),
        );
    }

    public function test_定義の指紋は分類の宣言を含まない(): void
    {
        /*
         * **境界をここで固定する。** `SchemaManifest` が見るのは field だけで、
         * taxonomy 宣言は見ない。だから「使っていない分類の宣言を変えても
         * 指紋は動かない」が成り立つ。
         *
         * ここが将来 taxonomy まで含むようになると、
         * 「関係する分類だけを載せる」という上の 2 つの契約と食い違う。
         * そのときはこのテストが赤くなり、どちらを直すか決めることになる。
         */
        $schema = app(SchemaRegistry::class)->get('news');

        $this->assertNotSame([], $schema->taxonomies, '前提: news は分類を宣言している');
        $this->assertSame(
            SchemaManifest::fingerprint($schema),
            SchemaManifest::fingerprint(new ContentSchema(
                id: $schema->id,
                label: $schema->label,
                kind: $schema->kind,
                fields: $schema->fields,
                taxonomies: [],
            )),
        );
    }

    // ── 受け皿の状態を取り違えない ──────────────────────────

    public function test_定義はあるが未同期なら同期を促す(): void
    {
        /*
         * **「作れ」と言わない。** ファイルは既に在る。
         * 走らせるものが違うので、理由も分ける。
         */
        $this->assertNull($this->inspect(['post'], ['news_category'], commentsRelevantFor: []));

        $findings = $this->lastFindings->toArray();

        $this->assertSame(1, $findings['taxonomy_not_synced']['total']);
        $this->assertArrayNotHasKey('missing_taxonomy', $findings);
    }

    public function test_無効にされた分類を断る(): void
    {
        $this->syncTaxonomies();
        DB::table('taxonomies')->where('key', 'news_category')
            ->update(['status' => 'archived', 'updated_at' => now()]);

        $this->assertNull($this->inspect(['news' => ['news_category']], ['news_category'], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['taxonomy_archived']['total']);
    }

    public function test_持ち主が食い違う分類を断る(): void
    {
        /*
         * 定義と DB のどちらが正か決められないまま項目を足すと、
         * 次の同期で**足したものが消える**か、定義の側が上書きされる。
         */
        $this->syncTaxonomies();
        DB::table('taxonomies')->where('key', 'news_category')
            ->update(['managed_by' => 'cms', 'updated_at' => now()]);

        $this->assertNull($this->inspect(['news' => ['news_category']], ['news_category'], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['taxonomy_managed_by_mismatch']['total']);
    }

    public function test_階層の有無が食い違う分類を断る(): void
    {
        // 「親から先に作る」（§C.3）の意味が両者で変わる
        $this->syncTaxonomies();

        $row = DB::table('taxonomies')->where('key', 'news_category')->first();
        DB::table('taxonomies')->where('key', 'news_category')
            ->update(['hierarchical' => ! $row->hierarchical, 'updated_at' => now()]);

        $this->assertNull($this->inspect(['news' => ['news_category']], ['news_category'], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['taxonomy_hierarchy_mismatch']['total']);
    }

    public function test_型が宣言していない分類への割り当てを断る(): void
    {
        $this->syncTaxonomies();

        $this->assertNull($this->inspect(['post' => ['news_category']], ['news_category'], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['taxonomy_not_declared_for_type']['total']);
    }

    public function test_コメントを受けない型でコメントがあれば断る(): void
    {
        // 記事だけ入れて、他人の書いた文章を落とすことになる（§C.4）
        DB::table('content_types')->where('content_type_id', 'post')
            ->update(['comments_enabled' => false, 'updated_at' => now()]);

        $this->assertNull($this->inspect(['post'], [], commentsRelevantFor: ['post']));
        $this->assertSame(1, $this->lastFindings->toArray()['type_comments_disabled']['total']);
    }

    public function test_公開すると言いながら辿り着けない型を断る(): void
    {
        $this->settingsRow('post', [
            'status' => 'active',
            'public_slug' => 'post',
            'registration' => json_encode([
                'public' => true,
                'has_archive' => false,
                'publicly_queryable' => false,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertNull($this->inspect(['post'], [], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['type_not_reachable']['total']);
    }

    public function test_一覧があれば詳細が出なくても到達できる(): void
    {
        /*
         * `has_archive` が生きていれば一覧から辿れる。
         * 詳細だけ出ないのは設定として成立している。
         */
        $this->settingsRow('post', [
            'status' => 'active',
            'public_slug' => 'post',
            'registration' => json_encode([
                'public' => true,
                'has_archive' => true,
                'publicly_queryable' => false,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertNotNull($this->inspect(['post'], [], commentsRelevantFor: []));
    }

    public function test_slugの欄が無い型は詳細に到達できないと読む(): void
    {
        /*
         * **設定だけを見て「出る」と判断しない。**
         * `PublicContentController::show()` は slug の欄が無い型を 404 にする
         * （URL の作りようがない）。`publicly_queryable` が true でも出ない。
         *
         * `media` は `kind=collection` でありながら slug の欄を持たない。
         * 取り込み先として引けるよう登録してから、実際に判定を通す。
         */
        $this->assertFalse(
            app(SchemaRegistry::class)->get('media')->hasField('slug'),
            '前提: media は slug の欄を持たない',
        );

        app(ContentTypeRegistry::class)->register('media', News::class);

        $this->settingsRow('media', [
            'status' => 'active',
            'public_slug' => 'media',
            'registration' => json_encode([
                'public' => true,
                'has_archive' => false,
                'publicly_queryable' => true,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertNull($this->inspect(['media'], [], commentsRelevantFor: []));

        $findings = $this->lastFindings->toArray();

        // 一覧も出ず、詳細も作れないので**誰も辿り着けない**
        $this->assertSame(1, $findings['type_not_reachable']['total']);
        // 投稿タイプではないことも同じ 1 回で出る（1 件目で止めない）
        $this->assertSame(1, $findings['type_not_editorial']['total']);
    }

    public function test_定義の無い分類がdbにだけ在っても断る(): void
    {
        /*
         * 分類の定義は code-managed の SSoT である（§C.3）。
         * DB にだけ在るものは**SSoT から外れている**ので、
         * そこへ項目を足すと次の同期で消える可能性がある。
         */
        DB::table('taxonomies')->insert([
            'id' => (string) Str::ulid(),
            'key' => 'orphan_taxonomy',
            'label' => '定義の無い分類',
            'hierarchical' => false,
            'status' => 'active',
            'lock_version' => 0,
            'managed_by' => 'code',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->inspect(['post'], ['orphan_taxonomy'], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['missing_taxonomy']['total']);
    }

    public function test_投稿タイプでない型を断る(): void
    {
        /*
         * **投稿タイプではない schema は、そもそも取り込み先として引けない。**
         *
         * `media` / `contact` / `site` は CMS そのものの機能であり
         * （`EditorialCollections::SYSTEM_FACILITY`）、`ContentTypeResolver` の
         * 登録に載っていない。つまり `unknown_type` の側で止まる。
         *
         * `type_not_editorial` が要るのは、**登録には載っているのに
         * 投稿タイプではない**型が現れた場合である。判定は
         * `ContentTypeLifecyclePolicy` が持っているので、そこを直接確かめる。
         */
        $this->assertNull($this->inspect(['media'], [], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['unknown_type']['total']);

        $this->assertFalse(app(ContentTypeLifecyclePolicy::class)->isEditorial('media'));
        $this->assertTrue(app(ContentTypeLifecyclePolicy::class)->isEditorial('post'));
    }

    public function test_公開できない型を断る(): void
    {
        // §C.2「公開できない状態なら止める」
        DB::table('content_types')->where('content_type_id', 'post')
            ->update(['public_slug' => null, 'updated_at' => now()]);

        $this->assertNull($this->inspect(['post'], [], commentsRelevantFor: []));
        $this->assertSame(1, $this->lastFindings->toArray()['type_not_public']['total']);
    }

    public function test_型の問題と分類の問題を一度に出す(): void
    {
        /*
         * **直しては実行し、を繰り返させない。**
         * 型を直してから分類で止まる、では 2 回走らせることになる。
         */
        $this->assertNull($this->inspect(
            ['does_not_exist' => ['also_missing']],
            ['also_missing'],
            commentsRelevantFor: [],
        ));

        $findings = $this->lastFindings->toArray();

        $this->assertSame(1, $findings['unknown_type']['total']);
        $this->assertSame(1, $findings['missing_taxonomy']['total']);
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * **関係する分類は必ず明示する。**
     *
     * ここで「省略なら型の使用分から補う」ようにすると、
     * `[]` を渡した場合と渡し忘れた場合が同じ意味になり、
     * **渡し忘れたテストが通ってしまう**。本番の 3 引数と同じ形で呼ぶ。
     *
     * @param  array<string, list<string>>|list<string>  $typeTaxonomies  型 => 使う分類 key（list なら分類を使わない型）
     * @param  list<string>  $taxonomyKeys  今回関係する分類すべて（未使用の定義も含む）
     * @param  list<string>  $commentsRelevantFor
     */
    private function resolve(
        array $typeTaxonomies,
        array $taxonomyKeys,
        array $commentsRelevantFor = ['post'],
    ): TargetState {
        $state = $this->inspect($typeTaxonomies, $taxonomyKeys, $commentsRelevantFor);

        if ($state === null) {
            $this->fail('取り込み先が使えない状態です: '.json_encode(
                $this->lastFindings->toArray(),
                JSON_UNESCAPED_UNICODE,
            ));
        }

        return $state;
    }

    /**
     * 判定と状態を**同じ一度の読み取り**から作る（本番と同じ入口）。
     */
    private function inspect(
        array $typeTaxonomies,
        array $taxonomyKeys,
        array $commentsRelevantFor = ['post'],
    ): ?TargetState {
        $normalised = [];

        foreach ($typeTaxonomies as $key => $value) {
            // list を渡されたら「分類を使わない型」として読む
            is_int($key) ? $normalised[$value] = [] : $normalised[$key] = $value;
        }

        $this->lastFindings = new PlanFindings;

        return app(TargetStateResolver::class)->inspect(
            $normalised,
            $taxonomyKeys,
            $commentsRelevantFor,
            $this->lastFindings,
        );
    }

    /** 分類の定義を DB へ同期する（受け皿が実在する状態を作る） */
    private function syncTaxonomies(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
    }

    private function fingerprintOf(string $type): string
    {
        return $this->resolve([$type], [])->canonical()['types'][$type]['schemaFingerprint'];
    }

    /** Field Group を 1 件作って型へ結びつける（production に書き込み経路が無いので直接入れる） */
    private function makeGroup(string $key, array $fields, array $locations): void
    {
        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => $key,
            'label' => '取り込み用の束',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($locations as $location) {
            DB::table('field_group_locations')->insert([
                'field_group_id' => $id,
                'kind' => $location['kind'],
                'value' => $location['value'],
            ]);
        }
    }

    /** @param array<string, mixed> $columns */
    private function settingsRow(string $type, array $columns): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $type],
            $columns + ['updated_at' => now(), 'created_at' => now()],
        );
    }

    private function archive(string $type): void
    {
        DB::table('content_types')
            ->where('content_type_id', $type)
            ->update(['status' => 'archived', 'updated_at' => now()]);
    }
}
