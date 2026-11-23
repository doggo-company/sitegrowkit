<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 未認証フロー（ログイン〜再設定〜メール確認〜二要素認証）の日本語化。
 *
 * `SettingsLocalizationTest` と同じ作り方で、**画面のソースを直接見る**。
 * 実行時のレンダリングに依存しないので、Fortify の機能フラグや
 * ログイン状態に左右されずに文言の回帰を止められる。
 */
final class AuthLocalizationTest extends TestCase
{
    /** @var list<string> */
    private const AUTH_SCREENS = [
        'resources/js/pages/auth/Login.vue',
        'resources/js/pages/auth/ForgotPassword.vue',
        'resources/js/pages/auth/ResetPassword.vue',
        'resources/js/pages/auth/VerifyEmail.vue',
        'resources/js/pages/auth/TwoFactorChallenge.vue',
        'resources/js/pages/auth/ConfirmPassword.vue',
        'resources/js/components/PasskeyVerify.vue',
    ];

    /** 認証画面の主要な表示文言が日本語で固定されている */
    public function test_authentication_screens_are_localized_in_japanese(): void
    {
        $source = $this->sources(self::AUTH_SCREENS);

        foreach ([
            // ログイン
            'ログイン',
            'メールアドレス',
            'パスワード',
            'パスワードをお忘れですか？',
            'ログイン状態を保持する',
            // パスワード再設定
            'パスワードの再設定',
            '再設定リンクを送信',
            'ログイン画面へ戻る',
            'パスワードを再設定',
            '新しいパスワード（確認）',
            // メールアドレスの確認
            'メールアドレスの確認',
            '確認メールを再送する',
            'ログアウト',
            // 二要素認証
            '二要素認証',
            '確認コード',
            'リカバリーコード',
            '続ける',
            // パスキー
            'パスキーでログイン',
            'またはメールアドレスでログイン',
            // パスワードの確認（既存。非回帰）
            'パスワードの確認',
        ] as $label) {
            $this->assertStringContainsString($label, $source, "日本語表示「{$label}」が見つからない");
        }
    }

    /**
     * 英語の表示文言が残っていない。
     *
     * **技術文字列は対象外**（route 名・`name` 属性・`data-test`・
     * `autocomplete`・`email@example.com` の placeholder など）。
     * ここで見るのは利用者の目に入る文だけ。
     */
    public function test_authentication_screens_have_no_english_copy_left(): void
    {
        $source = $this->sources(self::AUTH_SCREENS);

        foreach ([
            'Log in to your account',
            'Enter your email and password below to log in',
            '>Email address<',
            'Forgot your password?',
            'Remember me',
            'Forgot password',
            'Enter your email to receive a password reset link',
            'Email password reset link',
            'Or, return to',
            'Reset password',
            'Please enter your new password below',
            'Confirm password ',
            'Email verification',
            'Please verify your email address by clicking',
            'A new verification link has been sent',
            'Resend verification email',
            'Two-factor authentication',
            'Recovery code',
            'Authentication code',
            'Enter the authentication code provided',
            'login using a recovery code',
            'login using an authentication code',
            'Enter recovery code',
            'or you can ',
            'Sign in with a passkey',
            'Or continue with email',
            'Authenticating...',
        ] as $english) {
            $this->assertStringNotContainsString($english, $source, "英語表示「{$english}」が残っている");
        }
    }

    /**
     * 翻訳してはいけない技術文字列は残っている。
     *
     * 一括置換で route 名やテスト用属性まで japanize してしまう事故を止める。
     */
    public function test_technical_strings_are_left_untouched(): void
    {
        $source = $this->sources(self::AUTH_SCREENS);

        foreach ([
            'name="email"',
            'name="password"',
            'name="recovery_code"',
            'autocomplete="current-password"',
            'autocomplete="new-password"',
            'data-test="login-button"',
            'data-test="email-password-reset-link-button"',
            'data-test="reset-password-button"',
            // メールアドレスの入力例は翻訳対象ではない
            'placeholder="email@example.com"',
        ] as $technical) {
            $this->assertStringContainsString($technical, $source, "技術文字列 [{$technical}] が失われている");
        }
    }

    /** Laravel / Fortify が返す status・認証エラーも日本語になっている */
    public function test_framework_messages_are_localized(): void
    {
        $auth = require lang_path('ja/auth.php');
        $passwords = require lang_path('ja/passwords.php');

        $this->assertSame('メールアドレスまたはパスワードが正しくありません。', $auth['failed']);
        $this->assertStringContainsString(':seconds', $auth['throttle'], 'レート制限の残り秒数が失われている');

        $this->assertSame('パスワード再設定用のリンクをメールで送信しました。', $passwords['sent']);
        $this->assertSame('パスワードを再設定しました。', $passwords['reset']);

        // 既存の JSON 訳（Fortify の __() キー）も維持されている
        $json = json_decode((string) file_get_contents(lang_path('ja.json')), true, 16, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $this->assertSame(
            '二要素認証の確認コードが正しくありません。',
            $json['The provided two factor authentication code was invalid.'],
        );
    }

    /**
     * ログイン画面が開き、翻訳した画面コンポーネントが使われている。
     *
     * 文言そのものはビルド済み JS の中にあり HTML には現れないため、
     * ここでは **どの画面が描かれるか**だけを固定する
     * （文言の回帰は上のソース走査が担当）。
     */
    public function test_login_page_uses_the_localized_screen(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
    }

    /** 日本語ロケールが既定になっている（翻訳ファイルが選ばれる前提） */
    public function test_application_locale_is_japanese_by_default(): void
    {
        $this->assertSame('ja', (string) env('APP_LOCALE', config('app.locale')));
        $this->assertStringContainsString('APP_LOCALE=ja', (string) file_get_contents(base_path('.env.example')));
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
