<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\FirstSteps;
use App\Support\ReadinessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class Round3ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_real_zero_result_completes_step_six(): void
    {
        $this->assertFalse(app(FirstSteps::class)->all()[5]['done']);
        $this->assertNull(app(ReadinessResult::class)->doneMessage());
        app(ReadinessResult::class)->record(0);
        SettingsStore::forget();
        $this->assertTrue(app(FirstSteps::class)->all()[5]['done']);
        $this->assertStringContainsString('最初の 6 歩が済みました（公開前チェック', (string) app(ReadinessResult::class)->doneMessage());
        $this->assertCount(6, app(FirstSteps::class)->all());
        $this->assertFalse(app(FirstSteps::class)->all()[5]['checkable']);
        app(ReadinessResult::class)->record(1);
        $this->assertFalse(app(FirstSteps::class)->all()[5]['done']);
        $this->assertNull(app(ReadinessResult::class)->doneMessage());
    }

    public function test_readiness_records_the_actual_count_and_offers_guide_only_when_clear(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user)->get('/admin/readiness')->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('admin/readiness/Index')
                    ->where('checkedAt', app(ReadinessResult::class)->last()['checkedAt'])
                    ->where('deployGuide', fn ($guide): bool => app(ReadinessResult::class)->last()['count'] === 0
                        ? $guide['document'] === 'docs/deployment/xserver.md' : $guide === null);
            });
        $this->assertNotNull(app(ReadinessResult::class)->last());
    }

    public function test_zero_findings_shows_deployment_guide_and_a_later_finding_removes_it(): void
    {
        config(['mail.default' => 'smtp']);
        app(SettingsStore::class)->put(['contact_email' => 'info@doggo.test']);
        Route::get('/sitemap.xml', fn () => response('<urlset><url><loc>http://localhost/round3-clean</loc></url></urlset>'));
        Route::get('/round3-clean', fn () => response('<h1>犬の庭</h1>'));
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get('/admin/readiness')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('findings', 0)->where('deployGuide.document', 'docs/deployment/xserver.md'));
        $this->assertTrue(app(FirstSteps::class)->all()[5]['done']);
        config(['mail.default' => 'log']);
        $this->get('/admin/readiness')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('findings', 1)->where('deployGuide', null));
        $this->assertFalse(app(FirstSteps::class)->all()[5]['done']);
    }

    public function test_dashboard_receives_the_record_and_message(): void
    {
        app(ReadinessResult::class)->record(0);
        $this->actingAs(User::factory()->create(['role' => 'owner']))->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('firstSteps.steps.5.done', true)
            ->where('firstSteps.readiness.count', 0)
            ->where('firstSteps.doneMessage', app(ReadinessResult::class)->doneMessage()));
    }
}
