<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 戻せない backup を押したときに、画面が何を言うか（WP 一周 8 周目 2026-09-04 / 効くもの ③）。
 *
 * ## 何が起きていたか
 *
 * 一覧の「これへ戻す」を押すと `abort(409, …)` に落ちていた。Inertia は非 Inertia の
 * 応答を **全画面の `about:srcdoc` iframe** に流し込むので、買った人の画面に出るのは
 * Laravel の例外ページである:
 *
 * ```
 * Conflict
 * Symfony\Component\HttpKernel\Exception\HttpException
 * vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1447
 * この backup は復元できません: …（DB の形が違います）
 * LARAVEL 13.24.0  PHP 8.5.10  UNHANDLED  CODE 0
 * Exception trace  2 vendor frames …
 * ```
 *
 * 言うべきことは言えている。**framework の stack trace に埋まっている**だけである。
 * しかも親の CSP が iframe の inline script を止めるので、折りたたみもコピーも動かない
 * （この 1 操作で console error が 75 件出た）。
 *
 * 🔴 **本番はもっと悪い。** 生成 project の `.agentcms/deploy/env.production.example` は
 * `APP_DEBUG=false` なので、**「Conflict」だけが出て理由が消える**。
 * 「なぜ戻せないのか」「どれなら戻せるのか」が分からないまま止まる。
 *
 * ## どう直すか
 *
 * 戻せないことは**利用者が次の手を選べる状態**であって、異常終了ではない。
 * 一覧へ戻し、理由と**次にできること**を画面の言葉で出す。
 * 404（無い）と 403（権限が無い）は HTTP のまま — こちらは経路の話である。
 */
final class BackupRestoreConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $sandbox = storage_path('framework/testing/backups-conflict-'.getmypid());

        config()->set('filesystems.disks.local.root', $sandbox);
        config()->set('filesystems.disks.public.root', $sandbox.'/public');

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.local.root');

        if (is_string($root)) {
            self::removeTree($root);
        }

        parent::tearDown();
    }

    /** 🔴 これが本体。例外ページに落とさない */
    public function test_戻せないbackupで例外ページにしない(): void
    {
        $id = $this->staleBackup();

        $response = $this->actingAs($this->owner)
            ->from('/admin/backups')
            ->post("/admin/backups/{$id}/restore");

        $response->assertRedirect('/admin/backups');
        $this->assertSame(0, DB::table('users')->where('id', $this->owner->getKey())->value('session_epoch'));
    }

    /** 理由は、そのまま画面の言葉として渡す */
    public function test_戻せない理由を画面へ渡す(): void
    {
        $id = $this->staleBackup();

        $this->actingAs($this->owner)->from('/admin/backups')
            ->post("/admin/backups/{$id}/restore")
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'この backup は復元できません')
                && str_contains($message, 'DB の形が違います'));
    }

    /**
     * **理由だけでは足りない。** 次にできることを添える。
     *
     * 一覧には戻せる backup が並んでいる（並び順は別途 8 周目 ② で直す）ので、
     * 「ほかを選ぶ」「いまの状態から取り直す」の 2 つを出す。
     * 取り直しのコマンドは一覧が出しているものと同じ文字列にする。
     */
    public function test_次にできることを添える(): void
    {
        $id = $this->staleBackup();

        $this->actingAs($this->owner)->from('/admin/backups')
            ->post("/admin/backups/{$id}/restore")
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'ほかの backup')
                && str_contains($message, 'php artisan agentcms:backup'));
    }

    /** 一覧まで届いて、そこで読める */
    public function test_一覧が受け取って描く(): void
    {
        $id = $this->staleBackup();

        $this->actingAs($this->owner)->from('/admin/backups')
            ->followingRedirects()
            ->post("/admin/backups/{$id}/restore")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/backups/Index')
                ->where('flash.error', fn (?string $m): bool => is_string($m)
                    && str_contains($m, 'この backup は復元できません')));
    }

    /** 出す場所は 1 か所（success と同じ layout）。画面ごとに書かない */
    public function test_layoutが1か所で描く(): void
    {
        $source = File::get(base_path('resources/js/components/FlashMessage.vue'));

        $this->assertStringContainsString('data-testid="flash-error"', $source);
        // 読み上げに載せる。success は status、こちらは alert
        $this->assertStringContainsString('role="alert"', $source);
    }

    /** 無い / 権限が無いは HTTP のまま（経路の話であって、次の手が無い） */
    public function test_無いidと権限は今までどおり(): void
    {
        $this->actingAs($this->owner)->post('/admin/backups/../etc/restore')->assertNotFound();

        $id = $this->staleBackup();
        $editor = User::factory()->create(['role' => Role::Editor->value]);

        $this->actingAs($editor)->post("/admin/backups/{$id}/restore")->assertForbidden();
    }

    /**
     * 取ったあとに「いまだけにある変更」を 1 件作る。
     *
     * `BackupRestorer::verify()` は manifest の migrations といまの migrations を
     * 突き合わせるので、行を 1 つ足せば同じ conflict になる（実物と同じ経路）。
     */
    private function staleBackup(): string
    {
        $manifest = app(BackupBuilder::class)->create();

        DB::table('migrations')->insert([
            'migration' => '9999_99_99_999999_round8_probe',
            'batch' => 99,
        ]);

        return $manifest->id;
    }

    private static function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
