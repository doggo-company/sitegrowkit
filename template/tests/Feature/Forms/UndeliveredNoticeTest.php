<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 届かなかった通知に、気づける（WP 一周 10 周目 2026-09-04 / 効くもの ② の続き）。
 *
 * ## 何が起きていたか
 *
 * 問い合わせ通知メールが落ちても、**能動的に知らせる手段が Push しか無かった**。
 * VAPID キーは生成 project で空なので、買った人は全員その状態で始まる。
 *
 * 🔴 **メールが来ないことは「問い合わせが無い」と区別がつかない。**
 * だから気づく理由すら生まれない。受信一覧を自分で開けば
 * 「失敗」とは出るが、**開く理由が無い**。
 *
 * ## どうするか
 *
 * オーナー決定（2026-09-04）: **サイドバーの「フォーム」に赤い数を出す。**
 * ダッシュボードは毎日見るとは限らず、公開前チェックは公開の直前にしか回らない。
 * すでに目が慣れている場所に出すのがいちばん早い。
 *
 * 押した先で「**どれが届かなかったか**」と「**もう一度送る**」ができるところまで。
 */
final class UndeliveredNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value]);
    }

    /** 受付フォームは 1 本だけ用意する（生成直後の案件と同じ形） */
    private function formId(): int
    {
        $existing = DB::table('forms')->where('key', 'contact')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('forms')->insertGetId([
            'key' => 'contact',
            'name' => 'お問い合わせ',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function submission(?string $error, ?string $notifiedAt = null): int
    {
        $formId = $this->formId();

        return (int) DB::table('form_submissions')->insertGetId([
            'form_id' => $formId,
            'data' => json_encode(['name' => '田中', '__labels' => ['name' => 'お名前']], JSON_UNESCAPED_UNICODE),
            'status' => 'received',
            'admin_notified_at' => $notifiedAt,
            'admin_notification_error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 🔴 これが本体。届かなかった数がサイドバーへ渡る */
    public function test_届かなかった数を画面へ渡す(): void
    {
        $this->submission('SMTP connect failed');
        $this->submission('SMTP connect failed');
        $this->submission(null, (string) now());

        $this->actingAs($this->owner())->get('/admin/forms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav.undelivered', 2));
    }

    /** 届いているだけなら、数は出さない（赤い数を鳴らしっぱなしにしない） */
    public function test_全部届いていれば0(): void
    {
        $this->submission(null, (string) now());

        $this->actingAs($this->owner())->get('/admin/forms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav.undelivered', 0));
    }

    /** 受信を読めない人には数えない（見えないものの数だけ出さない） */
    public function test_受信を読めない人には出さない(): void
    {
        $this->submission('SMTP connect failed');

        $editor = User::factory()->create(['role' => Role::Editor->value]);

        $this->actingAs($editor)->get('/admin/pages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav.undelivered', 0));
    }

    /** サイドバーが赤い数を描く */
    public function test_サイドバーが赤い数を描く(): void
    {
        $nav = File::get(base_path('resources/js/lib/admin-nav.ts'));
        $menu = File::get(base_path('resources/js/components/NavMain.vue'));

        $this->assertStringContainsString('undelivered', $nav);
        $this->assertStringContainsString('nav-badge', $menu);
    }

    /** 押した先で「どれが届かなかったか」を絞り込める */
    public function test_届かなかったものだけを絞り込める(): void
    {
        $failed = $this->submission('SMTP connect failed');
        $this->submission(null, (string) now());

        $this->actingAs($this->owner())->get('/admin/forms/contact/submissions?delivery=failed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('submissions', 1)
                ->where('submissions.0.id', $failed));
    }

    /** 🔴 もう一度送れる。送れたら数が減る */
    public function test_もう一度送れて数が減る(): void
    {
        Mail::fake();

        $id = $this->submission('SMTP connect failed');

        $this->actingAs($this->owner())
            ->post("/admin/forms/contact/submissions/{$id}/resend")
            ->assertRedirect();

        $row = DB::table('form_submissions')->where('id', $id)->first();

        $this->assertNotNull($row?->admin_notified_at, 'もう一度送っても届いた印が付かない');
        $this->assertNull($row?->admin_notification_error, '失敗の記録が残ったまま');
    }

    /**
     * 送り先が 1 つも決まっていなければ、送ったことにしない。
     *
     * 宛先はフォームの設定 → サイト設定 → 既定、の順に落ちる。
     * **既定まで空にしないと、この道は塞がらない**（実測でここを 1 度外した）。
     */
    public function test_通知先が空なら送ったことにしない(): void
    {
        Mail::fake();

        app(SettingsStore::class)->put(['contact_email' => '']);
        config()->set('agentcms.mail.admin_to', '');

        $id = $this->submission('SMTP connect failed');

        $this->actingAs($this->owner())
            ->post("/admin/forms/contact/submissions/{$id}/resend")
            ->assertRedirect();

        $row = DB::table('form_submissions')->where('id', $id)->first();

        $this->assertNull($row?->admin_notified_at);
        $this->assertNotNull($row?->admin_notification_error);
    }

    /** 受信を変えられない人は送れない */
    public function test_権限が無ければ送れない(): void
    {
        $id = $this->submission('SMTP connect failed');
        $viewer = User::factory()->create(['role' => Role::Viewer->value]);

        $this->actingAs($viewer)
            ->post("/admin/forms/contact/submissions/{$id}/resend")
            ->assertForbidden();
    }
}
