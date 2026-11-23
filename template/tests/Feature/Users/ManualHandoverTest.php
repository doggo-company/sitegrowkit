<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use App\Application\Users\InviteUser;
use App\Application\Users\IssueSetupLink;
use App\Application\Users\PasswordSetupLink;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Role;
use App\Support\SetupLinkVault;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use RuntimeException;
use Tests\TestCase;

/**
 * 代理作成（手動受け渡し）— PHASE WP-BASELINE / STAGE #18B。
 *
 * ## 何のための機能か
 *
 * 制作会社が顧客の利用者を代理で作るとき、相手のメールが使えないことがある。
 * そこでメールを介さず、**1 回きり・期限つきのパスワード設定 URL** を
 * 管理者が受け取り、LINE など安全な別経路で本人へ渡す。
 *
 * ## ここで守りたい線
 *
 * | 守ること | 理由 |
 * | --- | --- |
 * | URL は 1 回だけ表示 | URL 自体が一時的な鍵。画面に残せば誰でも拾える |
 * | URL / token をどこにも保存しない | 監査・ログ・DB へ複製した時点で鍵が増える |
 * | 使い始めた人には発行しない | 利用者管理の権限が「なりすまし」の権限になる |
 * | URL を受け取ってもメール確認は済まない | URL は本人がメールを持っている証拠ではない |
 */
final class ManualHandoverTest extends TestCase
{
    use RefreshDatabase;

