<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\ArchiveTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\AttachTaxonomyToContentType;
use AgentCms\Core\Application\Taxonomy\CreateTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\DetachTaxonomyFromContentType;
use AgentCms\Core\Application\Taxonomy\RestoreTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\UpdateTaxonomyDefinition;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Models\User;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 分類の定義を管理画面から扱う（Phase 9.7 / STAGE 20D・SPEC §H）。
 *
 * ここで固定するのは 4 つ。
 *
 * 1. **key は作ったあと変えられない** — 割り当ても宣言も索引もそこを指している
 * 2. **無効化も関連付け外しも何も消さない** — 項目も、投稿に付いた分類も残る
 * 3. **削除できるのは一度も使っていない定義だけ** — 件数 0 では足りず、作成履歴まで見る
 * 4. **taxonomy-sync は cms 管理の定義と関連付けに触らない**
 */
final class TaxonomyDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actor = null;

    private function owner(): User
    {
        return $this->actor ??= User::factory()->create();
    }

    private function context(?User $user = null, ?string $token = null): OperationContext
    {
        $user ??= $this->owner();

        return OperationContext::admin($user->getKey(), (string) $user->name)
            ->withConfirmationToken($token);
    }

    private function nextRequest(): void
    {
        app()->forgetScopedInstances();
    }

    /** cms 管理の分類を 1 つ作る */
    private function createTaxonomy(string $key = 'topic', bool $hierarchical = false): Taxonomy
    {
        $result = app(CreateTaxonomyDefinition::class)->execute([
            'key' => $key,
            'label' => 'トピック',
            'description' => '記事の話題',
            'hierarchical' => $hierarchical,
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->nextRequest();

        return Taxonomy::query()->where('key', $key)->firstOrFail();
    }

    private function lockVersion(string $key): int
    {
        return (int) DB::table('taxonomies')->where('key', $key)->value('lock_version');
    }

    // ── 作成 ──────────────────────────────────────────────────────

    public function test_管理画面から分類を作れる(): void
    {
        $taxonomy = $this->createTaxonomy();

        $this->assertSame('cms', $taxonomy->managed_by->value);
        $this->assertSame('トピック', $taxonomy->label);
        $this->assertSame('記事の話題', $taxonomy->getAttribute('description'));
        $this->assertFalse($taxonomy->isArchived());
        $this->assertSame(1, $this->lockVersion('topic'));
    }

    /** @return list<array{0: string}> */
    public static function invalidKeys(): array
    {
        /*
         * 🔴 空文字はここから外した（2026-09-04）。
         *
         * キーは**任意**になり、空なら名前から作る（`TaxonomyKeyOptionalTest`）。
         * 日本語名だけで分類を作れるようにするための変更で、
         * 空はもう「間違った入力」ではない。
         */
        return [['Topic'], ['1topic'], ['topic-a'], ['topic key'], ['トピック']];
    }

    #[DataProvider('invalidKeys')]
    public function test_分類キーの文法は_taxonomy_keyが決める(string $key): void
    {
        $result = app(CreateTaxonomyDefinition::class)->execute([
            'key' => $key, 'label' => 'だめ',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status, "{$key} が通ってしまった");
        $this->assertSame(0, Taxonomy::query()->where('key', $key)->count());
    }

    public function test_コード管理の定義とキーが重ならない(): void
    {
        // `news_category` は content/taxonomies にある code 管理の定義
        $result = app(CreateTaxonomyDefinition::class)->execute([
            'key' => 'news_category', 'label' => '重複',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('定義ファイル', implode('', $result->errors['key'] ?? []));
    }

    public function test_同じキーは2つ作れない(): void
    {
        $this->createTaxonomy();

        $result = app(CreateTaxonomyDefinition::class)->execute([
            'key' => 'topic', 'label' => '重複',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(1, Taxonomy::query()->where('key', 'topic')->count());
    }

    public function test_キーは編集の入力として受け取らない(): void
    {
        $this->createTaxonomy();

        $result = app(UpdateTaxonomyDefinition::class)->execute([
            'key' => 'topic', 'lock_version' => 1, 'label' => '新しい名前', 'new_key' => 'renamed',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame('トピック', (string) DB::table('taxonomies')->where('key', 'topic')->value('label'));
    }

    // ── code 管理は読み取り専用 ───────────────────────────────────

    public function test_コード管理の定義は編集も無効化もできない(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $this->nextRequest();

        $context = $this->context();

        $this->assertSame(OperationStatus::Forbidden, app(UpdateTaxonomyDefinition::class)->execute([
            'key' => 'news_category', 'lock_version' => 0, 'label' => 'だめ',
        ], $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(ArchiveTaxonomyDefinition::class)->execute('news_category', 0, $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(RestoreTaxonomyDefinition::class)->execute('news_category', 0, $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(DeleteTaxonomyDefinition::class)->execute('news_category', 0, $context)->status);

        $this->assertSame('カテゴリ', (string) DB::table('taxonomies')->where('key', 'news_category')->value('label'), '定義ファイルの表示名が書き換わっている');
    }

    // ── 権限 ─────────────────────────────────────────────────────

    public function test_権限が無ければ何もできない(): void
    {
        $viewer = User::factory()->role(Role::Viewer)->create();
        $context = $this->context($viewer);

        $this->assertSame(OperationStatus::Forbidden, app(CreateTaxonomyDefinition::class)->execute(['key' => 'nope', 'label' => 'だめ'], $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $context)->status);
        $this->assertSame(OperationStatus::Forbidden, app(DetachTaxonomyFromContentType::class)->execute('topic', 'post', $context)->status);
        $this->assertSame(0, Taxonomy::query()->where('key', 'nope')->count());
    }

    // ── 階層の降格 ────────────────────────────────────────────────

    public function test_親を持つ項目があれば階層なしへ変えられない(): void
    {
        $taxonomy = $this->createTaxonomy('area', hierarchical: true);

        $parent = app(CreateTerm::class)->execute(['taxonomyKey' => 'area', 'name' => '関東'], $this->context());
        $this->assertTrue($parent->isSuccess(), (string) $parent->message);
        $parentId = (string) $parent->data['term']['id'];

        $child = app(CreateTerm::class)->execute(['taxonomyKey' => 'area', 'name' => '東京', 'parentId' => $parentId], $this->context());
        $this->assertTrue($child->isSuccess(), (string) $child->message);
        $this->nextRequest();

        $result = app(UpdateTaxonomyDefinition::class)->execute([
            'key' => 'area', 'lock_version' => $this->lockVersion('area'),
            'label' => 'エリア', 'hierarchical' => false,
        ], $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertTrue((bool) DB::table('taxonomies')->where('key', 'area')->value('hierarchical'), '階層が外れている');
        $this->assertSame(2, TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->count(), '項目が消えている');
    }

    // ── 無効化と復元 ──────────────────────────────────────────────

    public function test_無効化は確認を要り項目を消さない(): void
    {
        $taxonomy = $this->createTaxonomy();
        app(CreateTerm::class)->execute(['taxonomyKey' => 'topic', 'name' => '技術'], $this->context());
        $this->nextRequest();

        $version = $this->lockVersion('topic');
        $first = app(ArchiveTaxonomyDefinition::class)->execute('topic', $version, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertSame('active', (string) DB::table('taxonomies')->where('key', 'topic')->value('status'), '確認の前に書いている');

        $applied = app(ArchiveTaxonomyDefinition::class)->execute('topic', $version, $this->context(null, $first->confirmation?->token));

        $this->assertTrue($applied->isSuccess(), (string) $applied->message);
        $this->assertSame('archived', (string) DB::table('taxonomies')->where('key', 'topic')->value('status'));
        $this->assertSame(1, TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->count(), '項目が消えている');
    }

    public function test_無効にした分類は投稿の編集画面に出ない(): void
    {
        $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());
        $this->nextRequest();

        $this->assertContains('topic', app(SchemaRegistry::class)->get('post')->taxonomyKeys());

        DB::table('taxonomies')->where('key', 'topic')->update(['status' => 'archived']);
        $this->nextRequest();

        $this->assertNotContains('topic', app(SchemaRegistry::class)->get('post')->taxonomyKeys(), '無効なのに出ている');
        // 関連付けの行は残っている（復元すれば戻る）
        $this->assertSame(1, DB::table('content_type_taxonomies')->count());
    }

    public function test_復元で元どおり見える(): void
    {
        $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());
        DB::table('taxonomies')->where('key', 'topic')->update(['status' => 'archived']);
        $this->nextRequest();

        $result = app(RestoreTaxonomyDefinition::class)->execute('topic', $this->lockVersion('topic'), $this->context());
        $this->nextRequest();

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertContains('topic', app(SchemaRegistry::class)->get('post')->taxonomyKeys());
    }

    // ── 関連付け ──────────────────────────────────────────────────

    public function test_多対多で関連付けられる(): void
    {
        $this->createTaxonomy();

        $this->assertTrue(app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context())->isSuccess());
        $this->assertTrue(app(AttachTaxonomyToContentType::class)->execute('topic', 'works', false, true, $this->context())->isSuccess());
        $this->nextRequest();

        $this->assertContains('topic', app(SchemaRegistry::class)->get('post')->taxonomyKeys());
        $this->assertContains('topic', app(SchemaRegistry::class)->get('works')->taxonomyKeys());

        // 投稿タイプ側の制約は関連付けごとに違ってよい
        $works = app(SchemaRegistry::class)->get('works')->taxonomy('topic');
        $this->assertFalse($works->multiple);
        $this->assertTrue($works->required);
    }

    public function test_同じ組を二重に関連付けない(): void
    {
        $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());

        $again = app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());

        $this->assertSame(OperationStatus::Conflict, $again->status);
        $this->assertSame(1, DB::table('content_type_taxonomies')->count());
    }

    public function test_ファイルが宣言している型へは重ねられない(): void
    {
        $this->createTaxonomy('news_topic');

        // `news` は news_category をファイルで宣言している。同じ key を DB から重ねられない
        $sameKey = app(CreateTaxonomyDefinition::class)->execute(['key' => 'other', 'label' => 'ほか'], $this->context());
        $this->assertTrue($sameKey->isSuccess());

        // ファイル宣言と同じ key を持つ cms 定義は作れないので、
        // ここでは「ファイル宣言のある型へ、その key を重ねようとする」経路を直接叩く
        $result = app(AttachTaxonomyToContentType::class)->execute('news_topic', 'unknown_type', true, false, $this->context());

        $this->assertSame(OperationStatus::NotFound, $result->status);
        $this->assertSame(0, DB::table('content_type_taxonomies')->count());
    }

    public function test_関連付けを外しても投稿に付いた分類は消えない(): void
    {
        $taxonomy = $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());
        $this->nextRequest();

        $term = app(CreateTerm::class)->execute(['taxonomyKey' => 'topic', 'name' => '技術'], $this->context());
        $termId = (string) $term->data['term']['id'];

        $entryId = DB::table('post')->insertGetId([
            'title' => '分類つきの投稿', 'slug' => 'tagged', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TaxonomyTermAssignment::query()->create([
            'term_id' => $termId, 'content_type' => 'post', 'content_id' => $entryId, 'sort_order' => 0,
        ]);
        $this->nextRequest();

        $first = app(DetachTaxonomyFromContentType::class)->execute('topic', 'post', $this->context());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);

        $applied = app(DetachTaxonomyFromContentType::class)->execute('topic', 'post', $this->context(null, $first->confirmation?->token));
        $this->assertTrue($applied->isSuccess(), (string) $applied->message);
        $this->nextRequest();

        // **割り当ては 1 件も消えていない**
        $this->assertSame(1, TaxonomyTermAssignment::query()->where('content_type', 'post')->count());
        $this->assertNotContains('topic', app(SchemaRegistry::class)->get('post')->taxonomyKeys());

        // 付け直すと、そのまま元どおり見える
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());
        $this->nextRequest();

        $this->assertContains('topic', app(SchemaRegistry::class)->get('post')->taxonomyKeys());
        $this->assertSame(1, TaxonomyTermAssignment::query()
            ->where('content_type', 'post')
            ->whereIn('term_id', TaxonomyTerm::query()->where('taxonomy_id', $taxonomy->id)->select('id'))
            ->count());
    }

    public function test_無効にした投稿タイプとの関連も残る(): void
    {
        $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());

        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'archived', 'lifecycle' => 'ready',
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->nextRequest();

        $this->assertSame(1, DB::table('content_type_taxonomies')->where('content_type', 'post')->count());
    }

    // ── 厳しい条件つきの削除 ──────────────────────────────────────

    public function test_一度も使っていない定義だけ削除できる(): void
    {
        $this->createTaxonomy();
        $version = $this->lockVersion('topic');

        $first = app(DeleteTaxonomyDefinition::class)->execute('topic', $version, $this->context());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertSame(1, Taxonomy::query()->where('key', 'topic')->count(), '確認の前に消している');

        $applied = app(DeleteTaxonomyDefinition::class)->execute('topic', $version, $this->context(null, $first->confirmation?->token));

        $this->assertTrue($applied->isSuccess(), (string) $applied->message);
        $this->assertSame(0, Taxonomy::query()->where('key', 'topic')->count());
    }

    public function test_項目がある定義は削除できない(): void
    {
        $this->createTaxonomy();
        app(CreateTerm::class)->execute(['taxonomyKey' => 'topic', 'name' => '技術'], $this->context());
        $this->nextRequest();

        $result = app(DeleteTaxonomyDefinition::class)->execute('topic', $this->lockVersion('topic'), $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(1, Taxonomy::query()->where('key', 'topic')->count());
    }

    public function test_項目を作った履歴がある定義は削除できない(): void
    {
        $this->createTaxonomy();

        $term = app(CreateTerm::class)->execute(['taxonomyKey' => 'topic', 'name' => '一時的'], $this->context());
        $termId = (string) $term->data['term']['id'];
        TaxonomyTerm::query()->whereKey($termId)->delete();
        $this->nextRequest();

        // 件数は 0 だが、**作った履歴が残っている**ので「未使用」ではない
        $this->assertSame(0, TaxonomyTerm::query()->where('taxonomy_id', Taxonomy::query()->where('key', 'topic')->value('id'))->count());

        $result = app(DeleteTaxonomyDefinition::class)->execute('topic', $this->lockVersion('topic'), $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('過去に', (string) $result->message);
    }

    public function test_関連付けがある定義は削除できない(): void
    {
        $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());
        $this->nextRequest();

        $result = app(DeleteTaxonomyDefinition::class)->execute('topic', $this->lockVersion('topic'), $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(1, Taxonomy::query()->where('key', 'topic')->count());
    }

    // ── 楽観ロック ────────────────────────────────────────────────

    public function test_版が食い違えば保存せず知らせる(): void
    {
        $this->createTaxonomy();

        app(UpdateTaxonomyDefinition::class)->execute([
            'key' => 'topic', 'lock_version' => 1, 'label' => '1 回目',
        ], $this->context());
        $this->nextRequest();

        $result = app(UpdateTaxonomyDefinition::class)->execute([
            'key' => 'topic', 'lock_version' => 1, 'label' => '2 回目',
        ], $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame('1 回目', (string) DB::table('taxonomies')->where('key', 'topic')->value('label'), '後勝ちになっている');

        $audit = DB::table('audit_logs')->where('operation', 'taxonomy.definition.update')->latest('id')->first();
        $this->assertSame('conflict', $audit?->result);
        $metadata = json_decode((string) $audit?->metadata, true);
        $this->assertSame(1, $metadata['expectedVersion'] ?? null);
        $this->assertSame(2, $metadata['actualVersion'] ?? null);
    }

    // ── taxonomy-sync ─────────────────────────────────────────────

    public function test_syncはcms管理の定義と関連付けに触らない(): void
    {
        $this->createTaxonomy();
        app(AttachTaxonomyToContentType::class)->execute('topic', 'post', true, false, $this->context());
        $this->nextRequest();

        $before = DB::table('taxonomies')->where('key', 'topic')->first();
        $associations = DB::table('content_type_taxonomies')->count();

        $report = app(TaxonomySynchronizer::class)->sync();

        $this->assertTrue($report->applied, 'code 管理の同期まで止まっている');

        $after = DB::table('taxonomies')->where('key', 'topic')->first();

        $this->assertEquals($before, $after, 'cms 管理の定義が書き換わっている');
        $this->assertSame($associations, DB::table('content_type_taxonomies')->count());
    }

    public function test_同じキーのcms定義があればsyncは1行も書かない(): void
    {
        // code 定義と同じ key の行を cms 管理として作る（正規経路では作れないので直接入れる）
        DB::table('taxonomies')->insert([
            'id' => TaxonomyId::generate(),
            'key' => 'news_category', 'label' => '手で作った', 'hierarchical' => false,
            'status' => 'active', 'managed_by' => 'cms', 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = app(TaxonomySynchronizer::class)->sync();

        $this->assertFalse($report->applied, '衝突しているのに書き込んでいる');
        $this->assertSame('手で作った', (string) DB::table('taxonomies')->where('key', 'news_category')->value('label'));
    }

    // ── 監査 / catalog ────────────────────────────────────────────

    public function test_監査には項目名までしか残さない(): void
    {
        $this->createTaxonomy();

        app(UpdateTaxonomyDefinition::class)->execute([
            'key' => 'topic', 'lock_version' => 1,
            'label' => '秘密の表示名', 'description' => 'admin@example.test',
        ], $this->context());

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'taxonomy.definition.update')->latest('id')->value('metadata');

        $this->assertStringContainsString('label', $metadata);
        $this->assertStringNotContainsString('秘密の表示名', $metadata);
        $this->assertStringNotContainsString('admin@example.test', $metadata);
    }

    public function test_失敗も結末として記録する(): void
    {
        app(CreateTaxonomyDefinition::class)->execute(['key' => 'Bad', 'label' => 'だめ'], $this->context());

        $this->assertSame(
            'validation_error',
            DB::table('audit_logs')->where('operation', 'taxonomy.definition.create')->latest('id')->value('result'),
        );
    }

    public function test_定義の操作は_a_iへ公開しない(): void
    {

        foreach (OperationCatalog::ids() as $id) {
            $this->assertStringStartsNotWith('taxonomy.definition.', $id, "管理 operation [{$id}] が AI へ公開されている");
        }
    }

    // ── 非回帰 ────────────────────────────────────────────────────

    public function test_既存のコード管理の分類はこれまでどおり動く(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $this->nextRequest();

        $this->assertSame('code', (string) DB::table('taxonomies')->where('key', 'news_category')->value('managed_by'));
        $this->assertContains('news_category', app(SchemaRegistry::class)->get('news')->taxonomyKeys());

        $term = app(CreateTerm::class)->execute(['taxonomyKey' => 'news_category', 'name' => 'お知らせ'], $this->context());
        $this->assertTrue($term->isSuccess(), (string) $term->message);
    }
}
