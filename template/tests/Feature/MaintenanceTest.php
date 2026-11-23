<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Maintenance\MaintenanceProbe;
use App\Support\Maintenance\MaintenanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** 保守の未実行・失敗・古い記録を合格にせず、HTTPから外部確認を起動しない。 */
final class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $originalStorage;

    private string $temporaryStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->temporaryStorage = sys_get_temp_dir().'/sgk-maintenance-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($this->temporaryStorage);
        config(['app.url' => 'https://site.example.test']);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->temporaryStorage);
        parent::tearDown();
    }

    public function test_依存監査の成功と脆弱性と壊れた応答を区別する(): void
    {
        $probe = app(MaintenanceProbe::class);
        Process::fake(fn () => Process::result(output: '{"advisories":[]}', exitCode: 0));
        $this->assertSame('pass', $probe->dependencies()['status']);
        Process::assertRan(fn ($process) => $process->command === ['composer', '--no-plugins', '--no-scripts', 'audit', '--no-dev', '--format=json', '--no-interaction']);
        Process::fake(fn () => Process::result(output: '{"advisories":{"vendor/package":[{"id":"test"}]}}', exitCode: 1));
        $this->assertSame('fail', $probe->dependencies()['status']);
        Process::fake(fn () => Process::result(output: '{"advisories":[],"ignored-advisories":{"vendor/package":[{}]}}', exitCode: 0));
        $this->assertSame('fail', $probe->dependencies()['status']);
        Process::fake(fn () => Process::result(output: 'secret-value-in-error', errorOutput: 'another-secret', exitCode: 1));
        $row = $probe->dependencies();
        $this->assertSame('unknown', $row['status']);
        $this->assertStringNotContainsString('secret', json_encode($row, JSON_THROW_ON_ERROR));
        Process::fake(fn () => Process::result(output: '{"advisories":[]}', exitCode: 2));
        $this->assertSame('unknown', $probe->dependencies()['status']);
    }

    public function test_証明書の期限と_htt_p未確認を区別する(): void
    {
        $probe = app(MaintenanceProbe::class);
        $this->assertSame('pass', $probe->certificateExpiry(now()->addDays(90)->timestamp)['status']);
        $this->assertSame('warn', $probe->certificateExpiry(now()->addDays(10)->timestamp)['status']);
        $this->assertSame('fail', $probe->certificateExpiry(now()->subDay()->timestamp)['status']);
        config(['app.url' => 'http://localhost:8181']);
        $this->assertSame('unknown', $probe->certificate()['status']);
    }

    public function test_記録がない古い_ur_lが変わった結果を合格扱いしない(): void
    {
        $report = app(MaintenanceReport::class);
        $this->assertSame('missing', $report->read()['state']);
        $report->save($this->checks());
        $this->assertSame('current', $report->read()['state']);
        $this->assertSame(0600, fileperms($report->path()) & 0777);
        $this->travel(49)->hours();
        $this->assertSame('stale', $report->read()['state']);
        $this->travelBack();
        config(['app.url' => 'https://another.example.test']);
        $this->assertSame('stale', $report->read()['state']);
    }

    public function test_確認中に構成が変われば新しい構成の結果として保存しない(): void
    {
        $report = app(MaintenanceReport::class);
        $fingerprint = $report->fingerprint();
        config(['app.url' => 'https://changed.example.test']);
        $this->expectException(\RuntimeException::class);
        $report->save($this->checks(), $fingerprint);
    }

    public function test_壊れた記録や検査の重複は未確認になる(): void
    {
        $report = app(MaintenanceReport::class);
        $report->save($this->checks());
        $data = json_decode((string) file_get_contents($report->path()), true, flags: JSON_THROW_ON_ERROR);
        $data['checks'][1] = $data['checks'][0];
        file_put_contents($report->path(), json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertSame('missing', $report->read()['state']);
        file_put_contents($report->path(), '{broken');
        $this->assertSame('missing', $report->read()['state']);
    }

    public function test_cl_iは未確認も失敗として保存し正常終了を装わない(): void
    {
        Process::fake(fn () => Process::result(output: '{"advisories":[]}', exitCode: 0));
        config(['app.url' => 'http://localhost:8181']);
        $this->artisan('agentcms:maintenance')->assertExitCode(1);
        $report = app(MaintenanceReport::class)->read();
        $this->assertSame('current', $report['state']);
        $this->assertSame(['pass', 'unknown'], array_column($report['checks'], 'status'));
    }

    public function test_cl_iの同時実行は古い結果で上書きしない(): void
    {
        $report = app(MaintenanceReport::class);
        $report->save($this->checks());
        $before = file_get_contents($report->path());
        $lock = fopen(dirname($report->path()).'/run.lock', 'c');
        $this->assertIsResource($lock);
        flock($lock, LOCK_EX);
        try {
            Process::fake();
            $this->artisan('agentcms:maintenance')->assertExitCode(1);
            Process::assertNothingRan();
            $this->assertSame($before, file_get_contents($report->path()));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_管理画面は設定権限のある人だけが読み外部通信を始めない(): void
    {
        Process::fake();
        $this->actingAs(User::factory()->viewer()->create())->get('/admin/readiness')->assertForbidden();
        $this->actingAs(User::factory()->editor()->create())->get('/admin/readiness')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/readiness')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenance.state', 'missing'));
        Process::assertNothingRan();
    }

    /** @return list<array{key: string, label: string, status: string, message: string}> */
    private function checks(): array
    {
        return [
            ['key' => 'dependencies', 'label' => '依存関係', 'status' => 'pass', 'message' => '確認済み'],
            ['key' => 'certificate', 'label' => 'HTTPS', 'status' => 'pass', 'message' => '確認済み'],
        ];
    }
}
