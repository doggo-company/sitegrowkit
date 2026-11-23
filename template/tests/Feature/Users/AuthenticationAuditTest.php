<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

/**
 * 認証まわりの記録（STAGE #18A / Correction 2-6）。
 *
 * ## 以前の弱さ
 *
 * パスキーの記録は `property_exists()` で event の形を推測しており、
 * テストも **本物のイベントを流していなかった**。
 * パッケージ側の property 名が変わっても気づけないうえ、
 * 「資格情報を載せていない」ことも実物では確かめていなかった。
 *
 * ここでは `Laravel\Passkeys` の **本物の event** に本物の `Passkey` を載せて流す。
 */
final class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    /** パスキーの登録が actor / resource つきで残り、資格情報は残らない */
    public function test_パスキー登録が記録され資格情報は残らない(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());

        $user = User::factory()->create();
        $passkey = $this->makePasskey($user);

        PasskeyRegistered::dispatch($user, $passkey);

        $row = DB::table('audit_logs')->where('operation', 'auth.passkey.registered')->first();

        $this->assertNotNull($row, 'パスキー登録が記録されていない');
        $this->assertSame((int) $user->getKey(), (int) $row->actor_id);
        $this->assertSame('user', $row->resource_type);
        $this->assertSame((string) $user->getKey(), $row->resource_id);
        $this->assertSame('success', $row->result);
        $this->assertSame('external_effect', $row->classification);

        $this->assertSecretFree($row, $passkey);

        // どの鍵かは追える（資格情報ではなく行 ID で）
        $metadata = (array) json_decode((string) $row->metadata, true);
        $this->assertSame((int) $passkey->getKey(), (int) $metadata['passkeyId']);
    }

    /** パスキーの削除も同じ形で残る */
    public function test_パスキー削除が記録され資格情報は残らない(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());

        $user = User::factory()->create();
        $passkey = $this->makePasskey($user);

        PasskeyDeleted::dispatch($user, $passkey);

        $row = DB::table('audit_logs')->where('operation', 'auth.passkey.deleted')->first();

        $this->assertNotNull($row, 'パスキー削除が記録されていない');
        $this->assertSame((int) $user->getKey(), (int) $row->actor_id);
        $this->assertSame((string) $user->getKey(), $row->resource_id);
        $this->assertSecretFree($row, $passkey);
    }

    /**
     * パスワード変更は **代表 HTTP route** で本当に発火する。
     *
     * このアプリは Fortify の既定 Controller ではなく
     * `SecurityController::update` で更新するので、event を明示的に
     * 投げていないと listener は一度も呼ばれない（Correction 2-6）。
     */
    public function test_パスワード変更がhttp経路で記録される(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'NewPassw0rd!2026',
                'password_confirmation' => 'NewPassw0rd!2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'auth.password.updated',
            'actor_id' => $user->getKey(),
            'result' => 'success',
        ]);

        $this->assertNoSecretsInAuthLogs();
    }

    /** 二要素の有効化も代表 HTTP route で発火する */
    public function test_二要素の有効化がhttp経路で記録される(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'auth.two_factor.enabled',
            'actor_id' => $user->getKey(),
            'result' => 'success',
        ]);

        $this->assertNoSecretsInAuthLogs();
    }

    /** ログイン成功と失敗が代表 HTTP route で記録され、生のメールは残らない */
    public function test_ログインの成否がhttp経路で記録される(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['operation' => 'auth.login.failed', 'result' => 'forbidden']);
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'auth.login', 'result' => 'success', 'actor_id' => $user->getKey(),
        ]);

        // 既知の利用者なので actor_id で足りる。生のメールは残さない
        foreach (DB::table('audit_logs')->where('operation', 'like', 'auth.%')->get() as $row) {
            $this->assertStringNotContainsString((string) $user->email, (string) $row->metadata);
        }
    }

    private function assertSecretFree(object $row, Passkey $passkey): void
    {
        $serialized = json_encode((array) $row, JSON_UNESCAPED_UNICODE);

        foreach ([
            (string) $passkey->credential_id,
            (string) json_encode($passkey->credential),
            'credential',
            'secret',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                strtolower($forbidden),
                strtolower((string) $serialized),
                "監査へ [{$forbidden}] が残っている",
            );
        }
    }

    private function assertNoSecretsInAuthLogs(): void
    {
        foreach (DB::table('audit_logs')->where('operation', 'like', 'auth.%')->pluck('metadata') as $metadata) {
            foreach (['secret', 'recovery', 'credential', 'password', 'token'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower((string) $metadata));
            }
        }
    }

    private function makePasskey(User $user): Passkey
    {
        $passkey = new Passkey;
        $passkey->forceFill([
            'user_id' => $user->getKey(),
            'name' => 'テスト用の鍵',
            'credential_id' => 'credential-id-'.uniqid(),
            'credential' => ['publicKey' => 'super-secret-public-key-material'],
        ])->save();

        return $passkey;
    }
}