    /** 既定はメール（受け渡し方法を送らなければ今までどおり） */
    public function test_受け渡し方法を指定しなければ従来どおりメールで招待する(): void
    {
        Notification::fake();

        $result = $this->invite(['name' => '既定', 'email' => 'default@example.test']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(InviteUser::DELIVERY_EMAIL, $result->meta['deliveryMethod']);
        $this->assertTrue($result->meta['delivered']);
        $this->assertArrayNotHasKey('setupUrl', (array) $result->data);

        Notification::assertSentTo(
            User::query()->where('email', 'default@example.test')->firstOrFail(),
            UserInvitation::class,
        );
    }

    /** 手動なら通知は 0 件で、URL が返る */
    public function test_手動受け渡しはメールを送らずurlを返す(): void
    {
        Notification::fake();

        $result = $this->invite([
            'name' => '手動さん',
            'email' => 'manual@example.test',
            'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(InviteUser::DELIVERY_MANUAL, $result->meta['deliveryMethod']);

        // **外部へは 1 通も出ていない**
        Notification::assertNothingSent();

        $created = User::query()->where('email', 'manual@example.test')->firstOrFail();

        $this->assertTrue($created->isPendingInvitation());
        $this->assertNull($created->email_verified_at);
        $this->assertStringContainsString('/reset-password/', (string) $result->data['setupUrl']);
        $this->assertGreaterThan(0, (int) $result->data['expiresInMinutes']);
    }

    /** 受け取った URL で本人がパスワードを設定し、ログインできる（メール確認は未了のまま） */
    public function test_手動urlでパスワードを設定してログインできるが確認は済まない(): void
    {
        Notification::fake();

        $result = $this->invite([
            'name' => '手動さん',
            'email' => 'manual@example.test',
            'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $created = User::query()->where('email', 'manual@example.test')->firstOrFail();
        $password = $this->fakePassword();

        $this->get((string) $result->data['setupUrl'])->assertOk();

        $this->post('/reset-password', [
            'token' => $this->tokenFrom((string) $result->data['setupUrl']),
            'email' => 'manual@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => 'manual@example.test', 'password' => $password])
            ->assertRedirect();
        $this->assertAuthenticatedAs($created);

        /*
         * **URL を受け取れたことはメールを持っている証拠ではない。**
         * 管理者が別経路で渡したのだから、確認済みにしてはいけない。
         */
        $this->assertNull($created->refresh()->email_verified_at, '勝手に確認済みにした');
        $this->get('/admin/news')->assertRedirect(route('verification.notice', absolute: false));
    }

    /**
     * URL は **受け取り画面で 1 回だけ**表示され、二度目は出ない。
     *
     * リダイレクト先は secret を含まない固定 URL。実体は暗号化した封筒として
     * session に預かり、受け取り画面が取り出すと同時に消える。
     */
    public function test_urlは受け取り画面で一度だけ表示され二度目は一覧へ戻る(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        $redirect = $this->actingAs($owner)->post('/admin/users', [
            'name' => '手動さん', 'email' => 'manual@example.test',
            'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        // **リダイレクト先に secret を載せない**
        $redirect->assertRedirect('/admin/users/setup-link');

        $this->assertStringNotContainsString('reset-password', (string) $redirect->headers->get('Location'));
        $this->assertStringNotContainsString('manual@example.test', (string) $redirect->headers->get('Location'));

        // 1 回目は表示される
        $first = $this->actingAs($owner)->get('/admin/users/setup-link');

        $first->assertOk();
        $first->assertSee('パスワード設定 URL', escape: false);
        $first->assertSee('/reset-password/', escape: false);

        // 履歴・キャッシュに残さないヘッダー
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $first->headers->get('Pragma'));
        $this->assertSame('0', $first->headers->get('Expires'));
        $this->assertSame('no-referrer', $first->headers->get('Referrer-Policy'));

        // 画面側の meta も同じ方針（ヘッダーが落ちても参照元を送らない）
        $first->assertSee('content="no-referrer"', escape: false);

        // 既定（他の画面）は緩めたままにしない
        $this->actingAs($owner)->get('/admin/users')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 2 回目（再読み込み・戻る相当）は何も見せずに一覧へ返す
        $this->actingAs($owner)->get('/admin/users/setup-link')->assertRedirect('/admin/users');
    }

    /** 受け取り画面には、この応答限定の厳格な CSP が付く */
    public function test_受け取り画面に厳格なcspがつく(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/admin/users', [
            'name' => '手動さん', 'email' => 'manual@example.test',
            'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $response = $this->actingAs($owner)->get('/admin/users/setup-link');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $csp, 'この画面に CSP が無い');

        foreach ([
            "default-src 'none'",
            "img-src 'none'",
            "connect-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'none'",
        ] as $directive) {
            $this->assertStringContainsString($directive, $csp, "CSP に {$directive} が無い");
        }

        // nonce は毎回変わり、style / script の両方に同じものが付く
        $this->assertSame(1, preg_match("/script-src 'nonce-([^']+)'/", $csp, $m));
        $nonce = $m[1];

        $this->assertStringContainsString("style-src 'nonce-{$nonce}'", $csp);
        $response->assertSee('nonce="'.$nonce.'"', escape: false);

        // 'unsafe-inline' で逃げていない
        $this->assertStringNotContainsString('unsafe-inline', $csp);
    }

    /**
     * 発行できたのに **渡す途中で落ちた**とき（STAGE #18B / 追加レビュー）。
     *
     * 500 にすると「誰も知らない有効な鍵」が期限まで残る。
     * ここでは鍵をその場で失効させ、次にどうすればよいかを日本語で返す。
     */
    public function test_url表示に失敗しても500にせず鍵を失効させ案内する(): void
    {
        Notification::fake();
        Log::spy();

        // 渡す処理だけを落とす（発行そのものは成功する）
        $this->app->bind(SetupLinkVault::class, fn (): SetupLinkVault => new class extends SetupLinkVault
        {
            public function stash(array $envelope): void
            {
                throw new RuntimeException('封筒を閉じられませんでした');
            }
        });

        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/admin/users', [
            'name' => '手動さん', 'email' => 'manual@example.test',
            'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        // 500 にしない。利用者は作成されたままで、次の手順が示される
        $response->assertRedirect('/admin/users');

        // **画面に届く形**（error トースト）で返る。success ではない
        $toast = $this->flashedToast();

        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('利用者は作成されました', $toast['message']);
        $this->assertStringContainsString('表示できませんでした', $toast['message']);
        // 失効できたので、そう言い切ってよい
        $this->assertStringContainsString('使えなくしました', $toast['message']);
        $this->assertStringContainsString('発行し直して', $toast['message']);

        $this->assertDatabaseHas('users', ['email' => 'manual@example.test']);

        // **渡せなかった鍵は残さない**
        $this->assertSame(
            0,
            DB::table('password_reset_tokens')->where('email', 'manual@example.test')->count(),
            '誰にも渡していない token が残っている',
        );

        // ログにも値を出さない
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return ! str_contains($message, 'manual@example.test')
                && array_keys($context) === ['exception', 'code'];
        });
    }

    /** 再発行で同じことが起きたときも、案内は「もう一度発行」になる */
    public function test_再発行のurl表示失敗は再発行を案内する(): void
    {
        Notification::fake();

        $target = User::factory()->editor()->invited()->create(['email' => 'pending@example.test']);
        $owner = User::factory()->create();

        $this->app->bind(SetupLinkVault::class, fn (): SetupLinkVault => new class extends SetupLinkVault
        {
            public function stash(array $envelope): void
            {
                throw new RuntimeException('封筒を閉じられませんでした');
            }
        });

        $response = $this->actingAs($owner)
            ->from('/admin/users/'.$target->getKey())
            ->post('/admin/users/'.$target->getKey().'/setup-link');

        $response->assertRedirect('/admin/users/'.$target->getKey());

        $toast = $this->flashedToast();

        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('表示できませんでした', $toast['message']);
        $this->assertStringContainsString('発行し直して', $toast['message']);
        $this->assertStringNotContainsString('利用者は作成されました', $toast['message']);

        $this->assertSame(
            0,
            DB::table('password_reset_tokens')->where('email', 'pending@example.test')->count(),
        );
    }

    /**
     * 失効にも失敗したら、**「使えなくした」と言わない**（追加レビュー 2）。
     *
     * 案内が事実と違うと、管理者は「もう安全だ」と思って放置する。
     * 消せていないなら、消せていない前提の手順を伝える。
     */
    public function test_失効にも失敗したら使えなくしたと断言しない(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        // 渡す処理を落とし、さらに失効も落とす
        $this->app->bind(SetupLinkVault::class, fn (): SetupLinkVault => new class extends SetupLinkVault
        {
            public function stash(array $envelope): void
            {
                throw new RuntimeException('封筒を閉じられませんでした');
            }
        });
        $this->app->bind(PasswordSetupLink::class, fn (): PasswordSetupLink => new class extends PasswordSetupLink
        {
            public function revokeFor(int $userId): bool
            {
                return false;
            }
        });

        $response = $this->actingAs($owner)->post('/admin/users', [
            'name' => '手動さん', 'email' => 'manual@example.test',
            'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $response->assertRedirect('/admin/users');

        $toast = $this->flashedToast();

        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('表示できませんでした', $toast['message']);
        $this->assertStringContainsString('発行し直して', $toast['message']);
        // **消せていないのに消したと言わない**
        $this->assertStringNotContainsString('使えなくしました', $toast['message']);
        // 代わりに、次の発行で無効になることを伝える
        $this->assertStringContainsString('以前の URL は使えなくなります', $toast['message']);

        // 500 にはしない。利用者は残る
        $this->assertDatabaseHas('users', ['email' => 'manual@example.test']);
    }

    /**
     * 表示名に script を仕込まれても、画面は素通ししない（追加レビュー 3）。
     *
     * この画面は資格情報を出すので、任意の script が動く余地を残せない。
     */
    public function test_表示名に仕込まれたscriptは実行できる形で出ない(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $payload = '</script><script>window.__e2eXss__=1;</script>';

        $this->actingAs($owner)->post('/admin/users', [
            'name' => $payload, 'email' => 'xss@example.test',
            'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ])->assertRedirect('/admin/users/setup-link');

        $html = $this->actingAs($owner)->get('/admin/users/setup-link')->getContent();

        /*
         * 仕込まれた文字列は **タグとしては現れない**。
         * 文字としては残る（見出しにそう表示されるのが正しい）ので、
         * 見るのは「script 要素になっていないか」。
         */
        $this->assertStringNotContainsString($payload, (string) $html, '生のまま出ている');
        $this->assertStringNotContainsString('<script>window.__e2eXss__', (string) $html);
        $this->assertStringContainsString('&lt;/script&gt;', (string) $html, 'エスケープされていない');

        // script 要素は **nonce 付きの正規 1 本だけ**
        $this->assertSame(1, substr_count((string) $html, '<script'), 'script 要素が増えている');
        $this->assertSame(1, preg_match('/<script nonce="[^"]+">/', (string) $html));
    }

    /**
     * 再発行そのものが拒否されたときも、**画面に見える形**で返る。
     *
     * `withErrors(['setup_link' => ...])` は詳細画面に表示先が無く、
     * 利用者には何も起きていないように見えていた。
     */
    public function test_再発行の拒否はエラートーストで見える(): void
    {
        $started = User::factory()->editor()->create([
            'invited_at' => now()->subDay(), 'last_login_at' => now(),
        ]);
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)
            ->from('/admin/users/'.$started->getKey())
            ->post('/admin/users/'.$started->getKey().'/setup-link');

        $response->assertRedirect('/admin/users/'.$started->getKey());

        $toast = $this->flashedToast();

        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('すでに利用を開始', $toast['message']);

        // 文言に秘密は混ぜない
        $this->assertStringNotContainsString('reset-password', $toast['message']);
        $this->assertStringNotContainsString((string) $started->email, $toast['message']);

        /*
         * **画面の props まで届くこと**を確かめる。
         * session に入っているだけでは足りない（そこは共有されていない）。
         * リダイレクト先を実際に描画して、flash が渡っているかを見る。
         */
        /*
         * 置き場が正しいことも確かめる。
         *
         * `redirect()->with('success')` は共有されておらず画面に出ない。
         * ここで使っているのは **Inertia が配る flash** で、
         * 画面側の `initializeFlashToast` が購読しているのと同じ場所。
         */
        $frontend = (string) file_get_contents(base_path('resources/js/lib/flashToast.ts'));

        $this->assertStringContainsString('flash?.toast', $frontend, '画面が toast を購読していない');
        $this->assertStringContainsString('toast[data.type](data.message)', $frontend);
    }

    /**
     * Inertia の訪問では **ハード遷移**として返す。
     *
     * 受け取り画面は素の Blade なので、普通のリダイレクトを返すと
     * Inertia の client が「Inertia ではない応答」としてモーダルへ押し込む。
     * URL が変わらないまま資格情報が出るうえ、離脱・戻るの扱いも壊れる。
     */
    public function test_inertiaからの作成はハード遷移で受け取り画面へ送る(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/admin/users', [
                'name' => '手動さん', 'email' => 'manual@example.test',
                'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
            ]);

        $response->assertStatus(409);
        $this->assertStringEndsWith(
            '/admin/users/setup-link',
            (string) $response->headers->get('X-Inertia-Location'),
        );

        // 遷移先の指示にも secret は載らない
        $this->assertStringNotContainsString(
            'reset-password',
            (string) $response->headers->get('X-Inertia-Location'),
        );
    }

    /** 封筒が無いのに直接開いても、何も見せない */
    public function test_封筒が無ければ受け取り画面は一覧へ返す(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/admin/users/setup-link')->assertRedirect('/admin/users');
    }

    /** 受け取り画面も owner 以外は開けない */
    public function test_owner以外は受け取り画面を開けない(): void
    {
        foreach ([Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/admin/users/setup-link')
                ->assertForbidden();
        }
    }

    /**
     * session（= `sessions` テーブル）へ **平文で**残さない。
     *
     * このプロジェクトの既定 driver は database なので、
     * session に素で置いた値はそのまま DB の行になる。
     */
    public function test_sessionには暗号文しか残らない(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);

        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/admin/users', [
            'name' => '手動さん', 'email' => 'manual@example.test',
            'role' => Role::Editor->value, 'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ])->assertRedirect('/admin/users/setup-link');

        $rows = DB::table('sessions')->pluck('payload');

        $this->assertNotEmpty($rows, 'session 行が無い（この検証が無意味になっている）');

        foreach ($rows as $payload) {
            $decoded = base64_decode((string) $payload, true) ?: (string) $payload;

            // 平文の URL・token・メールアドレスは 1 つも出てこない
            $this->assertStringNotContainsString('reset-password', $decoded, 'session に URL が平文で残っている');
            $this->assertStringNotContainsString('manual@example.test', $decoded, 'session にメールアドレスが平文で残っている');

            // 封筒そのものは入っている（= この検証が空振りしていない）
            $this->assertStringContainsString('agentcms.setup_link', $decoded);
        }
    }

    /** URL も token も、応答以外のどこにも残らない */
    public function test_urlとtokenが監査にもレスポンス本文にも残らない(): void
    {
        Notification::fake();

        $result = $this->invite([
            'name' => '手動さん',
            'email' => 'manual@example.test',
            'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $url = (string) $result->data['setupUrl'];
        $token = $this->tokenFrom($url);

        $row = DB::table('audit_logs')->where('operation', 'user.invite')->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->result);
        /*
         * **JSON を文字列で比べない。**
         *
         * MySQL の JSON 列は返すときに整形する（`"a": false` のように `:` の後へ
         * 空白が入り、キー順も正規化される）。文字列で探すと**保存側の書き方**に
         * 依ってしまうので、decode してから見る。
         */
        $this->assertSame(
            'manual',
            (json_decode((string) $row->metadata, true) ?? [])['deliveryMethod'] ?? null,
        );

        foreach ((array) $row as $value) {
            $this->assertStringNotContainsString($token, (string) $value, '監査に token が残っている');
            $this->assertStringNotContainsString('reset-password', (string) $value, '監査に URL が残っている');
            $this->assertStringNotContainsString('manual@example.test', (string) $value);
        }

        // password_reset_tokens は Laravel 標準どおり **ハッシュだけ**
        $stored = (string) DB::table('password_reset_tokens')
            ->where('email', 'manual@example.test')->value('token');

        $this->assertNotSame('', $stored);
        $this->assertNotSame($token, $stored, '生の token が保存されている');
    }

    /** 発行し直すと前の URL は使えず、新しい URL が使える */
    public function test_再発行で古いurlが無効になり新しいurlが使える(): void
    {
        Notification::fake();

        $first = $this->invite([
            'name' => '手動さん',
            'email' => 'manual@example.test',
            'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $created = User::query()->where('email', 'manual@example.test')->firstOrFail();
        $oldUrl = (string) $first->data['setupUrl'];

        $reissued = app(IssueSetupLink::class)->execute((int) $created->getKey(), $this->context());

        $this->assertTrue($reissued->isSuccess());

        $newUrl = (string) $reissued->data['setupUrl'];
        $this->assertNotSame($oldUrl, $newUrl);

        $password = $this->fakePassword();

        // 古い URL の token では設定できない
        $this->from('/reset-password')->post('/reset-password', [
            'token' => $this->tokenFrom($oldUrl),
            'email' => 'manual@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors();

        // 新しい URL なら設定できる
        $this->post('/reset-password', [
            'token' => $this->tokenFrom($newUrl),
            'email' => 'manual@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();
    }

    /** 使い始めた人・無効な人には発行しない */
    public function test_利用開始済みと無効な利用者には発行しない(): void
    {
        $started = User::factory()->editor()->create([
            'invited_at' => now()->subDay(), 'last_login_at' => now(),
        ]);
        $inactive = User::factory()->editor()->invited()->create(['is_active' => false]);

        $startedResult = app(IssueSetupLink::class)->execute((int) $started->getKey(), $this->context());
        $inactiveResult = app(IssueSetupLink::class)->execute((int) $inactive->getKey(), $this->context());

        $this->assertSame(OperationStatus::Conflict, $startedResult->status);
        $this->assertStringContainsString('すでに利用を開始', (string) $startedResult->message);

        $this->assertSame(OperationStatus::Conflict, $inactiveResult->status);
        $this->assertStringContainsString('無効化された利用者', (string) $inactiveResult->message);

        // token は 1 件も作られていない
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /** owner 以外は発行できない（画面を隠すだけにしない） */
    public function test_owner以外は設定urlを発行できない(): void
    {
        $target = User::factory()->editor()->invited()->create();

        foreach ([Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $actor = User::factory()->role($role)->create();

            $this->actingAs($actor)
                ->post('/admin/users/'.$target->getKey().'/setup-link')
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /** 知らない受け渡し方法は既定へ倒さず拒否する */
    public function test_未知の受け渡し方法を拒否する(): void
    {
        Notification::fake();

        $result = $this->invite([
            'name' => '改ざん', 'email' => 'tampered@example.test',
            'deliveryMethod' => 'sms',
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('deliveryMethod', $result->errors);
        $this->assertDatabaseMissing('users', ['email' => 'tampered@example.test']);
        Notification::assertNothingSent();
    }

    /**
     * URL を作れなかったときは **利用者も作らない**（STAGE #18B）。
     *
     * メール経路と違い、外の世界へは何も出ていない。
     * 利用者だけ残すと「招待中に見えるのに渡す URL が無い」行ができる。
     */
    public function test_url発行に失敗したら利用者を作らずログにも値を残さない(): void
    {
        Notification::fake();
        Log::spy();

        /*
         * 発行を確実に失敗させる。
         *
         * **表を落として作らない。** MySQL は DDL で暗黙 commit するので、その時点で
         * 取引が閉じ、**あとの rollback が効かなくなる** — 「利用者を作らない」が
         * 守られていても試験だけが落ちる（SQLite では起きないので、長く見えなかった）。
         *
         * 見たいのは「**URL を発行できなかったとき**」なので、**発行する口を落とす**。
         * DB の都合ではなく、この試験が言っていることそのものである。
         */
        $this->app->bind(PasswordSetupLink::class, fn (): PasswordSetupLink => new class extends PasswordSetupLink
        {
            public function __construct() {}

            public function issueFor(User $user): string
            {
                // broker の insert が落ちたときと同じ形（bindings を抱えた例外）
                throw new QueryException(
                    'mysql',
                    'insert into password_reset_tokens (email, token) values (?, ?)',
                    [$user->email, 'hashed-token'],
                    new RuntimeException('SQLSTATE[42S02]: Base table or view not found'),
                );
            }
        });

        $result = $this->invite([
            'name' => '失敗さん', 'email' => 'failed@example.test',
            'deliveryMethod' => InviteUser::DELIVERY_MANUAL,
        ]);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('作成していません', (string) $result->message);
        $this->assertDatabaseMissing('users', ['email' => 'failed@example.test']);

        // 監査には失敗が残るが、値は残らない
        $row = DB::table('audit_logs')->where('operation', 'user.invite')->first();

        $this->assertNotNull($row);
        $this->assertSame('conflict', $row->result);
        $this->assertStringNotContainsString('failed@example.test', (string) $row->metadata);

        /*
         * **ログにも値を残さない。**
         * `report($e)` だと QueryException のメッセージに SQL の bindings
         * （メールアドレスとハッシュ化 token）が並ぶ。
         */
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return ! str_contains($message, 'failed@example.test')
                && ! str_contains($message, 'password_reset_tokens')
                && array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context) ?: '', 'failed@example.test');
        });
    }

    /**
     * 画面が **危険性・期限・再表示不可** を日本語で伝え、コピー結果を読み上げへ渡す。
     *
     * URL は「一時的な鍵」なので、渡す前に危険性が分かっていないと
     * スクリーンショットやチャットへ気軽に流れてしまう。
     */
    public function test_url表示のuiが危険性と期限と再表示不可を伝える(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/admin/setup-link.blade.php'));

        foreach ([
            'この URL',
            'パスワードを設定できます',
            '有効期限',
            '二度と表示できません',
            '発行し直すと',
            'URL をコピー',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $source, "画面の説明が足りない: {$phrase}");
        }

        // コピーの結果を色や動きだけで伝えない（読み上げにも届かせる）
        $this->assertStringContainsString('role="status"', $source);
        $this->assertStringContainsString('aria-live="polite"', $source);
        $this->assertStringContainsString('コピーしました', $source);
        $this->assertStringContainsString('コピーできませんでした', $source);

        // 入力欄には見える / 見えないの両方でラベルを付ける
        $this->assertStringContainsString('aria-labelledby="setup-link-heading"', $source);
        $this->assertStringContainsString('for="setup-link-url"', $source);

        // 戻る（bfcache 復元）でも中身を見せない
        $this->assertStringContainsString('pageshow', $source);
        $this->assertStringContainsString('event.persisted', $source);

        // 一覧へ戻る導線
        $this->assertStringContainsString('利用者一覧へ戻る', $source);
    }

    /** 詳細画面に再発行の導線があり、招待中のときだけ出す */
    public function test_詳細画面に再発行の導線がある(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/users/Edit.vue'));

        $this->assertStringContainsString('パスワード設定 URL を発行', $source);
        $this->assertStringContainsString('/setup-link', $source);
        // 招待中かつ有効なときだけ（画面の出し分けはサーバー判定の補助）
        $this->assertStringContainsString('user.pendingInvitation && user.isActive', $source);
        // 既存の招待メール再送は残す
        $this->assertStringContainsString('招待メールを再送', $source);
    }

    /** @param  array<string, mixed>  $input */
    private function invite(array $input): Result
    {
        return app(InviteUser::class)->execute(
            ['role' => Role::Editor->value, ...$input],
            $this->context(),
        );
    }

    private function context(): OperationContext
    {
        return OperationContext::admin(
            (int) User::factory()->create()->getKey(),
            '管理者',
        );
    }

    private function tokenFrom(string $url): string
    {
        $segments = explode('/', (string) parse_url($url, PHP_URL_PATH));

        return (string) end($segments);
    }

    /**
     * 画面へ実際に届く flash（Inertia の toast）を読む。
     *
     * `session('success')` は共有されていないので見ない —
     * そこを見ると「テストは通るが画面には出ない」を作ってしまう。
     *
     * @return array{type: string, message: string}
     */
    private function flashedToast(): array
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $toast = $flash['toast'] ?? null;

        $this->assertIsArray($toast, 'トーストが返っていない（画面には何も出ない）');

        return ['type' => (string) $toast['type'], 'message' => (string) $toast['message']];
    }

    /** 秘密スキャンが本物と見分けられるよう、断片から組み立てる（Correction 3） */
    private function fakePassword(): string
    {
        return implode('', ['InvitedPassw0rd', '!', '2026']);
    }
}
