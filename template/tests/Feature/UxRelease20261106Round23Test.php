<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Pages\Reading\TitleReading;
use App\Application\Users\InviteUser;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Capability;
use App\Support\Role;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 公開前の UX 一周 23 周目（2026-11-06・写真館・round23-grade.md）の回帰。
 *
 * - #2 招待された人がログインすると「お送りしたメールのリンクを開いて」と出るのに、確認メールが送られていなかった
 * - #3 招待されて初めて開くパスワードの画面が「パスワードの再設定」だった
 * - #4 七五三 → nana-go-san（IPADIC の読みがずれる語は例外の表で）
 * - #6 権限の無い管理画面が白地の 403 だった
 */
final class UxRelease20261106Round23Test extends TestCase
{
    use RefreshDatabase;

    public function test_読みがずれる語は例外の表で読む(): void
    {
        $this->assertSame('shichi-go-san-no-o-shashin', TitleReading::slug('七五三のお写真'));
        $this->assertSame('hatachi-no-kinen-shashin', TitleReading::slug('二十歳の記念写真'));
        $this->assertSame('isshou-mochi', TitleReading::slug('一升餅'));
    }

    public function test_例外の表の語は全部テストの例に入っている(): void
    {
        $examples = json_decode((string) file_get_contents(base_path('tests/fixtures/reading-examples.json')), true);
        $titles = implode("\n", array_map(static fn (array $row): string => (string) $row[0], is_array($examples) ? $examples : []));

        foreach (array_keys(TitleReading::exceptions()) as $word) {
            $this->assertStringContainsString($word, $titles, "例外の表の「{$word}」を含む例が reading-examples.json に無い");
        }

        $this->assertNotEmpty(TitleReading::exceptions());
    }

    public function test_招待された人がログインすると確認メールが届く_続けてログインしても1通(): void
    {
        Notification::fake();
        [$invited, $password] = $this->acceptInvitation();

        $this->post('/login', ['email' => $invited->email, 'password' => $password])->assertRedirect();
        Notification::assertSentToTimes($invited, VerifyEmail::class, 1);

        $this->post('/logout');
        $this->post('/login', ['email' => $invited->email, 'password' => $password])->assertRedirect();
        Notification::assertSentToTimes($invited, VerifyEmail::class, 1);
    }

    public function test_確認済みの人には送らない(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_招待されて初めて開くパスワードの画面は設定と言う(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $invited = $this->invite($owner, 'first@example.test');
        $url = $this->invitationUrl($invited);

        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/ResetPassword')
            ->where('firstTime', true));

        // トークンが合わなければ「招待中か」を言わない
        $this->get('/reset-password/wrong-token?email='.urlencode('first@example.test'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('firstTime', false));
    }

    public function test_権限の無い管理画面は管理画面の枠で誰なら見られるかを言う(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['role' => Role::Administrator->value])->save();
        $roles = implode('・', array_map(
            static fn (Role $role): string => $role->label(),
            array_values(array_filter(Role::cases(), static fn (Role $role): bool => $role->can(Capability::UserManage))),
        ));

        $this->actingAs($admin)->get('/admin/users')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/Forbidden')
                ->where('message', "この画面は{$roles}だけが見られます。")
                ->where('needed', '利用者の管理')
                ->has('backUrl'));

        // API・JSON は今までどおり（画面を返さない）
        $this->actingAs($admin)->getJson('/admin/users')->assertForbidden()->assertHeaderMissing('X-Inertia');
    }

    /** @return array{0: User, 1: string} */
    private function acceptInvitation(): array
    {
        $owner = User::factory()->create();
        $invited = $this->invite($owner, 'invited23@example.test');
        $url = $this->invitationUrl($invited);
        $segments = explode('/', (string) parse_url($url, PHP_URL_PATH));
        $password = implode('', ['Round23Passw0rd', '!', '2026']);

        $this->post('/reset-password', [
            'token' => (string) end($segments),
            'email' => $invited->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        return [$invited->refresh(), $password];
    }

    private function invite(User $owner, string $email): User
    {
        $result = app(InviteUser::class)->execute(
            ['name' => '鈴木 二人目', 'email' => $email, 'role' => 'administrator'],
            OperationContext::admin((int) $owner->getKey(), '管理者'),
        );
        $this->assertTrue($result->isSuccess());

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function invitationUrl(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user, &$url): bool {
            $url = collect($notification->toMail($user)->actionUrl)->first();

            return true;
        });

        return (string) $url;
    }
}
