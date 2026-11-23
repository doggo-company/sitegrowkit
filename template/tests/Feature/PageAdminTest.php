<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / #19 — ページの管理画面（SPEC §V.1〜§V.2 / §W / §Y / §Z）。
 *
 * ここで固定するのは **Admin route が Application Service まで到達すること**、
 * 認証・CSRF・認可、lifecycle（作成 → 設定 → プレビュー → 公開 → ゴミ箱 → 復元）、
 * プレビューの安全性。
 *
 * 業務ルール（publish 検証・contract・階層）は `PageDocumentTest` の責務であり重複させない。
 */
final class PageAdminTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<array{string, string, string}> method / uri / name */
    private const ROUTES = [
        ['GET', 'admin/pages', 'admin.pages.index'],
        ['GET', 'admin/pages/create', 'admin.pages.create'],
        ['GET', 'admin/pages/slug-preview', 'admin.pages.slug-preview'],
        ['POST', 'admin/pages', 'admin.pages.store'],
        ['GET', 'admin/pages/{id}/edit', 'admin.pages.edit'],
        ['PATCH', 'admin/pages/{id}', 'admin.pages.update'],
        ['GET', 'admin/pages/{id}/editor', 'admin.pages.editor'],
        ['GET', 'admin/pages/{id}/preview', 'admin.pages.preview'],
        ['POST', 'admin/pages/{id}/duplicate', 'admin.pages.duplicate'],
        ['POST', 'admin/pages/{id}/publish', 'admin.pages.publish'],
        ['POST', 'admin/pages/{id}/unpublish', 'admin.pages.unpublish'],
        ['POST', 'admin/pages/{id}/trash', 'admin.pages.trash'],
        ['POST', 'admin/pages/{id}/restore', 'admin.pages.restore'],
    ];

    // ─────────────────────────────────────────────────────────────
    // route 契約（ちょうど 10 本）
    //
    // Phase 9.6 / P0-C2 で Visual Page Editor の**画面**を 1 本足した。
    // 足したのは GET だけで、document の変更経路は増えていない
    // （`test_no_extra_page_admin_routes_are_exposed` が引き続き固定する）。
    // ─────────────────────────────────────────────────────────────

    public function test_page_admin_exposes_exactly_the_declared_routes(): void
    {
        $actual = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.pages.'))
            ->map(fn ($route) => [
                implode('|', array_diff($route->methods(), ['HEAD'])),
                $route->uri(),
                (string) $route->getName(),
            ])
            ->sortBy(fn (array $row) => $row[2])
            ->values()
            ->all();

        $expected = collect(self::ROUTES)->sortBy(fn (array $row) => $row[2])->values()->all();

        // 本数は書かない。**この一覧と一致すること**が言いたいことである
        $this->assertSame($expected, $actual);
    }

    public function test_page_admin_routes_share_the_existing_admin_middleware(): void
    {
        $reference = app('router')->getRoutes()->getByName('admin.news.index');
        $this->assertNotNull($reference);

        foreach (self::ROUTES as [, , $name]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} が無い");
            $this->assertSame(
                $reference->gatherMiddleware(),
                $route->gatherMiddleware(),
                "{$name} の middleware が既存管理画面と違う",
            );
            // web group が CSRF を含む。個別の except 追加はしない
            $this->assertContains('web', $route->middleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
        }
    }

    /** 物理削除・document 直編集・共有プレビューの入口を増やしていない */
    public function test_no_extra_page_admin_routes_are_exposed(): void
    {
        $admin = $this->admin();
        $page = $this->makePage();

        foreach ([
            ['delete', "/admin/pages/{$page->id}"],
            ['post', "/admin/pages/{$page->id}/purge"],
            ['patch', "/admin/pages/{$page->id}/document"],
        ] as [$method, $uri]) {
            $this->assertContains(
                $this->actingAs($admin)->{$method}($uri)->status(),
                [404, 405],
                "{$method} {$uri} が存在する",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 認証 / verified
    // ─────────────────────────────────────────────────────────────

    public function test_unauthenticated_requests_are_rejected_on_every_route(): void
    {
        $page = $this->makePage();
        $before = $this->snapshot();

        foreach ($this->requests($page->id) as $label => $call) {
            $call()->assertRedirect('/login');
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_unverified_users_are_rejected_on_every_route(): void
    {
        $page = $this->makePage();
        $user = User::factory()->unverified()->create();
        $notice = route('verification.notice', absolute: false);
        $before = $this->snapshot();

        foreach ($this->requests($page->id, $user) as $label => $call) {
            $call()->assertRedirect($notice);
        }

        $this->assertSame($before, $this->snapshot());
    }

    // ─────────────────────────────────────────────────────────────
    // read（一覧 / 作成 / 編集）
    // ─────────────────────────────────────────────────────────────

    public function test_index_lists_pages_and_switches_between_normal_and_trash(): void
    {
        $admin = $this->admin();
        $live = $this->makePage('live-page', '公開候補');
        $trashed = $this->makePage('trashed-page', 'ゴミ箱行き');
        $trashed->forceFill(['status' => PageStatus::Trashed->value, 'trashed_at' => now()])->save();

        $this->actingAs($admin)->get('/admin/pages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/pages/Index')
                ->where('trashed', false),
            );

        $slugs = array_column((array) $this->actingAs($admin)->get('/admin/pages')->inertiaProps('items'), 'slug');
        $this->assertContains($live->slug, $slugs);
        $this->assertNotContains($trashed->slug, $slugs, '通常一覧にゴミ箱が混ざっている');

        $trashSlugs = array_column(
            (array) $this->actingAs($admin)->get('/admin/pages?trashed=1')->inertiaProps('items'),
            'slug',
        );
        $this->assertContains($trashed->slug, $trashSlugs);
        $this->assertNotContains($live->slug, $trashSlugs);
    }

    public function test_create_and_edit_screens_render(): void
    {
        $admin = $this->admin();
        $page = $this->makePage();

        $this->actingAs($admin)->get('/admin/pages/create')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/pages/Create'));

        $this->actingAs($admin)->get("/admin/pages/{$page->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('admin/pages/Edit')
                ->where('page.slug', $page->slug)
                // 管理方式は read-only の表示情報として渡すだけ
                ->where('page.managedBy', Page::MANAGED_BY_DOCUMENT)
                ->where('publicUrl', null),
            );
    }

    // ─────────────────────────────────────────────────────────────
    // store / update
    // ─────────────────────────────────────────────────────────────

    public function test_store_creates_a_document_draft_with_an_empty_version(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/pages', ['title' => '会社案内', 'slug' => 'company'])
            ->assertRedirect();

        $page = Page::query()->where('slug', 'company')->sole();

        $this->assertSame(Page::MANAGED_BY_DOCUMENT, $page->managed_by);
        $this->assertSame(PageStatus::Draft->value, $page->status->value);
        $this->assertSame(1, PageVersion::query()->where('page_id', $page->id)->count());
        $this->assertNull($page->published_version_id);
    }

    /** transport 専用キー（`_token` / `_method`）は Application Service へ混ぜない */
    public function test_store_strips_only_transport_keys(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/pages', [
                'title' => 'トークン付き',
                'slug' => 'with-token',
                '_token' => 'csrf-value',
                '_method' => 'post',
            ])
            ->assertRedirect();

        $this->assertNotNull(Page::query()->where('slug', 'with-token')->first());
    }

    /**
     * controller が禁止キーを**黙って捨てない**（Phase 9.3B / #17 MB-3 / MB-5）。
     *
     * `only(['title','slug'])` で絞ると Admin だけ「送ったのに無視された」状態になる。
     * 受理可否は `CreatePage` が決める契約なので、Admin からも同じ validation error になる。
     */
    public function test_store_forwards_forbidden_and_unknown_keys_to_the_application_service(): void
    {
        $admin = $this->admin();

        foreach ([
            'managedBy' => ['managedBy' => Page::MANAGED_BY_BLADE],
            'managed_by' => ['managed_by' => Page::MANAGED_BY_BLADE],
            'status' => ['status' => 'published'],
            'whatever' => ['whatever' => 1],
        ] as $key => $extra) {
            $this->actingAs($admin)
                ->from('/admin/pages/create')
                ->post('/admin/pages', ['title' => 'ページ', 'slug' => "rejected-{$key}"] + $extra)
                ->assertSessionHasErrors($key);
        }

        $this->assertSame(0, Page::query()->where('slug', 'like', 'rejected-%')->count());
    }

    public function test_update_saves_metadata_and_seo(): void
    {
        $page = $this->makePage();

        $this->actingAs($this->admin())
            ->patch("/admin/pages/{$page->id}", [
                'title' => '新しいタイトル',
                'slug' => 'renamed',
                'seo' => ['description' => '説明文', 'noindex' => true],
            ])
            ->assertRedirect("/admin/pages/{$page->id}/edit");

        $fresh = $page->fresh();

        $this->assertSame('新しいタイトル', (string) $fresh?->title);
        $this->assertSame('renamed', (string) $fresh?->slug);
        $this->assertSame('説明文', $fresh?->seo['description'] ?? null);
        $this->assertTrue($fresh?->seo['noindex'] ?? false);
    }

    /**
     * update が運ぶのは `title` / `slug` / `seo` だけ。
     *
     * store（`CreatePage` が未知キーを拒否する）と違い、`UpdatePageMetadata` は
     * 検証済みキーだけを保存するので、余計なキーを渡しても黙って無視されるだけ。
     * だからこそ **controller が何を更新するのかを明示**し、境界をここで固定する。
     */
    public function test_update_only_carries_title_slug_and_seo(): void
    {
        $page = $this->makePage('boundary', '境界');

        $this->actingAs($this->admin())
            ->patch("/admin/pages/{$page->id}", [
                'title' => '更新後',
                'slug' => 'boundary-updated',
                'seo' => ['description' => '説明', 'noindex' => true],
                // 以下はどれも Application Service へ渡らない
                'managedBy' => Page::MANAGED_BY_BLADE,
                'status' => PageStatus::Published->value,
                'publishedAt' => '2026-01-01T00:00:00+09:00',
                'whatever' => 'x',
            ])
            ->assertRedirect("/admin/pages/{$page->id}/edit");

        $fresh = $page->fresh();

        // 更新されるのは metadata / SEO だけ
        $this->assertSame('更新後', (string) $fresh?->title);
        $this->assertSame('boundary-updated', (string) $fresh?->slug);
        $this->assertSame('説明', $fresh?->seo['description'] ?? null);

        // lifecycle は 1 つも動かない
        $this->assertSame(Page::MANAGED_BY_DOCUMENT, $fresh?->managed_by);
        $this->assertSame(PageStatus::Draft->value, $fresh?->status->value);
        $this->assertNull($fresh?->published_at);
        $this->assertNull($fresh?->published_version_id);

        // controller が渡すキーを明示している（transport をそのまま素通ししていない）
        $code = (string) File::get(base_path('app/Http/Controllers/Admin/PageAdminController.php'));
        $this->assertStringContainsString("only(['title', 'slug', 'seo'])", $code);
    }

    /** trash 中も slug は予約される。**なぜ使えないか**を具体的に伝える */
    public function test_creating_a_page_with_a_trashed_slug_explains_why(): void
    {
        $page = $this->makePage('reserved', '予約済み');
        $page->forceFill(['status' => PageStatus::Trashed->value, 'trashed_at' => now()])->save();

        $this->actingAs($this->admin())
            ->from('/admin/pages/create')
            ->post('/admin/pages', ['title' => '別のページ', 'slug' => 'reserved'])
            ->assertSessionHasErrors('slug');

        $errors = session('errors')?->get('slug') ?? [];
        $this->assertStringContainsString('ゴミ箱', implode(' ', $errors));
    }

    // ─────────────────────────────────────────────────────────────
    // publish / trash / restore（F-P2 と lifecycle）
    // ─────────────────────────────────────────────────────────────

    /** F-P2: Admin の公開は **1 回の POST** で完了する（確認を重ねない） */
    public function test_admin_publish_succeeds_in_a_single_post(): void
    {
        $page = $this->makePage();
        $this->addSection($page);

        $this->actingAs($this->admin())
            ->post("/admin/pages/{$page->id}/publish")
            ->assertRedirect("/admin/pages/{$page->id}/edit");

        $this->assertSame(PageStatus::Published->value, $page->fresh()?->status->value);
        $this->assertArrayNotHasKey('confirmation', $this->inertiaFlash());
    }

    /** ブロック編集の画面から公開すると、同じ画面に留まる（触り心地 2026-11-05） */
    public function test_publishing_from_the_editor_stays_on_the_editor(): void
    {
        $page = $this->makePage();
        $this->addSection($page);

        $this->actingAs($this->admin())
            ->post("/admin/pages/{$page->id}/publish", ['from' => 'editor'])
            ->assertRedirect("/admin/pages/{$page->id}/editor");

        $this->assertSame(PageStatus::Published->value, $page->fresh()?->status->value);
    }

    /** 空のページは公開できない（Phase 9.3B / #18）。状態は 1 つも変わらない */
    public function test_publishing_an_empty_page_fails_without_changing_state(): void
    {
        $page = $this->makePage();
        $before = $this->snapshot();

        $this->actingAs($this->admin())
            ->from("/admin/pages/{$page->id}/edit")
            ->post("/admin/pages/{$page->id}/publish")
            ->assertSessionHasErrors('root');

        /*
         * 状態は 1 つも変わらない。ただし **試みたことは残る**（Correction 2-5）ので、
         * 監査だけがちょうど 1 行増える。
         */
        $this->assertSame(
            [...$before, 'audits' => $before['audits'] + 1],
            $this->snapshot(),
            '公開できない試行でページの状態が変わった',
        );
        $this->assertSame(PageStatus::Draft->value, $page->fresh()?->status->value);

        // 増えた 1 行は「拒否」であって公開ではない
        $this->assertSame(
            'validation_error',
            DB::table('audit_logs')->where('operation', 'page.publish')->orderByDesc('id')->value('result'),
        );
    }

    public function test_trash_requires_confirmation_then_removes_the_page_from_the_public_site(): void
    {
        $admin = $this->admin();
        $page = $this->makePage('to-trash', 'ゴミ箱へ');
        $this->addSection($page);
        app(PublishPage::class)->execute($page->id, OperationContext::admin($this->operatorId(), 'fixture'));

        $this->get('/to-trash')->assertOk();

        // 1 回目は確認内容だけ返り、状態は変わらない
        $this->actingAs($admin)
            ->from("/admin/pages/{$page->id}/edit")
            ->post("/admin/pages/{$page->id}/trash")
            ->assertRedirect("/admin/pages/{$page->id}/edit");

        $flash = $this->inertiaFlash();
        $this->assertArrayHasKey('confirmation', $flash);
        $this->assertSame(PageStatus::Published->value, $page->fresh()?->status->value);

        // 2 回目（token 付き）で実際に移動する
        $this->actingAs($admin)
            ->post("/admin/pages/{$page->id}/trash", [
                'confirmation_token' => $flash['confirmation']['token'],
            ])
            ->assertRedirect('/admin/pages');

        $this->assertSame(PageStatus::Trashed->value, $page->fresh()?->status->value);
        $this->get('/to-trash')->assertNotFound();
    }

    /** 復元は **下書きへ戻す**。勝手に再公開しない */
    public function test_restore_returns_the_page_to_draft_and_says_so(): void
    {
        $page = $this->makePage('restore-me', '復元対象');
        $this->addSection($page);
        app(PublishPage::class)->execute($page->id, OperationContext::admin($this->operatorId(), 'fixture'));
        $page->forceFill(['status' => PageStatus::Trashed->value, 'trashed_at' => now()])->save();

        $this->actingAs($this->admin())
            ->post("/admin/pages/{$page->id}/restore")
            ->assertRedirect("/admin/pages/{$page->id}/edit");

        // 成功は既存の toast（app.ts が拾う Inertia flash）で伝える
        $this->assertSame(
            ['type' => 'success', 'message' => '下書きに戻りました。公開するには「公開する」を押してください'],
            $this->inertiaFlash()['toast'] ?? null,
        );

        $this->assertSame(PageStatus::Draft->value, $page->fresh()?->status->value);
        $this->get('/restore-me')->assertNotFound();
    }

    /** ボタン名と toast を一致させる（曖昧な「送信しました」を出さない） */
    public function test_every_mutation_reports_success_through_the_shared_toast(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/pages', ['title' => '作成', 'slug' => 'toast-create']);
        $this->assertSame('ページを作成しました', $this->inertiaFlash()['toast']['message'] ?? null);

        $page = Page::query()->where('slug', 'toast-create')->sole();

        $this->actingAs($admin)->patch("/admin/pages/{$page->id}", ['title' => '保存']);
        $this->assertSame('ページ設定を保存しました', $this->inertiaFlash()['toast']['message'] ?? null);

        $this->addSection($page);
        $this->actingAs($admin)->post("/admin/pages/{$page->id}/publish");
        // トップページが未設定のうちは、公開の知らせに「トップにする」への案内が続く（迷った 4 件 #1・2026-11-23）
        $this->assertStringStartsWith('ページを公開しました', (string) ($this->inertiaFlash()['toast']['message'] ?? ''));

        $first = $this->actingAs($admin)->post("/admin/pages/{$page->id}/trash");
        $this->assertNotNull($first);
        $token = $this->inertiaFlash()['confirmation']['token'];
        $this->actingAs($admin)->post("/admin/pages/{$page->id}/trash", ['confirmation_token' => $token]);
        $this->assertSame('ページをゴミ箱へ移しました', $this->inertiaFlash()['toast']['message'] ?? null);
    }

    /**
     * 公開の失敗理由が **画面に出る**（レビュー指摘）。
     *
     * 公開は `router.post` で送るため `form.errors` には入らない。
     * Edit 画面が `usePage().props.errors.root` を拾って表示することを固定する。
     */
    public function test_publish_failure_is_shown_on_the_edit_screen(): void
    {
        $page = $this->makePage('shows-error', 'エラー表示');

        $this->actingAs($this->admin())
            ->from("/admin/pages/{$page->id}/edit")
            ->post("/admin/pages/{$page->id}/publish")
            ->assertSessionHasErrors('root');

        $messages = session('errors')?->get('root') ?? [];
        $this->assertStringContainsString('少なくとも 1 つブロック', implode(' ', $messages));

        // 画面側が root を拾って出す（form.errors ではなく page props から）
        $view = (string) File::get(base_path('resources/js/pages/admin/pages/Edit.vue'));
        $this->assertStringContainsString('pageErrors.value.root', $view);
        $this->assertStringContainsString('data-testid="page-publish-error"', $view);
    }

    // ─────────────────────────────────────────────────────────────
    // preview（§Y）
    // ─────────────────────────────────────────────────────────────

    public function test_preview_shows_the_draft_while_the_public_url_shows_the_published_version(): void
    {
        $admin = $this->admin();
        $page = $this->makePage('preview-page', 'プレビュー');
        $section = $this->addSection($page);
        $this->insertHeading($page, $section, '公開時の見出し');
        app(PublishPage::class)->execute($page->id, OperationContext::admin($this->operatorId(), 'fixture'));

        // draft だけを書き換える
        $this->insertHeading($page, $section, '下書きだけの見出し');

        $preview = $this->actingAs($admin)->get("/admin/pages/{$page->id}/preview");

        $preview->assertOk()
            ->assertSee('下書きのプレビューです')
            ->assertSee('下書きだけの見出し')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $preview->headers->get('Cache-Control'));

        // 公開 URL は published のまま
        $public = $this->get('/preview-page');
        $public->assertOk()->assertSee('公開時の見出し');
        $public->assertDontSee('下書きだけの見出し');
        $public->assertDontSee('下書きのプレビューです');
    }

    public function test_preview_requires_authentication_and_writes_nothing(): void
    {
        $page = $this->makePage('quiet-preview', '静かなプレビュー');
        $this->addSection($page);

        $this->get("/admin/pages/{$page->id}/preview")->assertRedirect('/login');

        // 利用者の作成は計測前に済ませる（プレビュー自体の書き込みだけを見る）
        $admin = $this->admin();
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            $sql = strtolower(trim($query->sql));

            foreach (['insert ', 'update ', 'delete '] as $write) {
                if (str_starts_with($sql, $write)) {
                    $writes[] = $sql;
                }
            }
        });

        $before = PageVersion::query()->count();
        $this->actingAs($admin)->get("/admin/pages/{$page->id}/preview")->assertOk();

        $this->assertSame($before, PageVersion::query()->count(), 'プレビューで version が増えた');
        $this->assertSame([], $writes, 'プレビューで書き込みが発生した: '.implode(' / ', $writes));
    }

    /** 公開側と同じく Vue runtime を要求しない */
    public function test_preview_does_not_require_a_vue_runtime(): void
    {
        $page = $this->makePage('no-vue', 'Vue なし');
        $section = $this->addSection($page);
        $this->insertHeading($page, $section, '見出し');

        $html = (string) $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/preview")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('resources/js/app.ts', $html);
        $this->assertStringNotContainsString('id="app"', $html);
    }

    /** blade 管理ページは表示できるが、document のプレビュー対象にはならない */
    public function test_blade_managed_pages_are_visible_but_have_no_document_preview(): void
    {
        $page = Page::query()->create([
            'title' => 'Blade 管理',
            'slug' => 'blade-page',
            'status' => PageStatus::Draft->value,
            'managed_by' => Page::MANAGED_BY_BLADE,
        ]);

        $this->actingAs($this->admin())->get("/admin/pages/{$page->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.managedBy', Page::MANAGED_BY_BLADE));

        $this->actingAs($this->admin())->get("/admin/pages/{$page->id}/preview")->assertNotFound();

        // 画面側も document 専用の action（プレビュー / 公開）を blade ページへ出さない
        $view = (string) File::get(base_path('resources/js/pages/admin/pages/Edit.vue'));
        // document のときだけ出す区画。増えたらここに足す（数だけを直さない）
        $documentOnly = [
            'page-editor-link',    // ブロック編集（Visual Page Editor / P0-C2）
            'page-preview-link',   // 下書きプレビュー
            'page-duplicate-button', // 保存済み本文を別の下書きへ
            'page-publish-button', // 公開する
            'page-ai-guide',       // AI エージェントへの依頼（#21）
            'page-publish-at-input', // 公開日時（予約公開・release R02）
        ];

        foreach ($documentOnly as $testid) {
            $this->assertStringContainsString($testid, $view);
        }

        $this->assertSame(
            count($documentOnly),
            substr_count($view, 'v-if="isDocument && !isTrashed"'),
            'document 専用の区画が blade ページへも出ている',
        );
        // 公開中・予約中だけに出す取り消し操作も document 専用。
        $this->assertStringContainsString('v-if="isDocument && (isScheduled || isLive)"', $view);
        $this->assertStringContainsString('page-unpublish-button', $view);
        // 「編集不可」と metadata 編集フォームが同居する矛盾した文言を残さない
        $this->assertStringNotContainsString('コード管理（編集不可）', $view);
        $this->assertStringContainsString('本文はコードで管理', $view);
    }

    // ─────────────────────────────────────────────────────────────
    // 境界（API を保存経路にしない）
    // ─────────────────────────────────────────────────────────────

    public function test_the_admin_controller_does_not_use_the_api_surface(): void
    {
        $code = (string) File::get(base_path('app/Http/Controllers/Admin/PageAdminController.php'));

        foreach ([
            '#/api/v1#',
            "#route\('api\.v1#",
            '#PageApiController#',
            '#Http::#',
            '#OperationContext::api#',
            '#DB::#',
        ] as $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), "Admin が [{$pattern}] を使っている");
        }

        $this->assertStringContainsString('OperationContext::admin(', $code);

        foreach ([
            'resources/js/pages/admin/pages/Index.vue',
            'resources/js/pages/admin/pages/Create.vue',
            'resources/js/pages/admin/pages/Edit.vue',
        ] as $path) {
            $view = (string) File::get(base_path($path));

            foreach (['/api/v1', 'fetch(', 'axios'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $view, "{$path} が [{$forbidden}] を使っている");
            }
        }
    }

    /**
     * document の編集だけは操作 API を通る（Phase 9.6 / P0-C2）。
     *
     * ページの設定・公開・ゴミ箱は Admin Controller から Application Service を直接呼ぶ
     * （上のテストが固定している）。**document には admin route がそもそも無い**ので、
     * Visual Page Editor は同一オリジンの操作 API を唯一の変更経路として使う。
     * 経路を 2 本にしないための決定であって、他の画面へ広げてよい話ではない。
     */
    public function test_the_visual_editor_uses_the_operation_api_as_its_only_change_path(): void
    {
        $client = (string) File::get(base_path('resources/js/composables/usePageDocument.ts'));

        $this->assertStringContainsString('/api/v1/pages/', $client);

        /*
         * **送る先を固定する。数ではなく。**
         *
         * かつてここは「`fetch(` は 1 箇所」と数で見ていた。守りたいのは
         * 「document を変える道が 2 本にならない」ことで、**口の数ではない** —
         * P0-C6c で版の一覧（読み）と復元（書き）が増えたときに、
         * 数の側が先に嘘になった。
         *
         * **送り方も `fetch` から `adminApi` へ移った**（gateway 一本化）。
         * 守りは形を変えて残す — 見るのは今も「**どこへ投げているか**」で、
         * 探すのは**ファイルに書かれた宛先そのもの**にした。
         * コメントは除く（説明文の中の宛先に当たるため — C7 で一度誤検知した）。
         */
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $client);

        preg_match_all('#`(/[a-z][^`]*)`#', $code, $targets);

        $this->assertNotEmpty($targets[1], '送信口が 1 つも無い');

        foreach ($targets[1] as $target) {
            $this->assertStringStartsWith('/api/v1/pages/', $target, "{$target} は操作 API ではない");
        }

        $this->assertStringNotContainsString('/admin/', $code, 'admin 側へも投げている');

        /*
         * **確認ゲートを通ることは、口の側で固定する。**
         *
         * `X-Confirmation` を組み立てるのは `adminApi` になったので、ここで文字列を
         * 探しても見つからない。**通していることは「確認を渡している」ことで見る。**
         */
        $this->assertStringContainsString('confirmation:', $client, '確認ゲートを通していない');

        $gateway = (string) File::get(base_path('resources/js/lib/adminApi.ts'));

        $this->assertStringContainsString('X-Confirmation', $gateway, '口が確認を運んでいない');

        $editor = (string) File::get(base_path('resources/js/pages/admin/pages/Editor.vue'));

        $this->assertStringNotContainsString('fetch(', $editor, '画面が直接送信している');
    }

    /**
     * **Page の操作面が増えていないこと**を、名前で見る。
     *
     * ここは数（route 12 本・catalog 39 件）で固定していた。数は
     * **足したときに数だけ直して中身を見ない**道を作る。実際この隣の
     * コメントは「29 → 30」と書いたまま 39 を見ていた。
     *
     * AI へ公開する範囲そのものは `McpBoundaryTest` が名前で固定しているので、
     * ここが言うのは「**Page の route はこの一覧である**」だけにする。
     */
    /**
     * **送る口は 1 つだけ**（gateway 一本化）。
     *
     * C5 と C7 で**同じ 4 種類の欠陥が 2 度**出た。XSRF の取り方・応答の unwrap・
     * 確認（409）の扱い・検証の誤り（422）の見せ方を、画面ごとに書いていたためである。
     * **1 つ忘れると「押しても何も起きない」**になる。
     *
     * だから「新しい画面は使うこと」ではなく「**送るのは口の中だけ**」で固定する。
     * 前者は「新しいかどうか」を人が判断する検査で、**機械が判定できない**
     * （今日全廃した「数の pin」と同じ弱さを持つ）。
     *
     * ## 見るのは「送る」側だけである
     *
     * 読み取りの `fetch`（`/admin/...` の一覧・候補・検証結果）は口を通していない。
     * **通す理由が無い**からである — XSRF も確認も 422 も、書き込みの都合だからで、
     * それらは封筒（`{data, meta}`）すら使っていない。口を汎用の fetch にすると、
     * **何のための口か**が薄まる。
     *
     * だから検査は「**`method:` を指定する `fetch`**（= 送る側）は口の中だけ」とする。
     * これも機械が判定できる。
     */
    public function test_the_admin_screens_send_through_one_gateway(): void
    {
        $gateway = 'resources/js/lib/adminApi.ts';
        $offenders = [];

        foreach (File::allFiles(base_path('resources/js')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if ($relative === $gateway || ! in_array($file->getExtension(), ['ts', 'vue'], true)) {
                continue;
            }

            // コメントは見ない（説明文の中の `fetch(` に当たるため）
            $code = (string) preg_replace('#/\*.*?\*/#s', '', (string) $file->getContents());

            /*
             * **`fetch` を呼び、かつ `method` を書いている**ファイルが「送る側」である。
             *
             * 最初は `fetch(...method:` という並びで見ていたが、**短縮記法
             * （`{ method, body }`）を見落として変異が緑のまま通った**。
             * 呼び出しの形に頼らず、**2 つの事実の同居**で見る。
             *
             * 読み取りの 5 か所は `method` を 1 度も書いていない（実測）ので、
             * この形で分けられる。
             */
            if (str_contains($code, 'fetch(') && str_contains($code, 'method')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, '口を通さずに送っている場所があります');

        // 口そのものは在る（検査が空振りしていないこと）
        $this->assertStringContainsString('fetch(', (string) File::get(base_path($gateway)));
    }

    public function test_api_surfaces_match_the_declared_page_operations(): void
    {
        $expected = [
            'api/v1/components',
            'api/v1/pages',
            'api/v1/pages',
            'api/v1/pages/{id}',
            'api/v1/pages/{id}',
            'api/v1/pages/{id}/document',
            'api/v1/pages/{id}/document',
            'api/v1/pages/{id}/duplicate',
            'api/v1/pages/{id}/publish',
            'api/v1/pages/{id}/restore',
            'api/v1/pages/{id}/trash',
            'api/v1/pages/{id}/unpublish',
            'api/v1/pages/{id}/versions',
            'api/v1/pages/{id}/versions/restore',
        ];

        $actual = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/pages')
                || $route->uri() === 'api/v1/components')
            ->map(fn ($route) => $route->uri())
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual, 'Page API の route が変わっている');
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function makePage(string $slug = 'sample-page', string $title = 'サンプル'): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => $title, 'slug' => $slug],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    private function addSection(Page $page): string
    {
        $version = PageVersion::query()->findOrFail($page->refresh()->draft_version_id);
        $root = (string) $version->document['root']['id'];

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $root,
            'component' => ['type' => 'section', 'children' => []],
        ], OperationContext::admin($this->operatorId(), 'fixture'));

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        $children = $result->data['document']['root']['children'];

        return (string) end($children)['id'];
    }

    private function insertHeading(Page $page, string $parent, string $text): void
    {
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $parent,
            'component' => ['type' => 'heading', 'props' => ['text' => $text, 'level' => 2]],
        ], OperationContext::admin($this->operatorId(), 'fixture'));

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));
    }

    /** 9 route ぶんのリクエスト（認証まわりの一括確認用） */
    private function requests(int $pageId, ?User $user = null): array
    {
        $as = fn () => $user === null ? $this : $this->actingAs($user);

        return [
            'index' => fn () => $as()->get('/admin/pages'),
            'create' => fn () => $as()->get('/admin/pages/create'),
            'store' => fn () => $as()->post('/admin/pages', ['title' => 'x', 'slug' => 'x']),
            'edit' => fn () => $as()->get("/admin/pages/{$pageId}/edit"),
            'update' => fn () => $as()->patch("/admin/pages/{$pageId}", ['title' => 'x']),
            'preview' => fn () => $as()->get("/admin/pages/{$pageId}/preview"),
            'publish' => fn () => $as()->post("/admin/pages/{$pageId}/publish"),
            'unpublish' => fn () => $as()->post("/admin/pages/{$pageId}/unpublish"),
            'trash' => fn () => $as()->post("/admin/pages/{$pageId}/trash"),
            'restore' => fn () => $as()->post("/admin/pages/{$pageId}/restore"),
        ];
    }

    /** @return array<string, mixed> 変わってはいけないものの一括スナップショット */
    private function snapshot(): array
    {
        return [
            'pages' => Page::query()->count(),
            'versions' => PageVersion::query()->count(),
            'statuses' => Page::query()->orderBy('id')->pluck('status')->implode(','),
            'published' => Page::query()->orderBy('id')->pluck('published_version_id')->implode(','),
            'audits' => DB::table('audit_logs')->whereIn('operation', [
                'page.create', 'page.update', 'page.publish', 'page.trash', 'page.restore',
            ])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function inertiaFlash(): array
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);

        return $flash;
    }
}
