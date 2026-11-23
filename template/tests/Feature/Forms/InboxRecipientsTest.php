<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\FirstSteps;
use App\Support\InboxRecipients;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 公開前チェックとダッシュボードが、**送る側と同じ宛先**を見る（UX 一周 2026-09-28）。
 *
 * フォームの画面で通知先を入れても、検査はサイト設定の宛先しか見ていなかったので
 * 「開発用の宛先のまま（admin@example.test）」と言い続けた。実際の送信は
 * フォームの通知先へ届いている。
 */
final class InboxRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('agentcms.mail.admin_to', 'admin@example.test');
        config()->set('mail.default', 'smtp');
        app(SettingsStore::class)->put(['contact_email' => null]);
    }

    private function contactForm(?string $notifyTo, string $status = 'published'): void
    {
        DB::table('forms')->insert([
            'key' => 'contact',
            'name' => 'お問い合わせ',
            'status' => $status,
            'notify_to' => $notifyTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array{where: string, to: string, href: string}> */
    private function problems(): array
    {
        $this->app?->forgetScopedInstances();

        return app(InboxRecipients::class)->problems();
    }

    /** 🔴 これが本体。フォームに入れた宛先で届くなら、開発用の宛先とは言わない */
    public function test_フォームに通知先を入れれば開発用の宛先とは言わない(): void
    {
        $this->contactForm('owner@salon-fixture.jp');

        $this->assertSame([], $this->problems());
    }

    public function test_フォームもサイト設定も空なら開発用の宛先へ落ちると言う(): void
    {
        $this->contactForm(null);

        $problems = $this->problems();

        $this->assertCount(1, $problems);
        $this->assertSame('フォーム「お問い合わせ」の通知先', $problems[0]['where']);
        $this->assertSame('admin@example.test', $problems[0]['to']);
        $this->assertSame('/admin/forms/contact#notify_to', $problems[0]['href']);
    }

    public function test_サイト設定に入れればフォームは空でも届く(): void
    {
        $this->contactForm(null);
        app(SettingsStore::class)->put(['contact_email' => 'owner@salon-fixture.jp']);

        $this->assertSame([], $this->problems());
    }

    public function test_非公開のフォームは受け口に数えない(): void
    {
        $this->contactForm(null, 'draft');
        app(SettingsStore::class)->put(['contact_email' => 'owner@salon-fixture.jp']);

        // /contact は従来のお問い合わせになり、そちらはサイト設定の宛先で届く
        $this->assertSame([], $this->problems());
    }

    public function test_公開前チェックの画面はフォームの通知先を直す画面へ連れて行く(): void
    {
        $this->contactForm(null);
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        $findings = collect((array) $this->actingAs($owner)
            ->get('/admin/readiness')
            ->assertOk()
            ->inertiaProps('findings'));

        $notify = $findings->firstWhere('where', 'フォーム「お問い合わせ」の通知先');

        $this->assertNotNull($notify, '通知先の指摘が出ていない');
        $this->assertSame('/admin/forms/contact#notify_to', $notify['fix']['href']);
    }

    public function test_フォームに通知先を入れれば公開前チェックに通知先の指摘が出ない(): void
    {
        $this->contactForm('owner@salon-fixture.jp');
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        $wheres = collect((array) $this->actingAs($owner)
            ->get('/admin/readiness')
            ->assertOk()
            ->inertiaProps('findings'))->pluck('what')->all();

        foreach ($wheres as $what) {
            $this->assertStringNotContainsString('開発用の宛先', $what);
        }
    }

    public function test_フォームに通知先を入れれば最初の5歩の四歩目が済む(): void
    {
        $this->contactForm('owner@salon-fixture.jp');
        $this->app?->forgetScopedInstances();

        $inbox = collect(app(FirstSteps::class)->all())->firstWhere('key', 'inbox');

        $this->assertTrue($inbox['done']);
    }
}
