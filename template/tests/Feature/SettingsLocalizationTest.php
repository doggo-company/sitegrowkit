<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SettingsLocalizationTest extends TestCase
{
    /** 設定画面と利用者メニューの主要文言が日本語で固定されていることを確認する。 */
    public function test_settings_surfaces_are_localized_in_japanese(): void
    {
        $source = $this->sources([
            'resources/js/layouts/settings/Layout.vue',
            'resources/js/pages/settings/Profile.vue',
            'resources/js/pages/settings/Security.vue',
            'resources/js/pages/settings/Appearance.vue',
            'resources/js/pages/auth/ConfirmPassword.vue',
            'resources/js/components/UserMenuContent.vue',
            'resources/js/components/ManagePasskeys.vue',
            'resources/js/components/PasskeyItem.vue',
            'resources/js/components/PasskeyRegister.vue',
            'resources/js/components/ManageTwoFactor.vue',
            'resources/js/components/TwoFactorRecoveryCodes.vue',
            'resources/js/components/TwoFactorSetupModal.vue',
            'resources/js/components/AppearanceTabs.vue',
        ]);

        foreach ([
            'アカウント設定',
            'プロフィール設定',
            'セキュリティ設定',
            '外観設定',
            'パスワードの確認',
            'ログアウト',
            'パスキー',
            '二要素認証',
            'リカバリーコード',
            'ライト',
            'ダーク',
            'システム',
        ] as $label) {
            $this->assertStringContainsString($label, $source, "日本語表示「{$label}」が見つからない");
        }

        foreach ([
            'Manage your profile and account settings',
            'Profile settings',
            'Security settings',
            'Appearance settings',
            'Confirm password',
            'Delete account',
            'Two-factor authentication',
            'No passkeys yet',
            'Log out',
        ] as $english) {
            $this->assertStringNotContainsString($english, $source, "英語表示「{$english}」が残っている");
        }
    }

    /** 更新成功の通知と設定画面の検証メッセージも日本語であることを確認する。 */
    public function test_settings_feedback_is_localized_in_japanese(): void
    {
        $controllers = $this->sources([
            'app/Http/Controllers/Settings/ProfileController.php',
            'app/Http/Controllers/Settings/SecurityController.php',
        ]);

        $this->assertStringContainsString('プロフィールを更新しました', $controllers);
        $this->assertStringContainsString('パスワードを変更しました', $controllers);

        $validation = require lang_path('ja/validation.php');

        $this->assertSame('パスワードが正しくありません。', $validation['current_password']);
        $this->assertSame('メールアドレス', $validation['attributes']['email']);
    }

    /**
     * @param  list<string>  $paths
     */
    private function sources(array $paths): string
    {
        return implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents(base_path($path)),
            $paths,
        ));
    }
}
