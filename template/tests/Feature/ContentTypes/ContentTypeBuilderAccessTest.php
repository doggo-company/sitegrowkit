<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\ContentTypes\BuilderFieldType;
use AgentCms\Core\ContentTypes\ContentTypeBuilderPresets;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Phase 9.4 / STAGE #17A — 投稿タイプ管理画面の入口（SPEC §2-1〜3・26）。
 *
 * ここで固定するのは **誰が入れるか**と **何が読み取り専用か**。
 * 生成そのものの検証は `ContentTypeBuilderApplyTest` の責務。
 */
final class ContentTypeBuilderAccessTest extends BuilderTestCase
{
    /** SPEC §2-1 */
    public function test_未認証では投稿タイプの全routeが拒否される(): void
    {
        $this->get('/admin/content-types')->assertRedirect('/login');
        $this->get('/admin/content-types/create')->assertRedirect('/login');
        $this->post('/admin/content-types', $this->payload())->assertRedirect('/login');

        // 拒否されたリクエストでファイルも DB も変わらない
        $this->assertNoArtifacts();
    }

    public function test_未verifiedでは投稿タイプの全routeが拒否される(): void
    {
        $user = User::factory()->unverified()->create();
        $notice = route('verification.notice', absolute: false);

        $this->actingAs($user)->get('/admin/content-types')->assertRedirect($notice);
        $this->actingAs($user)->get('/admin/content-types/create')->assertRedirect($notice);
        $this->actingAs($user)->post('/admin/content-types', $this->payload())->assertRedirect($notice);

        $this->assertNoArtifacts();
    }

    /** SPEC §2-2 */
    public function test_認証済み管理者は一覧と作成画面を開ける(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/admin/content-types')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/content-types/Index'));

        $this->actingAs($user)->get('/admin/content-types/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/content-types/Create')
                ->has('options.fieldTypes')
                ->has('options.defaultFields')
                // 一覧列 / 検索対象の標準提案も **サーバーが authoritative**。
                // 画面側が空で始めると「検索が効かない投稿タイプ」が既定になる
                ->where('options.defaultList', ['title', 'status', 'published_at'])
                ->where('options.defaultSearch', ['title', 'body']));
    }

    /** 標準提案の一覧列 / 検索対象が、標準フィールドの中に実在する */
    public function test_標準提案の一覧列と検索対象が標準フィールドと整合する(): void
    {
        $keys = array_column(ContentTypeBuilderPresets::defaultFields(), 'key');

        foreach ([...ContentTypeBuilderPresets::defaultList(), ...ContentTypeBuilderPresets::defaultSearch()] as $key) {
            $this->assertContains($key, $keys, "標準提案 [{$key}] に対応するフィールドがありません");
        }

        // 検索対象は searchable な型だけ（LIKE を張れない列を既定にしない）
        $byKey = array_column(ContentTypeBuilderPresets::defaultFields(), null, 'key');

        foreach (ContentTypeBuilderPresets::defaultSearch() as $key) {
            $type = BuilderFieldType::from((string) $byKey[$key]['type']);
            $this->assertTrue($type->supportsSearchable(), "{$key} は検索対象にできません");
        }
    }

    /** SPEC §2-3 — code 管理の型は読み取り専用として出る */
    public function test_code管理のtypeが読み取り専用として一覧に出る(): void
    {
        $this->actingAs($this->admin())->get('/admin/content-types')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                /** @var list<array<string, mixed>> $types */
                $types = $page->toArray()['props']['types'];
                $byId = array_column($types, null, 'id');

                foreach (['news', 'works', 'product'] as $id) {
                    $this->assertArrayHasKey($id, $byId, "{$id} が一覧に無い");
                    $this->assertSame('code', $byId[$id]['managedBy']);
                    $this->assertFalse($byId[$id]['editable'], "{$id} が編集可能になっている");
                }

                // 投稿一覧の導線は route を持つ型にだけ出る（product は管理 route を持たない）
                $this->assertSame('/admin/news', $byId['news']['adminUrl']);
                $this->assertNull($byId['product']['adminUrl']);
            });
    }

    /**
     * 物理削除の route は**置かない**（Phase 9.7 / STAGE 20C）。
     *
     * 編集・無効化・復元・並べ替えは 20C で入ったが、
     * 「削除」は無効化であってテーブルも投稿も消さない、という契約を route の形で表す。
     * DELETE 動詞の route が生えたら、それは purge を実装したということである。
     */
    public function test_物理削除のrouteは存在しない(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['admin.content-types.destroy', 'admin.content-types.purge'] as $name) {
            $this->assertNull($routes->getByName($name), "{$name} が存在します（purge は P0-6 の後）");
        }

        foreach ($routes as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.content-types.')) {
                continue;
            }

            $this->assertNotContains('DELETE', $route->methods(), '物理削除の route がある');
        }
    }

    /** SPEC §2-26 — 既存管理画面と同じ middleware（web = CSRF / auth / verified） */
    public function test_既存管理画面と同じmiddlewareの中にある(): void
    {
        $reference = app('router')->getRoutes()->getByName('admin.news.index');
        $this->assertNotNull($reference);

        foreach ([
            'admin.content-types.index',
            'admin.content-types.create',
            'admin.content-types.store',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} が無い");
            // 作りの画面なので、既存の管理画面の門に加えて制作者だけの関所が付く（UX 一周 2026-09-28）
            $this->assertSame([...$reference->gatherMiddleware(), 'site.build'], $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
            $this->assertContains('web', $route->middleware());
        }
    }

    /**
     * SPEC §2-19 の前提 — テストは実プロジェクトを触っていない。
     *
     * `tearDown()` の fingerprint 比較と対で、「隔離できている」ことを明示する。
     */
    public function test_生成先が一時ディレクトリに隔離されている(): void
    {
        $this->assertSame($this->schemaPath, config('agentcms.schema_path'));
        $this->assertSame($this->modulesPath, config('agentcms.modules_path'));
        $this->assertStringStartsWith(sys_get_temp_dir(), $this->schemaPath);
        $this->assertNotSame(
            realpath(base_path('content/schemas')),
            realpath($this->schemaPath),
        );
        $this->assertTrue(File::exists($this->schemaPath.'/news.json'), '既存 schema が複製されていない');
    }
}
