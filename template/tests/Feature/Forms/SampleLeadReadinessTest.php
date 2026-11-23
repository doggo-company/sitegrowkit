<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Forms\Form;
use App\Models\User;
use App\Support\PublicationReadiness;
use App\Support\Role;
use Database\Seeders\ContactFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 同梱のお問い合わせフォームの説明文（制作会社向けの見本）を、公開前チェックが拾う（UX 一周 2026-09-28）。
 */
final class SampleLeadReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function readinessFindings(): Collection
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        return collect((array) $this->actingAs($owner)
            ->get('/admin/readiness')
            ->assertOk()
            ->inertiaProps('findings'));
    }

    /** 🔴 これが本体。見本の説明文のまま公開されていたら止める */
    public function test_見本の説明文のままなら公開前チェックが止めてフォームの説明へ連れて行く(): void
    {
        $this->seed(ContactFormSeeder::class);

        $lead = $this->readinessFindings()
            ->first(fn (array $f): bool => str_starts_with($f['what'], '見本の説明文のまま'));

        $this->assertNotNull($lead, '見本の説明文を拾っていない');
        $this->assertSame('/admin/forms/contact#description', $lead['fix']['href']);
    }

    public function test_説明をお店の文に書き換えれば止めない(): void
    {
        $this->seed(ContactFormSeeder::class);
        Form::query()->where('key', 'contact')->update(['description' => 'トリミングのご予約・ご相談はこちらからどうぞ。']);

        $leads = $this->readinessFindings()
            ->filter(fn (array $f): bool => str_starts_with($f['what'], '見本の説明文のまま'));

        $this->assertCount(0, $leads);
    }

    public function test_同梱の見本の説明文は印に当たる(): void
    {
        $this->seed(ContactFormSeeder::class);
        $description = (string) Form::query()->where('key', 'contact')->value('description');

        $this->assertNotSame([], PublicationReadiness::sampleLeadsIn("<p>{$description}</p>"), '見本の文と印がずれている');
    }

    public function test_お店が自分で書いたご相談の文には当たらない(): void
    {
        $this->assertSame([], PublicationReadiness::sampleLeadsIn('<p>リフォームのご相談・お見積もりは無料です。</p>'));
    }
}
