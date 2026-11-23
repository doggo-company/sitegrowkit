<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Forms\Form;
use App\Models\User;
use App\Support\BundledPages;
use App\Support\FirstSteps;
use App\Support\Role;
use App\Support\SamplePages;
use Database\Seeders\ContactFormSeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 公開前の UX 一周 9 周目（2026-11-05・農家民宿・ノート PC・rc13）の回帰。
 *
 * - M2（メンテナの決定）: 業種は増やさず、「その他」でお仕事の名前を打つ。名前を見本の文と
 *   お問い合わせの説明に使い、項目は業種に寄らない形にする
 * - R9-1: メニューに触る前に、最初の 5 歩の「メニュー」が済みになった
 */
final class UxRelease20261105Round9Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    /** @return array<string, string> */
    private function contactLabels(): array
    {
        $form = Form::query()->where('key', 'contact')->sole();

        return array_column(json_decode((string) DB::table('field_groups')->where('id', $form->field_group_id)->value('fields'), true), 'label', 'key');
    }

    public function test_その他でお仕事の名前を打つと見本とお問い合わせに使う(): void
    {
        $this->seed(ContactFormSeeder::class);
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'other', 'name' => '宿・民宿'])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains((string) $m, '「宿・民宿」'));

        $form = Form::query()->where('key', 'contact')->sole();
        $this->assertSame('宿・民宿へのご予約・ご相談はこちらからどうぞ。', $form->description);
        $labels = $this->contactLabels();
        $this->assertArrayNotHasKey('company', $labels, '会社・屋号の欄が残っている');
        $this->assertSame('ご希望の日時', $labels['preferred_date'] ?? null);

        $this->get('/about')->assertOk()->assertSee('「宿・民宿」のサイトの見本')->assertSee('宿・民宿の紹介');
        $this->get('/')->assertOk()->assertSee('〇〇（地域）の宿・民宿');
    }

    public function test_名前を替えて選び直すと前の名前で入れた説明も替わる(): void
    {
        $this->seed(ContactFormSeeder::class);
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'other', 'name' => '宿'])->assertRedirect();
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'other', 'name' => '農家民宿'])->assertRedirect();

        $this->assertSame('農家民宿へのご予約・ご相談はこちらからどうぞ。', Form::query()->where('key', 'contact')->value('description'));
    }

    public function test_名前が空でも決められて見本の言い回しにならない(): void
    {
        $this->seed(ContactFormSeeder::class);
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'other'])->assertRedirect();

        $this->assertSame('ご予約・ご相談はこちらからどうぞ。', Form::query()->where('key', 'contact')->value('description'));
    }

    public function test_メニューに触る前に三歩目を済みにしない(): void
    {
        $this->seed(MenuSeeder::class);
        $this->seed(ContactFormSeeder::class);
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'cafe'])->assertRedirect();
        app(BundledPages::class)->setHidden('about', true);
        app(BundledPages::class)->setHidden('service', true);

        $this->app?->forgetScopedInstances();
        $menu = collect(app(FirstSteps::class)->all())->firstWhere('key', 'menu');
        $this->assertFalse((bool) ($menu['done'] ?? true), 'メニューに触っていないのに済みになっている');
    }

    /** 出ていない脚注を「出ています」と書かない（UX 一周 9 周目 R9-4） */
    public function test_contactの見本の説明に出ていない脚注を書かない(): void
    {
        $this->seed(ContactFormSeeder::class);
        $row = collect(app(SamplePages::class)->forAdmin())->firstWhere('key', 'contact');

        if ($row === null) {
            $this->markTestSkipped('この環境では /contact が見本のデザインで出ていない');
        }

        $this->assertStringNotContainsString('脚注', (string) $row['note']);
        $this->assertStringNotContainsString('同梱のサンプルサイトです', (string) $row['note']);
    }
}
