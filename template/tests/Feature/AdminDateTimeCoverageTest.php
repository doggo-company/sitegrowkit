<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Backups\RestoreBackup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Backups\BackupBuilder;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 人が読む日時は、人の形で出す（WP 一周 10 周目 2026-09-04 / 気になり）。
 *
 * ## 何が起きていたか
 *
 * 5 周目の #10 で「管理画面の日時は JST で出す・保存は UTC」を 1 か所へまとめたが、
 * **通っていない画面が 3 つ残っていた**。実測で出ていたもの:
 *
 * ```
 * 利用者の最終ログイン        2026-09-04T12:16:56+09:00
 * backup の取得日時           2026-09-04T12:04:08+09:00
 * 復元の確認の見出し          2026-09-04T11:37:09+09:00 の backup へ戻します
 * ```
 *
 * 時間帯は合っているので**壊れてはいない**。読みにくいだけである。
 * ただし同じ管理画面の中で、投稿一覧は `2026-09-04 11:21` の形で出る。
 * **1 つの画面の中で 2 つの書き方が混ざっている。**
 */
final class AdminDateTimeCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $sandbox = storage_path('framework/testing/datetime-'.getmypid());

        config()->set('filesystems.disks.local.root', $sandbox);
        config()->set('filesystems.disks.public.root', $sandbox.'/public');
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.local.root');

        if (is_string($root)) {
            self::removeTree($root);
        }

        parent::tearDown();
    }

    /** 🔴 復元の確認の見出し（サーバが作る文字） */
    public function test_復元の確認の見出しは人の形(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);
        $id = app(BackupBuilder::class)->create()->id;

        $result = app(RestoreBackup::class)->execute(
            $id,
            OperationContext::admin((int) $owner->getKey(), (string) $owner->name),
        );

        $title = (string) $result->confirmation?->summary;

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} の backup へ戻します$/', $title, $title);
        $this->assertStringNotContainsString('T', $title);
        $this->assertStringNotContainsString('+09:00', $title);
    }

    /** 利用者の最終ログインと backup の取得日時（画面が作る文字） */
    public function test_画面は生のiso8601をそのまま描かない(): void
    {
        $checks = [
            'resources/js/pages/admin/users/Index.vue' => 'lastLoginAt',
            'resources/js/pages/admin/backups/Index.vue' => 'created_at',
        ];

        foreach ($checks as $file => $field) {
            $source = File::get(base_path($file));

            $this->assertStringContainsString(
                'formatDateTime',
                $source,
                "{$file} が admin-datetime を通していない",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\{\{\s*[a-zA-Z]+\.'.preg_quote($field, '/').'\s*(\?\?[^}]*)?\}\}/',
                $source,
                "{$file} が {$field} をそのまま描いている",
            );
        }
    }

    /**
     * **これ以上増やさない。**
     *
     * 管理画面の Vue が、日時らしき props を素で描いていないかを見張る。
     * 新しい画面が同じ形を持ち込んだらここで落ちる。
     */
    public function test_管理画面は日時をそのまま描かない(): void
    {
        $offenders = [];
        $root = base_path('resources/js/pages/admin');

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $source = File::get($file->getPathname());
            $relative = str_replace(base_path().'/', '', $file->getPathname());

            // `{{ x.created_at }}` `{{ x.lastLoginAt }}` のような素描き
            if (preg_match_all(
                '/\{\{\s*[a-zA-Z_]+\.(created_at|updated_at|lastLoginAt|last_login_at|published_at|taken_at)\s*(\?\?[^}]*)?\}\}/',
                $source,
                $matches,
            ) > 0) {
                foreach ($matches[0] as $match) {
                    $offenders[] = $relative.' → '.trim($match);
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "日時をそのまま描いている。`formatDateTime(値, timezone)` を通すこと:\n".implode("\n", $offenders),
        );
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
