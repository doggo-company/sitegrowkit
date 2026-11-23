<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Maintenance\ReleaseUpdateReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** 更新済み・最新版と誤認させず、古い記録や内部の診断内容を表示しない。 */
final class ReleaseUpdateReportTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private ReleaseUpdateReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/sgk-release-report-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/.agentcms/local', 0700, true);
        $this->report = new ReleaseUpdateReport($this->root);
        $this->app->instance(ReleaseUpdateReport::class, $this->report);
        file_put_contents($this->root.'/.agentcms/project.json', json_encode($this->project(), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @return array<string, int|string> */
    private function project(): array
    {
        return ['kit_version' => '0.9.4-rc3', 'template_schema_version' => 1, 'agent_context_version' => 1];
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return ['format_version' => 1, 'checked_at' => now()->toIso8601String(), 'status' => 'verified', 'signature' => 'verified', 'applied' => false, 'project' => $this->project(), 'candidate' => ['version' => '0.9.4-rc4'], 'verdict' => 'newer', 'compatibility' => ['status' => 'same'], 'admissibility' => ['status' => 'review_required'], 'archive' => ['status' => 'verified']];
    }

    /** @param array<string, mixed> $record */
    private function save(array $record): void
    {
        file_put_contents($this->root.'/.agentcms/local/update-check.json', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_未実施と署名だけの確認と実体照合を区別する(): void
    {
        $this->assertSame('missing', $this->report->read()['state']);
        $data = $this->record();
        $data['archive'] = ['status' => 'not_checked'];
        $this->save($data);
        $result = $this->report->read();
        $this->assertTrue($result['signatureVerified']);
        $this->assertFalse($result['archiveVerified']);
        $this->assertStringContainsString('未照合', $result['message']);
        $this->save($this->record());
        $result = $this->report->read();
        $this->assertTrue($result['archiveVerified']);
        $this->assertStringContainsString('まだ更新されていません', $result['message']);
    }

    public function test_古い結果と利用中の版が変わった結果を合格にしない(): void
    {
        foreach ([now()->subDays(3)->toIso8601String(), now()->addDay()->toIso8601String()] as $date) {
            $data = $this->record();
            $data['checked_at'] = $date;
            $this->save($data);
            $this->assertSame('stale', $this->report->read()['state']);
            $this->assertFalse($this->report->read()['signatureVerified']);
        }
        $this->save($this->record());
        file_put_contents($this->root.'/.agentcms/project.json', '{"kit_version":"0.9.4-rc4","template_schema_version":1,"agent_context_version":1}');
        $this->assertSame('stale', $this->report->read()['state']);
    }

    public function test_署名失敗と互換性不明と古い候補を導入候補扱いしない(): void
    {
        foreach ([['status' => 'failed', 'why' => '/private/secret-path'], ['verdict' => 'older'], ['compatibility' => ['status' => 'candidate_behind']], ['compatibility' => ['status' => 'migration_required']], ['verdict' => 'unknown'], ['admissibility' => ['status' => 'migration_review_required']], ['admissibility' => ['status' => 'blocked']], ['admissibility' => null]] as $override) {
            $this->save(array_replace($this->record(), $override));
            $result = $this->report->read();
            $this->assertStringContainsString('導入', $result['message']);
            $this->assertStringNotContainsString('secret-path', json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('新しい候補版を確認', $result['message']);
        }
    }

    public function test_同じ候補を配布元の最新情報だと言わない(): void
    {
        $data = $this->record();
        $data['verdict'] = 'same';
        $data['candidate']['version'] = '0.9.4-rc3';
        $this->save($data);
        $this->assertStringContainsString('最新情報を自動確認した結果ではありません', $this->report->read()['message']);
    }

    public function test_壊れた記録と大きすぎる記録とリンクを合格にしない(): void
    {
        $path = $this->root.'/.agentcms/local/update-check.json';
        foreach (['{bad', str_repeat('x', 65537), '[]', '{"format_version":99}'] as $raw) {
            file_put_contents($path, $raw);
            $this->assertFalse($this->report->read()['signatureVerified']);
        }
        $this->save($this->record());
        rename($path, $this->root.'/outside.json');
        symlink($this->root.'/outside.json', $path);
        $this->assertSame('missing', $this->report->read()['state']);
        unlink($path);
        rmdir($this->root.'/.agentcms/local');
        mkdir($this->root.'/elsewhere');
        copy($this->root.'/outside.json', $this->root.'/elsewhere/update-check.json');
        symlink($this->root.'/elsewhere', $this->root.'/.agentcms/local');
        $this->assertSame('missing', $this->report->read()['state']);
        unlink($this->root.'/.agentcms/local');
    }

    public function test_管理画面は設定権限を必要とし記録を表示するだけ(): void
    {
        $this->save($this->record());
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->get('/admin/readiness')->assertForbidden();
        $before = file_get_contents($this->root.'/.agentcms/local/update-check.json');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get('/admin/readiness')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.id', $owner->getKey())->where('auth.user.name', $owner->name)->where('releaseUpdate.candidateVersion', '0.9.4-rc4')->where('releaseUpdate.signatureVerified', true)->where('releaseUpdate.archiveVerified', true));
        $this->assertSame($before, file_get_contents($this->root.'/.agentcms/local/update-check.json'));
    }
}
