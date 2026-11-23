<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Backups\BackupBuilder;
use AgentCms\Core\Backups\BackupManifest;
use AgentCms\Core\Backups\BackupPaths;
use App\Models\User;
use App\Support\Capability;
use App\Support\OperationCapabilities;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * 本文の一括置換の管理画面（P1 ⑧ / GUI 化・2026-09-10）。
 *
 * CLI（`ContentReplaceTest`）と**同じ service** を通るので、ここで見るのは HTTP の形と、
 * 画面だけに課した 2 つ — **Owner 限定**と **中身まで確かめた backup** — である。
 */
final class ContentReplaceAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // 本物の storage を触らない（`BackupAdminTest` と同じ理由）
        $sandbox = storage_path('framework/testing/replace-admin-'.getmypid());
        config()->set('filesystems.disks.local.root', $sandbox);
        config()->set('filesystems.disks.public.root', $sandbox.'/public');

        // 一括置換は作りの画面（制作者だけ）。名前は既存の呼び出しに合わせて残している（UX 一周 2026-09-28）
        $this->owner = User::factory()->create(['role' => Role::Developer->value]);
    }

    protected function tearDown(): void
    {
        $root = config('filesystems.disks.local.root');

        if (is_string($root) && is_dir($root)) {
            exec('rm -rf '.escapeshellarg($root));
        }

        parent::tearDown();
    }

    // ── 認可 ────────────────────────────────────────────────

    public function test_制作者以外はオーナーでも一覧も予告も実行もできない(): void
    {
        $this->backup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        foreach ([Role::Owner, Role::Administrator, Role::Editor, Role::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));

            $this->get('/admin/content-replace')->assertForbidden();
            $this->postJson('/admin/content-replace/preview', $this->body())->assertForbidden();
            $this->postJson('/admin/content-replace/apply', $this->body(), ['X-Confirmation' => 'x'])->assertForbidden();
        }

        $this->assertSame('<p>旧社名の話</p>', DB::table('news')->where('id', $id)->value('body'));
        // 権限は表で決まる（画面で隠しているだけではない）
        $this->assertSame(Capability::ContentReplace, OperationCapabilities::for('content.replace'));
        $this->assertFalse(Role::Administrator->can(Capability::ContentReplace));
        $this->assertFalse(Role::Owner->can(Capability::ContentReplace));
    }

    /** オーナーが叩いても 403 で、誰が試みたかが監査に残る（関所で先に弾かない理由） */
    public function test_オーナーの拒否も監査に残る(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        $this->actingAs($owner)->postJson('/admin/content-replace/apply', $this->body())->assertForbidden();

        $row = DB::table('audit_logs')->where('operation', 'content.replace')->where('result', 'forbidden')->first();
        $this->assertNotNull($row, '拒否が監査に残っていない');
        $this->assertSame((int) $owner->getKey(), (int) $row->actor_id);
    }

    public function test_拒否と対象0件の実行も一度だけ監査する(): void
    {
        // 認可拒否でも誰が試みたかが残り、読み取りの予告と二重に記録しない。
        $viewer = User::factory()->create(['role' => Role::Viewer->value]);
        $this->actingAs($viewer)->postJson('/admin/content-replace/apply', $this->body())->assertForbidden();
        $this->assertSame(1, DB::table('audit_logs')->where('operation', 'content.replace')->where('result', 'forbidden')->count());

        $this->backup();
        $this->preview()->assertOk()->assertJsonPath('data.matched', 0);
        $this->apply('unused')->assertOk()->assertJsonPath('data.changed', 0);
        $rows = DB::table('audit_logs')->where('operation', 'content.replace')->where('result', 'success')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $this->owner->getKey(), (int) $rows->first()->actor_id);
    }

    public function test_aiへ公開しない(): void
    {
        $this->assertFalse(OperationCatalog::allows('content.replace'));
    }

    public function test_制作者は一覧を開けて対象の型と項目だけが渡る(): void
    {
        $this->backup();

        $response = $this->actingAs($this->owner)->get('/admin/content-replace')->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['backup']['ok']);
        $ids = array_column($props['targets'], 'id');
        $this->assertContains('news', $ids);
        $this->assertNotContains('contact', $ids, '受信箱は対象にしない');
        $this->assertNotContains('media', $ids);

        $news = collect($props['targets'])->firstWhere('id', 'news');
        $keys = array_column($news['fields'], 'key');
        $this->assertContains('body', $keys);
        $this->assertNotContains('status', $keys, '状態列は対象にしない');
        $this->assertNotContains('slug', $keys, 'slug は対象にしない');
    }

    // ── 予告 → 確認 → 実行 ──────────────────────────────────

    public function test_予告は変えずに抜粋を出し_tokenを返せば置き換わる(): void
    {
        $this->backup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');
        $before = (int) DB::table('news')->where('id', $id)->value('lock_version');

        $preview = $this->preview()->assertOk();
        $preview->assertJsonPath('data.matched', 1)->assertJsonPath('data.total', 1);
        $this->assertSame('<p>旧社名の話</p>', DB::table('news')->where('id', $id)->value('body'), '予告が本文を変えた');

        $sample = $preview->json('data.samples.0');
        $this->assertSame($id, $sample['id']);
        $this->assertSame('見出し', $sample['title']);
        $this->assertStringContainsString('旧社名', $sample['before']);
        $this->assertStringContainsString('新社名', $sample['after']);

        $token = $preview->json('data.confirmation.token');
        $this->assertIsString($token);

        $this->apply($token)->assertOk()->assertJsonPath('data.changed', 1);

        $this->assertSame('<p>新社名の話</p>', DB::table('news')->where('id', $id)->value('body'));
        // 古い編集画面は保存時に競合になる
        $this->assertGreaterThan($before, (int) DB::table('news')->where('id', $id)->value('lock_version'));
        // 置換前も置換後も版に残る
        $snapshots = (string) json_encode(DB::table('content_entry_revisions')->where('content_id', $id)->pluck('snapshot'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('旧社名', $snapshots);
        $this->assertStringContainsString('新社名', $snapshots);
    }

    public function test_監査は実ユーザーを記録し本文を入れない(): void
    {
        $this->backup();
        $this->news('見出し', '<p>旧社名の話</p>');

        $this->apply($this->token())->assertOk();

        $rows = DB::table('audit_logs')->where('operation', 'content.replace')->where('result', 'success')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $this->owner->getKey(), (int) $rows->first()->actor_id);

        $logged = (string) json_encode(DB::table('audit_logs')->where('operation', 'content.replace')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('旧社名', $logged);
        $this->assertStringNotContainsString('新社名', $logged);
    }

    public function test_tokenは1回で使い切る(): void
    {
        $this->backup();
        $this->news('あ', '<p>旧社名 1</p>');
        $this->news('い', '<p>旧社名 1</p>');

        $token = $this->token();
        $this->apply($token)->assertOk()->assertJsonPath('data.changed', 2);

        // もう対象は無いので 0 件（token が生きていても増えない）
        $this->news('う', '<p>旧社名 3</p>');
        $this->apply($token)->assertStatus(409)->assertJsonStructure(['confirmation' => ['token']]);
        $this->assertSame(1, DB::table('news')->where('body', 'like', '%旧社名%')->count());
    }

    public function test_予告のあとに本文が変わったら再確認になる(): void
    {
        $this->backup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');
        $token = $this->token();

        DB::table('news')->where('id', $id)->update(['body' => '<p>別の人が追記した旧社名の話</p>']);

        $response = $this->apply($token)->assertStatus(409);
        $response->assertJsonStructure(['confirmation' => ['token', 'summary']]);
        $this->assertSame('<p>別の人が追記した旧社名の話</p>', DB::table('news')->where('id', $id)->value('body'));
    }

    public function test_予告のあとに件数が増えたら再確認になる(): void
    {
        $this->backup();
        $this->news('あ', '<p>旧社名 1</p>');
        $token = $this->token();
        $late = $this->news('い', '<p>旧社名 2</p>');

        $this->apply($token)->assertStatus(409);
        $this->assertSame(2, DB::table('news')->where('body', 'like', '%旧社名%')->count());
        $this->assertStringContainsString('旧社名', (string) DB::table('news')->where('id', $late)->value('body'));
    }

    public function test_別の制作者のtokenは使えない(): void
    {
        $this->backup();
        $this->news('見出し', '<p>旧社名の話</p>');
        $token = $this->token();

        $other = User::factory()->create(['role' => Role::Developer->value]);
        $this->actingAs($other)
            ->postJson('/admin/content-replace/apply', $this->body(), ['X-Confirmation' => $token])
            ->assertStatus(409);
        $this->assertSame(1, DB::table('news')->where('body', 'like', '%旧社名%')->count());
    }

    // ── 保存時の検査 ────────────────────────────────────────

    public function test_richtextはscriptを残さない(): void
    {
        $this->backup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');
        $to = '<script>alert(9)</script><strong>新社名</strong>';

        $this->apply($this->token(to: $to), to: $to)->assertOk();

        $body = (string) DB::table('news')->where('id', $id)->value('body');
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('<strong>新社名</strong>', $body);
    }

    public function test_途中の1件が項目の条件に反したら全件と版とlock_versionを戻す(): void
    {
        $this->backup();
        $first = $this->news('x', '<p>本文</p>');
        $second = $this->news(str_repeat('前', 30).'x', '<p>本文</p>');
        $to = str_repeat('新', 100);

        $token = $this->token(field: 'title', from: 'x', to: $to);
        $this->apply($token, field: 'title', from: 'x', to: $to)->assertStatus(409)->assertJsonMissingPath('confirmation');

        $this->assertSame('x', DB::table('news')->where('id', $first)->value('title'));
        $this->assertSame(str_repeat('前', 30).'x', DB::table('news')->where('id', $second)->value('title'));
        $this->assertSame(0, DB::table('content_entry_revisions')->count());
        $this->assertSame(0, (int) DB::table('news')->where('id', $first)->value('lock_version'));
    }

    public function test_空のfromは入力の誤りとして項目ごとに返す(): void
    {
        $this->backup();
        $this->news('見出し', '<p>旧社名の話</p>');

        $this->preview(from: '')->assertStatus(422)->assertJsonValidationErrors(['from']);
    }

    public function test_状態列は対象にできない(): void
    {
        $this->backup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $this->preview(field: 'status', from: 'published', to: 'draft')->assertStatus(422)->assertJsonValidationErrors(['field']);
        $this->assertSame('published', DB::table('news')->where('id', $id)->value('status'));
    }

    /**
     * **予告の件数と変わる件数が一致する**（2026-09-10 レビュー修正）。
     *
     * LIKE は照合順序で `a` / `A`、全角、アクセントを同一視し、`\` を escape 文字として
     * 食う。候補はすべて PHP の文字どおりの一致で確かめるので、予告に出た数だけが変わる。
     * SQLite と実 MySQL の双方で同じ結果になること（MySQL は Codex が独立 DB で実行する）。
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function literalMatchCases(): iterable
    {
        yield '大小文字' => ['acme', ['<p>acme 1</p>', '<p>the acme</p>'], ['<p>Acme</p>', '<p>ACME</p>']];
        yield 'パーセント' => ['50%引', ['<p>50%引 です</p>'], ['<p>50X引 です</p>', '<p>50%%引</p>']];
        yield 'アンダースコア' => ['a_b', ['<p>a_b</p>'], ['<p>aXb</p>', '<p>a-b</p>']];
        yield 'バックスラッシュ' => ['\\old\\', ['<p>C:\\old\\x</p>'], ['<p>C:/old/x</p>', '<p>C:\\Old\\x</p>']];
        yield '全角' => ['ＡＢＣ', ['<p>ＡＢＣ</p>'], ['<p>ABC</p>']];
        yield 'アクセント' => ['café', ['<p>café</p>'], ['<p>cafe</p>', '<p>CAFÉ</p>']];
        yield 'escape文字' => ['注意!! ', ['<p>注意!! 本文</p>'], ['<p>注意! 本文</p>']];
        yield '記号の組み合わせ' => ['100%_ok\\!', ['<p>100%_ok\\!</p>'], ['<p>100X_ok\\!</p>', '<p>100%Xok\\!</p>', '<p>100%_ok/!</p>']];
    }

    /**
     * @param  list<string>  $hits
     * @param  list<string>  $misses
     */
    #[DataProvider('literalMatchCases')]
    public function test_予告のmatchedとtotalは実際に変わる件数と一致する(string $from, array $hits, array $misses): void
    {
        $this->backup();
        $hitIds = array_map(fn (string $body): int => $this->news('hit', $body), $hits);
        $missIds = array_map(fn (string $body): int => $this->news('miss', $body), $misses);

        $preview = $this->preview(from: $from, to: '★')->assertOk();
        $preview->assertJsonPath('data.matched', count($hits))->assertJsonPath('data.total', count($hits));
        $this->assertSame($hitIds, array_column($preview->json('data.samples'), 'id'), '予告の抜粋が対象と違う');

        $this->apply($preview->json('data.confirmation.token'), from: $from, to: '★')
            ->assertOk()->assertJsonPath('data.changed', count($hits));

        foreach ($hitIds as $id) {
            $this->assertStringContainsString('★', (string) DB::table('news')->where('id', $id)->value('body'));
        }

        foreach ($missIds as $i => $id) {
            $this->assertSame($misses[$i], DB::table('news')->where('id', $id)->value('body'), '対象でない行が変わった');
        }
    }

    public function test_失敗の診断に本文とsqlを残さない(): void
    {
        $this->backup();
        $this->news('x', '<p>本文</p>');
        $this->news(str_repeat('前', 30).'x', '<p>本文</p>');
        $to = str_repeat('新', 100);

        Log::spy();

        $this->apply($this->token(field: 'title', from: 'x', to: $to), field: 'title', from: 'x', to: $to)->assertStatus(409);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $dumped = (string) json_encode($context, JSON_UNESCAPED_UNICODE);

            return $message === 'content.replace.aborted'
                && is_string($context['exception'] ?? null)
                && ! str_contains($dumped, '新新')
                && ! str_contains($dumped, '前前')
                && ! str_contains(strtolower($dumped), 'select ')
                && ! str_contains(strtolower($dumped), 'update ');
        });
    }

    // ── 上限 ────────────────────────────────────────────────

    public function test_上限を超えたら1件も触らず条件を狭めるよう案内する(): void
    {
        $this->backup();

        for ($i = 0; $i < 201; $i++) {
            $this->news('記事 '.$i, '<p>旧社名 '.$i.'</p>');
        }

        $response = $this->preview()->assertStatus(409);
        $response->assertJsonPath('data.total', 201)->assertJsonPath('data.max', 200);
        $this->assertStringContainsString('条件を狭めて', (string) $response->json('message'));

        // 画面から `limit` を送っても、先頭だけを実行する形にはならない
        $this->postJson('/admin/content-replace/apply', [...$this->body(), 'limit' => 5], ['X-Confirmation' => 'x'])->assertStatus(409);
        $this->assertSame(201, DB::table('news')->where('body', 'like', '%旧社名%')->count());
    }

    // ── backup ──────────────────────────────────────────────

    public function test_backupが無ければ止まり画面へ案内する(): void
    {
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $props = $this->actingAs($this->owner)->get('/admin/content-replace')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['backup']['ok']);
        $this->assertSame('missing', $props['backup']['reason']);
        $this->assertSame('/admin/backups', $props['backup']['url']);

        $this->preview()->assertStatus(409)->assertJsonPath('data.backup', 'missing');
        $this->apply('x')->assertStatus(409)->assertJsonPath('data.backup', 'missing');
        $this->assertStringContainsString('旧社名', (string) DB::table('news')->where('id', $id)->value('body'));
    }

    public function test_古いbackupでは止まり画面から抜け道は無い(): void
    {
        $this->backup();
        $this->news('見出し', '<p>旧社名の話</p>');

        $this->travel(25)->hours();

        $this->preview()->assertStatus(409)->assertJsonPath('data.backup', 'stale');
        // CLI の `--allow-stale-backup` に当たるものを送っても効かない
        $this->postJson('/admin/content-replace/preview', [...$this->body(), 'allow_stale_backup' => true, 'allowStaleBackup' => true])
            ->assertStatus(409)->assertJsonPath('data.backup', 'stale');
    }

    public function test_中身が壊れたbackupは戻る先として数えない(): void
    {
        $manifest = $this->backup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        // 目録に無いファイルを足す（目録は読めるので CLI の検査は通るが、復元はできない）
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(BackupPaths::archive($manifest->id)));
        $zip->addFromString('media/original/injected.txt', 'x');
        $zip->close();

        $response = $this->preview()->assertStatus(409);
        $response->assertJsonPath('data.backup', 'broken');
        $this->assertNotEmpty($response->json('data.problems'));
        $this->assertStringContainsString('旧社名', (string) DB::table('news')->where('id', $id)->value('body'));
    }

    // ── helpers ─────────────────────────────────────────────

    /** @return array{type: string, field: string, from: string, to: string} */
    private function body(string $field = 'body', string $from = '旧社名', string $to = '新社名'): array
    {
        return ['type' => 'news', 'field' => $field, 'from' => $from, 'to' => $to];
    }

    private function preview(string $field = 'body', string $from = '旧社名', string $to = '新社名'): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/admin/content-replace/preview', $this->body($field, $from, $to));
    }

    private function apply(string $token, string $field = 'body', string $from = '旧社名', string $to = '新社名'): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/admin/content-replace/apply', $this->body($field, $from, $to), ['X-Confirmation' => $token]);
    }

    private function token(string $field = 'body', string $from = '旧社名', string $to = '新社名'): string
    {
        $token = $this->preview($field, $from, $to)->assertOk()->json('data.confirmation.token');
        $this->assertIsString($token, '予告が token を出していない');

        return $token;
    }

    private function news(string $title, string $body): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => 'r-'.uniqid(), 'body' => $body,
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 本物の backup を 1 つ取る（**中身まで確かめられる**形式 2） */
    private function backup(): BackupManifest
    {
        return app(BackupBuilder::class)->create();
    }
}
