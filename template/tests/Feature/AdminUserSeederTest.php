<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Role;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * 共有既定パスワードの禁止（Phase 7）:
 * password 未設定でのシードは黙って弱い管理者を作らず、明確に失敗する。
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_password_missing_fails_loudly(): void
    {
        config()->set('agentcms.admin.password', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD');

        $this->seed(AdminUserSeeder::class);
    }

    public function test_seeds_admin_with_configured_random_password(): void
    {
        config()->set('agentcms.admin.email', 'admin@example.test');
        config()->set('agentcms.admin.password', 'Kx7mPq2wNr9vTb4Zc6Ye8Uh3');

        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Kx7mPq2wNr9vTb4Zc6Ye8Uh3', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    /** 生成直後の管理者は制作者。作りの画面を開けるのが制作者だけになったため（UX 一周 2026-09-28） */
    public function test_新しく作る管理者は制作者で作りの画面を開ける(): void
    {
        config()->set('agentcms.admin.email', 'admin@example.test');
        config()->set('agentcms.admin.password', 'Kx7mPq2wNr9vTb4Zc6Ye8Uh3');

        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame(Role::Developer, $user->role());
        $this->actingAs($user)->get('/admin/content-types')->assertOk();
    }

    /** 既に居る管理者のロールは変えない（オーナーのまま・制作者のまま） */
    public function test_既に居る管理者のロールは変えない(): void
    {
        config()->set('agentcms.admin.email', 'admin@example.test');
        config()->set('agentcms.admin.password', 'Kx7mPq2wNr9vTb4Zc6Ye8Uh3');

        foreach ([Role::Owner, Role::Developer] as $role) {
            User::query()->delete();
            User::factory()->role($role)->create(['email' => 'admin@example.test']);

            $this->seed(AdminUserSeeder::class);

            $this->assertSame($role, User::query()->where('email', 'admin@example.test')->firstOrFail()->role());
        }
    }
}
