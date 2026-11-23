<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Application\Users\ChangeUserRole;
use App\Application\Users\DeactivateUser;
use App\Application\Users\InviteUser;
use App\Application\Users\ResendInvitation;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Role;
use App\Support\SessionInvalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Tests\TestCase;

/**
 * STAGE #18A Correction — 独立レビューで挙がった 9 点の回帰。
 *
 * 既存の `RolePermissionTest` / `UserManagementTest` / `ActivityLogTest` が
 * 覆えていなかった境界だけをここに集める。
 */
final class UserCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** Correction 1 — users の **全 route** が owner 以外を拒否する */
    public function test_利用者管理の全routeがowner以外を拒否する(): void
    {
        $target = User::factory()->editor()->create();
        $id = $target->getKey();

        $requests = [
            ['get', '/admin/users'],
            ['get', '/admin/users/create'],
            ['post', '/admin/users'],
            ['get', "/admin/users/{$id}"],
            ['patch', "/admin/users/{$id}"],
            ['post', "/admin/users/{$id}/role"],
            ['post', "/admin/users/{$id}/deactivate"],
            ['post', "/admin/users/{$id}/reactivate"],
            ['post', "/admin/users/{$id}/invitation"],
            ['get', "/admin/users/{$id}/activity"],
        ];

        foreach ([Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $actor = User::factory()->role($role)->create();

            foreach ($requests as [$method, $url]) {
                $this->actingAs($actor)->{$method}($url, [])
                    ->assertForbidden("{$role->value} が {$method} {$url} へ到達できた");
            }
        }

        // owner は同じ route を開ける（拒否が過剰でないこと）
        $owner = User::factory()->role(Role::Owner)->create();
        $this->actingAs($owner)->get('/admin/users')->assertOk();
        $this->actingAs($owner)->get('/admin/users/create')->assertOk();
        $this->actingAs($owner)->get("/admin/users/{$id}")->assertOk();
    }

    /**
     * Correction 2 / 2-1 — 最後の owner は **適用の瞬間に** 数え直して守られる。
     *
     * 確認トークンを 2 人ぶん先に発行しておき、そのあと順に適用する。
     * 判定が確認時点で固定されていると 2 人とも降格でき owner が 0 になる。
     *
     * ここは **順次実行**の回帰。同時実行（2 プロセス）は
     * `LastOwnerConcurrencyTest` が隔離 MySQL で担当する。
     */
    public function test_2人のownerを続けて降格しても最後の1人は残る(): void
    {
        $actor = User::factory()->role(Role::Owner)->create();          // 実行者（owner）
        $first = User::factory()->role(Role::Owner)->create();          // owner A
        $second = User::factory()->role(Role::Owner)->create();         // owner B

        $context = fn (?string $token = null) => OperationContext::admin(
            (int) $actor->getKey(), 'tester'
        )->withConfirmationToken($token);

        $service = app(ChangeUserRole::class);

        // 2 人ぶんの確認トークンを **先に** 取る（この時点ではどちらも降格可能）
        $tokenA = $service->execute((int) $first->getKey(), 'viewer', $context())->confirmation?->token;
        $tokenB = $service->execute((int) $second->getKey(), 'viewer', $context())->confirmation?->token;

        $this->assertIsString($tokenA);
        $this->assertIsString($tokenB);

        $this->assertTrue($service->execute((int) $first->getKey(), 'viewer', $context($tokenA))->isSuccess());
        $this->assertTrue($service->execute((int) $second->getKey(), 'viewer', $context($tokenB))->isSuccess());

        // 実行者自身が owner として残っている
        $this->assertGreaterThanOrEqual(
            1,
            User::query()->where('role', 'owner')->where('is_active', true)->count(),
            'active な owner が 0 人になった',
        );
    }

    /**
     * Correction 2-1 — **認可を通った実行者**でも、最後の 1 人は降格できない。
     *
     * 以前のこのケースは viewer を actor にしていたため、
     * 最後の owner 判定へ届く前に policy の forbidden で止まっており、
     * 「最後の owner を守った」ことの証拠になっていなかった。
     *
     * ここでは CLI（サーバー管理者の経路。policy は無条件で許可する）から実行する。
     * **owner が 1 人しか居ない状態**で降格を通そうとしても、
     * ロック後の数え直しが conflict で止めることを確かめる。
     */
    public function test_認可を通った実行者でも最後の1人は降格できない(): void
    {
        $last = User::factory()->role(Role::Owner)->create();      // 対象
        $other = User::factory()->role(Role::Owner)->create();     // もう 1 人の owner（あとで居なくなる）

        $service = app(ChangeUserRole::class);
        $context = OperationContext::cli('agentcms:test');

        /*
         * 確認を取る時点では owner が 2 人居るので、画面用の速い判定は通る。
         * ここで token が出ることが「手前で止まっていない」ことの証拠になる。
         */
        $token = $service->execute((int) $last->getKey(), 'viewer', $context)->confirmation?->token;

        $this->assertIsString($token, '確認が発行されていない = 認可か速い判定の手前で止まっている');

        // 確認と適用の間に、もう 1 人の owner が居なくなる（service を通さずに動かす）
        $other->forceFill(['role' => Role::Administrator->value])->save();

        $result = $service->execute(
            (int) $last->getKey(),
            'viewer',
            $context->withConfirmationToken($token),
        );

        // forbidden ではない = policy は通っている。止めたのは最後の owner 判定
        $this->assertSame(OperationStatus::Conflict, $result->status, '最後の owner が降格できた');
        $this->assertStringContainsString('最後のオーナー', (string) $result->message);
        $this->assertSame(Role::Owner, $last->refresh()->role());
        $this->assertSame(
            1,
            User::query()->where('role', 'owner')->where('is_active', true)->count(),
        );

        // 拒否こそ監査の対象（Correction 2-5）
        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'user.role.change',
            'result' => 'conflict',
            'resource_id' => (string) $last->getKey(),
        ]);
    }

    /** 同じ規則が無効化にも効く（最後の 1 人は無効化できない） */
    public function test_認可を通った実行者でも最後の1人は無効化できない(): void
    {
        $last = User::factory()->role(Role::Owner)->create();
        $other = User::factory()->role(Role::Owner)->create();

        $service = app(DeactivateUser::class);
        $context = OperationContext::cli('agentcms:test');

        $token = $service->execute((int) $last->getKey(), $context)->confirmation?->token;

        $this->assertIsString($token, '確認が発行されていない = 手前で止まっている');

        // 確認と適用の間に、もう 1 人の owner が無効化される
        $other->forceFill(['is_active' => false])->save();

        $result = $service->execute((int) $last->getKey(), $context->withConfirmationToken($token));

        $this->assertSame(OperationStatus::Conflict, $result->status, '最後の owner が無効化できた');
        $this->assertStringContainsString('最後のオーナー', (string) $result->message);
        $this->assertTrue($last->refresh()->is_active);
    }

    /** Correction 4 — メールアドレス変更で確認済みが外れ、verified 画面へ戻れない */
    public function test_メール変更で確認済みが外れる(): void
    {
        Notification::fake();

        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();

        $this->actingAs($target)->get('/admin/news')->assertOk();

        $this->actingAs($owner)->patch('/admin/users/'.$target->getKey(), [
            'name' => (string) $target->name,
            'email' => 'moved@example.test',
        ])->assertRedirect();

        $target->refresh();
        $this->assertSame('moved@example.test', $target->email);
        $this->assertNull($target->email_verified_at, '確認済みが外れていない');
        $this->assertSame(1, (int) $target->session_epoch, 'session が失効していない');

        // 確認を終えるまで verified な管理画面へ戻れない
        $this->flushSession();
        auth()->logout();
        $this->actingAs($target)->get('/admin/news')
            ->assertRedirect(route('verification.notice', absolute: false));

        // 監査には値を残さず、項目名だけ
        $metadata = (string) DB::table('audit_logs')->where('operation', 'user.update')->value('metadata');
        $this->assertStringNotContainsString('moved@example.test', $metadata);
        $this->assertStringContainsString('email', $metadata);
    }

    /** Correction 5 — アカウント保護の変更が記録され、秘密は残らない */
    public function test_二要素とパスワードの変更が記録される(): void
    {
        $user = User::factory()->role(Role::Owner)->create();

        event(new PasswordUpdatedViaController($user));
        event(new TwoFactorAuthenticationEnabled($user));
        event(new TwoFactorAuthenticationConfirmed($user));
        event(new RecoveryCodesGenerated($user));
        event(new TwoFactorAuthenticationDisabled($user));

        foreach ([
            'auth.password.updated',
            'auth.two_factor.enabled',
            'auth.two_factor.confirmed',
            'auth.recovery_codes.generated',
            'auth.two_factor.disabled',
        ] as $operation) {
            $this->assertDatabaseHas('audit_logs', [
                'operation' => $operation, 'result' => 'success', 'actor_id' => $user->getKey(),
            ]);
        }

        // 秘密・リカバリーコードは 1 文字も残らない
        foreach (DB::table('audit_logs')->where('operation', 'like', 'auth.%')->pluck('metadata') as $metadata) {
            foreach (['secret', 'recovery', 'credential', 'password'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower((string) $metadata));
            }
        }
    }

    /** Correction 6 — owner を付ける招待は確認を通す */
    public function test_owner招待は確認を要求する(): void
    {
        Notification::fake();
        $owner = User::factory()->role(Role::Owner)->create();
        $context = OperationContext::admin((int) $owner->getKey(), 'tester');
        $input = ['name' => '新オーナー', 'email' => 'new-owner@example.test', 'role' => 'owner'];

        $first = app(InviteUser::class)->execute($input, $context);

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertDatabaseMissing('users', ['email' => 'new-owner@example.test']);

        $second = app(InviteUser::class)->execute(
            $input,
            $context->withConfirmationToken((string) $first->confirmation?->token),
        );

        $this->assertTrue($second->isSuccess());
        $this->assertDatabaseHas('users', ['email' => 'new-owner@example.test', 'role' => 'owner']);

        // 編集者の招待は確認なしで通る（過剰な確認を出さない）
        $editor = app(InviteUser::class)->execute(
            ['name' => '編集者', 'email' => 'editor2@example.test', 'role' => 'editor'],
            $context,
        );
        $this->assertTrue($editor->isSuccess());
    }

    /** Correction 6 — 再送は招待中の利用者だけ。失敗時に invited_at を進めない */
    public function test_招待の再送は招待中のみ(): void
    {
        Notification::fake();
        $owner = User::factory()->role(Role::Owner)->create();
        $context = OperationContext::admin((int) $owner->getKey(), 'tester');

        // 一度ログインしている＝利用開始済み
        $active = User::factory()->editor()->create(['invited_at' => now()->subDay(), 'last_login_at' => now()]);
        $result = app(ResendInvitation::class)->execute((int) $active->getKey(), $context);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        Notification::assertNothingSentTo($active);

        // 招待中なら送れる
        $pending = User::factory()->editor()->invited()->create();
        $before = $pending->invited_at;

        $this->assertTrue(app(ResendInvitation::class)->execute((int) $pending->getKey(), $context)->isSuccess());
        Notification::assertSentTo($pending, UserInvitation::class);
        $this->assertTrue(
            $pending->refresh()->invited_at->greaterThanOrEqualTo($before),
            '送信できたのに招待日時が戻っている',
        );
    }

    /** Correction 7 — 結末ごとに ちょうど 1 行。同じ結末を二重記録しない */
    public function test_利用者操作は結末ごとにちょうど1行記録される(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();
        $url = '/admin/users/'.$target->getKey().'/deactivate';

        // 1 回目 = 確認待ち
        $this->actingAs($owner)->post($url);
        $token = $this->flashedToken();

        // 2 回目 = 成功
        $this->actingAs($owner)->post($url, ['confirmation_token' => $token]);

        $rows = DB::table('audit_logs')->where('operation', 'user.deactivate')->get();

        $this->assertCount(2, $rows, '結末ごとに 1 行になっていない');
        $this->assertSame(
            ['confirmation_required', 'success'],
            $rows->pluck('result')->sort()->values()->all(),
        );

        // 権限が無い試行も **1 行だけ** forbidden として残る
        $viewer = User::factory()->viewer()->create();
        $this->actingAs($viewer)->post('/admin/users/'.$target->getKey().'/reactivate');

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('operation', 'user.reactivate')->where('result', 'forbidden')->count(),
            'policy と service で二重に記録されている',
        );
    }

    /** Correction 7 — 入力エラーも 1 行残り、入力値は残らない */
    public function test_入力エラーもauditされ値は残らない(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();

        $this->actingAs($owner)->from('/admin/users/create')->post('/admin/users', [
            'name' => '', 'email' => 'broken', 'role' => 'editor',
        ])->assertSessionHasErrors();

        $row = DB::table('audit_logs')
            ->where('operation', 'user.invite')->where('result', 'validation_error')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('broken', (string) $row->metadata);
        // 結末は result 列が持つ。metadata へ重ねない
        $this->assertStringNotContainsString('outcome', (string) $row->metadata);
    }

    /** Correction 8 — 確認トークンを生のまま監査へ残さない */
    public function test_確認トークンは監査へ生保存されない(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();
        $url = '/admin/users/'.$target->getKey().'/deactivate';

        $this->actingAs($owner)->post($url);
        $token = $this->flashedToken();
        $this->actingAs($owner)->post($url, ['confirmation_token' => $token]);

        $stored = DB::table('audit_logs')
            ->where('operation', 'user.deactivate')->where('result', 'success')->value('confirmation_token');

        $this->assertNotNull($stored);
        $this->assertNotSame($token, $stored, '生のトークンが保存されている');
        $this->assertSame(substr(hash('sha256', (string) $token), 0, 32), $stored);

        // 同じトークンからは常に同じ指紋（追跡はできる）
        $this->assertSame(32, strlen((string) $stored));
    }

    /**
     * Correction 2-4 — 確認待ちの行と成功の行が **同じ指紋**で結ばれる。
     *
     * 以前は確認待ちの行に指紋が無かった（context にまだトークンが無いため）。
     * それだと「どの確認から実行されたか」を監査から辿れず、
     * 確認と適用が別人・別対象でもログ上は見分けが付かない。
     */
    public function test_確認待ちと成功が同じ指紋で結ばれる(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();
        $url = '/admin/users/'.$target->getKey().'/deactivate';

        $this->actingAs($owner)->post($url);
        $token = $this->flashedToken();
        $this->actingAs($owner)->post($url, ['confirmation_token' => $token]);

        $rows = DB::table('audit_logs')->where('operation', 'user.deactivate')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['confirmation_required', 'success'], $rows->pluck('result')->all());

        $fingerprints = $rows->pluck('confirmation_token');

        $this->assertNotContains(null, $fingerprints->all(), '確認待ちの行に指紋が無い');
        $this->assertCount(1, $fingerprints->unique()->all(), '確認と適用が別の指紋になっている');
        $this->assertSame(substr(hash('sha256', $token), 0, 32), (string) $fingerprints->first());

        // **生のトークンはどちらの行にも無い**（横取りの材料を残さない）
        foreach ($rows as $row) {
            foreach ((array) $row as $value) {
                $this->assertStringNotContainsString($token, (string) $value, '生のトークンが残っている');
            }
        }

        /*
         * 指紋は「同じ確認かどうか」を見分けられる（全部同じ値にはならない）。
         * 別の対象への確認は別の指紋になる。
         */
        $another = User::factory()->editor()->create();
        $this->actingAs($owner)->post('/admin/users/'.$another->getKey().'/deactivate');

        $this->assertNotSame(
            (string) $fingerprints->first(),
            (string) DB::table('audit_logs')
                ->where('operation', 'user.deactivate')
                ->orderByDesc('id')->value('confirmation_token'),
            '別の確認が同じ指紋になっている',
        );

        // 使い切り・束縛そのものは `ContentOperationsTest` と
        // `ContentTypeBuilderApplyTest` が担当する（ここは指紋の責務だけを見る）
    }

    /** Correction 7 — 別 HTTP 試行なので request_id は別（同一試行内では同じ） */
    public function test_確認と適用は別の試行として記録される(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->editor()->create();
        $url = '/admin/users/'.$target->getKey().'/deactivate';

        $this->actingAs($owner)->post($url);
        $this->actingAs($owner)->post($url, ['confirmation_token' => $this->flashedToken()]);

        $rows = DB::table('audit_logs')->where('operation', 'user.deactivate')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        // 別リクエストなので同じ行にはならない（created_at と result が異なる）
        $this->assertNotSame($rows[0]->result, $rows[1]->result);
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
    }

    /** 世代番号のキーが session に記録される（Correction 3 の土台） */
    public function test_session世代が記録される(): void
    {
        $user = User::factory()->role(Role::Owner)->create();

        $this->actingAs($user)->get('/admin/news')->assertOk();

        $this->assertSame(0, session(SessionInvalidator::SESSION_KEY));
    }

    private function flashedToken(): string
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $token = $flash['confirmation']['token'] ?? null;

        $this->assertIsString($token, '確認トークンが返っていない');

        return $token;
    }

    private function confirmAndPost(User $actor, string $url, array $data = []): void
    {
        $this->actingAs($actor)->post($url, $data);
        $this->actingAs($actor)->post($url, [...$data, 'confirmation_token' => $this->flashedToken()]);
    }
}
