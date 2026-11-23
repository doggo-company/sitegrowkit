<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\BuilderFieldType;
use AgentCms\Core\ContentTypes\ContentTypeGenerator;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\ViewErrorBag;

/**
 * Phase 9.4 / STAGE #17A — Preview → Confirm → Apply（SPEC §2-4〜7・19〜21・27・28）。
 *
 * ここが STAGE の中心。**確認前に何も変わらない**ことと、
 * **途中で失敗しても何も残らない**ことを、実際のファイルと DB で確かめる。
 */
final class ContentTypeBuilderApplyTest extends BuilderTestCase
{
    /** SPEC §2-21 — 確認前にはファイルも DB も一切変更しない */
    public function test_確認前にはファイルもdbも変更されない(): void
    {
        $before = $this->sandboxFingerprint();

        $this->actingAs($this->admin())
            ->from('/admin/content-types/create')
            ->post('/admin/content-types', $this->payload())
            ->assertRedirect('/admin/content-types/create');

        $this->assertSame($before, $this->sandboxFingerprint(), 'プレビューでファイルが変わっています');
        $this->assertFalse(DbSchema::hasTable($this->typeId), 'プレビューでテーブルが作られています');
        $this->assertNotNull($this->flashedConfirmationToken(), '確認トークンが返っていない');

        // 確認内容（計画）も一緒に返る
        $plan = $this->inertiaFlash()['plan'] ?? null;
        $this->assertIsArray($plan);
        $this->assertSame($this->typeId, $plan['id']);
        $this->assertSame("/admin/{$this->typeId}", $plan['adminUrl']);
        $this->assertSame($this->typeId, $plan['table']);
    }

    /** SPEC §2-4 — 正常な Content Type を作成できる */
    public function test_正常なcontent_typeを作成できる(): void
    {
        $this->createContentType($this->admin());

        $this->assertFileExists($this->schemaPath.'/'.$this->typeId.'.json');
        $this->assertFileExists($this->modulesPath.'/'.$this->typeStudly.'/Models/'.$this->typeStudly.'.php');
        $this->assertNotEmpty(glob($this->modulesPath.'/'.$this->typeStudly.'/database/migrations/*_create_'.$this->typeId.'_table.php'));
        $this->assertTrue(DbSchema::hasTable($this->typeId));

        $schema = app(SchemaRegistry::class)->get($this->typeId);
        $this->assertSame('cms', $schema->managedBy);
        $this->assertSame($this->typeId, $schema->table);
        $this->assertSame(['title', 'slug', 'answer', 'status'], $schema->fieldKeys());

        // 生成カラムは schema の column-backed フィールドと一致する（SchemaTableParity と同じ契約）
        $columns = DbSchema::getColumnListing($this->typeId);
        foreach ($schema->columnFields() as $field) {
            $this->assertContains($field->key, $columns, "カラム {$field->key} が無い");
        }
        $this->assertContains('id', $columns);
        $this->assertContains('created_at', $columns);
        /*
         * `data` は **常に**持たせる（Phase 9.5-B / owner 決定 G2-(a)）。
         *
         * Builder が選べる 16 型はすべて column-backed なので作った直後は空だが、
         * Field Group を割り当てた瞬間に受け皿が要る。後から ALTER するより、
         * 最初から nullable で置いておくほうが安全である。
         */
        $this->assertContains('data', $columns, 'Field Group の値の受け皿が無い');

        // 監査に残る（値ではなく「何を作ったか」だけ）
        $audit = DB::table('audit_logs')->where('operation', 'content.type.create')->first();
        $this->assertNotNull($audit);
        $this->assertSame('content_type', $audit->resource_type);
        $this->assertSame($this->typeId, $audit->resource_id);
    }

