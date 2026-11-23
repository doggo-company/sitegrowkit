<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Notifications\Push\FakePushTransport;
use Modules\Notifications\Push\PushTransport;
use Tests\TestCase;

/**
 * 届かない設定を「保存しました」と言わない（WP 一周 10 周目 2026-09-04 / 効くもの ②）。
 *
 * ## 何が起きていたか
 *
 * VAPID キーは生成 project で**空**である。つまり買った人は全員この状態で始まる。
 *
 * 画面の上半分は正直に「サーバーの VAPID キーが未設定のため Push 通知を利用できません」と
 * 言う。ところが**その真下の「受け取る通知」は、3 つとも ON で押せて、保存すると
 * 「通知設定を保存しました」と出る**。
 *
 * 「新規お問い合わせ ON」を見た人は「問い合わせが来たら知らせてくれる」と読む。
 * 実際には 1 通も出ない（`Notifier::send()` は `configured()` が false なら即戻る）。
 *
 * 🔴 静かに嘘をつく形である。押せて、保存できて、「保存しました」と出るのに、何も起きない。
 *
 * ## どう直すか
 *
 * 使えないときは**押せなくする**。そのうえで、押せない理由と
 * **使えるようにするには何が要るか**を、その場（「受け取る通知」の中）に出す。
 * 上の Card にしか書いていないと、下だけ見た人には届かない。
 */
final class NotificationSettingsHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value]);
    }

    private function withPush(bool $configured): void
    {
        $this->app->instance(PushTransport::class, new FakePushTransport(isConfigured: $configured));
    }

    /** 使えないことは、画面が受け取っている（今までどおり） */
    public function test_使えないことを画面へ渡す(): void
    {
        $this->withPush(false);

        $this->actingAs($this->owner())->get('/admin/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/notifications/Settings')
                ->where('pushConfigured', false));
    }

    /** 使えるときは、そう渡る（塞ぎっぱなしにしない） */
    public function test_使えるときは使えると渡す(): void
    {
        $this->withPush(true);

        $this->actingAs($this->owner())->get('/admin/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pushConfigured', true));
    }

    /**
     * 🔴 これが本体。**使えないときは、受け取る通知を押せなくする。**
     *
     * 画面の記述そのものを固定する（この repo に Vue の component test 基盤が無いため、
     * `ContentTypeCommentsTest::test_コメントの切替ボタンは保存フォームを送信しない` と同じ形）。
     */
    public function test_使えないときはトグルと保存を押せない(): void
    {
        $source = $this->settingsSource();

        // 各トグル
        $this->assertMatchesRegularExpression(
            '/<Checkbox\b(?:(?!\/>).)*?:disabled="!pushConfigured"/s',
            $source,
            'Push が使えないのにトグルを押せる',
        );

        // 保存ボタン（押せると「保存しました」まで出てしまう）
        $this->assertMatchesRegularExpression(
            '/data-testid="save-preferences"|:disabled="form\.processing \|\| !pushConfigured"/s',
            $source,
        );
        $this->assertStringContainsString('form.processing || !pushConfigured', $source);
    }

    /** 押せない理由と、使えるようにするために要るものを、その場に出す */
    public function test_受け取る通知の中に理由と手順を出す(): void
    {
        $source = $this->settingsSource();

        $this->assertStringContainsString('preferences-unavailable', $source);
        $this->assertStringContainsString('いまは届きません', $source);
        $this->assertStringContainsString('notifications:vapid-keys', $source);
    }

    /**
     * 「メール通知失敗」だけは、Push を直しても足りない。
     *
     * この種別は**知らせる手段が Push しか無い**（`ContactController` が
     * `NotificationType::ContactEmailFailed` を `Notifier` へ渡すだけ）。
     * VAPID 未設定では、問い合わせ通知メールが落ちても能動的に知る方法が無い。
     *
     * ここでは**その事実だけ**を固定する。代わりの気づき方は別途（案を記録に残した）。
     */
    public function test_メール通知失敗はpushだけで知らせている(): void
    {
        $source = File::get(base_path('modules/Contact/Http/ContactController.php'));

        $this->assertStringContainsString('NotificationType::ContactEmailFailed', $source);

        // メールでも知らせるようになったら、この試験を消して新しい経路を固定すること
        $this->assertStringNotContainsString('Mail::to', $source);
    }

    private function settingsSource(): string
    {
        return File::get(base_path('resources/js/pages/admin/notifications/Settings.vue'));
    }
}
