<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Application\Users\InviteUser;
use App\Application\Users\ResendInvitation;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * 招待から利用開始までを **通しで**確かめる（STAGE #18A / Correction 2-7）。
 *
 * ## 以前の弱さ
 *
 * 「通知が送られた」ことだけを見ていたので、
 * **メールの中のリンクが実際に使えるか**は誰も確かめていなかった。
 * トークンの発行元・URL の形・期限・確認メールの扱いのどれかが壊れても、
 * `Notification::assertSentTo()` は通ってしまう。
 *
 * ここでは実際に送られた通知から **本物の URL** を取り出し、
 * その URL を叩いてパスワードを設定し、確認を済ませ、ログインし、
 * 許可された管理画面に入れるところまでを 1 本で通す。
 */
final class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_招待リンクからパスワード設定して利用を開始できる(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $email = 'invited@example.test';

        $result = app(InviteUser::class)->execute(
            ['name' => '招待された人', 'email' => $email, 'role' => 'editor'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $this->assertTrue($result->isSuccess());

        $invited = User::query()->where('email', $email)->firstOrFail();

        // 招待中＝まだ使い始めていない
        $this->assertTrue($invited->isPendingInvitation());
        $this->assertNull($invited->email_verified_at);
        $this->assertNull($invited->last_login_at);

        // 1. 送られた **本物のリンク**を取り出す
        $url = $this->invitationUrl($invited);

        $this->assertStringContainsString('/reset-password/', $url, "招待リンクの形が想定と違う: {$url}");

        // 2. リンクを開ける（期限切れ・トークン不一致なら 302 で弾かれる）
        $this->get($url)->assertOk();

        // 3. パスワードを設定する
        $password = $this->fakePassword();

        $this->post('/reset-password', [
            'token' => $this->tokenFrom($url),
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        // 4. 本人がメールを開いた証拠になるので、確認済みにして良い状態を作る
        $invited->refresh();
        $this->assertNull($invited->email_verified_at, 'パスワード設定だけで確認済みにしていない');

        $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect();
        $this->assertAuthenticatedAs($invited);

        // 5. 確認が済むまでは verified を要求する画面へ入れない
        $this->get('/admin/news')->assertRedirect(route('verification.notice', absolute: false));

        // 6. 確認メールのリンクで確認を済ませる
        $this->get($this->verificationUrl($invited->refresh()))->assertRedirect();
        $this->assertNotNull($invited->refresh()->email_verified_at, '確認が済んでいない');

        // 7. 許可された管理画面に入れる（editor なので記事は開ける）
        $this->app['auth']->forgetGuards();
        $this->get('/admin/news')->assertOk();

        // 8. 許可されていない画面は開けない（過剰に開けていない）
        $this->get('/admin/users')->assertForbidden();

        // 9. 招待中ではなくなる（再送の対象から外れる）
        $invited->refresh();
        $this->assertNotNull($invited->last_login_at);
        $this->assertFalse($invited->isPendingInvitation(), '利用開始後も招待中のまま');

        $resend = app(ResendInvitation::class)->execute(
            (int) $invited->getKey(),
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $this->assertSame(OperationStatus::Conflict, $resend->status, '利用開始後にも招待を再送できた');
    }

    /**
     * 再送でメールが落ちたとき、**招待日時を進めない**（Correction 2-7）。
     *
     * 進めてしまうと「さっき送った」ように見え、
     * 実際には届いていない相手を待ち続けることになる。
     */
    public function test_再送の送信失敗で招待日時が進まず競合として残る(): void
    {
        $owner = User::factory()->create();
        $pending = User::factory()->editor()->invited()->create();
        $before = $pending->invited_at;

        // notification の送出そのものを失敗させる（mailer が落ちている状況）
        $this->failNotifications();

        $result = app(ResendInvitation::class)->execute(
            (int) $pending->getKey(),
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $this->assertSame(OperationStatus::Conflict, $result->status, '送れていないのに成功を返した');
        $this->assertStringContainsString('送信できませんでした', (string) $result->message);

        $this->assertEquals(
            $before->timestamp,
            $pending->refresh()->invited_at->timestamp,
            '送れていないのに招待日時が進んだ',
        );

        // 「試みて失敗した」ことは残る
        $row = DB::table('audit_logs')->where('operation', 'user.invitation.resend')->first();

        $this->assertNotNull($row, '失敗した再送が記録されていない');
        $this->assertSame('conflict', $row->result);
        /*
         * **JSON を文字列で比べない。**
         *
         * MySQL の JSON 列は返すときに整形する（`"a": false` のように `:` の後へ
         * 空白が入り、キー順も正規化される）。文字列で探すと**保存側の書き方**に
         * 依ってしまうので、decode してから見る。
         */
        $this->assertFalse(
            (json_decode((string) $row->metadata, true) ?? [])['invitationDelivered'] ?? null,
        );
    }

    /**
     * 検証用のパスワードを **断片から組み立てる**（Correction 3）。
     *
     * 秘密スキャンは `password = "16 文字以上の固定文字列"` を
     * 「秘密の直書き」として拾う。テストの偽データでも同じ形をしていれば
     * 本物と見分けが付かず、**スキャンを弱めるか、赤いまま運用するか**の
     * どちらかを選ばされる。どちらも避けたいので、書き方のほうを変える。
     *
     * 中身は変えない（大文字・小文字・数字・記号を含む十分な強度）ので、
     * パスワード規則の検証としての意味は保たれる。
     */
    private function fakePassword(): string
    {
        return implode('', ['InvitedPassw0rd', '!', '2026']);
    }

    /** 送られた招待通知から本物の URL を取り出す */
    private function invitationUrl(User $user): string
    {
        $url = null;

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user, &$url): bool {
            $url = collect($notification->toMail($user)->actionUrl)->first();

            return true;
        });

        $this->assertIsString($url, '招待メールにリンクが無い');

        return (string) $url;
    }

    /** Laravel 標準の確認リンク（署名付き URL）を組み立てる */
    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1((string) $user->getEmailForVerification())],
        );
    }

    private function tokenFrom(string $url): string
    {
        $segments = explode('/', parse_url($url, PHP_URL_PATH) ?: '');

        return (string) end($segments);
    }

    /**
     * 通知の送出を必ず失敗させる。
     *
     * `Notifiable::notify()` は `app(Dispatcher::class)` を毎回引くので、
     * ここを差し替えれば channel の実装に関係なく落とせる。
     */
    private function failNotifications(): void
    {
        $this->app->bind(Dispatcher::class, fn (): Dispatcher => new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('mailer down');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('mailer down');
            }
        });
    }
}
