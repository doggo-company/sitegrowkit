<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupRestorer;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * 復元したあと、**画面が戻った値を出す**（WP 一周 9 周目 2026-09-04 / 効くもの ①）。
 *
 * ## 何が起きていたか
 *
 * `SettingsStore::all()` は `Cache::rememberForever` で持っていて、
 * 捨てるのは `put()`（設定画面からの保存）のときだけである。
 * 復元は **DB へ直に書く**ので、キャッシュは残る。**期限が無いので永久に**。
 *
 * 実測（買った人と同じ install）。サイト名と通知先をわざと壊してから復元した:
 *
 * | | |
 * |---|---|
 * | DB の `site_name` | `"沼津どうぶつ病院"`（**正しく戻っている**） |
 * | 公開サイトの `<title>` | `あああ間違えた名前` |
 * | 管理画面のサイト設定 | `あああ間違えた名前` / `wrong@example.test` |
 * | 問い合わせの通知先 | **`wrong@example.test` に届いた** |
 *
 * 🔴 **いちばん悪いのはこの先である。**
 *
 * オーナーは設定画面を見て「復元されなかった」と思う。そして **保存を押す**。
 * 画面に出ていたのは古い値なので、`put()` がそれをそのまま書き、
 * **戻っていた値が消える**。実際にやって、DB が古い値へ上書きされるのを確かめた。
 * エラーは 1 件も出ない。
 *
 * ## 試験の置き方
 *
 * 🔴 **DB を見て緑にしてはいけない。** 今回まさに DB は正しかった。
 * 見るのは `SettingsStore` を通した値 — つまり**画面が読むのと同じ経路**である。
 */
final class RestoreCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $sandbox = storage_path('framework/testing/backups-cache-'.getmypid());

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

    /** 🔴 これが本体。画面が読む経路で、戻った値が出る */
    public function test_復元のあと設定は戻った値を返す(): void
    {
        $store = app(SettingsStore::class);

        $store->put(['site_name' => '沼津どうぶつ病院']);
        $id = app(BackupBuilder::class)->create()->id;

        // 壊す（画面からの保存と同じ経路。ここでキャッシュにも古い値が乗る）
        $store->put(['site_name' => 'あああ間違えた名前']);
        $this->assertSame('あああ間違えた名前', $store->get('site_name'));

        app(BackupRestorer::class)->restore($id);

        $this->assertSame('沼津どうぶつ病院', app(SettingsStore::class)->get('site_name'));
    }

    /**
     * 公開サイトが出す名前まで見る（DB が正しいだけでは足りない）。
     *
     * 🔴 **壊したあとに 1 度サイトを見る**のを省いてはいけない。`put()` は
     * キャッシュを捨てるので、その直後に復元すると**たまたま緑になる**。
     * 実際の順は「壊した → サイトを見た（ここで古い値が乗る）→ 慌てて戻す」である。
     */
    public function test_復元のあと公開サイトが戻った名前を出す(): void
    {
        $store = app(SettingsStore::class);

        $store->put(['site_name' => '沼津どうぶつ病院']);
        $id = app(BackupBuilder::class)->create()->id;

        $store->put(['site_name' => 'あああ間違えた名前']);
        $this->get('/')->assertOk();

        app(BackupRestorer::class)->restore($id);

        $html = $this->get('/')->assertOk()->getContent() ?: '';

        $this->assertStringContainsString('沼津どうぶつ病院', $html);
        $this->assertStringNotContainsString('あああ間違えた名前', $html);
    }

    /**
     * 管理画面も同じ。
     *
     * ここが古いままだと、オーナーは「戻っていない」と思って保存を押し、
     * **戻った値を古い値で上書きしてしまう**。
     */
    public function test_復元のあと管理画面が戻った値を出す(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);
        $store = app(SettingsStore::class);

        $store->put(['site_name' => '沼津どうぶつ病院', 'contact_email' => 'info@numazu-animal.test']);
        $id = app(BackupBuilder::class)->create()->id;

        $store->put(['site_name' => 'あああ間違えた名前', 'contact_email' => 'wrong@example.test']);

        // 保存したあと画面が描き直される。**ここで古い値がキャッシュに乗る**
        $this->actingAs($owner)->get('/admin/site')->assertOk();

        app(BackupRestorer::class)->restore($id);

        /*
         * Inertia は props を json_encode して `data-page` に載せるので、
         * 日本語は `\u6cbc\u6d25…` に escape される。**HTML の文字列一致では見えない。**
         * 画面が受け取る値そのものを見る。
         */
        $this->actingAs($owner)->get('/admin/site')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('values.site_name', '沼津どうぶつ病院')
                ->where('values.contact_email', 'info@numazu-animal.test'));
    }

    /**
     * **期限の無いキャッシュを足したら、復元の手当ても足す。**
     *
     * この試験が守るのは実装ではなく**約束**である。`rememberForever` は
     * 「誰かが捨てるまで永久に残る」なので、DB を丸ごと置き換える復元と必ず衝突する。
     * 新しく足した人がここで気づけるように、場所を数え上げて固定しておく。
     *
     * 増やすときは `BackupRestorer` 側の後始末も足してから、この一覧を更新する。
     */
    public function test_期限の無いキャッシュは復元の手当てとセットで増やす(): void
    {
        $known = ['core/src/Settings/SettingsStore.php'];

        $found = [];

        foreach (['app', 'core/src', 'modules'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // 説明の文章に出てくる語では拾わない。**呼び出しだけ**を数える
                if (str_contains(File::get($file->getPathname()), 'Cache::rememberForever(')) {
                    $found[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        sort($found);

        $this->assertSame(
            $known,
            $found,
            '期限の無いキャッシュが増減している。復元（BackupRestorer）で捨てる手当てを足してから、この一覧を更新すること',
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
