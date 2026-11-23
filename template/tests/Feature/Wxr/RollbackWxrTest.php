<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\Media\HostResolver;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Rollback\BundleJudge;
use AgentCms\Core\Wxr\Rollback\ContentReverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 取り込みを取り消す（Phase 9.14 / STAGE 27E-2・SPEC §D.3・§I.1）。
 *
 * ## 束は 1 つの単位である
 *
 * 記事と、その版・割り当て・その記事宛のコメント・受付設定は 1 つの束で、
 * 触られていなければ**物理削除**する。ゴミ箱へ入れると綴りが解放されず（OG5）、
 * purge の口も無い（OG8）ので、**取り消してやり直す道が塞がる**（F-10）。
 *
 * ## 消すのは「実際に戻した対象」の行だけ
 *
 * batch の行をまとめて消すと、「触られていたので残した」対象の台帳記録まで消え、
 * 再実行が absent と読んで**生きている投稿の複製を公開側に増やす**（§I.1）。
 */
final class RollbackWxrTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    /**
     * 取り消しが**消してよい表**（§D.3 — purge の口ではないことの保証はここが持つ）。
     *
     * この一覧を広げるときは、`test_束の削除はrollback以外から到達できない` も
     * 一緒に見ること。**共通の削除サービスを作らない。**
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'import_batches', 'audit_logs', 'import_records',
        'news', 'content_entry_revisions', 'taxonomy_term_assignments',
        'content_comment_settings', 'content_comments', 'taxonomy_terms',
        'media', 'media_variants',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-rollback-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active',
                'public_slug' => 'news',
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'comments_enabled' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    // ── 入口の守り ──────────────────────────────────────────

    public function test_無い記録は取り消せない(): void
    {
        $this->rollback('01J00000000000000000000000')->assertExitCode(1);

        $log = DB::table('audit_logs')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('batch_not_found', (string) $log->metadata);
    }

    public function test_走っている取り込みは取り消せない(): void
    {
        $id = $this->batchRow('running');

        $this->rollback($id)->assertExitCode(1);

        $this->assertStringContainsString(
            'batch_running',
            (string) DB::table('audit_logs')->latest('id')->value('metadata'),
        );
    }

    /**
     * 27E-2 から `--i-have-a-backup` を要求する（§D.3）。
     *
     * 27E-1 は「物理削除をしないので apply ほど不可逆ではない」を理由に求めなかった。
     * **その前提が消えた** — apply は増やすだけだが、取り消しは消す。
     */
    public function test_backupの確認が無ければ取り消せない(): void
    {
        $id = $this->importOne();

        $this->artisan('agentcms:wxr-rollback', ['batch' => $id])->assertExitCode(1);

        // **記事は 1 行も減っていない**（断る前に消し始めない）
        $this->assertSame(1, DB::table('news')->count());
        $this->assertStringContainsString(
            'backup_not_confirmed',
            (string) DB::table('audit_logs')->latest('id')->value('metadata'),
        );
    }

    public function test_全部戻し終えた後は二度取り消せない(): void
    {
        $id = $this->importOne();
        $this->rollback($id)->assertExitCode(0);

        $this->rollback($id)->assertExitCode(1);

        $this->assertStringContainsString(
            'batch_already_rolled_back',
            (string) DB::table('audit_logs')->latest('id')->value('metadata'),
        );
    }

    /**
     * F-11 — 「取り消し済み」は印ではなく**実態**で判定する。
     */
    public function test_行が残っていれば取り消しを続きから実行できる(): void
    {
        $id = $this->importOne();

        // 対象の見つからないコメントの行（この行は残るので、まだ取り消せるものがある）
        DB::table('import_records')->insert([
            'batch_id' => $id,
            'external_id' => '901',
            'kind' => 'comment',
            'target_type' => 'comment',
            'target_id' => '999999',
            'created_by_import' => true,
            'created_at' => now(),
        ]);

        $this->rollback($id)->assertExitCode(0);

        $first = '2026-08-17 00:00:00';
        DB::table('import_batches')->where('id', $id)->update(['rolled_back_at' => $first]);

        $this->rollback($id)
            ->doesntExpectOutputToContain('既に取り消されています')
            ->assertExitCode(0);

        // **最初に取り消した時刻が残る**（failed_at と同じ側 — 履歴を消さない）
        $this->assertSame($first, (string) DB::table('import_batches')->value('rolled_back_at'));
    }

    /**
     * 対象の消えたメディアの行は残る（**27E-2 の「既知の穴」はここへ移った**）。
     *
     * 27E-2 の間はメディアを 1 件も戻さなかったので、`--media` の batch は
     * 「行 0 件」に到達しなかった。27E-3 で `MediaReverter` が入り、その穴は閉じた —
     * 残るのは**対象が見つからない行**だけで、これは記事やコメントと同じ扱いである。
     * 行が残っているかぎり続きから何度でも実行できる（F-11）。
     */
    public function test_対象の消えたメディアの行は残り何度でも実行できる(): void
    {
        $id = $this->importOne();

        DB::table('import_records')->insert([
            'batch_id' => $id,
            'external_id' => '501',
            'kind' => 'media',
            'target_type' => 'media',
            // media の行が無い id（人が管理画面から消したか、DB を直接触った）
            'target_id' => '999999',
            'created_by_import' => true,
            'created_at' => now(),
        ]);

        $this->rollback($id)->assertExitCode(0);
        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(1, DB::table('import_records')->where('kind', 'media')->count());
    }

    // ── 束を消す ────────────────────────────────────────────

    public function test_触られていない束は物理削除される(): void
    {
        $id = $this->importOne();

        $this->rollback($id)->assertExitCode(0);

        // ゴミ箱を経由しない（F-10 — 綴りが解放されないと入れ直せない）
        $this->assertSame(0, DB::table('news')->count());
    }

    /**
     * 束は 1 つの単位である。**版・割り当て・受付設定・所有コメントも同時に消える。**
     */
    public function test_束の付随物も同じ取り消しで消える(): void
    {
        $id = $this->importWithEverything();

        // 取り込んだ時点で、束のどの部分も行を持っている
        $this->assertGreaterThan(0, DB::table('content_entry_revisions')->count());
        $this->assertSame(1, DB::table('taxonomy_term_assignments')->count());
        $this->assertSame(1, DB::table('content_comment_settings')->count());
        $this->assertSame(1, DB::table('content_comments')->count());

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('content_entry_revisions')->count());
        $this->assertSame(0, DB::table('taxonomy_term_assignments')->count());
        $this->assertSame(0, DB::table('content_comment_settings')->count());
        $this->assertSame(0, DB::table('content_comments')->count());
    }

    /**
     * **孤児をこの 4 つの表で名指しで数える。**
     *
     * `content_id` は型ごとに違う表を指すので FK を張れない（§H.2）。
     * DB は何も教えてくれないので、**ここで数えるしかない**。
     */
    public function test_孤児は4つの表のどれにも残らない(): void
    {
        $id = $this->importWithEverything();

        // 片方は触って残す。**残った束の付随物は孤児ではない**
        $this->importSecond();
        $this->touch('記事 12');

        $this->rollback($id)->assertExitCode(0);

        $alive = DB::table('news')->pluck('id')->map(intval(...))->all();

        foreach (['content_entry_revisions', 'content_comments', 'taxonomy_term_assignments', 'content_comment_settings'] as $table) {
            $orphans = DB::table($table)
                ->where('content_type', 'news')
                ->whereNotIn('content_id', $alive === [] ? [0] : $alive)
                ->count();

            $this->assertSame(0, $orphans, "{$table} に生きていない記事を指す行が残っています");
        }
    }

    public function test_触られた記事は戻さない(): void
    {
        $id = $this->importTwo();

        $this->touch('記事 12');

        $this->rollback($id)->assertExitCode(0);

        // 11 は消え、12 は生きたまま（§D.3）
        $this->assertSame(0, DB::table('news')->where('title', '記事 11')->count());
        $this->assertSame(1, DB::table('news')->where('title', '記事 12')->count());
    }

    public function test_戻した対象の台帳の行だけ消える(): void
    {
        $id = $this->importTwo();

        $this->touch('記事 12');

        $this->rollback($id)->assertExitCode(0);

        $records = DB::table('import_records')->pluck('external_id')->all();
        $this->assertSame(['12'], $records);
    }

    public function test_対象が見つからない行は残す(): void
    {
        $id = $this->importOne();

        // DB を直接触った場合にしか起きない（完全削除の経路は無い — OG8）
        DB::table('news')->delete();

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(1, DB::table('import_records')->count());
    }

    /**
     * 取り消しの最中に人が触ったら、**その束は消さない**。
     *
     * 掴んでから消すまでの間に起きるので、外からは作れない。
     * `ContentReverter` の注入点（`protected`）で当てる。
     */
    public function test_取り消しの最中に触られたら戻さない(): void
    {
        $id = $this->importOne();

        $this->app->bind(ContentReverter::class, fn ($app) => new class($app->make(ContentTypeResolver::class), $app->make(BundleJudge::class)) extends ContentReverter
        {
            protected function beforeDelete(string $type, int $id): void
            {
                DB::table('news')->where('id', $id)->update(['updated_at' => now()->addHour()]);
            }
        });

        $this->rollback($id)
            ->expectsOutputToContain('戻せなかった')
            ->assertExitCode(0);

        // 消えていないし、台帳の行も残っている
        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(1, DB::table('import_records')->count());
    }

    // ── 行を持たない束（WordPress の大半） ──────────────────

    /**
     * **`open` ばかりの WXR で壊れないこと。**
     *
     * 受付設定の行を持つのは `closed` の記事だけ（§C.4.3）なので、
     * 実際の移行では**束の大半が行を持たない**。「行が無い = 消された = 触られた」と
     * 読むと、そこで取り消しがほぼ何もできなくなる（§D.3 の (d) の節）。
     */
    public function test_受付設定の行が無い記事も取り消せる(): void
    {
        $id = $this->importMany(20);

        $this->assertSame(0, DB::table('content_comment_settings')->count());

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('import_records')->count());
    }

    /**
     * `is_open = true` は**取り込みが書かない形**なので、人が「開く」を押した証拠になる。
     */
    public function test_受付設定が開かれていたら束ごと残す(): void
    {
        $id = $this->importOne();

        DB::table('content_comment_settings')->insert([
            'content_type' => 'news',
            'content_id' => (int) DB::table('news')->value('id'),
            'is_open' => true,
            'updated_at' => now()->subYear(),
        ]);

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(1, DB::table('import_records')->where('kind', 'content')->count());
    }

    // ── 他人の記事に付けたコメント ──────────────────────────

    /**
     * `skip_existing` の記事に付けた所有コメントは、記事がこちらの物ではないので
     * **コメント単体で判定する**（§D.3 の表）。
     */
    public function test_他人の記事に付けたコメントは単体で判定する(): void
    {
        $this->importing('a.xml', $this->entry('11', comments: [$this->comment('901')]));

        $second = $this->importing('b.xml', $this->entry('11', comments: [
            $this->comment('901'), $this->comment('902'), $this->comment('903'),
        ]));

        // 903 だけ人が触る（承認・編集）
        DB::table('content_comments')->where('body', 'like', '%903%')->update(['updated_at' => now()->addHour()]);

        $this->rollback($second)->assertExitCode(0);

        // 記事は 1 回目の物なので残る。902 は消え、901（1 回目の物）と 903 は残る
        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(0, DB::table('content_comments')->where('body', 'like', '%902%')->count());
        $this->assertSame(1, DB::table('content_comments')->where('body', 'like', '%901%')->count());
        $this->assertSame(1, DB::table('content_comments')->where('body', 'like', '%903%')->count());
    }

    // ── 返信の付いたコメント（F-12） ────────────────────────

    /**
     * F-12 — **返信から先に消す。** 親を先に消すと `fk_comment_parent` が止める。
     *
     * 取り込みは返信を意図して作る（9.13 は 1 段まで — OG4）のに、
     * 取り消しがそれを消せないままだった。1 文でまとめて消すと、DB が
     * どちらを先に消すかはこちらが決められない。
     */
    public function test_返信の付いた束も消える(): void
    {
        $id = $this->importing('a.xml', $this->entry('11', comments: [
            $this->comment('901'), $this->comment('902', '901'),
        ]));

        $this->assertSame(2, DB::table('content_comments')->count());
        $this->assertSame(1, DB::table('content_comments')->whereNotNull('parent_id')->count());

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('content_comments')->count());
    }

    /**
     * F-12 — 人が返信を書いたコメントは、**その返信が孤児になるので消さない**。
     *
     * 束の (c) と同じ理屈を、単体で判定するコメントにも当てる。
     */
    public function test_人の返信が付いた所有コメントは残す(): void
    {
        $this->importing('a.xml', $this->entry('11', comments: [$this->comment('901')]));

        $second = $this->importing('b.xml', $this->entry('11', comments: [
            $this->comment('901'), $this->comment('902'),
        ]));

        $parent = (int) DB::table('content_comments')->where('body', 'like', '%902%')->value('id');
        $reply = $this->humanReply($parent, 'news', (int) DB::table('news')->value('id'));

        $this->rollback($second)
            ->expectsOutputToContain('触られていたので戻さなかった')
            ->assertExitCode(0);

        // 親も、人の返信も残る。台帳の行も残る
        $this->assertSame(1, DB::table('content_comments')->where('id', $parent)->count());
        $this->assertSame(1, DB::table('content_comments')->where('id', $reply)->count());
        $this->assertSame(1, DB::table('import_records')->where('external_id', '902')->count());
    }

    /**
     * F-24 — **移行後に読者が書いたコメントがあれば、記事ごと残す**（§D.3 の (c)）。
     *
     * ## 既存の試験では通っていなかった経路
     *
     * 「人の返信が付いた所有コメントは残す」は、**取り込んだコメントへの返信**を見ている。
     * ここで見るのは **親を持たないコメント**を人が新しく書いた場合で、
     * 判定の入口が違う（返信は親をたどって残るが、こちらは束の判定しか働かない）。
     *
     * ## 壊れると何が起きるか
     *
     * 取り消しが、**移行後に読者が書いたコメントごと記事を物理削除する**。
     * 書いた人にも運用にも見えないまま消えるので、**気づく手立てが無い**。
     */
    public function test_移行後に読者が書いたコメントがあれば記事ごと残す(): void
    {
        $import = $this->importing('a.xml', $this->entry('11'));

        $postId = (int) DB::table('news')->value('id');

        // **親を持たない**コメントを、人が移行後に書く
        $comment = DB::table('content_comments')->insertGetId([
            'content_type' => 'news',
            'content_id' => $postId,
            'parent_id' => null,
            'status' => 'approved',
            'author_name' => '移行後の読者',
            'body' => '移行後に書いた感想',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rollback($import)
            ->expectsOutputToContain('触られていたので戻さなかった')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('news')->where('id', $postId)->count(), '記事ごと消えた');
        $this->assertSame(
            1,
            DB::table('content_comments')->where('id', $comment)->count(),
            '読者が書いたコメントが消えた',
        );
        // 台帳の行も残る（戻せていないので、再実行が人に判断させる）
        $this->assertSame(1, DB::table('import_records')->where('external_id', '11')->count());
    }

    /**
     * **順が効くのはここである。**
     *
     * 誰も触っていない親と返信は、**両方消える**。親から先に見ると、
     * 親は「返信が残っている」で残ってしまい、**触られていないものが残る**。
     * だから子から先に見る（F-12）。
     */
    public function test_他人の記事に付けた返信ごと消える(): void
    {
        $this->importing('a.xml', $this->entry('11', comments: [$this->comment('901')]));

        $second = $this->importing('b.xml', $this->entry('11', comments: [
            $this->comment('901'), $this->comment('902'), $this->comment('903', '902'),
        ]));

        $this->rollback($second)->assertExitCode(0);

        // 記事と 901 は 1 回目の物なので残る。902 と その返信 903 は消える
        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(1, DB::table('content_comments')->count());
        $this->assertSame(1, DB::table('content_comments')->where('body', 'like', '%901%')->count());
    }

    /**
     * 残した返信が**所有しているもの**でも同じである。
     *
     * 子から先に見るので、返信を先に判定する。そこで残した返信の親を消すと、
     * やはり `fk_comment_parent` で落ちる。**残っている子が在れば親は消さない。**
     */
    public function test_残した返信の親も消さない(): void
    {
        $this->importing('a.xml', $this->entry('11', comments: [$this->comment('901')]));

        $second = $this->importing('b.xml', $this->entry('11', comments: [
            $this->comment('901'), $this->comment('902'), $this->comment('903', '902'),
        ]));

        // 返信の方だけ人が触る（承認・編集）
        DB::table('content_comments')->where('body', 'like', '%903%')->update(['updated_at' => now()->addHour()]);

        $this->rollback($second)->assertExitCode(0);

        $this->assertSame(1, DB::table('content_comments')->where('body', 'like', '%903%')->count());
        $this->assertSame(1, DB::table('content_comments')->where('body', 'like', '%902%')->count());
    }

    // ── 分類項目 ────────────────────────────────────────────

    public function test_参照が無くなった分類項目は消える(): void
    {
        $id = $this->importWithEverything();

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('taxonomy_terms')->count());
        $this->assertSame(0, DB::table('import_records')->count());
    }

    public function test_まだ参照されている分類項目は残す(): void
    {
        $id = $this->importWithEverything();

        // 取り込みの外から同じ項目を使っている記事（移行後に人が付けた）
        $termId = (string) DB::table('taxonomy_terms')->value('id');
        $other = DB::table('news')->insertGetId([
            'title' => '人の記事', 'slug' => 'hito', 'status' => 'published', 'body' => '人が書いた',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => $termId, 'content_type' => 'news', 'content_id' => $other, 'created_at' => now(),
        ]);

        $this->rollback($id)
            ->expectsOutputToContain('まだ使われている')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('taxonomy_terms')->count());
        // 戻せていないので台帳の行も残る
        $this->assertSame(1, DB::table('import_records')->where('kind', 'term')->count());
    }

    /**
     * 子から先に消す（`creationOrder` の逆順）。親を先に消そうとすると FK が止める。
     */
    public function test_子から先に消すので親も消える(): void
    {
        $id = $this->importing('a.xml',
            $this->term('1', 'oya', '親')
            .$this->term('2', 'ko', '子', 'oya')
            .$this->entry('11', categories: ['ko']),
            ['category:news_category'],
        );

        $this->assertSame(2, DB::table('taxonomy_terms')->count());

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('taxonomy_terms')->count());
    }

    public function test_使い回した対象は触らず記録だけ消える(): void
    {
        $existing = $this->existingTerm('news_category', 'oshirase');

        $id = $this->importing('a.xml',
            $this->term('1', 'oshirase', 'お知らせ').$this->entry('11', categories: ['oshirase']),
            ['category:news_category'],
        );

        // 使い回したので所有していない
        $this->assertFalse((bool) DB::table('import_records')->where('kind', 'term')->value('created_by_import'));

        $this->rollback($id)->assertExitCode(0);

        // **対象は残り、記録だけ消える**
        $this->assertSame(1, DB::table('taxonomy_terms')->where('id', $existing)->count());
        $this->assertSame(0, DB::table('import_records')->count());
    }

    // ── メディア（27E-3） ───────────────────────────────────

    /**
     * 使われていないメディアは、**行も実体も**消える。
     */
    public function test_使われていないメディアは実体ごと消える(): void
    {
        $id = $this->importWithMedia();

        $media = DB::table('media')->first();
        $this->assertNotNull($media);
        $this->assertTrue(Storage::disk((string) $media->disk)->exists((string) $media->path));

        // 記事の側が消えれば、本文が指していた参照も消える
        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('media')->count());
        $this->assertFalse(Storage::disk((string) $media->disk)->exists((string) $media->path));
        $this->assertSame(0, DB::table('import_records')->count());
    }

    /**
     * **本文が URL で指していれば残す。**
     *
     * `MediaUsageInspector` が id しか見なかった頃は、ここで消えて記事の画像が壊れた
     * （27E-3・ADR-0020 — 取り消しの都合ではなく元からあった穴）。
     */
    public function test_本文が_ur_lで指しているメディアは残す(): void
    {
        $id = $this->importWithMedia();

        $media = DB::table('media')->first();
        $this->assertNotNull($media);

        // 移行後に人が書いた記事が、同じ画像を本文で使っている
        DB::table('news')->insert([
            'title' => '人の記事', 'slug' => 'hito', 'status' => 'published',
            'body' => '<img src="/storage/'.$media->path.'">',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->rollback($id)
            ->expectsOutputToContain('まだ使われているので残した')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('media')->count());
        $this->assertTrue(Storage::disk((string) $media->disk)->exists((string) $media->path));
        // 戻せていないので台帳の行も残る
        $this->assertSame(1, DB::table('import_records')->where('kind', 'media')->count());
    }

    /**
     * 使い回した添付の記録も、他の種類と同じく**対象に触れず行だけ**消える。
     *
     * 27E-2 の間は `media` を除外していた（戻す口がまだ無く、行だけ消すと
     * **実体が残ったまま台帳から消える**ため）。27E-3 でその理由は無くなった。
     */
    public function test_使い回したメディアは触らず記録だけ消える(): void
    {
        $id = $this->importOne();

        $mediaId = DB::table('media')->insertGetId([
            'kind' => 'image', 'visibility' => 'public', 'disk' => 'public',
            'name' => '元からある画像', 'original_name' => 'a.png',
            'path' => 'media/image/moto.png', 'mime' => 'image/png', 'size' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('import_records')->insert([
            'batch_id' => $id,
            'external_id' => '501',
            'kind' => 'media',
            'target_type' => 'media',
            'target_id' => (string) $mediaId,
            // **こちらが作っていない**（元からあったものを使い回した）
            'created_by_import' => false,
            'created_at' => now(),
        ]);

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(1, DB::table('media')->where('id', $mediaId)->count());
        $this->assertSame(0, DB::table('import_records')->count());
    }

    // ── 取り消し → 再実行（§I.1 の end-to-end 検証）─────────────

    /**
     * **27E-2 の正しさの中心。** ゴミ箱を経由しないので綴りが解放され、
     * 同じ WXR を入れ直せる（F-10 が閉じる）。
     */
    public function test_取り消して再実行すると取り消した分だけ入り直す(): void
    {
        $path = $this->write('a.xml', $this->wxr(
            $this->entry('11').$this->entry('12'),
        ));

        $this->apply($path);
        $id = (string) DB::table('import_batches')->value('id');

        $this->touch('記事 12');
        $this->rollback($id)->assertExitCode(0);

        // 11 は消えているので入り直せる
        $this->apply($path);

        // 触られた 12 に複製は生まれない（台帳の行が残っているので skip_existing）
        $this->assertSame(1, DB::table('news')->where('title', '記事 12')->count());
        $this->assertSame(1, DB::table('news')->where('title', '記事 11')->count());
    }

    public function test_途中で落ちても再実行で続きから(): void
    {
        $id = $this->importTwo();

        // 1 件目（11）を消した直後に落ちた状態を作る
        DB::table('news')->where('title', '記事 11')->delete();
        DB::table('import_records')->where('external_id', '11')->delete();

        $this->rollback($id)->assertExitCode(0);

        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('import_records')->count());
        $this->assertSame('rolled_back', DB::table('import_batches')->value('status'));
    }

    // ── 監査と書き込みの範囲 ────────────────────────────────

    public function test_監査に本文も題名も入らない(): void
    {
        $id = $this->importOne();

        $this->rollback($id)->assertExitCode(0);

        $logs = (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('記事 11', $logs);
        $this->assertStringNotContainsString('本文', $logs);
    }

    public function test_取り消しの監査が残る(): void
    {
        $id = $this->importOne();

        $this->rollback($id)->assertExitCode(0);

        $log = DB::table('audit_logs')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('cli', $log->interface);
        $this->assertSame($id, $log->resource_id);
    }

    public function test_取り消しは許した表以外に書かない(): void
    {
        $id = $this->importWithEverything();

        $before = $this->snapshot(self::ALLOWED);
        $forbidden = $this->watchWrites(self::ALLOWED);

        try {
            $this->rollback($id)->assertExitCode(0);
        } finally {
            DB::flushQueryLog();
        }

        $this->assertSame([], $forbidden());
        $this->assertSame($before, $this->snapshot(self::ALLOWED));
    }

    /**
     * **purge の口を作っていないことの保証は、文ではなくここが持つ**（§D.3 — OG8 との区別）。
     *
     * 消す道具は取り消しの中にしかなく、**共通の削除サービスを作らない**。
     *
     * ## このテストが固定するのは 1 つだけである
     *
     * 「この 3 クラスが他から呼ばれていないこと」しか見ていない。
     * **新しく書かれた別の削除コードを止めるのはここではなく `ALLOWED` の許可表**で、
     * 2 つは別のものを守っている。片方だけ緑でも、もう片方は何も保証しない。
     *
     * ## 名前を**書いた**ものと**呼んだ**ものを区別する
     *
     * コメントの中で名前を挙げただけの説明（「分類項目の取り消しは逆に …」）は
     * 呼び出しではない。素朴に文字列を探すと、**説明を書くたびにこのテストが赤くなり**、
     * 直し方が「説明を消す」になる。それは守りたいものと逆である。
     * だから注釈を落としてから探す。
     */
    public function test_束の削除はrollback以外から到達できない(): void
    {
        $root = dirname(__DIR__, 3);
        $callers = [];

        foreach ([$root.'/core/src', $root.'/app', $root.'/modules'] as $dir) {
            $callers = [...$callers, ...$this->callersIn($dir)];
        }

        sort($callers);

        /*
         * `BundleJudge` はここに現れない — 名前を挙げているのは docblock だけで、
         * **呼んでいるのは `ContentReverter` の側**である。注釈を落とすとそれが見える。
         */
        $this->assertSame(
            ['ContentReverter', 'LedgerSweeper', 'RollbackWxrCommand', 'TermReverter'],
            array_values(array_unique($callers)),
        );

        // AI へは出さない（§E.3）
        $catalog = (string) file_get_contents($root.'/app/Support/OperationCatalog.php');
        $this->assertStringNotContainsString('wxr.rollback', $catalog);
    }

    /** @return list<string> */
    private function callersIn(string $dir): array
    {
        $callers = [];

        foreach ($this->phpFiles($dir) as $file) {
            $body = (string) file_get_contents($file);

            if (preg_match('/\b(ContentReverter|TermReverter|LedgerSweeper)\b/', $this->withoutPhpComments($body)) === 1) {
                $callers[] = basename($file, '.php');
            }
        }

        return $callers;
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** 注釈を落とした PHP。**説明の中の名前を呼び出しと読み違えないため** */
    private function withoutPhpComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function touch(string $title): void
    {
        DB::table('news')->where('title', $title)->update(['updated_at' => now()->addHour()]);
    }

    /** 1 記事を取り込み、batch id を返す */
    private function importOne(): string
    {
        return $this->importing('a.xml', $this->entry('11'));
    }

    /** 2 記事を取り込み、batch id を返す */
    private function importTwo(): string
    {
        return $this->importing('a.xml', $this->entry('11').$this->entry('12'));
    }

    /** 2 件目の記事だけを別の取り込みで足す */
    private function importSecond(): string
    {
        return $this->importing('c.xml', $this->entry('12'));
    }

    private function importMany(int $count): string
    {
        $body = '';

        for ($i = 1; $i <= $count; $i++) {
            $body .= $this->entry((string) (100 + $i));
        }

        return $this->importing('a.xml', $body);
    }

    /** 画像を 1 枚持つ取り込み（`--media`） */
    private function importWithMedia(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        Http::fake(['*' => Http::response((string) $png, 200, ['Content-Type' => 'image/png'])]);

        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $item = '<item><title>記事 11</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>kiji-11</wp:post_name>'
            .'<content:encoded><![CDATA[本文 <img src="https://example.com/wp/uploads/e.png">]]></content:encoded>'
            .'</item>'
            .'<item><title>e.png</title><wp:post_id>501</wp:post_id>'
            .'<wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status>'
            .'<wp:attachment_url>https://example.com/wp/uploads/e.png</wp:attachment_url>'
            .'</item>';

        $path = $this->write('m.xml', $this->wxr($item));

        $this->artisan('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--media' => true,
            '--plan-hash' => (string) app(PlanBuilder::class)
                ->build($path, ImportOptions::fromCli([], [], true))
                ->fingerprint(),
        ])->assertExitCode(0);

        return (string) DB::table('import_batches')->latest('started_at')->orderByDesc('id')->value('id');
    }

    /** 版・割り当て・受付設定・コメントの**全部を持つ**束を 1 つ作る */
    private function importWithEverything(): string
    {
        return $this->importing('a.xml',
            $this->term('1', 'oshirase', 'お知らせ')
            .$this->entry('11', categories: ['oshirase'], comments: [$this->comment('901')], closed: true),
            ['category:news_category'],
        );
    }

    /**
     * @param  list<string>  $taxonomies
     */
    private function importing(string $name, string $body, array $taxonomies = []): string
    {
        $path = $this->write($name, $this->wxr($body));

        $this->apply($path, $taxonomies);

        return (string) DB::table('import_batches')->latest('started_at')->orderByDesc('id')->value('id');
    }

    private function batchRow(string $status): string
    {
        $id = (string) Str::ulid();

        DB::table('import_batches')->insert([
            'id' => $id,
            'source' => 'a.xml',
            'file_hash' => str_repeat('0', 64),
            'plan_hash' => str_repeat('0', 64),
            'options' => '{}',
            'status' => $status,
            'counts' => '{}',
            'started_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  list<string>  $taxonomies
     */
    private function apply(string $path, array $taxonomies = []): void
    {
        $options = [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => $this->hashFor($path, $taxonomies),
        ];

        if ($taxonomies !== []) {
            $options['--taxonomy'] = $taxonomies;
        }

        $this->artisan('agentcms:wxr-apply', $options)->assertExitCode(0);
    }

    /**
     * @param  list<string>  $taxonomies
     */
    private function hashFor(string $path, array $taxonomies = []): string
    {
        return (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], $taxonomies, false))
            ->fingerprint();
    }

    private function rollback(string $batch): PendingCommand
    {
        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-rollback', [
            'batch' => $batch,
            '--i-have-a-backup' => true,
        ]);
    }

    private function existingTerm(string $taxonomy, string $slug): string
    {
        $taxonomyId = (string) DB::table('taxonomies')->where('key', $taxonomy)->value('id');
        $id = Str::ulid()->toString();

        DB::table('taxonomy_terms')->insert([
            'id' => $id,
            'taxonomy_id' => $taxonomyId,
            'name' => '既にある項目',
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function term(string $termId, string $slug, string $name, string $parentSlug = ''): string
    {
        return '<wp:term>'
            ."<wp:term_id>{$termId}</wp:term_id>"
            .'<wp:term_taxonomy>news_category</wp:term_taxonomy>'
            ."<wp:term_slug>{$slug}</wp:term_slug>"
            ."<wp:term_name><![CDATA[{$name}]]></wp:term_name>"
            ."<wp:term_parent>{$parentSlug}</wp:term_parent>"
            .'</wp:term>';
    }

    private function comment(string $id, string $parent = '0'): string
    {
        return '<wp:comment>'
            ."<wp:comment_id>{$id}</wp:comment_id>"
            .'<wp:comment_author><![CDATA[書いた人]]></wp:comment_author>'
            .'<wp:comment_author_email>kaita@example.com</wp:comment_author_email>'
            .'<wp:comment_author_url></wp:comment_author_url>'
            .'<wp:comment_author_IP>198.51.100.1</wp:comment_author_IP>'
            .'<wp:comment_date>2016-01-01 09:00:00</wp:comment_date>'
            .'<wp:comment_date_gmt>2016-01-01 00:00:00</wp:comment_date_gmt>'
            ."<wp:comment_content><![CDATA[コメント {$id}]]></wp:comment_content>"
            .'<wp:comment_approved>1</wp:comment_approved>'
            ."<wp:comment_parent>{$parent}</wp:comment_parent>"
            .'</wp:comment>';
    }

    /** 人が後から書いた返信（台帳に載らない = 所有していない） */
    private function humanReply(int $parentId, string $type, int $contentId): int
    {
        return (int) DB::table('content_comments')->insertGetId([
            'content_type' => $type,
            'content_id' => $contentId,
            'parent_id' => $parentId,
            'status' => 'approved',
            'author_name' => '移行後の人',
            'body' => '人の返信',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $comments
     */
    private function entry(string $id, array $categories = [], array $comments = [], bool $closed = false): string
    {
        $body = '';

        foreach ($categories as $nicename) {
            $body .= "<category domain=\"category\" nicename=\"{$nicename}\"><![CDATA[{$nicename}]]></category>";
        }

        return "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            ."<wp:post_name>kiji-{$id}</wp:post_name>"
            .($closed ? '<wp:comment_status>closed</wp:comment_status>' : '')
            ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded>"
            .$body
            .implode('', $comments)
            .'</item>';
    }

    private function wxr(string $body = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
