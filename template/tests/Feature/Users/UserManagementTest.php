<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Role;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * PHASE WP-BASELINE / STAGE #18A — 利用者の管理（SPEC §2-1・5〜8・14）。
 */
final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /** SPEC §2-1 — migration 後に既存利用者が owner かつ有効になる */
    public function test_既存利用者はownerへ移行しowner不在にならない(): void
    {
        // migration の移行はすでに適用済み。ここでは seeder の保証を確かめる
        DB::table('users')->insert([
            'name' => '旧管理者', 'email' => 'legacy@example.test',
            'password' => bcrypt('x'), 'role' => 'viewer', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        config(['agentcms.admin.email' => 'legacy@example.test', 'agentcms.admin.password' => 'secret-password']);
        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'legacy@example.test')->firstOrFail();

        $this->assertSame(Role::Owner, $user->role());
        $this->assertTrue($user->is_active);
        $this->assertSame('旧管理者', $user->name, '既存の表示名を変えない');
        $this->assertTrue(User::query()->where('role', 'owner')->where('is_active', true)->exists());
    }

    /** SPEC §2-7 — 招待の作成とメール。password が response / audit / log に出ない */
    public function test_利用者を招待できパスワードは一切出ない(): void
    {
        Notification::fake();
        $owner = User::factory()->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->post('/admin/users', [
            'name' => '新しい編集者', 'email' => 'editor@example.test', 'role' => 'editor',
        ]);

        $response->assertRedirect('/admin/users')->assertSessionHas('success');

        $invited = User::query()->where('email', 'editor@example.test')->firstOrFail();
        $this->assertSame(Role::Editor, $invited->role());
        $this->assertTrue($invited->is_active);
        $this->assertNotNull($invited->invited_at);
        $this->assertNull($invited->last_login_at);

        Notification::assertSentTo($invited, UserInvitation::class);

        // 監査には権限だけが残り、パスワードもメールアドレスも残らない
        $audit = DB::table('audit_logs')->where('operation', 'user.invite')->firstOrFail();
        $metadata = (string) $audit->metadata;
        $this->assertStringContainsString('editor', $metadata);
        $this->assertStringNotContainsString('editor@example.test', $metadata);
        $this->assertStringNotContainsString('password', strtolower($metadata));

        // 画面へ返る props にもハッシュは出ない
        $edit = $this->actingAs($owner)->get('/admin/users/'.$invited->getKey());
        $edit->assertOk();
        $this->assertStringNotContainsString($invited->password, (string) $edit->getContent());
    }

    /** SPEC §2-7 — 重複と不正なメールアドレスを拒否する */
    public function test_重複や不正なメールアドレスを拒否する(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($owner)->from('/admin/users/create')
            ->post('/admin/users', ['name' => 'x', 'email' => 'taken@example.test', 'role' => 'editor'])
            ->assertSessionHasErrors('email');

        $this->actingAs($owner)->from('/admin/users/create')
            ->post('/admin/users', ['name' => 'x', 'email' => 'not-an-email', 'role' => 'editor'])
            ->assertSessionHasErrors('email');

        $this->actingAs($owner)->from('/admin/users/create')
            ->post('/admin/users', ['name' => 'x', 'email' => 'ok@example.test', 'role' => 'superuser'])
            ->assertSessionHasErrors('role');

        $this->assertSame(2, User::query()->count());
    }

    /** SPEC §2-5 — 最後のオーナーは降格も無効化もできない。自分自身も無効化できない */
    public function test_最後のownerと自分自身は守られる(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();

        // 最後のオーナー = 自分。降格も無効化も拒否
        $this->actingAs($owner)->from('/admin/users/'.$owner->getKey())
            ->post('/admin/users/'.$owner->getKey().'/role', ['role' => 'viewer'])
            ->assertSessionHasErrors('user');
        $this->actingAs($owner)->from('/admin/users/'.$owner->getKey())
            ->post('/admin/users/'.$owner->getKey().'/deactivate')
            ->assertSessionHasErrors('user');

        $this->assertSame(Role::Owner, $owner->refresh()->role());
        $this->assertTrue($owner->is_active);

        // オーナーが 2 人でも「自分自身」は変えられない（安全側へ倒す）
        $second = User::factory()->role(Role::Owner)->create();
        $this->actingAs($owner)->from('/admin/users/'.$owner->getKey())
            ->post('/admin/users/'.$owner->getKey().'/role', ['role' => 'administrator'])
            ->assertSessionHasErrors('user');

        // 相手のオーナーは降格できる（自分以外・最後の 1 人でもない）
        $this->confirmAndPost($owner, '/admin/users/'.$second->getKey().'/role', ['role' => 'administrator']);
        $this->assertSame(Role::Administrator, $second->refresh()->role());
    }

    /** SPEC §2-6 — 無効化と権限変更で session が失効し、無効な利用者はログインできない */
    public function test_無効化とrole変更でsessionが失効する(): void
    {
        // 本番と同じ session driver（テスト既定は array で行を持たない）
        config(['session.driver' => 'database']);

        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();

        $sessions = fn (): int => DB::table('sessions')->where('user_id', $target->getKey())->count();

        DB::table('sessions')->insert([
            'id' => 'session-a', 'user_id' => $target->getKey(), 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
        ]);
        $this->assertSame(1, $sessions());

        $this->confirmAndPost($owner, '/admin/users/'.$target->getKey().'/role', ['role' => 'viewer']);
        $this->assertSame(0, $sessions(), 'role 変更で session が残っている');

        DB::table('sessions')->insert([
            'id' => 'session-b', 'user_id' => $target->getKey(), 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->confirmAndPost($owner, '/admin/users/'.$target->getKey().'/deactivate');
        $this->assertFalse($target->refresh()->is_active);
        $this->assertSame(0, $sessions(), '無効化で session が残っている');
        $this->assertNull($target->remember_token, 'remember-me が残っている');

        // 無効な利用者はログインできない（一度ログアウトして入口から確かめる）
        auth()->logout();
        $this->flushSession();

        $this->post('/login', ['email' => $target->email, 'password' => 'password'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    /** SPEC §2-14 — role / is_active を一括代入で書き換えられない */
    public function test_roleとis_activeを一括代入で改ざんできない(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->viewer()->create();

        $this->actingAs($owner)->patch('/admin/users/'.$target->getKey(), [
            'name' => '改ざん', 'email' => $target->email,
            'role' => 'owner', 'is_active' => false,
        ]);

        $target->refresh();
        $this->assertSame('改ざん', $target->name, '名前の更新は通る');
        $this->assertSame(Role::Viewer, $target->role(), 'ロールが一括代入で変わっている');
        $this->assertTrue($target->is_active, '有効状態が一括代入で変わっている');

        // モデル側の契約としても fillable に含まない
        $this->assertNotContains('role', (new User)->getFillable());
        $this->assertNotContains('is_active', (new User)->getFillable());
    }

    /** SPEC §2-14 — 権限の無い利用者は他人の情報へ到達できない（IDOR） */
    public function test_権限の無い利用者は他人のユーザー画面へ到達できない(): void
    {
        $victim = User::factory()->role(Role::Owner)->create();

        foreach ([Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->get('/admin/users/'.$victim->getKey())->assertForbidden();
            $this->actingAs($user)->get('/admin/users/'.$victim->getKey().'/activity')->assertForbidden();
            $this->actingAs($user)->patch('/admin/users/'.$victim->getKey(), [
                'name' => 'x', 'email' => 'x@example.test',
            ])->assertForbidden();
            $this->actingAs($user)->post('/admin/users/'.$victim->getKey().'/deactivate')->assertForbidden();
        }

        $this->assertTrue($victim->refresh()->is_active);
    }

    /** SPEC §2-8 — 利用者操作が正しい actor / result / interface で監査される */
    public function test_利用者操作がauditされる(): void
    {
        Notification::fake();
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();

        $this->actingAs($owner)->post('/admin/users', [
            'name' => 'x', 'email' => 'new@example.test', 'role' => 'viewer',
        ]);
        $this->confirmAndPost($owner, '/admin/users/'.$target->getKey().'/role', ['role' => 'viewer']);
        $this->confirmAndPost($owner, '/admin/users/'.$target->getKey().'/deactivate');
        $this->actingAs($owner)->post('/admin/users/'.$target->getKey().'/reactivate');

        foreach (['user.invite', 'user.role.change', 'user.deactivate', 'user.reactivate'] as $operation) {
            $this->assertDatabaseHas('audit_logs', [
                'operation' => $operation,
                'result' => 'success',
                'interface' => 'admin',
                'actor_id' => $owner->getKey(),
                'resource_type' => 'user',
            ]);
        }
    }

    /** SPEC §2-14 — CSRF 保護が効いている */
    public function test_csrf保護が有効(): void
    {
        $reference = app('router')->getRoutes()->getByName('admin.news.index');

        foreach ([
            'admin.users.index', 'admin.users.create', 'admin.users.store', 'admin.users.edit',
            'admin.users.update', 'admin.users.role', 'admin.users.deactivate',
            'admin.users.reactivate', 'admin.users.invitation', 'admin.users.activity',
            'admin.activity.index', 'admin.activity.export',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} が無い");
            $this->assertSame($reference?->gatherMiddleware(), $route->gatherMiddleware(), $name);
            $this->assertContains('active', $route->gatherMiddleware(), "{$name} に active middleware が無い");
        }
    }

    /** 確認トークンを取得して本適用まで行う */
    private function confirmAndPost(User $actor, string $url, array $data = []): void
    {
        $this->actingAs($actor)->post($url, $data);

        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $token = $flash['confirmation']['token'] ?? null;

        $this->assertIsString($token, "確認トークンが返っていない: {$url}");

        $this->actingAs($actor)->post($url, [...$data, 'confirmation_token' => $token]);
    }
}
