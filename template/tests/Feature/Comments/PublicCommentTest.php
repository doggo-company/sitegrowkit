<?php

declare(strict_types=1);

namespace Tests\Feature\Comments;

use AgentCms\Core\Comments\Comment;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Comments\CommentStatus;
use AgentCms\Core\Spam\HoneypotCheck;
use AgentCms\Core\Spam\SpamBlocks;
use App\Mail\CommentNotification;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * コメントの公開面（Phase 9.13 / STAGE 26D・SPEC §C・§F・§G）。
 *
 * ここで固定するのは **型が OFF なら口ごと無いこと**と、
 * **通知が落ちても書かれたものが残ること**、そして
 * **承認するまで公開面に出ないこと**である。
 */
final class PublicCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // 開発機の .env に鍵があると結果が machine ごとに変わる（9.12 と同じ）
        config([
            'agentcms.turnstile.secret_key' => '',
            'agentcms.turnstile.site_key' => '',
            'agentcms.comments.auto_approve' => false,
        ]);
    }

    // ── 二層 opt-in（§C.2） ────────────────────────────────

    public function test_型がoffなら口ごと無い(): void
    {
        $post = $this->post_();

        // 「隠す」ではなく 404
        $this->post("/comments/post/{$post->id}", $this->input())->assertNotFound();
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_型がoffなら記事ページにコメント欄が出ない(): void
    {
        $this->publishType(comments: false);
        $post = $this->post_();

        $this->get("/blog/{$post->slug}")
            ->assertOk()
            ->assertDontSee('コメント')
            ->assertDontSee('comments');
    }

    public function test_型がonなら記事ページにコメント欄が出る(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->get("/blog/{$post->slug}")
            ->assertOk()
            ->assertSee('コメントを書く')
            ->assertSee(HoneypotCheck::FIELD);
    }

    public function test_下書きの記事には書けない(): void
    {
        $this->enableComments();
        $post = $this->post_(status: 'draft');

        $this->post("/comments/post/{$post->id}", $this->input())->assertNotFound();
    }

    public function test_無い記事には書けない(): void
    {
        $this->enableComments();

        $this->post('/comments/post/999999', $this->input())->assertNotFound();
    }

    // ── 投稿（§C.3・§C.4） ────────────────────────────────

    public function test_書けるが承認するまで公開面に出ない(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input())
            ->assertRedirect()
            ->assertSessionHas('comment_posted');

        $comment = Comment::query()->firstOrFail();

        $this->assertSame(CommentStatus::Pending, $comment->status());

        // 送信できたのに何も起きない、を作らない
        $this->followingRedirects()
            ->post("/comments/post/{$post->id}", $this->input())
            ->assertSee('承認後に表示されます');

        $this->get("/blog/{$post->slug}")->assertDontSee('よい記事でした');
    }

    public function test_承認すると出る(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->post("/comments/post/{$post->id}", $this->input());

        Comment::query()->firstOrFail()->forceFill([
            'status' => CommentStatus::Approved->value,
            'approved_at' => now(),
        ])->save();

        $this->get("/blog/{$post->slug}")
            ->assertSee('よい記事でした')
            ->assertSee('コメント（1）');
    }

    public function test_件数は承認済みだけを数える(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->comment($post->id, CommentStatus::Approved);
        $this->comment($post->id, CommentStatus::Pending);
        $this->comment($post->id, CommentStatus::Spam);

        // 「3 件」と出て 1 件しか見えない、を作らない
        $this->get("/blog/{$post->slug}")->assertSee('コメント（1）');
    }

    public function test_必須が空なら理由が出る(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", ['author_name' => '山田'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_受付を終えた記事は書けないが承認済みは出続ける(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->comment($post->id, CommentStatus::Approved);

        app(CommentGate::class)->setEntryOpen('post', (int) $post->id, false);

        $this->post("/comments/post/{$post->id}", $this->input())
            ->assertRedirect()
            ->assertSessionHasErrors('comment');

        // 止まるのは新規受付だけ
        $this->get("/blog/{$post->slug}")
            ->assertSee('よい記事でした')
            ->assertSee('受付を終了しました');
    }

    // ── 通知（§C.4・OG12） ────────────────────────────────

    public function test_通知が落ちてもコメントは残る(): void
    {
        $this->enableComments();
        $post = $this->post_();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->post("/comments/post/{$post->id}", $this->input())->assertRedirect();

        $this->assertSame(1, Comment::query()->count());
    }

    public function test_管理者へ通知が送られる(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input());

        Mail::assertSent(CommentNotification::class);
    }

    // ── 返信（§C.3・OG4） ─────────────────────────────────

    public function test_承認済みのコメントへ返信できる(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $parent = $this->comment($post->id, CommentStatus::Approved);

        $this->post("/comments/post/{$post->id}", $this->input() + ['parent_id' => $parent->getKey()]);

        $reply = Comment::query()->whereNotNull('parent_id')->firstOrFail();

        $this->assertSame((int) $parent->getKey(), $reply->parent_id);
    }

    public function test_返信への返信は親なしになる(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $parent = $this->comment($post->id, CommentStatus::Approved);
        $reply = $this->comment($post->id, CommentStatus::Approved, parentId: (int) $parent->getKey());

        $this->post("/comments/post/{$post->id}", $this->input() + ['parent_id' => $reply->getKey()]);

        // 断ると「返信できないフォーム」に見えるが、実際には書けている
        $latest = Comment::query()->latest('id')->firstOrFail();

        $this->assertNull($latest->parent_id);
    }

    public function test_別の記事のコメントへは返信できない(): void
    {
        $this->enableComments();
        $a = $this->post_();
        $b = $this->post_(slug: 'other');
        $parent = $this->comment($a->id, CommentStatus::Approved);

        $this->post("/comments/post/{$b->id}", $this->input() + ['parent_id' => $parent->getKey()]);

        $this->assertNull(Comment::query()->where('content_id', $b->id)->firstOrFail()->parent_id);
    }

    // ── bot と濫用（§F） ────────────────────────────────────

    public function test_honeypotは保存せずに成功したように見せる(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input() + [HoneypotCheck::FIELD => 'http://spam.test'])
            ->assertSessionHas('comment_posted');

        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(1, (int) DB::table(SpamBlocks::TABLE)
            ->where('kind', 'comment')->where('subject_id', 'post')->value('hits'));
    }

    public function test_リンクが多い投稿は捨てずにスパムへ落とす(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $body = 'https://a.test https://b.test https://c.test https://d.test';

        $this->post("/comments/post/{$post->id}", ['author_name' => '山田', 'body' => $body]);

        // 拒否ではなく spam。誤判定で人の文章を捨てない（OG6）
        $this->assertSame(CommentStatus::Spam, Comment::query()->firstOrFail()->status());
    }

    public function test_短時間に書きすぎると429(): void
    {
        config(['agentcms.comments.rate_limit_per_minute' => 2]);
        RateLimiter::clear('spam:comment');
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input())->assertRedirect();
        $this->post("/comments/post/{$post->id}", $this->input())->assertRedirect();
        $this->post("/comments/post/{$post->id}", $this->input())->assertStatus(429);

        $this->assertSame(2, Comment::query()->count());
    }

    public function test_上限は記事ではなく型ごとに数える(): void
    {
        config(['agentcms.comments.rate_limit_per_minute' => 1]);
        RateLimiter::clear('spam:comment');
        $this->enableComments();
        $a = $this->post_();
        $b = $this->post_(slug: 'other');

        $this->post("/comments/post/{$a->id}", $this->input())->assertRedirect();

        // 荒らしは別の記事へも書く
        $this->post("/comments/post/{$b->id}", $this->input())->assertStatus(429);
    }

    // ── 自動承認（§C.5・§F.2） ────────────────────────────

    public function test_自動承認を入れるとすぐ出る(): void
    {
        config(['agentcms.comments.auto_approve' => true]);
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input());

        $this->assertSame(CommentStatus::Approved, Comment::query()->firstOrFail()->status());
    }

    public function test_自動承認でも連投は承認待ちにする(): void
    {
        config(['agentcms.comments.auto_approve' => true]);
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input());
        $this->post("/comments/post/{$post->id}", $this->input());

        // 自動承認はスパムの通り道にするためのものではない
        $this->assertSame(CommentStatus::Pending, Comment::query()->latest('id')->firstOrFail()->status());
    }

    // ── 表示（§F.3・§G.1） ───────────────────────────────

    public function test_本文のhtmlは解釈されない(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->comment($post->id, CommentStatus::Approved, body: '<script>alert(1)</script>危険');

        $response = $this->get("/blog/{$post->slug}");

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', escape: false);
        $response->assertSee('危険');
    }

    public function test_公開面にメールアドレスを出さない(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->comment($post->id, CommentStatus::Approved);

        $this->get("/blog/{$post->slug}")->assertDontSee('taro@example.test');
    }

    public function test_生のipを保存しない(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->post("/comments/post/{$post->id}", $this->input());

        $row = (array) DB::table('content_comments')->firstOrFail();

        $this->assertArrayNotHasKey('ip', $row);
        $this->assertSame(64, strlen((string) $row['ip_hash']));
    }

    public function test_投稿者のurlはnofollowで出す(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->comment($post->id, CommentStatus::Approved, url: 'https://example.test');

        // 未認証の相手が入れた URL に検索評価を渡さない
        $this->get("/blog/{$post->slug}")->assertSee('rel="nofollow ugc noopener"', escape: false);
    }

    // ── 保持期間（§G.2） ─────────────────────────────────

    public function test_pruneはipとuaだけ消してコメントは残す(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->post("/comments/post/{$post->id}", $this->input());

        DB::table('content_comments')->update(['created_at' => now()->subDays(200)]);

        $this->artisan('agentcms:comment-prune')->assertSuccessful();

        $row = DB::table('content_comments')->firstOrFail();

        $this->assertNotNull($row->body);
        $this->assertNull($row->ip_hash);
        $this->assertNull($row->user_agent);
    }

    /**
     * **消す表を、消す文の中から読まない。**
     *
     * 「返信が残っている親は消さない」を `WHERE NOT EXISTS (SELECT … FROM
     * content_comments …)` で書くと、**MySQL は拒む**
     * （`1093 You can't specify target table for update in FROM clause`）。
     * SQLite は通すので、**試験は緑のまま本番でだけ落ちる**状態だった。
     *
     * ここでは**発行された SQL を見る**。driver を跨いで走らせなくても、
     * 「消す文が同じ表を読んでいない」ことなら、どこでも確かめられる。
     */
    public function test_消す文が同じ表を読んでいない(): void
    {
        $post = $this->post_();
        $parent = $this->comment($post->id, CommentStatus::Spam);
        // **返信が居る親**を作る（居ないと、消せない親の判定を一度も通らない）
        $this->comment($post->id, CommentStatus::Spam, parentId: $parent->id);

        DB::table('content_comments')->update(['created_at' => now()->subDays(400)]);

        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->artisan('agentcms:comment-prune')->assertSuccessful();

        $deletes = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'delete'),
        ));

        $this->assertNotEmpty($deletes, '消す文が 1 つも出ていません（この試験は何も見ていません）');

        foreach ($deletes as $sql) {
            $body = substr($sql, stripos($sql, 'where') === false ? 0 : (int) stripos($sql, 'where'));

            $this->assertStringNotContainsStringIgnoringCase(
                'from "content_comments"',
                str_replace('`', '"', $body),
                "消す文が同じ表を読んでいます（MySQL が拒みます）: {$sql}",
            );
        }

        $this->assertSame(0, DB::table('content_comments')->count(), '古いスパムが残っています');
    }

    /**
     * **2 段の削除は 1 つの取引の中で起きる。**
     *
     * 返信を消してから根を消す 2 段になっている。途中で落ちると
     * **返信だけ消えて根が残る** — 実測すると、直す前の形では MySQL で
     * まさにそれが起きた（2 件が 1 件になって止まった）。
     *
     * 「落ちたときにどうなるか」を試験から作るには失敗を差し込む口が要るので、
     * ここでは**取引が始まったこと**を見る。始まっていれば、落ちた分は戻る。
     */
    public function test_削除は1つの取引の中で起きる(): void
    {
        $post = $this->post_();
        $parent = $this->comment($post->id, CommentStatus::Spam);
        $this->comment($post->id, CommentStatus::Spam, parentId: $parent->id);

        DB::table('content_comments')->update(['created_at' => now()->subDays(400)]);

        $began = 0;

        Event::listen(TransactionBeginning::class, function () use (&$began): void {
            $began++;
        });

        $this->artisan('agentcms:comment-prune')->assertSuccessful();

        $this->assertGreaterThan(0, $began, '削除が取引の外で起きています（途中で落ちると跡が残ります）');
        $this->assertSame(0, DB::table('content_comments')->count(), '古いスパムが残っています');
    }

    public function test_pruneは古いスパムだけ消す(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->comment($post->id, CommentStatus::Spam);
        $this->comment($post->id, CommentStatus::Trash);
        $this->comment($post->id, CommentStatus::Approved);

        DB::table('content_comments')->update(['created_at' => now()->subDays(200)]);

        $this->artisan('agentcms:comment-prune')->assertSuccessful();

        // 他人の文章は勝手に消さない。スパムを貯める理由は無い
        $this->assertSame(0, Comment::query()->where('status', 'spam')->count());
        $this->assertSame(1, Comment::query()->where('status', 'trash')->count());
        $this->assertSame(1, Comment::query()->where('status', 'approved')->count());
    }

    public function test_pruneはdry_runなら何も変えない(): void
    {
        $this->enableComments();
        $post = $this->post_();
        $this->post("/comments/post/{$post->id}", $this->input());
        DB::table('content_comments')->update(['created_at' => now()->subDays(200)]);

        $this->artisan('agentcms:comment-prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(DB::table('content_comments')->value('ip_hash'));
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @return array<string, string> */
    private function input(): array
    {
        return ['author_name' => '山田太郎', 'author_email' => 'taro@example.test', 'body' => 'よい記事でした'];
    }

    private function post_(string $slug = 'hello', string $status = 'published'): object
    {
        $id = DB::table('post')->insertGetId([
            'title' => '記事', 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id, 'slug' => $slug];
    }

    private function comment(
        int $contentId,
        CommentStatus $status,
        ?int $parentId = null,
        string $body = 'よい記事でした',
        ?string $url = null,
    ): Comment {
        $id = DB::table('content_comments')->insertGetId([
            'content_type' => 'post',
            'content_id' => $contentId,
            'parent_id' => $parentId,
            'status' => $status->value,
            'author_name' => '山田太郎',
            'author_email' => 'taro@example.test',
            'author_url' => $url,
            'body' => $body,
            'is_staff' => false,
            'approved_at' => $status === CommentStatus::Approved ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Comment::query()->findOrFail($id);
    }

    /** 型を公開する（`isPublic()` が要求するものを揃える） */
    private function publishType(bool $comments = false): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'comments_enabled' => $comments,
                'status' => 'active',
                'lifecycle' => 'ready',
                'public_slug' => 'blog',
                'registration' => json_encode(['public' => true], JSON_UNESCAPED_UNICODE),
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    /** 型側の opt-in（9.7 で入った設定を直接立てる） */
    private function enableComments(): void
    {
        $this->publishType(comments: true);
    }
}
