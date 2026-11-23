<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\ContentExport;
use App\Support\ContentExportRuns;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentExportAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agentcms.content_export_root' => storage_path('framework/testing/exports-'.Str::random(10))]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(ContentExport::root());
        parent::tearDown();
    }

    private function launcher(): ContentExportRuns
    {
        $runs = new class extends ContentExportRuns
        {
            /** 試験中に外部プロセスは起こさない。予約後に本物のworkerを同じプロセスで走らせる。 */
            public function launch(string $id): void {}
        };
        $this->app->instance(ContentExportRuns::class, $runs);

        return $runs;
    }

    public function test_権限のない利用者は一覧開始状態ダウンロードへ直接アクセスできない(): void
    {
        $id = strtolower((string) Str::ulid());
        foreach ([Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));
            $this->get('/admin/exports')->assertForbidden();
            $this->postJson('/admin/exports')->assertForbidden();
            $this->getJson('/admin/exports/'.$id)->assertForbidden();
            $this->get('/admin/exports/'.$id.'/download')->assertForbidden();
        }
        $this->assertDirectoryDoesNotExist(ContentExport::root());
    }

    public function test_予約中はダウンロードできずworker完了後だけ持ち出せる(): void
    {
        $runs = $this->launcher();
        $this->actingAs(User::factory()->create(['role' => Role::Owner->value]));
        $id = $this->postJson('/admin/exports')->assertOk()->json('data.id');
        $this->assertSame($id, $this->postJson('/admin/exports')->assertOk()->json('data.id'));
        $this->assertSame('queued', $runs->current()['status']);
        $this->get('/admin/exports/'.$id.'/download')->assertNotFound();
        $this->assertTrue($runs->run($id, app(ContentExport::class)));
        $this->assertFalse($runs->run($id, app(ContentExport::class)));
        $this->getJson('/admin/exports/'.$id)->assertOk()->assertJsonPath('run.status', 'done');
        $this->get('/admin/exports/'.$id.'/download')->assertOk()->assertDownload('sitegrowkit-content-'.$id.'.xml');
        $this->get('/admin/exports')->assertOk();
    }

    public function test_更新が止まった処理を永遠に実行中にしない(): void
    {
        $runs = $this->launcher();
        $state = $runs->start();
        $state['updated'] = time() - 121;
        file_put_contents(ContentExport::path($state['id']).'.json', json_encode($state));
        $this->assertSame('stalled', $runs->current()['status']);
        $this->assertFalse($runs->run($state['id'], app(ContentExport::class)));
        $this->assertNotSame($state['id'], $runs->start()['id']);
    }

    public function test_起動できないときは成功表示せず代替手順を出す(): void
    {
        $runs = new class extends ContentExportRuns
        {
            public function launch(string $id): void
            {
                throw new \RuntimeException('DO-NOT-LEAK');
            }
        };
        $state = $runs->start();
        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('agentcms:content-export', $state['message']);
        $this->assertStringNotContainsString('DO-NOT-LEAK', $state['message']);
    }
}
