<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Application\Users\InviteUser;
use App\Application\Users\UpdateUser;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * メールが送れなかったときの扱い（STAGE #18A / Correction 2-8）。
 *
 * ## 何が壊れていたか
 *
 * `UpdateUser` はメールアドレスを保存し、確認済みを外し、session を失効させたあとで
 * `sendEmailVerificationNotification()` を **素で** 呼んでいた。
 * mailer が落ちていると例外がそのまま外へ出て
 *
 * - 変更は commit 済み（アドレスは変わり、本人はログインできない）
 * - 画面には 500
 * - 監査は 1 行も残らない（記録は結末を返す直前にあるため到達しない）
 *
 * という、**起きたことが誰にも分からない**状態になる。
 * 直したのは「送信の失敗を結末として返す」こと。
 */
final class UserMailFailureTest extends TestCase
{
    use RefreshDatabase;

    /** 送信が落ちても 500 にせず、変更は残し、監査も残す */
    public function test_確認メールが送れなくても更新は成功として扱われ記録される(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create(['email' => 'before@example.test']);

        $this->failNotifications();

        $result = app(UpdateUser::class)->execute(
            (int) $target->getKey(),
            ['name' => (string) $target->name, 'email' => 'after@example.test'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        // 例外にしない（500 にしない）
        $this->assertSame(OperationStatus::Success, $result->status);

        // **何を送ろうとして落ちたか**が明示される
        $this->assertSame(UpdateUser::DELIVERY_VERIFICATION, $result->meta['deliveryKind']);
        $this->assertFalse($result->meta['delivered'], '送信失敗が伝わっていない');

        // 状態変更は保持する（巻き戻さない）
        $target->refresh();
        $this->assertSame('after@example.test', $target->email);
        $this->assertNull($target->email_verified_at, '確認済みが外れていない');
        $this->assertSame(1, (int) $target->session_epoch, 'session が失効していない');

        // 監査は残り、**メールアドレスそのものは残らない**
        $row = DB::table('audit_logs')->where('operation', 'user.update')->first();

        $this->assertNotNull($row, '監査が残っていない');
        $this->assertSame('success', $row->result);
        /*
         * **JSON を文字列で比べない。**
         *
         * MySQL の JSON 列は返すときに整形する（`"a": false` のように `:` の後へ
         * 空白が入り、キー順も正規化される）。文字列で探すと**保存側の書き方**に
         * 依ってしまうので、decode してから見る。
         */
        $metadata = json_decode((string) $row->metadata, true) ?? [];

        $this->assertSame('verification', $metadata['deliveryKind'] ?? null);
        $this->assertFalse($metadata['delivered'] ?? null);
        $this->assertStringNotContainsString('after@example.test', (string) $row->metadata);
        $this->assertStringNotContainsString('before@example.test', (string) $row->metadata);
    }

    /**
     * 画面には日本語で「何が起きたか」「次に何をすればよいか」が出る。
     *
     * **案内する手順は実在するものだけ**（Correction 3）。
     * 以前は「同じ内容で保存し直すと再送されます」と出していたが、
     * 2 回目はメールアドレスが変わらないので送信処理へ入らない。
     * 効かない手順を案内すると、利用者は同じ操作を繰り返して詰まる。
     */
    public function test_送信失敗はadmin画面に実在する導線で伝わる(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create();

        $this->failNotifications();

        $response = $this->actingAs($owner)->patch('/admin/users/'.$target->getKey(), [
            'name' => (string) $target->name,
            'email' => 'moved@example.test',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', function (string $message): bool {
            return str_contains($message, '確認メールを送信できませんでした')
                // 本人がログインして再送する導線（実在する）
                && str_contains($message, 'ログイン')
                && str_contains($message, '再送')
                // **効かない手順は案内しない**
                && ! str_contains($message, '保存し直す')
                // 相手は使い始めているので、招待の話は混ぜない（Correction 4）
                && ! str_contains($message, '招待');
        });

        // 500 になっていない = 変更が宙に浮かない
        $this->assertSame('moved@example.test', $target->refresh()->email);
    }

    /**
     * 案内した導線を **実際に通す**（Correction 3）。
     *
     * 文言だけを assert しても「その手順で本当に再送できるか」は分からない。
     * 送信が落ちた直後の状態から、本人が新しいアドレスでログインし、
     * 確認の案内画面から再送して、**確認メールが実際に飛ぶ**ところまで歩く。
     */
    public function test_案内どおりに本人がログインして確認メールを再送できる(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->create();

        // 1. 送信が落ちた状態を作る（アドレスは変わり、未確認になる）
        $this->failNotifications();

        $failed = app(UpdateUser::class)->execute(
            (int) $target->getKey(),
            ['name' => (string) $target->name, 'email' => 'moved@example.test'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $this->assertFalse($failed->meta['delivered']);
        $this->assertSame(UpdateUser::DELIVERY_VERIFICATION, $failed->meta['deliveryKind']);
        $this->assertNull($target->refresh()->email_verified_at);

        // 2. mailer が復旧する
        $this->restoreNotifications();
        Notification::fake();

        // 3. 本人が **新しいアドレス**でログインする
        $this->post('/login', ['email' => 'moved@example.test', 'password' => 'password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($target);

        // 4. 確認が済むまで管理画面は使えず、案内画面へ誘導される
        $this->get('/admin/news')->assertRedirect(route('verification.notice', absolute: false));
        $this->get('/email/verify')->assertOk();

        // 5. その画面から再送できる（ここが案内した導線の実体）
        $this->post('/email/verification-notification')->assertRedirect();

        Notification::assertSentTo($target, VerifyEmail::class);
    }

    /** 送れた場合はいつもどおり（過剰な警告を出さない） */
    public function test_送信できた場合は警告を出さない(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $target = User::factory()->editor()->create();

        $result = app(UpdateUser::class)->execute(
            (int) $target->getKey(),
            ['name' => (string) $target->name, 'email' => 'ok@example.test'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(UpdateUser::DELIVERY_VERIFICATION, $result->meta['deliveryKind']);
        $this->assertTrue($result->meta['delivered']);

        // 使い始めている相手に招待をやり直さない
        Notification::assertNotSentTo($target, UserInvitation::class);
        Notification::assertSentTo($target, VerifyEmail::class);
    }

    /**
     * 招待中の相手のメールを変えたら、**招待をやり直す**（Correction 4）。
     *
     * ## 確認メールでは復帰できない
     *
     * 招待中の利用者はまだパスワードを決めていない。
     * 「メールアドレスを確認してください」だけ届いてもログインできず、
     * しかも旧アドレス宛に出した招待リンクは **新しいアドレスでは通らない**
     * （reset token はアドレスへ紐づく）。
     * つまり何も送り直さないと、利用開始の手段が完全に消える。
     */
    public function test_招待中の相手はメール変更で招待をやり直す(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $target = User::factory()->editor()->invited()->create([
            'email' => 'typo@example.test',
            // 「進んだ」を秒単位で確実に見分けるため、過去の招待にしておく
            'invited_at' => now()->subDay(),
        ]);
        $before = $target->invited_at;

        $this->assertTrue($target->isPendingInvitation());

        $result = app(UpdateUser::class)->execute(
            (int) $target->getKey(),
            ['name' => (string) $target->name, 'email' => 'fixed@example.test'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(UpdateUser::DELIVERY_INVITATION, $result->meta['deliveryKind']);
        $this->assertTrue($result->meta['delivered']);

        // **招待が送られ、確認メールは送られない**
        Notification::assertSentTo($target, UserInvitation::class);
        Notification::assertNotSentTo($target, VerifyEmail::class);

        // 送れたので招待日時は進む
        $target->refresh();
        $this->assertSame('fixed@example.test', $target->email);
        $this->assertTrue($target->invited_at->greaterThan($before), '招待日時が進んでいない');
        $this->assertTrue($target->isPendingInvitation(), '招待中でなくなっている');
    }

    /** 送り直した招待のリンクが **新しいアドレスで実際に使える** */
    public function test_やり直した招待リンクで新しいアドレスからパスワードを設定できる(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $target = User::factory()->editor()->invited()->create(['email' => 'typo@example.test']);

        app(UpdateUser::class)->execute(
            (int) $target->getKey(),
            ['name' => (string) $target->name, 'email' => 'fixed@example.test'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );

        $url = null;

        Notification::assertSentTo(
            $target->refresh(),
            UserInvitation::class,
            function (UserInvitation $notification) use ($target, &$url): bool {
                $url = $notification->toMail($target)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($url);
        $this->assertStringContainsString('fixed%40example.test', (string) $url, '旧アドレス宛のリンクになっている');

        // リンクを開いて、新しいアドレスでパスワードを設定できる
        $this->get((string) $url)->assertOk();

        $segments = explode('/', (string) parse_url((string) $url, PHP_URL_PATH));
        $password = $this->fakePassword();

        $this->post('/reset-password', [
            'token' => end($segments),
            'email' => 'fixed@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => 'fixed@example.test', 'password' => $password])->assertRedirect();
        $this->assertAuthenticatedAs($target);
    }

    /** 招待のやり直しが送れなかったとき、招待日時を進めず正しい導線を出す */
    public function test_招待のやり直しが送れなくても状態は保たれ招待再送を案内する(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->editor()->invited()->create([
            'email' => 'typo@example.test',
            'invited_at' => now()->subDay(),
        ]);
        $before = $target->invited_at;

        $this->failNotifications();

        $response = $this->actingAs($owner)->patch('/admin/users/'.$target->getKey(), [
            'name' => (string) $target->name,
            'email' => 'fixed@example.test',
        ]);

        // 500 にしない。メール変更自体は保持する
        $response->assertRedirect();
        $target->refresh();
        $this->assertSame('fixed@example.test', $target->email);

        // 送れていないので招待日時は進めない
        $this->assertEquals($before->timestamp, $target->invited_at->timestamp, '送れていないのに招待日時が進んだ');

        // 招待中の相手には **招待の再送**を案内する（ログインは案内しない）
        $response->assertSessionHas('success', function (string $message): bool {
            return str_contains($message, '招待メールを送信できませんでした')
                && str_contains($message, '招待を再送')
                && ! str_contains($message, 'ログインすると');
        });

        // 監査は success のまま、配送失敗が種別つきで分かる。値は残さない
        $row = DB::table('audit_logs')->where('operation', 'user.update')->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->result);
        /*
         * **JSON を文字列で比べない。**
         *
         * MySQL の JSON 列は返すときに整形する（`"a": false` のように `:` の後へ
         * 空白が入り、キー順も正規化される）。文字列で探すと**保存側の書き方**に
         * 依ってしまうので、decode してから見る。
         */
        $metadata = json_decode((string) $row->metadata, true) ?? [];

        $this->assertSame('invitation', $metadata['deliveryKind'] ?? null);
        $this->assertFalse($metadata['delivered'] ?? null);
        $this->assertStringNotContainsString('fixed@example.test', (string) $row->metadata);
        $this->assertStringNotContainsString('typo@example.test', (string) $row->metadata);
    }

    /** 招待の成功時は、招待を送ったことを正確に伝える */
    public function test_招待のやり直しが成功したら招待送信として案内する(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $target = User::factory()->editor()->invited()->create(['email' => 'typo@example.test']);

        $this->actingAs($owner)->patch('/admin/users/'.$target->getKey(), [
            'name' => (string) $target->name,
            'email' => 'fixed@example.test',
        ])->assertSessionHas('success', function (string $message): bool {
            return str_contains($message, '招待メールを送信しました')
                && ! str_contains($message, '確認メール');
        });
    }

    /** 秘密スキャンが本物と見分けられるよう、断片から組み立てる（Correction 3） */
    private function fakePassword(): string
    {
        return implode('', ['InvitedPassw0rd', '!', '2026']);
    }

    /**
     * オーナー招待の確認は **表示した内容ごと**束縛される（Correction 2-8）。
     *
     * 確認画面には名前が出る。名前が束縛に入っていないと、
     * 「田中さんをオーナーにしますか？」で承認させたトークンを使って
     * **別の表示名**の利用者を作れてしまう（確認内容の差し替え）。
     */
    public function test_owner招待の確認は表示名まで束縛される(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $context = OperationContext::admin((int) $owner->getKey(), '管理者');
        $service = app(InviteUser::class);

        $shown = ['name' => '田中', 'email' => 'new-owner@example.test', 'role' => 'owner'];

        $first = $service->execute($shown, $context);
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $token = (string) $first->confirmation?->token;

        // 承認したのは「田中」。名前だけ差し替えて適用しようとする
        $swapped = $service->execute(
            [...$shown, 'name' => '別人'],
            $context->withConfirmationToken($token),
        );

        $this->assertSame(
            OperationStatus::ConfirmationRequired,
            $swapped->status,
            '確認した内容と違う名前で作成できた',
        );
        $this->assertDatabaseMissing('users', ['email' => 'new-owner@example.test']);

        // 承認したとおりの内容なら通る（過剰に厳しくない）
        $applied = $service->execute($shown, $context->withConfirmationToken($token));

        $this->assertTrue($applied->isSuccess());
        $this->assertDatabaseHas('users', ['name' => '田中', 'email' => 'new-owner@example.test', 'role' => 'owner']);
    }

    /**
     * 落としていた通知の送出を元へ戻す。
     *
     * `failNotifications()` は `Dispatcher` へ具体束縛を置くので、
     * `Notification::fake()`（`ChannelManager` を差し替える）だけでは戻らない。
     * 既定の解決先へ委ねる束縛に置き直して、fake が効くようにする。
     */
    private function restoreNotifications(): void
    {
        $this->app->bind(Dispatcher::class, fn (): Dispatcher => $this->app->make(ChannelManager::class));
    }

    /** 通知の送出を必ず失敗させる（mailer が落ちている状況） */
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
