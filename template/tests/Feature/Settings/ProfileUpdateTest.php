<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * 本人が自分のアカウントを消す口は **無い**（STAGE #18A 追加要件 B）。
     *
     * 以前はここに「自分で削除できる」テストがあったが、削除できると
     * 監査ログの actor が失われ、最後のオーナーが自分を消して
     * 誰も管理できない CMS を作れてしまう。
     * 停止はオーナーが `/admin/users` から無効化する（行は残す）で統一した。
     *
     * 「削除できる」を「削除できない」へ置き換えた回帰。
     */
    public function test_自分でアカウントを削除する経路は存在しない()
    {
        $user = User::factory()->create();

        // route そのものが無い
        $this->assertFalse(
            Route::has('profile.destroy'),
            'アカウント削除の route が残っている',
        );

        // URL を直接叩いても消えない（405 / 404 のどちらでも「消えない」ことが要点）
        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password']);

        $this->assertNotNull($user->fresh(), '利用者が削除された');
        $this->assertAuthenticatedAs($user);
    }

    /** 設定画面に削除の UI も文言も出さない */
    public function test_プロフィール設定に削除のuiを出さない()
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/settings/Profile.vue'));

        foreach (['DeleteUser', 'アカウントを削除', 'profile.destroy'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "削除の痕跡が残っている: {$forbidden}");
        }

        $this->assertFileDoesNotExist(base_path('resources/js/components/DeleteUser.vue'));
        $this->assertFileDoesNotExist(base_path('app/Http/Requests/Settings/ProfileDeleteRequest.php'));

        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }
}
