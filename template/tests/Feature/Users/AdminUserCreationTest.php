<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 管理画面から利用者を増やせること（STAGE #18A 追加要件 A）。
 *
 * ## パスワードは誰も見ない
 *
 * 管理者が仮パスワードを決めて伝える方式にしない。
 * 仮パスワードは **画面・応答・監査ログのどこかに必ず残り**、
 * それを見られた時点で他人になりすませる。
 * ここでは利用者の行だけを作り、本人が自分でパスワードを決める
 * リンクをメールで送る（`InviteUser` / `UserInvitation`）。
 *
 * ## 何を確かめるか
 *
 * 画面が開けることではなく、**作成 → 一覧反映 → 招待メール →
 * そのリンクでパスワードを設定できる**ところまで通ること。
 */
final class AdminUserCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ownerは招待画面から利用者を作成し本人が使い始められる(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $email = 'newcomer@example.test';

        /*
         * 1. 一覧から招待画面へ入れる（導線が生きている）。
         *
         * 一覧は Inertia の SPA なので、返る HTML にリンクは埋まっていない。
         * ここでは **画面のソースに導線があること**と
         * **その URL が実際に開けること**の 2 つで確かめる
         * （見た目としてボタンが出ているかは隔離ブラウザ側で見る）。
         */
        $this->assertStringContainsString(
            '/admin/users/create',
            (string) file_get_contents(base_path('resources/js/pages/admin/users/Index.vue')),
            '一覧に招待への導線が無い',
        );

        $this->actingAs($owner)->get('/admin/users')->assertOk();
        $this->actingAs($owner)->get('/admin/users/create')->assertOk();

        // 2. 作成する。**パスワードは 1 文字も送らない**
        $this->actingAs($owner)->post('/admin/users', [
            'name' => '新入りさん',
            'email' => $email,
            'role' => Role::Editor->value,
        ])->assertRedirect('/admin/users');

        // 3. 一覧に出る
        $created = User::query()->where('email', $email)->firstOrFail();

        $this->assertSame(Role::Editor, $created->role());
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->isPendingInvitation(), '招待中になっていない');
        $this->assertNull($created->email_verified_at);

        /*
         * 一覧の props に載る（Inertia なので HTML ではなく payload を見る）。
         * 並び順に依存しないよう、検索で 1 件へ絞ってから確かめる。
         */
        $this->actingAs($owner)->get('/admin/users?q='.urlencode($email))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('users', 1)
                ->where('users.0.name', '新入りさん')
                ->where('users.0.email', $email),
            );

        // 4. 招待メールが届き、そのリンクでパスワードを設定できる
        $url = null;

        Notification::assertSentTo(
            $created,
            UserInvitation::class,
            function (UserInvitation $notification) use ($created, &$url): bool {
                $url = $notification->toMail($created)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($url);

        // ここから先は **招待された本人**（別のブラウザ）。管理者の session は捨てる
        auth()->logout();
        $this->flushSession();

        $this->get((string) $url)->assertOk();

        $segments = explode('/', (string) parse_url((string) $url, PHP_URL_PATH));
        $password = $this->fakePassword();

        $this->post('/reset-password', [
            'token' => end($segments),
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect();
        $this->assertAuthenticatedAs($created);
    }

    /** 作成した利用者のパスワードは **どこにも出さない** */
    public function test_仮パスワードを画面にも監査にも出さない(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $email = 'nopassword@example.test';

        $response = $this->actingAs($owner)->post('/admin/users', [
            'name' => '秘密なし', 'email' => $email, 'role' => Role::Viewer->value,
        ]);

        $created = User::query()->where('email', $email)->firstOrFail();

        // 応答にも画面にもハッシュや平文は出さない
        $this->assertStringNotContainsString((string) $created->password, $response->getContent());

        $detail = $this->actingAs($owner)->get('/admin/users/'.$created->getKey());
        $detail->assertOk();
        $this->assertStringNotContainsString((string) $created->password, $detail->getContent());
        $this->assertStringNotContainsString('password', strtolower($this->userPayload($detail->getContent())));

        // 監査にも残さない（項目名すら password を含まない）
        $row = DB::table('audit_logs')->where('operation', 'user.invite')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('password', strtolower((string) $row->metadata));
        $this->assertStringNotContainsString($email, (string) $row->metadata);
    }

    /** owner 以外は作成できない（画面も POST も） */
    public function test_owner以外は利用者を作成できない(): void
    {
        Notification::fake();

        foreach ([Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $actor = User::factory()->role($role)->create();

            $this->actingAs($actor)->get('/admin/users/create')->assertForbidden();
            $this->actingAs($actor)->post('/admin/users', [
                'name' => '不正', 'email' => "x-{$role->value}@example.test", 'role' => 'owner',
            ])->assertForbidden();

            $this->assertDatabaseMissing('users', ['email' => "x-{$role->value}@example.test"]);
        }
    }

    /** 画面の文言が実態（招待）を表している */
    public function test_作成画面は招待であることを日本語で伝える(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/users/Create.vue'));

        $this->assertStringContainsString('利用者を招待', $source);
        $this->assertStringContainsString('パスワードは本人が設定します', $source);

        // 受け渡し方法の説明（STAGE #18B）。どちらの経路も日本語で意味が分かる
        $this->assertStringContainsString('メールで招待する（推奨）', $source);
        $this->assertStringContainsString('パスワード設定の案内を送ります', $source);
        $this->assertStringContainsString('URL を受け取って自分で渡す', $source);
        $this->assertStringContainsString('1 度だけ表示', $source);

        // 管理者がパスワードを決める UI は無い
        $this->assertStringNotContainsString('type="password"', $source);
        $this->assertStringNotContainsString('仮パスワード', $source);
    }

    /** 詳細画面が返した Inertia payload のうち user 部分だけを取り出す */
    private function userPayload(string $html): string
    {
        return preg_match('/data-page="([^"]*)"/', $html, $m) === 1
            ? html_entity_decode($m[1])
            : $html;
    }

    /** 秘密スキャンが本物と見分けられるよう、断片から組み立てる（Correction 3） */
    private function fakePassword(): string
    {
        return implode('', ['InvitedPassw0rd', '!', '2026']);
    }
}
