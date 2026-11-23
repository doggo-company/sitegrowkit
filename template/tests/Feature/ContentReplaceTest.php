<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Backups\BackupManifest;
use AgentCms\Core\Backups\BackupPaths;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * 本文の一括置換（P1 ⑧）。
 *
 * ## この操作は間引きより重い
 *
 * 版の間引きが消すのは自動保存の版で、**本体は残る**。
 * 一括置換は**本文そのものを書き換え、元の文字列はどこにも残らない**。
 * だから **backup が前提**であり、**既定では 1 文字も変えない**。
 */
final class ContentReplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_途中の項目が文字数制限に違反したら先行更新と履歴も戻る(): void
    {
        $this->putBackup();
        $first = $this->news('x', '<p>本文</p>');
        $second = $this->news(str_repeat('前', 30).'x', '<p>本文</p>');
        $arguments = ['--type' => 'news', '--field' => 'title', '--from' => 'x', '--to' => str_repeat('新', 100)];
        Artisan::call('agentcms:content-replace', $arguments);
        preg_match('/--confirm=([0-9a-f]+)/', Artisan::output(), $match);
        $this->assertNotEmpty($match[1] ?? null);
        $this->artisan('agentcms:content-replace', [...$arguments, '--apply' => true, '--confirm' => $match[1]])->assertExitCode(1);
        $this->assertSame('x', DB::table('news')->where('id', $first)->value('title'));
        $this->assertSame(str_repeat('前', 30).'x', DB::table('news')->where('id', $second)->value('title'));
        $this->assertSame(0, DB::table('content_entry_revisions')->count());
        $this->assertSame(0, (int) DB::table('news')->where('id', $first)->value('lock_version'));
    }

    public function test_公開状態列は文章の一括置換で変更できない(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>本文</p>');
        $this->artisan('agentcms:content-replace', ['--type' => 'news', '--field' => 'status', '--from' => 'published', '--to' => 'draft'])->assertExitCode(1);
        $this->assertSame('published', DB::table('news')->where('id', $id)->value('status'));
    }

    public function test_一括置換後は古い編集画面の版が一致しない(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');
        $before = (int) DB::table('news')->where('id', $id)->value('lock_version');
        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
            '--apply' => true, '--confirm' => $this->announce(),
        ])->assertExitCode(0);
        $this->assertGreaterThan($before, (int) DB::table('news')->where('id', $id)->value('lock_version'));
    }

    public function test_件数と対象_i_dが同じでも本文が変わったら再確認する(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');
        $token = $this->announce();
        DB::table('news')->where('id', $id)->update(['body' => '<p>別の人が追記した旧社名の話</p>']);
        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
            '--apply' => true, '--confirm' => $token,
        ])->assertExitCode(1);
        $this->assertSame('<p>別の人が追記した旧社名の話</p>', DB::table('news')->where('id', $id)->value('body'));
    }

    public function test_置換もrichtextの保存時サニタイズを通る(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');
        $replacement = '<script>alert(9)</script><strong>新社名</strong>';
        $token = $this->announce(to: $replacement);
        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => $replacement,
            '--apply' => true, '--confirm' => $token,
        ])->assertExitCode(0);
        $body = (string) DB::table('news')->where('id', $id)->value('body');
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('<strong>新社名</strong>', $body);
    }

    protected function setUp(): void
    {
        parent::setUp();

        BackupPaths::ensureRoot();

        foreach (glob(BackupPaths::root().'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function news(string $title, string $body): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => 'r-'.uniqid(), 'body' => $body,
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * backup を 1 つ置く。
     *
     * **中身が読める zip にする。** 検査は `BackupCatalog` を通るので、
     * 目録が読めない zip は「戻る先」として数えられない（それが仕様である）。
     */
    private function putBackup(?string $createdAt = null): void
    {
        $manifest = new BackupManifest(
            id: 'test', format: 1, createdAt: $createdAt ?? now()->toIso8601String(),
            app: [], migrations: [], tables: [], media: ['files' => 0, 'bytes' => 0],
            checksums: [], excluded: [], sizeBytes: 0,
        );

        $zip = new ZipArchive;
        $zip->open(BackupPaths::root().'/'.now()->format('Ymd-His').'-test.zip', ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->close();
    }

    /** 予告を走らせて token を取る（**予告は 1 文字も変えない**） */
    private function announce(string $from = '旧社名', string $to = '新社名', ?int $limit = null): string
    {
        Artisan::call('agentcms:content-replace', array_filter([
            '--type' => 'news', '--field' => 'body', '--from' => $from, '--to' => $to,
            '--limit' => $limit,
        ], static fn (mixed $v): bool => $v !== null));

        $matched = [];
        preg_match('/--confirm=([0-9a-f]+)/', Artisan::output(), $matched);

        return $matched[1] ?? '';
    }

    /**
     * **backup が無ければ触らない。**
     *
     * 戻せない操作を、戻る先が無い状態で始めない。
     */
    public function test_backupが無ければ止まる(): void
    {
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
        ])->assertExitCode(1);

        $this->assertStringContainsString(
            '旧社名',
            (string) DB::table('news')->where('id', $id)->value('body'),
        );
    }

    /**
     * **壊れた zip は「戻る先」ではない。**
     *
     * ファイルが在るかだけを見ると、書きかけの zip でも通ってしまう。
     */
    public function test_目録が読めないbackupは数に入らない(): void
    {
        file_put_contents(BackupPaths::root().'/20260101-000000-broken.zip', 'not a zip');
        $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
        ])->assertExitCode(1);
    }

    /** **古い backup では止まる。** 昨日の状態へしか戻れないなら、それは戻る先ではない */
    public function test_古いbackupでは止まる(): void
    {
        $this->putBackup(now()->subDays(3)->toIso8601String());
        $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
        ])->assertExitCode(1);

        // 明示すれば進める（**判断は人が持つ**）
        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
            '--allow-stale-backup' => true,
        ])->assertExitCode(0);
    }

    /** **既定では 1 文字も変えない。** 予告を出すだけ */
    public function test_既定では予告だけ(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertStringContainsString(
            '旧社名',
            (string) DB::table('news')->where('id', $id)->value('body'),
        );
    }

    /**
     * **予告を経ないと置けない。**
     *
     * `--apply` だけで動くなら、予告は「見てもいい表示」でしかない。
     */
    public function test_tokenが無ければ置き換わらない(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true,
        ])->assertExitCode(1);

        $this->assertStringContainsString(
            '旧社名',
            (string) DB::table('news')->where('id', $id)->value('body'),
        );
    }

    public function test_予告のtokenを返せば置き換わる(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $token = $this->announce();
        $this->assertNotSame('', $token, '予告が token を出していない');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true, '--confirm' => $token,
        ])->assertExitCode(0);

        $this->assertSame(
            '<p>新社名の話</p>',
            (string) DB::table('news')->where('id', $id)->value('body'),
        );
    }

    /**
     * **予告と実行がずれない。**
     *
     * 「2 件」と聞いて押したのに 3 件変わる、を仕組みの側で止める。
     * token は**件数を含む指紋**に縛られているので、増えていれば合わない。
     */
    public function test_予告のあとに対象が増えたらtokenが効かない(): void
    {
        $this->putBackup();
        $this->news('あ', '<p>旧社名 1</p>');
        $this->news('い', '<p>旧社名 2</p>');

        $token = $this->announce();

        // 予告のあとに 3 件目が入った
        $late = $this->news('う', '<p>旧社名 3</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true, '--confirm' => $token,
        ])->assertExitCode(1);

        $this->assertSame(3, DB::table('news')->where('body', 'like', '%旧社名%')->count());
        $this->assertStringContainsString(
            '旧社名',
            (string) DB::table('news')->where('id', $late)->value('body'),
        );
    }

    /** **予告した件数が、そのまま置き換わる件数になる。** */
    public function test_予告した件数と置き換わる件数が同じ(): void
    {
        $this->putBackup();
        $this->news('あ', '<p>旧社名 1</p>');
        $this->news('い', '<p>旧社名 2</p>');
        $this->news('う', '<p>関係ない</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
        ]);
        $announced = Artisan::output();

        $matched = [];
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->assertStringContainsString('2 件', $announced);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('2 件')->assertExitCode(0);

        $this->assertSame(0, DB::table('news')->where('body', 'like', '%旧社名%')->count());
        $this->assertSame(2, DB::table('news')->where('body', 'like', '%新社名%')->count());
    }

    /**
     * **戻せる道を残す。**
     *
     * 版を残さないと、backup を丸ごと戻す以外に手が無い。
     */
    public function test_置換のたびに版が残る(): void
    {
        $this->putBackup();
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true, '--confirm' => $this->announce(),
        ])->assertExitCode(0);

        $rows = DB::table('content_entry_revisions')
            ->where('content_type', 'news')->where('content_id', $id)->get();

        $this->assertGreaterThanOrEqual(1, $rows->count(), '版が残っていない');
        // **手で残した版**として積む。自動保存の間引きで消えない側である
        $this->assertSame('manual', (string) $rows->last()->kind);
    }

    /** **監査に本文を入れない。** 置換した文字列そのものは残さない */
    public function test_監査に本文を入れない(): void
    {
        $this->putBackup();
        $this->news('見出し', '<p>旧社名の話</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true, '--confirm' => $this->announce(),
        ])->assertExitCode(0);

        $logged = (string) json_encode(
            DB::table('audit_logs')->where('operation', 'content.replace')->get(),
            JSON_UNESCAPED_UNICODE,
        );

        $this->assertNotSame('[]', $logged, '監査に残っていない');
        $this->assertStringNotContainsString('旧社名', $logged);
        $this->assertStringNotContainsString('新社名', $logged);
    }

    /**
     * **置き換える前の内容が、版に残る**（F-35）。
     *
     * あとの版だけを積んでも**戻る先にはならない**。この操作がいちばん要る母集団 —
     * **WXR で取り込んだ記事や seeder が入れた記事** — は CMS の外で生まれており、
     * **版が 1 件も無い**。件数だけを数える試験は、**前か後かを見ていなかった**。
     */
    public function test_置換前の内容が版に残る(): void
    {
        $this->putBackup();

        // **CMS を通さずに入れた記事**（取り込み・seeder と同じ生まれ方）
        $id = $this->news('見出し', '<p>旧社名の話</p>');

        $this->assertSame(0, DB::table('content_entry_revisions')
            ->where('content_type', 'news')->where('content_id', $id)->count(), '前提: 版が無い');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true, '--confirm' => $this->announce(),
        ])->assertExitCode(0);

        $snapshots = (string) json_encode(
            DB::table('content_entry_revisions')
                ->where('content_type', 'news')->where('content_id', $id)->pluck('snapshot'),
            JSON_UNESCAPED_UNICODE,
        );

        // **旧文字列が、どこかの版に残っている**（= 戻る先が在る）
        $this->assertStringContainsString('旧社名', $snapshots, '置換前の内容がどの版にも無い');
    }

    /**
     * **入れ替えを token が捕まえる**（F-36）。
     *
     * 1 件消えて 1 件増えれば**件数は同じ**。件数だけを指紋にしていると、
     * **予告で見たのとは別の行**を置き換えてしまう。
     */
    public function test_同じ件数でも別の行になっていたらtokenが効かない(): void
    {
        $this->putBackup();
        $keep = $this->news('あ', '<p>旧社名 1</p>');
        $gone = $this->news('い', '<p>旧社名 2</p>');

        $token = $this->announce();

        // **件数は 2 のまま、行が入れ替わる**
        DB::table('news')->where('id', $gone)->update(['body' => '<p>関係ない</p>']);
        $this->news('う', '<p>旧社名 3</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body',
            '--from' => '旧社名', '--to' => '新社名', '--apply' => true, '--confirm' => $token,
        ])->assertExitCode(1);

        $this->assertStringContainsString(
            '旧社名',
            (string) DB::table('news')->where('id', $keep)->value('body'),
        );
    }

    /**
     * **`--limit` の飽和を token が捕まえる**（F-37）。
     *
     * 絞っていると、**対象が増えても触る行は先頭 N 件のまま**なので、
     * 「触る行」の指紋だけでは変化が見えない。**絞る前の件数**も縛る。
     */
    public function test_limitで絞っていても対象が増えたらtokenが効かない(): void
    {
        $this->putBackup();
        $first = $this->news('あ', '<p>旧社名 1</p>');

        $token = $this->announce(limit: 1);

        // 予告のあとに、**触らない側**が増えた（先頭 1 件は変わらない）
        $this->news('い', '<p>旧社名 2</p>');

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧社名', '--to' => '新社名',
            '--limit' => 1, '--apply' => true, '--confirm' => $token,
        ])->assertExitCode(1);

        $this->assertStringContainsString(
            '旧社名',
            (string) DB::table('news')->where('id', $first)->value('body'),
        );
    }

    /**
     * **記号を含む語でも、予告が過大にならない。**
     *
     * `%` と `_` は LIKE の記号なので、`--from` に入れると
     * **実際には置き換わらない行まで当たる**（置換は PHP の `str_replace` なので、
     * 余分な行は 1 文字も変わらない）。予告だけが膨らむ形だった。
     */
    public function test_記号を含む語でも予告と実行が一致する(): void
    {
        $this->putBackup();

        // `%` を挟んだ語を**文字どおり**含む行
        $hit = $this->news('あ', '<p>旧%社名 です</p>');
        // LIKE では当たるが、**文字どおりには含まない**行
        $miss = $this->news('い', '<p>旧いろは社名 です</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧%社名', '--to' => '新社名',
        ]);
        $announced = Artisan::output();

        $this->assertStringContainsString('1 件', $announced, '予告が過大（LIKE の当たりを数えている）');

        $matched = [];
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧%社名', '--to' => '新社名',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertStringContainsString('新社名', (string) DB::table('news')->where('id', $hit)->value('body'));
        // **触らない行は触らない**
        $this->assertStringContainsString('旧いろは社名', (string) DB::table('news')->where('id', $miss)->value('body'));
    }

    /**
     * **`_` も記号である。**
     *
     * `%` だけを見ていると、`_` を含む語で同じ過大が残る
     * （`_` は LIKE の「任意の 1 文字」）。
     */
    public function test_アンダースコアを含む語でも予告と実行が一致する(): void
    {
        $this->putBackup();

        $hit = $this->news('あ', '<p>旧_社名 です</p>');
        // LIKE の `_` には当たるが、**文字どおりには含まない**行
        $miss = $this->news('い', '<p>旧A社名 です</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧_社名', '--to' => '新社名',
        ]);
        $announced = Artisan::output();

        $this->assertStringContainsString('1 件', $announced, '予告が過大（`_` を記号のまま数えている）');

        $matched = [];
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧_社名', '--to' => '新社名',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertStringContainsString('新社名', (string) DB::table('news')->where('id', $hit)->value('body'));
        $this->assertStringContainsString('旧A社名', (string) DB::table('news')->where('id', $miss)->value('body'));
    }

    /**
     * **指紋に入るのは、実際に触る行だけ**（F-36 が 1 段強くなる）。
     *
     * LIKE でしか当たらない行を予告のあとに書き換えても、**触る行は変わっていない**ので
     * token は生きている。ここが LIKE の当たりのままだと、**関係の無い行の編集で
     * 予告が無効になる**（利用者から見れば理由が分からない）。
     */
    public function test_触らない行を変えてもtokenは生きている(): void
    {
        $this->putBackup();
        $this->news('あ', '<p>旧%社名 です</p>');
        $miss = $this->news('い', '<p>旧いろは社名 です</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧%社名', '--to' => '新社名',
        ]);
        $matched = [];
        preg_match('/--confirm=([0-9a-f]+)/', Artisan::output(), $matched);

        // 触らない側だけを書き換える
        DB::table('news')->where('id', $miss)->update(['body' => '<p>旧ろはに社名 です</p>']);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '旧%社名', '--to' => '新社名',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->assertExitCode(0);
    }

    /**
     * **候補は LIKE で絞り、本文は chunk で読む**（2026-09-10 レビュー修正）。
     *
     * 以前は「普通の語では本文を読まない」を試験していたが、LIKE の当たりは照合順序で
     * 大小文字・アクセント・全半角を同一視するので、**読まずに数えると予告が過大になる**。
     * いまは候補を必ず PHP で確かめる。その代わり、全件を 1 度に memory へ載せない
     * （主キー順の chunk）ことと、値が bind されていること（SQL へ連結しない）、
     * `\` が MySQL の escape 文字として食われない形（wildcard へ倒す）で渡ることを見る。
     */
    public function test_候補はlikeで絞りchunkで読み値はbindする(): void
    {
        $this->putBackup();
        $this->news('あ', '<p>C:\\old 旧社名 です</p>');

        $scans = [];
        DB::listen(function ($q) use (&$scans): void {
            if (str_contains($q->sql, ' like ')) {
                $scans[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
            }
        });

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '\\old 旧社名', '--to' => '新社名',
        ]);

        $this->assertNotEmpty($scans, '候補を LIKE で絞っていない');

        foreach ($scans as $scan) {
            // 識別子の引用符はDBごとに違う。実際の接続のgrammarで確かめる。
            $column = DB::connection()->getQueryGrammar()->wrap('body');
            $this->assertStringContainsString($column.' like ?', $scan['sql'], '列名が wrap されていない、または値が連結されている');
            $this->assertStringContainsString(' limit ', $scan['sql'], 'chunk で読んでいない');
            $this->assertStringNotContainsString('旧社名', $scan['sql'], '値が SQL に連結されている');
            // `\` は MySQL で escape として食われるので、pattern には残さない（wildcard へ倒す）
            $this->assertContains('%%old 旧社名%', $scan['bindings']);
        }
    }

    /**
     * **大小文字は別の文字である。**
     *
     * MySQL の一般的な照合順序（`*_ci`）や SQLite の ASCII では LIKE が `acme` と `Acme` を
     * 同一視する。予告はそれをそのまま数えていた（実際に変わるのは PHP の厳密な一致だけ）。
     * 予告の件数と変わる件数は一致しなければならない。
     */
    public function test_大小文字が違う行は数えず変えない(): void
    {
        $this->putBackup();
        $hit = $this->news('あ', '<p>acme の話</p>');
        $miss = $this->news('い', '<p>Acme の話</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => 'acme', '--to' => 'Newco',
        ]);
        $announced = Artisan::output();
        $this->assertStringContainsString('1 件', $announced, '予告が過大（LIKE の大小文字同一視を数えている）');
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => 'acme', '--to' => 'Newco',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertSame('<p>Newco の話</p>', DB::table('news')->where('id', $hit)->value('body'));
        $this->assertSame('<p>Acme の話</p>', DB::table('news')->where('id', $miss)->value('body'));
    }

    /**
     * **`\` を含む語も見つかる。**
     *
     * MySQL では `\` が LIKE の既定 escape 文字なので、escape せずに渡すと
     * `C:\path` のような正当な語が**当たらない**（対象を漏らす）。
     */
    public function test_バックスラッシュを含む語も対象になる(): void
    {
        $this->putBackup();
        $hit = $this->news('あ', '<p>C:\\old\\path です</p>');
        $miss = $this->news('い', '<p>C:/old/path です</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '\\old\\', '--to' => '\\new\\',
        ]);
        $announced = Artisan::output();
        $this->assertStringContainsString('1 件', $announced, '`\\` を含む語が見つからない、または過大');
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '\\old\\', '--to' => '\\new\\',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertSame('<p>C:\\new\\path です</p>', DB::table('news')->where('id', $hit)->value('body'));
        $this->assertSame('<p>C:/old/path です</p>', DB::table('news')->where('id', $miss)->value('body'));
    }

    /**
     * **全角・アクセントは別の文字である。**
     *
     * `utf8mb4_unicode_ci` は `ＡＢＣ` と `ABC`、`café` と `cafe` を同一視する。
     * 予告はそれを数えず、実際に変わる行だけを数える。
     */
    public function test_全角やアクセントが違う行は数えず変えない(): void
    {
        $this->putBackup();
        $hit = $this->news('あ', '<p>ＡＢＣ café</p>');
        $missWidth = $this->news('い', '<p>ABC café</p>');
        $missAccent = $this->news('う', '<p>ＡＢＣ cafe</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => 'ＡＢＣ café', '--to' => 'XYZ',
        ]);
        $announced = Artisan::output();
        $this->assertStringContainsString('1 件', $announced, '予告が過大（全角・アクセントの同一視を数えている）');
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => 'ＡＢＣ café', '--to' => 'XYZ',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertSame('<p>XYZ</p>', DB::table('news')->where('id', $hit)->value('body'));
        $this->assertSame('<p>ABC café</p>', DB::table('news')->where('id', $missWidth)->value('body'));
        $this->assertSame('<p>ＡＢＣ cafe</p>', DB::table('news')->where('id', $missAccent)->value('body'));
    }

    /**
     * **記号が連続する語も文字どおりに扱う。**
     *
     * LIKE の記号や escape 文字に見えるものが並んでいても、文字どおりの一致だけを数える。
     */
    public function test_記号が連続する語も対象になる(): void
    {
        $this->putBackup();
        $hit = $this->news('あ', '<p>注意!! 旧社名</p>');
        $miss = $this->news('い', '<p>注意! 旧社名</p>');

        Artisan::call('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '注意!! ', '--to' => '',
        ]);
        $announced = Artisan::output();
        $this->assertStringContainsString('1 件', $announced);
        preg_match('/--confirm=([0-9a-f]+)/', $announced, $matched);

        $this->artisan('agentcms:content-replace', [
            '--type' => 'news', '--field' => 'body', '--from' => '注意!! ', '--to' => '',
            '--apply' => true, '--confirm' => $matched[1] ?? '',
        ])->expectsOutputToContain('1 件')->assertExitCode(0);

        $this->assertSame('<p>旧社名</p>', DB::table('news')->where('id', $hit)->value('body'));
        $this->assertSame('<p>注意! 旧社名</p>', DB::table('news')->where('id', $miss)->value('body'));
    }

    /** **AI へは出さない。** 戻せない操作を渡さない */
    public function test_aiへ公開しない(): void
    {
        $this->assertFalse(OperationCatalog::allows('content.replace'));
    }
}