    /**
     * **同じ要求の中で、書いたモデルがその場で読めること**（Phase 9.7 / STAGE 20F）。
     *
     * 作成は「まだ無いことを確かめる → 作る」の順で進む。前半で
     * `class_exists()` を呼ぶと、composer の ClassLoader は**見つからなかったことを
     * 覚える**（`missingClasses`）。そのままだと、後半でファイルを置いても
     * 同じプロセスからは二度と見つからず、作成は必ず巻き戻る。
     * ブラウザから型を作れなかったのはこれが理由だった。
     *
     * `autoload: false` で確かめているのは、**探しに行かせないため**である。
     * 探させてしまうと、テスト環境の補助 autoloader が拾ってしまい、
     * 本番で起きる失敗をこのテストが見逃す。
     */
    public function test_作成した要求の中でモデルが読み込まれている(): void
    {
        $class = 'Modules\\'.$this->typeStudly.'\\Models\\'.$this->typeStudly;

        // 「まだ無い」を確かめる経路と同じことを、先に起こしておく
        $this->assertFalse(class_exists($class, autoload: true));

        $this->createContentType($this->admin());

        $this->assertTrue(
            class_exists($class, autoload: false),
            '生成したモデルがこの要求の中で読み込まれていない（次の確認で巻き戻る）',
        );
    }

