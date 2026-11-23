<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\OperationContext;
use App\Application\Users\ChangeUserRole;
use App\Application\Users\DeactivateUser;
use App\Models\User;
use App\Support\SessionInvalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 権限が変わった session が生き残らないことの実証（STAGE #18A / Correction 2-2）。
 *
 * ## 以前のテストが証明できていなかったこと
 *
 * 変更後に `actingAs()` を呼び直していたため、検証していたのは
 * 「**新しく作った** session が弾かれるか」だった。
 * 実際に守りたいのは「**開いたままの** session が次のリクエストで死ぬか」であり、
 * それは actingAs を挟むと再現できない。
 *
 * ここでは
 *
 * 1. 対象が本物の `POST /login` でログインし、
 * 2. その session を握ったまま管理画面を開き、
 * 3. **別人（owner）が Application Service を直接実行**して権限を動かし、
 * 4. 対象の *次の* リクエストがどうなるかを見る。
 *
 * ## driver ごとに死に方が違う
 *
 * | driver | 何が起きるか | 見え方 |
 * | --- | --- | --- |
 * | `database` | session 行そのものが消える | 未認証として login へ |
 * | `array` | 行を消せない。世代番号の不一致で弾く | 「権限が変更されたため」エラー付きで login へ |
 *
 * 両方を **別々に**確かめる。片方だけだと、行削除に頼った実装でも
 * 世代番号に頼った実装でも通ってしまい、多重防御になっているか分からない。
 */
final class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    /** database driver — session 行の削除で落ちる */
    public function test_database_driverでは行削除でログアウトする(): void
    {
        config(['session.driver' => 'database']);

        $target = $this->loginAsTarget();
        $this->assertSame(1, $this->sessionRows($target), 'database driver なのに session 行が無い');

        $this->demoteFromAnotherOwner($target);

        // 行が消えている＝この driver の第一防御が効いている
        $this->assertSame(0, $this->sessionRows($target), 'session 行が残っている');

        // 握ったままの cookie で次のリクエストを送っても入れない
        $this->nextRequest()->get('/admin/news')->assertRedirect('/login');
        $this->assertGuest();
    }

    /** array driver — 行を消せないので世代番号で落ちる */
    public function test_array_driverでは世代不一致でログアウトする(): void
    {
        config(['session.driver' => 'array']);

        $target = $this->loginAsTarget();

        // この driver では消せる行が無い（行削除に頼れないことの確認）
        $this->assertSame(0, $this->sessionRows($target));

        $before = session(SessionInvalidator::SESSION_KEY);
        $this->assertSame(0, $before, 'ログイン時に世代が刻まれていない');

        $this->demoteFromAnotherOwner($target);

        // session が持っている世代は **古いまま**（ここが不一致の材料）
        $this->assertSame($before, session(SessionInvalidator::SESSION_KEY));
        $this->assertSame(1, (int) $target->refresh()->session_epoch);

        $this->nextRequest()->get('/admin/news')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => '権限が変更されたため、ログインし直してください。']);
        $this->assertGuest();
    }

    /** 無効化でも同じ（権限変更に限らない） */
    public function test_無効化でも開いたままのsessionが死ぬ(): void
    {
        config(['session.driver' => 'array']);

        $target = $this->loginAsTarget();
        $owner = User::factory()->create();
        $context = OperationContext::admin((int) $owner->getKey(), 'owner');

        $service = app(DeactivateUser::class);
        $token = $service->execute((int) $target->getKey(), $context)->confirmation?->token;
        $this->assertIsString($token);
        $this->assertTrue(
            $service->execute((int) $target->getKey(), $context->withConfirmationToken($token))->isSuccess(),
        );

        $this->nextRequest()->get('/admin/news')->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * 記録の無い session は信用しない（Correction 2-2 の窓を閉じたことの実証）。
     *
     * ログインの瞬間に世代を刻むので、正規の経路ならここは null にならない。
     * 逆に null の session を持ち込まれたら **変更後の値を初期値として
     * 受け入れず**、ログインし直させる。
     */
    public function test_世代の記録が無いsessionは受け入れない(): void
    {
        config(['session.driver' => 'array']);

        $target = $this->loginAsTarget();

        // 世代の記録だけを落とす（ログイン前に確立された古い session を模す）
        session()->forget(SessionInvalidator::SESSION_KEY);
        session()->save();

        $this->nextRequest()->get('/admin/news')->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * 「次のリクエスト」を本物に近づける。
     *
     * テストでは container が使い回されるので、`SessionGuard` が
     * **前のリクエストで解決した利用者オブジェクト**を握ったままになる。
     * それだと middleware は古い `is_active` / `session_epoch` を見てしまい、
     * 弾かれるはずの session が通ってしまう（実際の HTTP では毎回新しい）。
     *
     * `actingAs()` は使わない — あれは session 世代を刻み直すので
     * 「入り直した」ことになり、検証したい状況が消える。
     */
    private function nextRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** 本物のログイン。以降はこの session を握ったまま検証する */
    private function loginAsTarget(): User
    {
        $target = User::factory()->editor()->create();

        $this->post('/login', ['email' => $target->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($target);

        // ログインした session で管理画面が開けている（ここが起点）
        $this->get('/admin/news')->assertOk();

        return $target;
    }

    /**
     * 別の owner が **HTTP を介さず** Application Service を直接実行して降格する。
     *
     * こうすることで対象の session はテストクライアント側に残ったままになり、
     * 「開いたままのタブ」を正しく再現できる。
     */
    private function demoteFromAnotherOwner(User $target): void
    {
        $owner = User::factory()->create();
        $context = OperationContext::admin((int) $owner->getKey(), 'owner');

        $service = app(ChangeUserRole::class);
        $token = $service->execute((int) $target->getKey(), 'viewer', $context)->confirmation?->token;

        $this->assertIsString($token, '確認が発行されていない');
        $this->assertTrue(
            $service->execute(
                (int) $target->getKey(),
                'viewer',
                $context->withConfirmationToken($token),
            )->isSuccess(),
            '降格に失敗した',
        );

        $this->assertSame(1, (int) $target->refresh()->session_epoch, '世代が進んでいない');
    }

    private function sessionRows(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->getKey())->count();
    }
}
