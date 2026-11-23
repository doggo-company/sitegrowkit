<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Settings\SettingsStore;
use App\Support\ReadinessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 公開前チェックの日時は、サーバーの時間帯（APP_TIMEZONE）に関わらず日本時間で出す（直し 4 回目）。
 * 直し 4 回目の時点では、サーバー用の env.*.example に APP_TIMEZONE が無く、XServer では既定の UTC で動いた
 * （直し 5 回目で足した。古い shared/.env のサーバーは UTC のままなので、この試験は残す）。
 */
final class Round4ReadinessTimeTest extends TestCase
{
    use RefreshDatabase;

    private string $timezoneBefore = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezoneBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->timezoneBefore);
        parent::tearDown();
    }

    public function test_label_is_japan_time_even_when_the_server_runs_in_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        // 日本時間 2026-11-23 19:28 ＝ UTC 10:28
        Carbon::setTestNow(Carbon::parse('2026-11-23T10:28:00Z'));

        app(ReadinessResult::class)->record(0);
        SettingsStore::forget();

        $last = app(ReadinessResult::class)->last();
        $this->assertNotNull($last);
        $this->assertSame('11月23日 19:28', $last['label']);
        $this->assertStringContainsString('公開前チェック 11月23日 19:28', (string) app(ReadinessResult::class)->doneMessage());
    }

    public function test_an_old_record_saved_with_a_utc_label_reads_back_in_japan_time(): void
    {
        app(SettingsStore::class)->put(['readiness_result' => ['count' => 0, 'checkedAt' => '2026-11-23T10:28:00+00:00', 'label' => '11月23日 10:28']]);
        SettingsStore::forget();

        $this->assertSame('11月23日 19:28', app(ReadinessResult::class)->last()['label'] ?? null);
    }

    public function test_an_unreadable_date_is_treated_as_not_run(): void
    {
        app(SettingsStore::class)->put(['readiness_result' => ['count' => 0, 'checkedAt' => 'not-a-date', 'label' => 'x']]);
        SettingsStore::forget();

        $this->assertNull(app(ReadinessResult::class)->last());
        $this->assertNull(app(ReadinessResult::class)->doneMessage());
    }
}
