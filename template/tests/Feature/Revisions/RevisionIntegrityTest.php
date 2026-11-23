<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\Revisions\RestoreContentRevision;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Revisions\RevisionSnapshot;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 復元が **本当に安全か**（Phase 9.8 / STAGE 21D CORRECTION）。
 *
 * 版の仕組みが動くだけでは足りない。守りたいのは「戻した結果が正しいと言えること」で、
 * 言えないなら止まることである。ここで見るのは、**言えないのに通ってしまう経路**を
 * 塞げているかどうかである。
 */
final class RevisionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var list<string> テストが作ったファイル。**必ず片付ける** */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    // ── A. 分類が空だった版 ─────────────────────────────────────

    public function test_分類が空の版へ戻すと現在の割り当ても消える(): void
    {
        [$id, $termId] = $this->newsWithTerm();

        // 版 1（分類なし）→ 版 2（分類あり）
        $this->assignTerm('news', $id, $termId);

        $this->assertNotSame([], RevisionSnapshot::taxonomies('news', $id));

        $latest = app(RevisionWriter::class)->latest('news', $id);
        $this->assertNotNull($latest);

        $this->restore('news', $id, 1, (int) DB::table('news')->where('id', $id)->value('lock_version'));

        /*
         * **空は「触らない」ではなく「1 件も無い」である。**
         * 早期 return して現在の割り当てを残すと、戻したのに分類だけ古いままになり、
         * 「現在の内容 = 最新版」が崩れる。
         */
        $this->assertSame([], RevisionSnapshot::taxonomies('news', $id));
        $this->assertLatestMatchesCurrent('news', $id);
    }

    // ── B. 消えた分類項目 ───────────────────────────────────────

    public function test_消えた分類項目はpreviewに出してから落とす(): void
    {
        [$id, $termId] = $this->newsWithTerm();

        // 版 2: 分類あり → 版 3: 分類なし（**いまの内容は版 3 と一致している**）
        $this->assignTerm('news', $id, $termId);
        $this->clearTerms('news', $id);

        // そのあとで分類項目そのものが消えた
        DB::table('taxonomy_terms')->where('id', $termId)->delete();

        $version = (int) DB::table('news')->where('id', $id)->value('lock_version');
        $preview = app(RestoreContentRevision::class)->execute('news', $id, 2, $this->context(), $version);

        $this->assertSame(OperationStatus::ConfirmationRequired, $preview->status);

        $impact = implode("\n", $preview->confirmation?->impact ?? []);

        // **黙って落とさない。** どの分類の、どの項目が落ちるかを言う
        $this->assertStringContainsString('news_category', $impact);
        $this->assertStringContainsString($termId, $impact);
    }

    // ── C. 指紋と中身が食い違う定義 ─────────────────────────────

    public function test_指紋と中身が合わない定義はfail_closed(): void
    {
        $id = $this->newPost('内容');
        $this->update('post', $id, ['title' => '2 つ目']);

        $before = $this->fingerprintOf('post', $id);

        /*
         * **JSON として読めるだけでは信用しない。**
         * 中身を canonical に直して hash を取り直し、鍵と一致するかを見る。
         * 一致しないなら、その版の定義は正しくない。
         */
        DB::table(SchemaManifestRepository::TABLE)
            ->where('fingerprint', $before)
            ->update(['manifest' => '[{"identity":"title","key":"title","type":"text"}]']);

        $snapshot = $this->currentState('post', $id);

        $result = app(RestoreContentRevision::class)->execute(
            'post', $id, 1, $this->context(), (int) DB::table('post')->where('id', $id)->value('lock_version'),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringNotContainsString('/var/www', (string) $result->message);
        $this->assertSame($snapshot, $this->currentState('post', $id), 'DB が変わっている');
    }

    // ── D. 参照先の型が変わった ─────────────────────────────────

    public function test_参照先の型が変わったfieldは復元しない(): void
    {
        $work = $this->work('参照先');

        $result = app(CreateContent::class)->execute('product', [
            'title' => '参照つき', 'description' => '<p>d</p>', 'price' => 100, 'status' => 'draft',
            'related_work' => $work,
        ], $this->context());

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        $id = (int) $result->data['item']['id'];

        // 版 2 では参照を外す（**いまの値は空**。戻せば版 1 の値が書かれるはず）
        $this->update('product', $id, ['related_work' => null]);
        $this->assertNull(DB::table('products')->where('id', $id)->value('related_work'));

        /*
         * 参照先の型が `works` → `news` へ変わった。**同じ数字が別の型の ID として
         * 通ってしまう**のが一番危うい形である。ID は型と組でしか意味を持たない。
         */
        $this->overrideTarget('product', 'related_work', 'news');
        DB::table('news')->insert([
            'id' => $work, 'title' => '別物', 'slug' => 'other', 'body' => '<p>b</p>',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $version = (int) DB::table('products')->where('id', $id)->value('lock_version');
        $preview = app(RestoreContentRevision::class)->execute('product', $id, 1, $this->context(), $version);

        $this->assertSame(OperationStatus::ConfirmationRequired, $preview->status);
        $this->assertStringContainsString('related_work', implode("\n", $preview->confirmation?->impact ?? []));

        $applied = app(RestoreContentRevision::class)->execute(
            'product', $id, 1,
            $this->context()->withConfirmationToken((string) $preview->confirmation?->token),
            $version,
        );

        $this->assertTrue($applied->isSuccess(), (string) $applied->message);

        // **書いていない**（別の型の ID として解釈し直していない）
        $this->assertNull(DB::table('products')->where('id', $id)->value('related_work'));
    }

    // ── E. 入れ子の中の参照 ─────────────────────────────────────

    public function test_入れ子の中の参照先が消えていればfail_closed(): void
    {
        $work = $this->work('入れ子の参照先');

        // **先に**入れ子の項目を宣言してから版を作る（版の定義にその項目が入る）
        $this->declareNestedRelation();

        $id = DB::table('products')->insertGetId([
            'title' => '入れ子', 'slug' => 'nested-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
            'data' => json_encode(['spec_rows' => [['row_ref' => $work]]]),
        ]);

        $this->artisan('agentcms:content-baseline', ['--type' => 'product'])->assertSuccessful();

        DB::table('works')->where('id', $work)->delete();

        $snapshot = $this->currentState('product', $id);

        $result = app(RestoreContentRevision::class)->execute(
            'product', $id, 1, $this->context(),
            (int) DB::table('products')->where('id', $id)->value('lock_version'),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('参照先が見つかりません', (string) $result->message);
        $this->assertSame($snapshot, $this->currentState('product', $id));
    }

    public function test_入れ子が空やnullでも壊れない(): void
    {
        $this->declareNestedRelation();

        $id = DB::table('products')->insertGetId([
            'title' => '空の入れ子', 'slug' => 'empty-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
            'data' => json_encode(['spec_rows' => [[], ['row_ref' => null]], 'spec_group' => null]),
        ]);

        $this->artisan('agentcms:content-baseline', ['--type' => 'product'])->assertSuccessful();

        $version = (int) DB::table('products')->where('id', $id)->value('lock_version');
        $result = app(RestoreContentRevision::class)->execute('product', $id, 1, $this->context(), $version);

        // 型が揃っていない値で警告も TypeError も出さない
        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
    }

    // ── F. 確認のあとで状態が変わった ───────────────────────────

    public function test_確認のあとで参照先が消えたら適用しない(): void
    {
        $work = $this->work('あとで消える');

        $created = app(CreateContent::class)->execute('product', [
            'title' => '参照つき', 'description' => '<p>d</p>', 'price' => 100, 'status' => 'draft',
            'related_work' => $work,
        ], $this->context());
        $id = (int) $created->data['item']['id'];

        $this->update('product', $id, ['title' => '2 つ目']);

        $version = (int) DB::table('products')->where('id', $id)->value('lock_version');
        $token = (string) app(RestoreContentRevision::class)
            ->execute('product', $id, 1, $this->context(), $version)->confirmation?->token;

        // 確認を押すまでのあいだに参照先が消えた
        DB::table('works')->where('id', $work)->delete();

        $snapshot = $this->currentState('product', $id);

        $applied = app(RestoreContentRevision::class)->execute(
            'product', $id, 1, $this->context()->withConfirmationToken($token), $version,
        );

        $this->assertFalse($applied->isSuccess());
        $this->assertSame($snapshot, $this->currentState('product', $id), 'DB が変わっている');
    }

    public function test_確認のあとで内容が変わったら適用しない(): void
    {
        $id = $this->newPost('もと');
        $this->update('post', $id, ['title' => '2 つ目']);

        $version = (int) DB::table('post')->where('id', $id)->value('lock_version');
        $token = (string) app(RestoreContentRevision::class)
            ->execute('post', $id, 1, $this->context(), $version)->confirmation?->token;

        // **契約外の書き込み**が割り込んだ（最新版と現在が食い違う）
        DB::table('post')->where('id', $id)->update(['title' => '直接書き換え']);

        $applied = app(RestoreContentRevision::class)->execute(
            'post', $id, 1, $this->context()->withConfirmationToken($token), $version,
        );

        $this->assertSame(OperationStatus::Conflict, $applied->status);
        $this->assertSame('直接書き換え', DB::table('post')->where('id', $id)->value('title'));
    }

    // ── 補助 ────────────────────────────────────────────────────

    /** @return array{0: int, 1: string} */
    private function newsWithTerm(): array
    {
        $id = DB::table('news')->insertGetId([
            'title' => '分類つき', 'slug' => 'n-'.uniqid(), 'body' => '<p>b</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomies')->insert([
            'id' => '01JTAXAAAAAAAAAAAAAAAAAAAA', 'key' => 'news_category', 'label' => '分類',
            'hierarchical' => true, 'managed_by' => 'code', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxonomy_terms')->insert([
            'id' => '01JTERMAAAAAAAAAAAAAAAAAAA', 'taxonomy_id' => '01JTAXAAAAAAAAAAAAAAAAAAAA',
            'name' => 'お知らせ', 'slug' => 'oshirase', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('agentcms:content-baseline', ['--type' => 'news'])->assertSuccessful();

        return [$id, '01JTERMAAAAAAAAAAAAAAAAAAA'];
    }

    private function clearTerms(string $type, int $id): void
    {
        $result = app(UpdateContent::class)->execute(
            $type, $id, ['title' => '分類を外した'], $this->context(), partial: true,
            taxonomies: TaxonomyPayload::fromInput(['taxonomies' => ['news_category' => []]]),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function assignTerm(string $type, int $id, string $termId): void
    {
        $result = app(UpdateContent::class)->execute(
            $type, $id, ['title' => '分類をつけた'], $this->context(), partial: true,
            taxonomies: TaxonomyPayload::fromInput(['taxonomies' => ['news_category' => [$termId]]]),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    /** 入れ子の relation を持つ Field Group を宣言する（overlay 経由） */
    private function declareNestedRelation(): void
    {
        DB::table('field_groups')->insert([
            'id' => '01JGROUPAAAAAAAAAAAAAAAAAA', 'key' => 'spec', 'label' => '仕様',
            'sort_order' => 0, 'status' => 'published',
            'fields' => json_encode([[
                'key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => 10,
                'id' => '01JREPEATERAAAAAAAAAAAAAAA',
                'fields' => [[
                    'key' => 'row_ref', 'type' => 'relation', 'label' => '参照', 'target' => 'works',
                    'id' => '01JROWREFAAAAAAAAAAAAAAAAA',
                ]],
            ]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => '01JGROUPAAAAAAAAAAAAAAAAAA',
            'kind' => 'content_type', 'value' => 'product',
        ]);

        app()->forgetScopedInstances();
    }

    /**
     * schema の参照先を差し替える（override は `fields` を**オブジェクト**で書く）。
     *
     * リポジトリのファイルを作るので、必ず `tearDown` で消す。
     * テストが repo を汚したまま終わると、次に走るテストが原因不明で落ちる。
     */
    private function overrideTarget(string $type, string $field, string $target): void
    {
        $path = base_path("content/schemas/overrides/{$type}.json");
        @mkdir(dirname($path), 0775, true);

        file_put_contents($path, (string) json_encode([
            'id' => $type,
            'fields' => [$field => ['target' => $target]],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->temporaryFiles[] = $path;

        app()->forgetScopedInstances();
    }

    private function fingerprintOf(string $type, int $id): string
    {
        return (string) DB::table(RevisionWriter::TABLE)
            ->where('content_type', $type)->where('content_id', $id)
            ->orderBy('revision')->value('schema_fingerprint');
    }

    /** @return array<string, mixed> */
    private function currentState(string $type, int $id): array
    {
        $table = app(SchemaRegistry::class)->get($type)->table;

        return [
            'row' => (array) DB::table($table)->where('id', $id)->first(),
            'taxonomies' => RevisionSnapshot::taxonomies($type, $id),
            'revisions' => DB::table(RevisionWriter::TABLE)
                ->where('content_type', $type)->where('content_id', $id)->count(),
        ];
    }

    private function assertLatestMatchesCurrent(string $type, int $id): void
    {
        $schema = app(SchemaRegistry::class)->get($type);
        $model = app(ContentTypeResolver::class)
            ->newModel($type)->newQuery()->find($id);

        $this->assertNotNull($model);

        $latest = app(RevisionWriter::class)->latest($type, $id);

        $this->assertNotNull($latest);
        $this->assertTrue(
            RevisionSnapshot::sameContent($latest['snapshot'], RevisionSnapshot::of($schema, $type, $model)),
            '現在の内容と最新版が食い違っている',
        );
    }

    private function restore(string $type, int $id, int $revision, int $lockVersion): void
    {
        $token = (string) app(RestoreContentRevision::class)
            ->execute($type, $id, $revision, $this->context(), $lockVersion)->confirmation?->token;

        $result = app(RestoreContentRevision::class)->execute(
            $type, $id, $revision, $this->context()->withConfirmationToken($token), $lockVersion,
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function newPost(string $title): int
    {
        $result = app(CreateContent::class)->execute('post', [
            'title' => $title, 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return (int) $result->data['item']['id'];
    }

    private function work(string $title): int
    {
        return DB::table('works')->insertGetId([
            'title' => $title, 'slug' => 'w-'.uniqid(), 'category' => 'web', 'body' => '<p>b</p>',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function update(string $type, int $id, array $attributes): void
    {
        $result = app(UpdateContent::class)->execute($type, $id, $attributes, $this->context(), partial: true);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }
}
