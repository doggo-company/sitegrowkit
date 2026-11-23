<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Application\Backups\RestoreBackup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 戻す前に、**何が失われるか**を見せる（WP 一周 9 周目 2026-09-04 / 気になり ①）。
 *
 * ## 何が起きていたか
 *
 * 確認は**表ごとの件数の増減**だけを出していた。実測で出たのはこの 2 行である:
 *
 * ```
 * audit_logs: 13 件 → 9 件
 * not_found_logs: 1 件 → 0 件
 * ```
 *
 * 機械の名前で、しかも**いちばん気にならないもの**しか出ていない。
 *
 * 🔴 **もっと悪いのは、出ないほうである。**
 *
 * 件数が変わらない変更は 1 行も出ない。記事の誤字を直した、公開の状態を変えた、
 * サイト名を入れ替えた — どれも行数は同じなので、確認は**何も言わない**。
 * それでも復元すれば中身は置き換わる。**戻せない操作の前に、失われるものが見えない。**
 *
 * ## どう見せるか
 *
 * backup を取った時刻より後に**足された・変えられた**行を数え、
 * 人が知っている名前（投稿・ページ・サイト設定…）と代表の題で出す。
 * 機械の名前（`audit_logs` など）は「そのほかの記録」にまとめる。
 */
final class RestoreImpactTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $sandbox = storage_path('framework/testing/backups-impact-'.getmypid());

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

    /** 取ったあとに足したものは、名前で出る */
    public function test_あとから足したものを名前と題で出す(): void
    {
        $id = app(BackupBuilder::class)->create()->id;
        sleep(1);
        $this->entry('予防接種のご案内');

        $impact = $this->impact($id);

        $this->assertStringContainsString('投稿', $impact);
        $this->assertStringContainsString('予防接種のご案内', $impact);
    }

    /**
     * 🔴 これが本体。**件数が変わらない変更こそ出す。**
     *
     * 誤字を直しただけなら行数は同じ。それでも復元すれば直す前へ戻る。
     */
    public function test_件数が変わらない書き換えも出す(): void
    {
        $entry = $this->entry('あとで直す記事');
        $id = app(BackupBuilder::class)->create()->id;

        sleep(1);
        DB::table('post')->where('id', $entry)->update(['title' => '直したあとの題', 'updated_at' => now()]);

        $impact = $this->impact($id);

        $this->assertStringContainsString('投稿', $impact);
        $this->assertStringContainsString('直したあとの題', $impact);
    }

    /** サイト設定も同じ（ここを戻したくて押す人がいちばん多い） */
    public function test_設定の変更も出す(): void
    {
        $id = app(BackupBuilder::class)->create()->id;
        sleep(1);
        app(SettingsStore::class)->put(['site_name' => 'あとで変えた名前']);

        $this->assertStringContainsString('サイト設定', $this->impact($id));
    }

    /** 更新記録だけで、削除も含む変更の不存在を断言しない。 */
    public function test_更新記録が無くても変更なしとは断言しない(): void
    {
        $id = app(BackupBuilder::class)->create()->id;

        $this->assertStringNotContainsString('変更はありません', $this->impact($id));
    }

    public function test_取得と同じ秒の編集も取りこぼさない(): void
    {
        $this->freezeTime();
        $entry = $this->entry('編集前');
        $id = app(BackupBuilder::class)->create()->id;
        DB::table('post')->where('id', $entry)->update(['title' => '同じ秒に編集した題', 'updated_at' => now()]);
        $this->assertStringContainsString('同じ秒に編集した題', $this->impact($id));
    }

    /** 機械の名前を人に見せない */
    public function test_表の名前をそのまま出さない(): void
    {
        $id = app(BackupBuilder::class)->create()->id;
        sleep(1);
        $this->entry('あとから足した記事');

        $impact = $this->impact($id);

        foreach (['audit_logs', 'not_found_logs', 'content_entry_revisions', 'post:'] as $raw) {
            $this->assertStringNotContainsString($raw, $impact);
        }
    }

    /**
     * 箇条書きの記号を自分で書かない。
     *
     * 画面はこの配列を `<ul><li>` で描くので、頭に付けると点が 2 つ並ぶ。
     * （9 周目に実物で 1 度やって気づいた）
     */
    public function test_箇条書きの記号を自分で書かない(): void
    {
        $id = app(BackupBuilder::class)->create()->id;
        sleep(1);
        $this->entry('あとから足した記事');

        $this->assertStringNotContainsString('・', $this->impact($id));
    }

    /** 戻せないことと、その前に取ることは今までどおり言う */
    public function test_今までの断りは残っている(): void
    {
        $id = app(BackupBuilder::class)->create()->id;
        $impact = $this->impact($id);

        $this->assertStringContainsString('戻す直前に自動で backup を取ってから置き換えます', $impact);
        $this->assertStringContainsString('全員のログインが切れます', $impact);
        $this->assertStringContainsString('取得時に無かったメディアは公開面から消えます', $impact);
    }

    private function impact(string $id): string
    {
        $result = app(RestoreBackup::class)->execute(
            $id,
            OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name),
        );

        return implode("\n", $result->confirmation?->impact ?? []);
    }

    private function entry(string $title): int
    {
        return (int) DB::table('post')->insertGetId([
            'title' => $title, 'slug' => 'p-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'draft', 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
