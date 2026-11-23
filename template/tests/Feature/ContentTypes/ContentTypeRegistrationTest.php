<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\CmsManagedContentTypes;
use AgentCms\Core\ContentTypes\ContentTypeIdentifier;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecycle;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeReadiness;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettings;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeStatus;
use AgentCms\Core\ContentTypes\Registration\ManagedEntry;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 投稿タイプの登録の**読み取り基盤**（Phase 9.7 / STAGE 20B・ADR-0019）。
 *
 * ここで固定するのは 3 つ。
 *
 * 1. **行が無い型にも既定を返す** — migration が既存の型へ一括 insert しないので、
 *    既定が 1 か所で決まっていないと面ごとに違う既定が生まれる
 * 2. **未知の値は fail-closed** — `status` は archived 側へ、`lifecycle` は使えない側へ倒す
 * 3. **1 リクエストに 1 回しか読まない** — 型の数だけ query が飛ぶ実装にしない
 */
final class ContentTypeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ContentTypeSettingsResolver
    {
        return app(ContentTypeSettingsResolver::class);
    }

    private function policy(): ContentTypeLifecyclePolicy
    {
        return app(ContentTypeLifecyclePolicy::class);
    }

    /** @param array<string, mixed> $overrides */
    private function insertRow(string $id, array $overrides = []): void
    {
        DB::table('content_types')->insert(array_merge([
            'content_type_id' => $id,
            'status' => 'active',
            'lifecycle' => 'ready',
            'menu_order' => 0,
            'comments_enabled' => false,
            'revisions_enabled' => true,
            'public_slug' => null,
            'registration' => null,
            'display_overrides' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // ── 既定値（行が無い） ────────────────────────────────────────

    public function test_行が無い型は既定を返す(): void
    {
        $settings = $this->resolver()->for('news');

        $this->assertFalse($settings->stored);
        $this->assertSame(ContentTypeStatus::Active, $settings->status);
        $this->assertSame(ContentTypeLifecycle::Ready, $settings->lifecycle);
        $this->assertSame(0, $settings->menuOrder);
        $this->assertFalse($settings->commentsEnabled);
        $this->assertTrue($settings->revisionsEnabled);
        $this->assertNull($settings->publicSlug);
        $this->assertSame([], $settings->registration);
        $this->assertSame([], $settings->displayOverrides);
        $this->assertSame(0, $settings->lockVersion);
    }

    public function test_migrationは既存の型を1件もimportしない(): void
    {
        // 表はある（migration は通っている）が、行は 0 件である
        $this->assertTrue(Schema::hasTable('content_types'));
        $this->assertSame(0, DB::table('content_types')->count());
        $this->assertSame(ContentTypeReadiness::Ready, $this->resolver()->readiness());
    }

    // ── 未知の値の fail-closed ────────────────────────────────────

    /**
     * **未知の値は DB へ書かずに当てる。**
     *
     * かつては `status = 'weird'` を行へ入れていたが、**MySQL は CHECK 制約で拒む**
     * （`3819 Check constraint violated`）— そして拒むのが正しい。本番でその行は作れない。
     *
     * ここが決めているのは「**読むときに未知の値をどう倒すか**」で、
     * それは `ContentTypeSettings::fromRow()` に閉じている。だから**行から読む口へ直に当てる**。
     * 書けない値を書けたことにして試験する必要は無い。
     */
    public function test_未知のstatusはarchivedとして読む(): void
    {
        $settings = ContentTypeSettings::fromRow([
            'content_type_id' => 'news',
            'status' => 'weird',
        ]);

        $this->assertSame(ContentTypeStatus::Archived, $settings->status);
        $this->assertContains('status=weird', $settings->unrecognized);
    }

    /** 未知の値は DB へ書かずに当てる（上と同じ理由 — CHECK 制約が正しく拒む） */
    public function test_未知のlifecycleは使えない状態として読む(): void
    {
        $settings = ContentTypeSettings::fromRow([
            'content_type_id' => 'news',
            'lifecycle' => 'weird',
        ]);

        $this->assertSame(ContentTypeLifecycle::Deprovisioning, $settings->lifecycle);
        $this->assertContains('lifecycle=weird', $settings->unrecognized);

        /*
         * **倒した状態では何もできない。**
         *
         * 能力の側は「読める lifecycle」で作った行から見る（`notReadyLifecycles` の
         * data provider が `deprovisioning` を含んでおり、そこが同じことを見ている）。
         */
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notReadyLifecycles(): array
    {
        return [['provisioning'], ['deprovisioning'], ['tombstoned']];
    }

    #[DataProvider('notReadyLifecycles')]
    public function test_readyでないlifecycleはstatusに関わらず全能力false(string $lifecycle): void
    {
        // status は active。それでも lifecycle が勝つ
        $this->insertRow('news', ['lifecycle' => $lifecycle, 'status' => 'active']);

        foreach ($this->policy()->capabilities('news') as $ability => $allowed) {
            $this->assertFalse($allowed, "{$ability} は {$lifecycle} で false のはず");
        }
    }

    public function test_archivedはreadだけ通る(): void
    {
        $this->insertRow('news', ['status' => 'archived']);

        $this->assertTrue($this->policy()->canRead('news'), 'OG-F: archived でも read は通す');
        $this->assertFalse($this->policy()->canWrite('news'));
        $this->assertFalse($this->policy()->canCreate('news'));
        $this->assertFalse($this->policy()->showInMenu('news'));
        $this->assertFalse($this->policy()->isPublic('news'), 'OG-D: archived は公開面から外す');
        $this->assertFalse($this->policy()->commentsEnabled('news'));
    }

    public function test_activeかつreadyは全能力が通る(): void
    {
        $this->insertRow('news');

        $this->assertTrue($this->policy()->canRead('news'));
        $this->assertTrue($this->policy()->canWrite('news'));
        $this->assertTrue($this->policy()->canCreate('news'));
        $this->assertTrue($this->policy()->showInMenu('news'));
    }

    public function test_publicはスラッグが無ければfalse(): void
    {
        $this->insertRow('news', ['registration' => json_encode(['public' => true])]);
        $this->assertFalse($this->policy()->isPublic('news'), 'URL が無いのに公開とは言わない');

        DB::table('content_types')->where('content_type_id', 'news')->update(['public_slug' => 'oshirase']);
        app()->forgetScopedInstances();

        $this->assertTrue($this->policy()->isPublic('news'));
    }

    // ── readiness ────────────────────────────────────────────────

    public function test_表が無ければunmigratedで書き込みを止め一覧は落とさない(): void
    {
        Schema::drop('content_types');

        $resolver = $this->resolver();

        $this->assertSame(ContentTypeReadiness::Unmigrated, $resolver->readiness());
        $this->assertFalse($resolver->readiness()->allowsWrites());
        $this->assertNotNull($resolver->readiness()->message());

        // 一覧側は落とさない。既定で読める
        $this->assertSame(ContentTypeStatus::Active, $resolver->for('news')->status);
        $this->assertFalse($this->policy()->canWrite('news'), '設定を読めないまま書かせない');
    }

    public function test_読めなければindeterminateで書き込みを止める(): void
    {
        // 届かない接続を用意する（DB 障害と同じ状態）
        config()->set('database.connections.agentcms_unreachable', [
            'driver' => 'sqlite',
            'database' => '/nonexistent-directory/agentcms-unreachable.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $broken = new ContentTypeSettingsResolver(DB::connection('agentcms_unreachable'));

        $this->assertSame(ContentTypeReadiness::Indeterminate, $broken->readiness());
        $this->assertFalse($broken->readiness()->allowsWrites());
        // 例外を投げない。投げると一覧ごと落ちて復元も診断もできなくなる
        $this->assertSame(ContentTypeStatus::Active, $broken->for('news')->status);
    }

    // ── query の回数 ──────────────────────────────────────────────

    public function test_1リクエスト内で重複queryしない(): void
    {
        $this->insertRow('news');
        $this->insertRow('works');

        $resolver = $this->resolver();

        $resolver->for('news');
        $resolver->for('works');
        $resolver->for('unknown_type');
        $resolver->readiness();
        $resolver->stored();

        $this->assertSame(1, $resolver->loadCount());
    }

    public function test_resolverはscopedで登録されている(): void
    {
        $first = app(ContentTypeSettingsResolver::class);
        $second = app(ContentTypeSettingsResolver::class);

        $this->assertSame($first, $second, '1 リクエスト内では同じ instance');

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(ContentTypeSettingsResolver::class), 'リクエスト境界で捨てる');
    }

    // ── allowlist ────────────────────────────────────────────────

    public function test_未知のキーは読み取りで落として診断に出す(): void
    {
        $this->insertRow('news', [
            'registration' => json_encode(['public' => true, 'capability_type' => 'post']),
            'display_overrides' => json_encode(['label' => '知らせ', 'menu_icon' => 'https://example.com/x.svg']),
        ]);

        $settings = $this->resolver()->for('news');

        $this->assertSame(['public' => true], $settings->registration);
        $this->assertSame(['label' => '知らせ'], $settings->displayOverrides);
        $this->assertContains('registration.capability_type', $settings->unrecognized);
        $this->assertContains('display_overrides.menu_icon', $settings->unrecognized);
    }

    public function test_iconはallowlistの名前だけ(): void
    {
        $this->insertRow('news', ['display_overrides' => json_encode(['icon' => '<svg onload=alert(1)>'])]);

        $settings = $this->resolver()->for('news');

        $this->assertSame([], $settings->displayOverrides);
        $this->assertContains('display_overrides.icon', $settings->unrecognized);
    }

    /** 範囲外の値は DB へ書かずに当てる（CHECK 制約が正しく拒む） */
    public function test_menu_orderは範囲外でも読み取りで丸める(): void
    {
        $settings = ContentTypeSettings::fromRow([
            'content_type_id' => 'news',
            'menu_order' => 99999,
        ]);

        $this->assertSame(9999, $settings->menuOrder);
    }

    public function test_不正な公開スラッグは読み取りで捨てる(): void
    {
        $this->insertRow('news', ['public_slug' => 'Bad Slug!']);

        $settings = $this->resolver()->for('news');

        $this->assertNull($settings->publicSlug);
        $this->assertContains('public_slug', $settings->unrecognized);
    }

    public function test_公開スラッグは型をまたいで一意(): void
    {
        $this->insertRow('news', ['public_slug' => 'blog']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRow('works', ['public_slug' => 'blog']);
    }

    public function test_同じ内部_i_dを2行持てない(): void
    {
        $this->insertRow('news');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRow('news');
    }

    // ── 汎用モデルと識別子 ────────────────────────────────────────

    public function test_汎用モデルは検証済み_i_dからだけテーブルを決める(): void
    {
        $errors = [];
        $model = ManagedEntry::tryForType('faq', $errors);

        $this->assertNotNull($model);
        $this->assertSame('faq', $model->getTable());
        $this->assertSame([], $errors);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unsafeIdentifiers(): array
    {
        return [
            ['faq; drop table users'],
            ['../../etc/passwd'],
            ['Faq'],
            ['select'],
            ['class'],
            [''],
        ];
    }

    #[DataProvider('unsafeIdentifiers')]
    public function test_危険な_i_dからはモデルを作れない(string $id): void
    {
        $errors = [];

        $this->assertNull(ManagedEntry::tryForType($id, $errors));
        $this->assertNotSame([], $errors);
    }

    public function test_postは新しい_i_dとしては予約されている(): void
    {
        $errors = [];
        $this->assertNull(ContentTypeIdentifier::tryFrom('post', $errors));
        $this->assertNotSame([], $errors);

        // ただし **既に登録されている型としては読める**（同梱の既定型が自分自身を見失わない）
        $registeredErrors = [];
        $this->assertNotNull(ContentTypeIdentifier::forRegistered('post', $registeredErrors));
        $this->assertSame([], $registeredErrors);
    }

    public function test_for_registeredでも危険な_i_dは通らない(): void
    {
        foreach (['select', 'class', 'Faq', 'faq;drop'] as $id) {
            $errors = [];
            $this->assertNull(
                ContentTypeIdentifier::forRegistered($id, $errors),
                "{$id} は登録済み扱いでも通してはいけない",
            );
        }
    }

    // ── boot が DB を読まないこと ─────────────────────────────────

    public function test_boot経路はcontent_typesへqueryしない(): void
    {
        // boot 直後、resolver は **まだ解決されてすらいない**
        $this->assertFalse(
            app()->resolved(ContentTypeSettingsResolver::class),
            'boot が resolver を触っていたら、route 登録で DB を読んでいる',
        );

        // boot がやること（schema の読み込みと型の発見）を query spy 付きでやり直す
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $schemas = app(SchemaRegistry::class);
        $schemas->declared();
        CmsManagedContentTypes::discover($schemas);

        $this->assertSame([], $queries, 'route 登録とモデル登録は DB を 1 度も引かない');
    }

    // ── migration の up / down ────────────────────────────────────

    public function test_downは設定行が在れば止める(): void
    {
        $this->insertRow('news');

        $migration = require base_path('core/database/migrations/2026_08_15_000020_create_content_types_table.php');

        try {
            $migration->down();
            $this->fail('設定行が在るのに down が通ってしまった');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('content_types', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('content_types'), '止めたのに表が消えている');
        $this->assertSame(1, DB::table('content_types')->count());
    }

    public function test_downは自分の表だけを落とす(): void
    {
        $migration = require base_path('core/database/migrations/2026_08_15_000020_create_content_types_table.php');

        $before = $this->tableNames();

        $migration->down();

        $this->assertFalse(Schema::hasTable('content_types'));
        $this->assertSame(
            array_values(array_diff($before, ['content_types'])),
            $this->tableNames(),
            'down が自分以外の表に触っている',
        );

        // up でそのまま戻る（列も既定値も同じ）
        $migration->up();

        $this->assertTrue(Schema::hasTable('content_types'));
        $this->assertSame($before, $this->tableNames());
        $this->assertSame(0, DB::table('content_types')->count(), 'up は 1 行も作らない');

        foreach (['content_type_id', 'status', 'lifecycle', 'menu_order', 'comments_enabled',
            'revisions_enabled', 'public_slug', 'registration', 'display_overrides',
            'lock_version', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('content_types', $column), "{$column} が無い");
        }
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $names = array_map(
            static fn (array $table): string => $table['name'],
            Schema::getTables(),
        );
        sort($names);

        return array_values($names);
    }

    // ── 値オブジェクト ────────────────────────────────────────────

    public function test_settingsは行が無いときと同じ形を返す(): void
    {
        $defaults = ContentTypeSettings::defaultsFor('faq')->toArray();

        $this->assertSame('active', $defaults['status']);
        $this->assertSame('ready', $defaults['lifecycle']);
        $this->assertFalse($defaults['stored']);
        $this->assertSame([], $defaults['unrecognized']);
    }
}