    /**
     * 生成 schema の `admin.search` と各 field の `searchable` が一致する。
     *
     * 画面の操作口は 1 つ（「検索対象にする項目」）で、送信時に
     * field 側へ導出している。両者がずれた schema を作らないことを固定する。
     */
    public function test_検索対象の指定がschemaの2箇所で一致する(): void
    {
        $this->createContentType($this->admin(), [
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => '質問', 'searchable' => true, 'choices' => []],
                ['key' => 'answer', 'type' => 'richtext', 'label' => '回答', 'searchable' => true, 'choices' => []],
                ['key' => 'memo', 'type' => 'textarea', 'label' => 'メモ', 'searchable' => false, 'choices' => []],
                ['key' => 'status', 'type' => 'status', 'label' => 'ステータス', 'choices' => []],
            ],
            'list' => ['title', 'status'],
            'search' => ['title', 'answer'],
        ]);

        $schema = app(SchemaRegistry::class)->get($this->typeId);

        $flagged = array_values(array_map(
            static fn ($field): string => $field->key,
            array_filter($schema->fields, static fn ($field): bool => $field->searchable),
        ));

        $this->assertSame(['title', 'answer'], $schema->searchable);
        $this->assertSame($schema->searchable, $flagged, 'admin.search と searchable フラグがずれています');
        $this->assertSame(['title', 'status'], $schema->adminList);
    }

    /**
     * **矛盾した入力でも**、生成 schema の 2 つの表現は必ず一致する。
     *
     * UI の送信ロジックに安全性を預けない（Admin / CLI から直接
     * 食い違う payload を送っても、ずれた schema にならない）。
     */
    public function test_矛盾した検索指定でもschemaは一致する(): void
    {
        $this->createContentType($this->admin(), [
            'fields' => [
                // search には入っているのに searchable=false
                ['key' => 'title', 'type' => 'text', 'label' => '質問', 'searchable' => false, 'choices' => []],
                // search には入っていないのに searchable=true
                ['key' => 'memo', 'type' => 'textarea', 'label' => 'メモ', 'searchable' => true, 'choices' => []],
                ['key' => 'other', 'type' => 'textarea', 'label' => 'その他', 'searchable' => false, 'choices' => []],
                ['key' => 'status', 'type' => 'status', 'label' => 'ステータス', 'choices' => []],
            ],
            'list' => ['title'],
            'search' => ['title'],
        ]);

        $schema = app(SchemaRegistry::class)->get($this->typeId);

        $flagged = array_values(array_map(
            static fn ($field): string => $field->key,
            array_filter($schema->fields, static fn ($field): bool => $field->searchable),
        ));

        // どちらの指定も落とさず（和集合）、2 つの表現は一致する
        $this->assertSame(['title', 'memo'], $schema->searchable);
        $this->assertSame($schema->searchable, $flagged);
        $this->assertNotContains('other', $schema->searchable);
    }

    /** SPEC §2-7 — 作成直後に ContentTypeResolver が解決できる */
    public function test_作成直後にresolverが解決できる(): void
    {
        $this->createContentType($this->admin());

        $resolver = app(ContentTypeResolver::class);

        $this->assertTrue($resolver->knows($this->typeId));
        $this->assertContains($this->typeId, $resolver->availableTypes());
        $this->assertSame("Modules\\{$this->typeStudly}\\Models\\{$this->typeStudly}", $resolver->modelClass($this->typeId));
        $this->assertSame($this->typeId, $resolver->newModel($this->typeId)->getTable());

        // 既存の型も引き続き解決できる
        foreach (['news', 'works', 'product'] as $existing) {
            $this->assertTrue($resolver->knows($existing), "{$existing} が解決できなくなっています");
        }
    }

    /** SPEC §2-5 — 作成直後にサイドバーへ出る（次のリクエストで） */
    public function test_作成直後にサイドバーへ表示される(): void
    {
        $user = $this->admin();
        $this->createContentType($user);

        // アプリを作り直す = 「次のリクエスト」。route も registry も登録し直される
        $this->rebootApplication();

        $response = $this->actingAs($user)->get('/admin/content-types');
        $response->assertOk();

        $shared = $response->viewData('page')['props']['nav']['contentTypes'];
        $ids = array_column($shared, 'id');

        $this->assertContains($this->typeId, $ids, 'サイドバーに新しい投稿タイプが出ていない');
        $this->assertContains('news', $ids, '既存の投稿タイプが消えている');
        $this->assertContains('works', $ids);

        $faq = $shared[(int) array_search($this->typeId, $ids, true)];
        $this->assertSame('よくある質問', $faq['label']);
        $this->assertSame("/admin/{$this->typeId}", $faq['href']);
    }

    /** SPEC §2-6 — 作成直後に投稿 CRUD が使える */
    public function test_作成直後に投稿crudが使える(): void
    {
        $user = $this->admin();
        $this->createContentType($user);
        $this->rebootApplication();

        $this->actingAs($user)->get("/admin/{$this->typeId}")->assertOk();
        $this->actingAs($user)->get("/admin/{$this->typeId}/create")->assertOk();

        $this->actingAs($user)->post("/admin/{$this->typeId}", [
            'title' => '営業時間は？',
            'answer' => '<p>10:00-19:00 です</p>',
            'status' => 'draft',
        ])->assertRedirect("/admin/{$this->typeId}");

        $model = app(ContentTypeResolver::class)->newModel($this->typeId);
        $created = $model->newQuery()->first();

        $this->assertNotNull($created);
        $this->assertSame('営業時間は？', $created->getAttribute('title'));
        // slug は title から自動生成される（既存 news / works と同じ規約）
        $this->assertNotEmpty((string) $created->getAttribute('slug'));

        $id = (int) $created->getKey();

        $this->actingAs($user)->get("/admin/{$this->typeId}/{$id}/edit")->assertOk();

        $this->actingAs($user)->put("/admin/{$this->typeId}/{$id}", [
            'title' => '営業時間について',
            'answer' => '<p>10:00-19:00</p>',
            'status' => 'published',
        ])->assertRedirect("/admin/{$this->typeId}");

        $this->assertSame('営業時間について', (string) $model->newQuery()->find($id)?->getAttribute('title'));

        // 削除は破壊的操作なので確認を通る（既存 CRUD と同じ ConfirmationGate）
        $this->actingAs($user)->delete("/admin/{$this->typeId}/{$id}")->assertRedirect();
        $token = $this->flashedConfirmationToken();
        $this->assertNotNull($token, '削除で確認トークンが返っていない');
        $this->assertSame(1, $model->newQuery()->count(), '確認前に削除されている');

        $this->actingAs($user)
            ->delete("/admin/{$this->typeId}/{$id}", ['confirmation_token' => $token])
            ->assertRedirect("/admin/{$this->typeId}");

        $this->assertSame(0, $model->newQuery()->count());
    }

    /** SPEC §2-20 — 同じ確認トークンは 2 回使えない */
    public function test_確認トークンの再利用を拒否する(): void
    {
        $user = $this->admin();
        $payload = $this->payload();

        $this->actingAs($user)->post('/admin/content-types', $payload)->assertRedirect();
        $token = $this->flashedConfirmationToken();
        $this->assertNotNull($token);

        $this->actingAs($user)
            ->post('/admin/content-types', [...$payload, 'confirmation_token' => $token])
            ->assertRedirect('/admin/content-types');

        // 同じトークンで 2 回目 = 別 ID にしても通らない（トークンは入力に束縛されている）
        $this->actingAs($user)
            ->from('/admin/content-types/create')
            ->post('/admin/content-types', [
                ...$this->payload(['id' => $this->typeId.'x', 'label' => 'FAQ2']),
                'confirmation_token' => $token,
            ])
            ->assertRedirect('/admin/content-types/create');

        $this->assertFileDoesNotExist($this->schemaPath.'/'.$this->typeId.'x.json', '使用済みトークンで作成されています');
        $this->assertFalse(DbSchema::hasTable($this->typeId.'x'));
    }

    /** トークンは入力内容へ束縛される（別の内容へ使い回せない） */
    public function test_確認トークンは入力内容に束縛される(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post('/admin/content-types', $this->payload())->assertRedirect();
        $token = $this->flashedConfirmationToken();
        $this->assertNotNull($token);

        // 同じ ID でもフィールド構成を変えたら適用されない
        $tampered = $this->payload([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => '質問', 'choices' => []],
                ['key' => 'secret', 'type' => 'text', 'label' => '追加', 'choices' => []],
            ],
            'list' => ['title'],
            'search' => [],
        ]);

        $this->actingAs($user)
            ->post('/admin/content-types', [...$tampered, 'confirmation_token' => $token])
            ->assertRedirect();

        $this->assertFileDoesNotExist($this->schemaPath.'/'.$this->typeId.'.json', '内容を差し替えた要求が適用されています');
        $this->assertFalse(DbSchema::hasTable($this->typeId));
    }

    /** 同じ内容を確認 → 適用したときはトークンが一致する（回帰: ULID を指紋に混ぜない） */
    public function test_同じ内容なら確認から適用へトークンが通る(): void
    {
        $user = $this->admin();
        $payload = $this->payload();

        $this->actingAs($user)->post('/admin/content-types', $payload)->assertRedirect();
        $token = $this->flashedConfirmationToken();

        $this->actingAs($user)
            ->post('/admin/content-types', [...$payload, 'confirmation_token' => (string) $token])
            ->assertRedirect('/admin/content-types')
            ->assertSessionHas('success');

        $this->assertTrue(DbSchema::hasTable($this->typeId));
    }

    /** SPEC §2-19 — 適用途中で失敗したら schema・table・registry の残骸が残らない */
    public function test_適用途中の失敗で残骸が残らない(): void
    {
        $user = $this->admin();

        // **決定的な故障注入**: schema の公開先パスをディレクトリで塞ぐ。
        // `is_file()` は false なので事前検査は通り、モデル生成 → migration →
        // テーブル作成まで進んだあと、最後の schema 公開だけが必ず失敗する
        // （権限による注入はコンテナの root では効かないため使わない）。
        $this->occupySchemaPath();

        $payload = $this->payload();
        $this->actingAs($user)->post('/admin/content-types', $payload)->assertRedirect();
        $token = $this->flashedConfirmationToken();
        $this->assertNotNull($token);

        $this->actingAs($user)
            ->from('/admin/content-types/create')
            ->post('/admin/content-types', [...$payload, 'confirmation_token' => $token])
            ->assertRedirect('/admin/content-types/create')
            ->assertSessionHasErrors('id');

        // 注入した障害物を先に取り除いてから、残骸の有無だけを見る
        $this->releaseSchemaPath();

        // 半端な状態が残っていない
        $this->assertFileDoesNotExist($this->schemaPath.'/'.$this->typeId.'.json', 'schema だけ残っています');
        $this->assertFalse(DbSchema::hasTable($this->typeId), 'テーブルだけ残っています');
        $this->assertFalse(app(SchemaRegistry::class)->has($this->typeId), 'registry に残っています');
        $this->assertSame(
            0,
            DB::table('migrations')->where('migration', 'like', '%create_'.$this->typeId.'_table')->count(),
            'migration の適用記録が残っています',
        );
    }

    /** 失敗のメッセージにサーバーの絶対パスを出さない */
    public function test_失敗メッセージに絶対パスを出さない(): void
    {
        $user = $this->admin();
        $this->occupySchemaPath();

        $payload = $this->payload();
        $this->actingAs($user)->post('/admin/content-types', $payload)->assertRedirect();

        $this->actingAs($user)
            ->from('/admin/content-types/create')
            ->post('/admin/content-types', [
                ...$payload, 'confirmation_token' => (string) $this->flashedConfirmationToken(),
            ])
            ->assertRedirect('/admin/content-types/create');

        $bag = session('errors');
        $messages = $bag instanceof ViewErrorBag
            ? $bag->getBag('default')->all()
            : Arr::flatten((array) $bag);

        $this->assertNotEmpty($messages, 'エラーメッセージが返っていません');

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(base_path(), (string) $message, '絶対パスが画面へ出ています');
            $this->assertStringNotContainsString(sys_get_temp_dir(), (string) $message);
        }

        $this->releaseSchemaPath();
    }

    /** 失敗後に、原因を直せば同じ内容でやり直せる（再実行可能） */
    public function test_失敗後に同じ内容で再実行できる(): void
    {
        $user = $this->admin();
        $this->occupySchemaPath();

        $payload = $this->payload();
        $this->actingAs($user)->post('/admin/content-types', $payload)->assertRedirect();
        $this->actingAs($user)->post('/admin/content-types', [
            ...$payload, 'confirmation_token' => (string) $this->flashedConfirmationToken(),
        ])->assertRedirect();

        // 失敗した側は **自分が作ったものだけ**を消して、空のモジュールも残さない
        $this->assertDirectoryDoesNotExist(
            $this->modulesPath.'/'.$this->typeStudly,
            '失敗したのにモジュールのディレクトリが残っています',
        );

        // 原因を取り除く
        $this->releaseSchemaPath();
        $this->assertDirectoryDoesNotExist($this->schemaPath.'/'.$this->typeId.'.json');

        $this->createContentType($user);

        $this->assertTrue(DbSchema::hasTable($this->typeId));
        $this->assertTrue(app(SchemaRegistry::class)->has($this->typeId));
    }

    /** SPEC §2-27 — 同じ ID の 2 回目は計画段階で拒否される（二重生成しない） */
    public function test_同じidの再送で二重生成されない(): void
    {
        $user = $this->admin();
        $this->createContentType($user);

        // 2 本目（同じ ID）は計画段階で落ちる
        $this->actingAs($user)
            ->from('/admin/content-types/create')
            ->post('/admin/content-types', $this->payload())
            ->assertRedirect('/admin/content-types/create')
            ->assertSessionHasErrors('id');

        $this->assertCount(
            1,
            glob($this->modulesPath.'/'.$this->typeStudly.'/database/migrations/*_create_'.$this->typeId.'_table.php') ?: [],
            'migration が二重に作られています',
        );
    }

    /** ロックを他プロセスが握っている間は作成に入らない（rollback の巻き添えを防ぐ） */
    public function test_ロック保持中は作成を開始しない(): void
    {
        $user = $this->admin();
        $payload = $this->payload();

        $this->actingAs($user)->post('/admin/content-types', $payload)->assertRedirect();
        $token = $this->flashedConfirmationToken();

        $store = app('cache.store')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock('agentcms:content-type:create:'.$this->typeId, 60);
        $this->assertTrue($lock->get(), '前提のロックを取得できませんでした');

        try {
            $this->actingAs($user)
                ->from('/admin/content-types/create')
                ->post('/admin/content-types', [...$payload, 'confirmation_token' => (string) $token])
                ->assertRedirect('/admin/content-types/create')
                ->assertSessionHasErrors('id');
        } finally {
            $lock->release();
        }

        $this->assertFalse(DbSchema::hasTable($this->typeId));
        $this->assertFileDoesNotExist($this->schemaPath.'/'.$this->typeId.'.json');
    }

    /** SPEC §2-28 — 生成物が MySQL 8.0 系 / PHP 8.3 と互換であること */
    public function test_生成物がphp83とmysql8互換である(): void
    {
        $this->createContentType($this->admin(), [
            'label' => 'カタログ',
            'fields' => $this->allBuilderFieldTypes(),
            'list' => ['title'],
            'search' => ['title'],
        ]);

        $model = $this->modulesPath.'/'.$this->typeStudly.'/Models/'.$this->typeStudly.'.php';
        $migration = (glob($this->modulesPath.'/'.$this->typeStudly.'/database/migrations/*.php') ?: [])[0] ?? null;

        $this->assertNotNull($migration);

        foreach ([$model, $migration] as $file) {
            $source = (string) file_get_contents($file);

            // 構文として妥当（生成器が壊れた PHP を書いていない）
            $this->assertTrue(
                Process::run(['php', '-l', $file])->successful(),
                "生成された PHP が構文エラーです: {$file}",
            );
            $this->assertStringStartsWith("<?php\n\ndeclare(strict_types=1);", $source);
            // 生成物であることが読み取れる
            $this->assertStringContainsString(ContentTypeGenerator::MARKER, $source);
            // 生 SQL / MySQL 固有構文を書かない（Blueprint だけで表現する）
            $this->assertStringNotContainsString('DB::statement', $source);
            $this->assertStringNotContainsString('ENGINE=', $source);
            // PHP 8.4 以降でしか通らない構文を使っていない
            $this->assertStringNotContainsString('public(set)', $source);
        }

        // 生成 migration は Blueprint の可搬な API しか使わない
        $migrationSource = (string) file_get_contents($migration);
        $this->assertStringContainsString("Schema::create('{$this->typeId}'", $migrationSource);
        $this->assertStringContainsString("Schema::dropIfExists('{$this->typeId}')", $migrationSource);

        // すべての Builder 型がカラムとして作られている
        $columns = DbSchema::getColumnListing($this->typeId);
        foreach (app(SchemaRegistry::class)->get($this->typeId)->columnFields() as $field) {
            $this->assertContains($field->key, $columns, "{$field->type} のカラムが無い");
        }
    }

    /** 生成 migration は migrate:fresh でも通る（隔離テスト DB で確認） */
    public function test_生成migrationがmigrate_freshで再構築できる(): void
    {
        $this->createContentType($this->admin());

        // migration の探索パスは boot 時に登録される。作成直後の再構築は
        // 「次の CLI 実行」と同じ条件（= アプリを作り直してから）で確かめる
        $this->rebootApplication();

        $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true])->run());

        $this->assertTrue(DbSchema::hasTable($this->typeId), 'migrate:fresh で再作成されていない');
        $this->assertTrue(DbSchema::hasTable('news'), '既存テーブルが再作成されていない');
    }

    /** 生成物は Pint（Laravel preset）に適合する */
    public function test_生成物がpintの整形規則に適合する(): void
    {
        $this->createContentType($this->admin());

        $result = Process::path(base_path())
            ->run(['./vendor/bin/pint', '--test', $this->modulesPath.'/'.$this->typeStudly]);

        $this->assertTrue($result->successful(), "生成物が Pint に適合しません:\n".$result->output());
    }

    /**
     * schema の公開先パスを**ディレクトリで塞ぐ**（決定的な故障注入）。
     *
     * `link()` は対象が存在すれば必ず失敗するので、テーブル作成まで進んだあとの
     * 「最後の 1 手だけ失敗する」状況を、権限や競合に頼らず再現できる。
     */
    private function occupySchemaPath(): void
    {
        mkdir($this->schemaPath.'/'.$this->typeId.'.json', 0o755, recursive: true);
    }

    private function releaseSchemaPath(): void
    {
        @rmdir($this->schemaPath.'/'.$this->typeId.'.json');
    }

    /**
     * Builder が選ばせる **すべての** field type を含む定義。
     *
     * @return list<array<string, mixed>>
     */
    private function allBuilderFieldTypes(): array
    {
        $fields = [];

        foreach (BuilderFieldType::cases() as $type) {
            $fields[] = [
                'key' => $type === BuilderFieldType::Text ? 'title' : 'f_'.$type->value,
                'type' => $type->value,
                'label' => $type->label(),
                'choices' => $type->needsChoices() ? ['alpha', 'beta'] : [],
            ];
        }

        return $fields;
    }
}
