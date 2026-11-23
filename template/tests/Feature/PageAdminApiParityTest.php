<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Inertia\Support\SessionKey;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / #20 — Admin ↔ API の parity と Audit の interface（SPEC §V.2 AD-2 / AD-5・§AC）。
 *
 * ## 何を比較するか
 *
 * **HTTP の見た目は比較しない。** Admin は redirect + session、API は JSON + status で
 * 表現が違うのは当然なので、そこを差異に数えない。比較するのは
 * **Application Service へ届いた結果**（status 相当・error keys・DB state・lifecycle・audit）。
 *
 * ## 確定している例外（AD-5）
 *
 * **publish だけ**が経路で異なる。Admin の「公開する」操作自体を人間の確認とみなすため
 * Admin は 1 回で通り、API は確認を要する。
 * **trash は Admin / API とも ConfirmationGate を通る**（差は無い）。
 * どちらの場合も、確認要求の有無以外の最終状態は一致していなければならない。
 *
 * ## 独立性
 *
 * 経路ごとに**別のページ**を用意する。同じ slug / ID を順に叩くと
 * unique 制約や状態遷移の干渉を「parity 差」と誤認するため。
 */
final class PageAdminApiParityTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @var list<string> 比較対象の mutation */
    private const MUTATIONS = [
        'page.create', 'page.update', 'page.publish', 'page.trash', 'page.restore',
    ];

    /**
     * 操作ごとの分類。曖昧に「空でない」ではなく **exact** で確かめるための表。
     *
     * @var array<string, string>
     */
    private const CLASSIFICATIONS = [
        'page.create' => 'write',
        'page.update' => 'write',
        'page.publish' => 'external_effect',
        'page.trash' => 'destructive',
        'page.restore' => 'write',
    ];

    // ─────────────────────────────────────────────────────────────
    // A. create
    // ─────────────────────────────────────────────────────────────

    public function test_create_parity_for_valid_input(): void
    {
        $admin = $this->adminUser();

        $viaAdmin = $this->admin($admin, 'post', '/admin/pages', [
            'title' => '会社案内', 'slug' => 'parity-admin-create',
        ]);
        $viaApi = $this->api($admin, 'postJson', '/api/v1/pages', [
            'title' => '会社案内', 'slug' => 'parity-api-create',
        ]);

        $this->assertSame('success', $viaAdmin['status']);
        $this->assertSame($viaAdmin['status'], $viaApi['status']);

        // 生まれたページの business state が一致する
        $this->assertSame(
            $this->pageState('parity-admin-create'),
            $this->pageState('parity-api-create'),
        );
        $this->assertSame([
            'managedBy' => Page::MANAGED_BY_DOCUMENT,
            'status' => PageStatus::Draft->value,
            'versions' => 1,
            'published' => null,
            'publishedAt' => null,
        ], $this->pageState('parity-admin-create'));

        // 送った title がどちらの経路でもそのまま保存される
        foreach (['parity-admin-create', 'parity-api-create'] as $slug) {
            $this->assertSame(
                '会社案内',
                (string) Page::query()->where('slug', $slug)->sole()->title,
                "{$slug} の title が一致しない",
            );
        }
    }

    public function test_create_parity_for_rejected_input(): void
    {
        $admin = $this->adminUser();
        $before = $this->snapshot();

        foreach ([
            'managedBy' => ['managedBy' => Page::MANAGED_BY_BLADE],
            'managed_by' => ['managed_by' => Page::MANAGED_BY_BLADE],
            'status' => ['status' => 'published'],
            'whatever' => ['whatever' => 1],
        ] as $key => $extra) {
            $viaAdmin = $this->admin($admin, 'post', '/admin/pages',
                ['title' => 'x', 'slug' => "reject-admin-{$key}"] + $extra);
            $viaApi = $this->api($admin, 'postJson', '/api/v1/pages',
                ['title' => 'x', 'slug' => "reject-api-{$key}"] + $extra);

            $this->assertSame('validation_error', $viaAdmin['status'], "admin が {$key} を受理した");
            $this->assertSame($viaAdmin['status'], $viaApi['status'], "{$key} の結果が経路で違う");
            $this->assertSame([$key], $viaAdmin['errorKeys']);
            $this->assertSame($viaAdmin['errorKeys'], $viaApi['errorKeys']);
        }

        // slug の形式違反も同じ key
        $viaAdmin = $this->admin($admin, 'post', '/admin/pages', ['title' => 'x', 'slug' => 'Invalid Slug']);
        $viaApi = $this->api($admin, 'postJson', '/api/v1/pages', ['title' => 'x', 'slug' => 'Invalid Slug']);

        $this->assertSame(['slug'], $viaAdmin['errorKeys']);
        $this->assertSame($viaAdmin['errorKeys'], $viaApi['errorKeys']);

        // どちらの経路でも 1 行も書かない
        $this->assertSame($before, $this->snapshot());
    }

    // ─────────────────────────────────────────────────────────────
    // B. update
    // ─────────────────────────────────────────────────────────────

    public function test_update_parity_for_valid_and_invalid_input(): void
    {
        $admin = $this->adminUser();
        $forAdmin = $this->makePage('update-admin');
        $forApi = $this->makePage('update-api');

        $payload = ['title' => '更新後', 'seo' => ['description' => '説明', 'noindex' => true]];

        $viaAdmin = $this->admin($admin, 'patch', "/admin/pages/{$forAdmin->id}", $payload + ['slug' => 'update-admin-2']);
        $viaApi = $this->api($admin, 'patchJson', "/api/v1/pages/{$forApi->id}", $payload + ['slug' => 'update-api-2']);

        $this->assertSame('success', $viaAdmin['status']);
        $this->assertSame($viaAdmin['status'], $viaApi['status']);

        $this->assertSame($this->metadataState('update-admin-2'), $this->metadataState('update-api-2'));
        $this->assertSame(
            ['title' => '更新後', 'description' => '説明', 'noindex' => true],
            $this->metadataState('update-admin-2'),
        );

        // 不正入力も同じ error key で、状態は動かない
        $before = $this->snapshot();

        $viaAdmin = $this->admin($admin, 'patch', "/admin/pages/{$forAdmin->id}", ['slug' => 'Invalid Slug']);
        $viaApi = $this->api($admin, 'patchJson', "/api/v1/pages/{$forApi->id}", ['slug' => 'Invalid Slug']);

        $this->assertSame('validation_error', $viaAdmin['status']);
        $this->assertSame($viaAdmin['status'], $viaApi['status']);
        $this->assertSame(['slug'], $viaAdmin['errorKeys']);
        $this->assertSame($viaAdmin['errorKeys'], $viaApi['errorKeys']);

        $viaAdmin = $this->admin($admin, 'patch', "/admin/pages/{$forAdmin->id}",
            ['seo' => ['description' => str_repeat('あ', 201)]]);
        $viaApi = $this->api($admin, 'patchJson', "/api/v1/pages/{$forApi->id}",
            ['seo' => ['description' => str_repeat('あ', 201)]]);

        $this->assertSame(['seo.description'], $viaAdmin['errorKeys']);
        $this->assertSame($viaAdmin['errorKeys'], $viaApi['errorKeys']);

        $this->assertSame($before, $this->snapshot());
    }

    /** Admin controller が運ぶのは title / slug / seo だけ（lifecycle を動かさない） */
    public function test_admin_update_never_moves_lifecycle(): void
    {
        $admin = $this->adminUser();
        $page = $this->makePage('lifecycle-guard');

        $this->admin($admin, 'patch', "/admin/pages/{$page->id}", [
            'title' => '設定だけ',
            'managedBy' => Page::MANAGED_BY_BLADE,
            'status' => PageStatus::Published->value,
            'publishedAt' => '2026-01-01T00:00:00+09:00',
            'unknown' => 'x',
        ]);

        $this->assertSame([
            'managedBy' => Page::MANAGED_BY_DOCUMENT,
            'status' => PageStatus::Draft->value,
            'versions' => 1,
            'published' => null,
            'publishedAt' => null,
        ], $this->pageState('lifecycle-guard'));
    }

    // ─────────────────────────────────────────────────────────────
    // C. publish（AD-5 の確定例外）
    // ─────────────────────────────────────────────────────────────

    public function test_publish_parity_admin_needs_no_confirmation_but_api_does(): void
    {
        $admin = $this->adminUser();
        $forAdmin = $this->makePage('publish-admin', renderable: true);
        $forApi = $this->makePage('publish-api', renderable: true);

        // Admin: 1 回で公開まで届く
        $viaAdmin = $this->admin($admin, 'post', "/admin/pages/{$forAdmin->id}/publish");
        $this->assertSame('success', $viaAdmin['status']);

        // API: token 無しでは確認を要求し、状態は変わらない
        $first = $this->api($admin, 'postJson', "/api/v1/pages/{$forApi->id}/publish");
        $this->assertSame('confirmation_required', $first['status']);
        $this->assertSame(PageStatus::Draft->value, $forApi->fresh()?->status->value);
        $this->assertNull($forApi->fresh()?->published_version_id);

        // 同じ token を X-Confirmation で送り直すと通る
        $second = $this->api($admin, 'postJson', "/api/v1/pages/{$forApi->id}/publish", [],
            ['X-Confirmation' => $first['confirmationToken']]);
        $this->assertSame('success', $second['status']);

        // 確認要求の有無**以外**の最終状態は一致する
        $this->assertSame($this->pageState('publish-admin'), $this->pageState('publish-api'));
        $this->assertSame(PageStatus::Published->value, $this->pageState('publish-admin')['status']);

        // 公開されたのは **その時点の draft** であること（別の版を指していない）
        foreach (['publish-admin', 'publish-api'] as $slug) {
            $page = Page::query()->where('slug', $slug)->sole();

            $this->assertNotNull($page->published_version_id);
            $this->assertSame(
                $page->draft_version_id,
                $page->published_version_id,
                "{$slug} の公開版が draft と一致しない",
            );
        }

        $this->get('/publish-admin')->assertOk();
        $this->get('/publish-api')->assertOk();
    }

    public function test_empty_publish_parity_fails_before_confirmation(): void
    {
        $admin = $this->adminUser();
        $forAdmin = $this->makePage('empty-admin');
        $forApi = $this->makePage('empty-api');
        $before = $this->snapshot();

        $viaAdmin = $this->admin($admin, 'post', "/admin/pages/{$forAdmin->id}/publish");
        $viaApi = $this->api($admin, 'postJson', "/api/v1/pages/{$forApi->id}/publish");

        // 確認より**先に**検証する。API も confirmation_required にならない
        $this->assertSame('validation_error', $viaAdmin['status']);
        $this->assertSame($viaAdmin['status'], $viaApi['status']);
        $this->assertSame(['root'], $viaAdmin['errorKeys']);
        $this->assertSame($viaAdmin['errorKeys'], $viaApi['errorKeys']);

        $this->assertSame($before, $this->snapshot());
    }

    // ─────────────────────────────────────────────────────────────
    // D / E. trash・restore
    // ─────────────────────────────────────────────────────────────

    public function test_trash_parity_requires_confirmation_on_both_paths(): void
    {
        $admin = $this->adminUser();
        $forAdmin = $this->makePage('trash-admin', renderable: true, publish: true);
        $forApi = $this->makePage('trash-api', renderable: true, publish: true);

        // 1 回目: どちらも確認を求め、状態は変わらない
        $adminFirst = $this->admin($admin, 'post', "/admin/pages/{$forAdmin->id}/trash");
        $apiFirst = $this->api($admin, 'postJson', "/api/v1/pages/{$forApi->id}/trash");

        $this->assertSame('confirmation_required', $adminFirst['status']);
        $this->assertSame($adminFirst['status'], $apiFirst['status']);
        $this->assertSame(PageStatus::Published->value, $forAdmin->fresh()?->status->value);
        $this->assertSame(PageStatus::Published->value, $forApi->fresh()?->status->value);

        // 2 回目: Admin は confirmation_token、API は X-Confirmation
        $adminSecond = $this->admin($admin, 'post', "/admin/pages/{$forAdmin->id}/trash",
            ['confirmation_token' => $adminFirst['confirmationToken']]);
        $apiSecond = $this->api($admin, 'postJson', "/api/v1/pages/{$forApi->id}/trash", [],
            ['X-Confirmation' => $apiFirst['confirmationToken']]);

        $this->assertSame('success', $adminSecond['status']);
        $this->assertSame($adminSecond['status'], $apiSecond['status']);

        $this->assertSame($this->pageState('trash-admin'), $this->pageState('trash-api'));
        $this->assertSame(PageStatus::Trashed->value, $this->pageState('trash-admin')['status']);

        $this->get('/trash-admin')->assertNotFound();
        $this->get('/trash-api')->assertNotFound();
    }

    public function test_restore_parity_returns_both_paths_to_draft(): void
    {
        $admin = $this->adminUser();
        $forAdmin = $this->makePage('restore-admin', renderable: true, publish: true, trash: true);
        $forApi = $this->makePage('restore-api', renderable: true, publish: true, trash: true);

        $viaAdmin = $this->admin($admin, 'post', "/admin/pages/{$forAdmin->id}/restore");
        $viaApi = $this->api($admin, 'postJson', "/api/v1/pages/{$forApi->id}/restore");

        $this->assertSame('success', $viaAdmin['status']);
        $this->assertSame($viaAdmin['status'], $viaApi['status']);

        $this->assertSame($this->pageState('restore-admin'), $this->pageState('restore-api'));
        // 下書きへ戻り、勝手に再公開しない
        $this->assertSame(PageStatus::Draft->value, $this->pageState('restore-admin')['status']);

        $this->get('/restore-admin')->assertNotFound();
        $this->get('/restore-api')->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // F. Audit の interface（AD-2 / D-12）
    // ─────────────────────────────────────────────────────────────

    public function test_admin_mutations_are_audited_as_admin(): void
    {
        $admin = $this->adminUser();
        $pageId = $this->runAdminLifecycle($admin, 'audit-admin');

        foreach (self::MUTATIONS as $operation) {
            $row = DB::table('audit_logs')
                ->where('operation', $operation)
                ->where('result', OperationStatus::Success->value)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($row, "{$operation} の監査が無い");
            $this->assertSame('admin', $row->interface, "{$operation} が admin として記録されていない");
            $this->assertSame('user', $row->actor_type);
            $this->assertSame($admin->id, (int) $row->actor_id);
            $this->assertSame('page', $row->resource_type);
            // 別のページの行を拾っていないこと
            $this->assertSame((string) $pageId, (string) $row->resource_id, "{$operation} の対象が違う");
            $this->assertSame(OperationStatus::Success->value, $row->result);
            $this->assertSame(
                self::CLASSIFICATIONS[$operation],
                $row->classification,
                "{$operation} の分類が違う",
            );

            // AD-2: Admin 操作が api として記録された行は 1 つも無い
            $this->assertSame(0, DB::table('audit_logs')
                ->where('operation', $operation)
                ->where('interface', 'api')
                ->count(), "{$operation} が api として記録された");
        }
    }

    public function test_api_mutations_are_audited_as_api_and_stay_separable(): void
    {
        $admin = $this->adminUser();
        $this->runAdminLifecycle($admin, 'sep-admin');
        $this->runApiLifecycle($admin, 'sep-api');

        foreach (self::MUTATIONS as $operation) {
            $interfaces = DB::table('audit_logs')
                ->where('operation', $operation)
                ->where('result', OperationStatus::Success->value)
                ->pluck('interface')
                ->unique()
                ->sort()
                ->values()
                ->all();

            $this->assertSame(['admin', 'api'], $interfaces, "{$operation} の経路が区別できない");
        }
    }

    /** API 側も interface だけでなく、監査行の中身を exact に確かめる */
    public function test_api_mutations_are_audited_with_full_detail(): void
    {
        $admin = $this->adminUser();
        $pageId = $this->runApiLifecycle($admin, 'audit-api');

        foreach (self::MUTATIONS as $operation) {
            $row = DB::table('audit_logs')
                ->where('operation', $operation)
                ->where('result', OperationStatus::Success->value)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($row, "{$operation} の監査が無い");
            $this->assertSame('api', $row->interface, "{$operation} が api として記録されていない");
            $this->assertSame('user', $row->actor_type);
            $this->assertSame($admin->id, (int) $row->actor_id);
            $this->assertSame('page', $row->resource_type);
            $this->assertSame((string) $pageId, (string) $row->resource_id, "{$operation} の対象が違う");
            $this->assertSame(OperationStatus::Success->value, $row->result);
            $this->assertSame(
                self::CLASSIFICATIONS[$operation],
                $row->classification,
                "{$operation} の分類が違う",
            );
        }
    }

    /** 検証エラーと確認要求は「成功した操作」として数えない */
    public function test_validation_and_confirmation_do_not_create_success_audits(): void
    {
        $admin = $this->adminUser();
        $page = $this->makePage('no-audit');
        // STAGE #18A: 失敗・確認要求も **その結末として** 記録される。
        // ここで守りたいのは「成功として数えられない」こと
        $before = DB::table('audit_logs')->where('result', 'success')->count();

        // validation error（空 publish・不正 slug）
        $this->admin($admin, 'post', "/admin/pages/{$page->id}/publish");
        $this->api($admin, 'postJson', '/api/v1/pages', ['title' => 'x', 'slug' => 'Invalid Slug']);

        // confirmation required（trash の 1 回目）
        $this->admin($admin, 'post', "/admin/pages/{$page->id}/trash");
        $this->api($admin, 'postJson', "/api/v1/pages/{$page->id}/trash");

        $this->assertSame(
            $before,
            DB::table('audit_logs')->where('result', 'success')->count(),
            '失敗・確認要求が成功として監査された',
        );
        // 成功としては 1 件も残らない（結末そのものは残る）
        $this->assertSame(0, DB::table('audit_logs')
            ->whereIn('operation', ['page.publish', 'page.trash'])
            ->where('result', 'success')
            ->count());
    }

    /**
     * 監査 metadata に **機微な値**を混ぜない。
     *
     * slug は「どのページを操作したか」を追うための公開情報で、既存契約として
     * metadata に入る（`['slug' => …]`）。ここで禁じるのは本文・資格情報・
     * 確認トークンのような、監査に不要で漏れると困る値。
     */
    public function test_audit_metadata_carries_no_sensitive_values(): void
    {
        $admin = $this->adminUser();
        $this->runAdminLifecycle($admin, 'meta-check');

        $rows = DB::table('audit_logs')->pluck('metadata');
        $this->assertNotEmpty($rows);

        // 確認を経た操作があるので、発行済みの token が漏れていないかも実値で見る
        $tokens = DB::table('audit_logs')
            ->whereNotNull('confirmation_token')
            ->pluck('confirmation_token')
            ->filter()
            ->all();

        foreach ($rows as $metadata) {
            $json = (string) $metadata;
            $lower = strtolower($json);

            foreach ([
                'password', 'secret', 'credential', 'authorization',
                'cookie', 'csrf', '_token', 'body', 'apikey', 'api_key',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $lower, "metadata に [{$forbidden}] がある");
            }

            foreach ($tokens as $token) {
                $this->assertStringNotContainsString((string) $token, $json, 'metadata に確認トークンが入っている');
            }
        }

        // 追跡に必要な slug は入っていてよい（過剰に禁じない）
        $create = DB::table('audit_logs')->where('operation', 'page.create')->orderByDesc('id')->first();
        $this->assertStringContainsString('meta-check', (string) $create?->metadata);
    }

    // ─────────────────────────────────────────────────────────────
    // 既存 surface の非回帰
    // ─────────────────────────────────────────────────────────────

    public function test_existing_surfaces_are_unchanged(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        // ブロック編集に加え、公開・予約の取り消しを明示操作として追加した。
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.pages.unpublish'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.pages.duplicate'));
        // 作成の画面の URL の候補（読みはサーバー。UX 一周 19 周目）
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.pages.slug-preview'));
        $this->assertSame(13, $routes
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.pages.'))
            ->count());

        /*
         * **増えたものを名指しで確かめる。**
         *
         * 数だけを合わせると「1 本消えて別の 1 本が増えた」を見逃す。
         * P0-C6c で版の一覧（GET）と復元（POST）の 2 本が増えた。
         */
        $pageRoutes = $routes
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/pages')
                || $route->uri() === 'api/v1/components')
            ->map(fn ($route) => $route->uri())
            ->values();

        $this->assertContains('api/v1/pages/{id}/versions', $pageRoutes);
        $this->assertContains('api/v1/pages/{id}/versions/restore', $pageRoutes);
        // ゴミ箱からの復元は別物として残っている（同じ名前で 2 つの意味を持たせない）
        $this->assertContains('api/v1/pages/{id}/restore', $pageRoutes);
        $this->assertContains('api/v1/pages/{id}/unpublish', $pageRoutes);
        /*
         * **本数は書かない。** 増えたときに数だけ直して中身を見ない道になる。
         * route の一覧そのものは `PageAdminTest` が名前で固定している。
         */

        // STAGE 21C で `content.trash` が 1 つだけ増えた（29 → 30。ゴミ箱への移動）。
        // P0-C6c で `page.document.versions` が増えた（**復元は足していない** — §4.12）
        $this->assertContains('page.document.versions', OperationCatalog::ids());
        $this->assertNotContains('page.document.restore', OperationCatalog::ids());
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function adminUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * Admin（HTML / Inertia）経路の結果を **業務レベルへ正規化**する。
     *
     * redirect や session といった HTTP の表現は捨て、
     * status / error keys / confirmation token だけを取り出す。
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, errorKeys: list<string>, confirmationToken: string|null}
     */
    private function admin(User $user, string $method, string $uri, array $payload = []): array
    {
        // 前の request の flash が混ざらないようにしてから叩く
        session()->forget(['errors', SessionKey::FLASH_DATA]);

        $response = $this->actingAs($user)->{$method}($uri, $payload);

        // 4xx / 5xx を「session に何も無い＝success」と読み違えないため、
        // **まず HTTP が期待どおり redirect したこと**を確かめる
        $this->assertTrue(
            $response->status() >= 300 && $response->status() < 400,
            "{$method} {$uri} が redirect しなかった（status: {$response->status()}）",
        );

        $keys = $this->errorKeys(session('errors'));

        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);

        if ($keys !== []) {
            sort($keys);

            return ['status' => 'validation_error', 'errorKeys' => $keys, 'confirmationToken' => null];
        }

        if (isset($flash['confirmation'])) {
            return [
                'status' => 'confirmation_required',
                'errorKeys' => [],
                'confirmationToken' => (string) $flash['confirmation']['token'],
            ];
        }

        return ['status' => 'success', 'errorKeys' => [], 'confirmationToken' => null];
    }

    /**
     * session の errors から **フィールド名**を取り出す。
     *
     * 保存のされ方で `ViewErrorBag` / `MessageBag` / `['default' => MessageBag]` の
     * どれにもなり得るので、どの形でも同じフィールド名の並びになるようにする。
     *
     * @return list<string>
     */
    private function errorKeys(mixed $bag): array
    {
        if ($bag instanceof ViewErrorBag) {
            return $bag->getBag('default')->keys();
        }

        if ($bag instanceof MessageBag) {
            return $bag->keys();
        }

        if (is_array($bag)) {
            $inner = $bag['default'] ?? null;

            if ($inner instanceof MessageBag) {
                return $inner->keys();
            }

            // MessageBag が配列化された形（`['format' => …, 'messages' => ['field' => […]]]`）
            if (is_array($inner) && is_array($inner['messages'] ?? null)) {
                return array_map(strval(...), array_keys($inner['messages']));
            }

            // `['default' => ['field' => [messages]]]` の形。bag 名ではなく **項目名**を返す
            if (is_array($inner)) {
                return array_map(strval(...), array_keys($inner));
            }

            return array_map(strval(...), array_keys($bag));
        }

        return [];
    }

    /**
     * API（JSON）経路の結果を同じ形へ正規化する。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array{status: string, errorKeys: list<string>, confirmationToken: string|null}
     */
    private function api(User $user, string $method, string $uri, array $payload = [], array $headers = []): array
    {
        $response = $this->actingAs($user)->{$method}($uri, $payload, $headers);
        $json = $response->json();

        if ($response->status() === 422) {
            $keys = array_keys((array) ($json['errors'] ?? []));
            sort($keys);

            return ['status' => 'validation_error', 'errorKeys' => $keys, 'confirmationToken' => null];
        }

        if (isset($json['confirmation']['token'])) {
            return [
                'status' => 'confirmation_required',
                'errorKeys' => [],
                'confirmationToken' => (string) $json['confirmation']['token'],
            ];
        }

        $response->assertOk();

        return ['status' => 'success', 'errorKeys' => [], 'confirmationToken' => null];
    }

    /** fixture 用（検証対象の HTTP mutation を Application Service で代替しない） */
    private function makePage(
        string $slug,
        bool $renderable = false,
        bool $publish = false,
        bool $trash = false,
    ): Page {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => $slug, 'slug' => $slug], $context);

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $page = Page::query()->findOrFail($result->data['page']['id']);

        if ($renderable) {
            $root = (string) PageVersion::query()
                ->findOrFail($page->refresh()->draft_version_id)->document['root']['id'];

            $inserted = app(PatchPageDocument::class)->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => $root,
                'component' => ['type' => 'section', 'children' => []],
            ], $context);

            $this->assertSame(OperationStatus::Success, $inserted->status, json_encode($inserted->errors));
        }

        if ($publish) {
            $page->forceFill([
                'published_version_id' => $page->refresh()->draft_version_id,
                'status' => PageStatus::Published->value,
                'published_at' => now(),
            ])->save();
        }

        if ($trash) {
            $page->forceFill(['status' => PageStatus::Trashed->value, 'trashed_at' => now()])->save();
        }

        return $page->refresh();
    }

    /**
     * Admin から 5 mutation をひと通り成功させ、対象ページの id を返す。
     *
     * @return int 監査行の resource_id と突き合わせるための id
     */
    private function runAdminLifecycle(User $user, string $slug): int
    {
        $this->admin($user, 'post', '/admin/pages', ['title' => $slug, 'slug' => $slug]);
        $page = Page::query()->where('slug', $slug)->sole();

        $this->admin($user, 'patch', "/admin/pages/{$page->id}", ['title' => "{$slug} 更新"]);
        $this->makeRenderable($page);
        $this->admin($user, 'post', "/admin/pages/{$page->id}/publish");

        $first = $this->admin($user, 'post', "/admin/pages/{$page->id}/trash");
        $this->admin($user, 'post', "/admin/pages/{$page->id}/trash",
            ['confirmation_token' => $first['confirmationToken']]);

        $this->admin($user, 'post', "/admin/pages/{$page->id}/restore");

        return (int) $page->id;
    }

    /**
     * API から 5 mutation をひと通り成功させ、対象ページの id を返す。
     *
     * @return int 監査行の resource_id と突き合わせるための id
     */
    private function runApiLifecycle(User $user, string $slug): int
    {
        $this->api($user, 'postJson', '/api/v1/pages', ['title' => $slug, 'slug' => $slug]);
        $page = Page::query()->where('slug', $slug)->sole();

        $this->api($user, 'patchJson', "/api/v1/pages/{$page->id}", ['title' => "{$slug} 更新"]);
        $this->makeRenderable($page);

        $publish = $this->api($user, 'postJson', "/api/v1/pages/{$page->id}/publish");
        $this->api($user, 'postJson', "/api/v1/pages/{$page->id}/publish", [],
            ['X-Confirmation' => $publish['confirmationToken']]);

        $trash = $this->api($user, 'postJson', "/api/v1/pages/{$page->id}/trash");
        $this->api($user, 'postJson', "/api/v1/pages/{$page->id}/trash", [],
            ['X-Confirmation' => $trash['confirmationToken']]);

        $this->api($user, 'postJson', "/api/v1/pages/{$page->id}/restore");

        return (int) $page->id;
    }

    private function makeRenderable(Page $page): void
    {
        $root = (string) PageVersion::query()
            ->findOrFail($page->refresh()->draft_version_id)->document['root']['id'];

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $root,
            'component' => ['type' => 'section', 'children' => []],
        ], OperationContext::admin($this->operatorId(), 'fixture'));
    }

    /** @return array<string, mixed> ページの business state（経路比較の対象） */
    private function pageState(string $slug): array
    {
        $page = Page::query()->where('slug', $slug)->sole();

        return [
            'managedBy' => $page->managed_by,
            'status' => $page->status->value,
            'versions' => PageVersion::query()->where('page_id', $page->id)->count(),
            'published' => $page->published_version_id === null ? null : 'set',
            'publishedAt' => $page->published_at === null ? null : 'set',
        ];
    }

    /** @return array<string, mixed> */
    private function metadataState(string $slug): array
    {
        $page = Page::query()->where('slug', $slug)->sole();

        return [
            'title' => $page->title,
            'description' => $page->seo['description'] ?? null,
            'noindex' => $page->seo['noindex'] ?? null,
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
            'audits' => DB::table('audit_logs')->where('result', 'success')->count(),
        ];
    }
}
