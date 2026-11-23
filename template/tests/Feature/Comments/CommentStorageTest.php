<?php

declare(strict_types=1);

namespace Tests\Feature\Comments;

use AgentCms\Core\Application\Comments\GetComment;
use AgentCms\Core\Application\Comments\ListComments;
use AgentCms\Core\Application\Comments\ReplyToComment;
use AgentCms\Core\Application\Comments\SetCommentStatus;
use AgentCms\Core\Application\Comments\SetEntryCommentOpen;
use AgentCms\Core\Application\ContentTypes\SetContentTypeComments;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Comments\Comment;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Comments\CommentPresenter;
use AgentCms\Core\Comments\CommentStatus;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use App\Models\User;
use App\Support\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * コメントの storage と moderation（Phase 9.13 / STAGE 26C）。
 *
 * ここで固定するのは **保存される形**と、**二層 opt-in の順序**、
 * そして **監査に他人の文章が載らないこと**である。公開面の受付は 26D。
 */
final class CommentStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    // ── 表の形 ─────────────────────────────────────────────

    public function test_表と索引ができている(): void
    {
        $this->assertTrue(Schema::hasTable('content_comments'));
        $this->assertTrue(Schema::hasTable(CommentGate::SETTINGS_TABLE));

        $names = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('content_comments'),
        );

        $this->assertContains('idx_comment_entry', $names);
        $this->assertContains('idx_comment_status', $names);
    }

    public function test_記事への外部キーは張らない(): void
    {
        // 型は管理画面から増える。参照先の表が実行時にしか決まらない
        $this->assertNotNull($this->comment(contentType: 'not-a-real-type', contentId: 999999));
    }

    public function test_親を残したまま消させない(): void
    {
        $parent = $this->comment();
        $this->comment(parentId: (int) $parent->getKey());

        // 返信だけが宙に浮く状態を作らない
        $this->assertThrows(
            fn () => DB::table('content_comments')->where('id', $parent->getKey())->delete(),
            QueryException::class,
        );
    }

    public function test_downはコメントが残っていれば止まる(): void
    {
        $this->comment();

        $this->assertThrows(fn () => $this->migration()->down(), \RuntimeException::class);

        $this->assertTrue(Schema::hasTable('content_comments'));
    }

    public function test_downは空なら通って戻せる(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('content_comments'));
        $this->assertFalse(Schema::hasTable(CommentGate::SETTINGS_TABLE));

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('content_comments'));
    }

    // ── 二層 opt-in（§C.2） ────────────────────────────────

    public function test_型がoffなら記事側の設定に関わらず受け付けない(): void
    {
        $gate = app(CommentGate::class);

        // 既定はすべて OFF（9.7 §L）
        $this->assertFalse($gate->enabledForType('post'));

        $gate->setEntryOpen('post', 1, true);

        // 記事側で開いた覚えがあっても、型を止めたら止まる
        $this->assertFalse($gate->acceptsNew('post', 1));
    }

    public function test_型がonなら既定で受け付ける(): void
    {
        $this->enableComments('post');
        $gate = app(CommentGate::class);

        $this->assertTrue($gate->enabledForType('post'));
        // 行が無い = 型に従う
        $this->assertNull($gate->entryOpen('post', 1));
        $this->assertTrue($gate->acceptsNew('post', 1));
    }

    public function test_記事ごとに閉じられる(): void
    {
        $this->enableComments('post');
        $gate = app(CommentGate::class);

        $gate->setEntryOpen('post', 1, false);

        $this->assertFalse($gate->acceptsNew('post', 1));
        // ほかの記事は開いたまま
        $this->assertTrue($gate->acceptsNew('post', 2));
    }

    public function test_型に従うへ戻せる(): void
    {
        $this->enableComments('post');
        $gate = app(CommentGate::class);

        $gate->setEntryOpen('post', 1, false);
        $gate->setEntryOpen('post', 1, null);

        // false を書いて代用しない。行ごと消えて「未設定」へ戻る
        $this->assertNull($gate->entryOpen('post', 1));
        $this->assertSame(0, DB::table(CommentGate::SETTINGS_TABLE)->count());
    }

    public function test_記事ごとの設定は型がoffなら保存させない(): void
    {
        $result = app(SetEntryCommentOpen::class)->execute('post', 1, true, $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(0, DB::table(CommentGate::SETTINGS_TABLE)->count());
    }

    // ── moderation（§D.2） ────────────────────────────────

    public function test_既定は承認待ち(): void
    {
        $this->assertSame(CommentStatus::Pending, $this->comment()->status());
    }

    public function test_承認できる(): void
    {
        $comment = $this->comment();

        $result = app(SetCommentStatus::class)
            ->execute([(int) $comment->getKey()], 'approved', $this->context());

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        $comment->refresh();

        $this->assertSame(CommentStatus::Approved, $comment->status());
        $this->assertNotNull($comment->approved_at);
    }

    public function test_承認を取り消しても承認した記録は残る(): void
    {
        $comment = $this->comment();
        $ids = [(int) $comment->getKey()];

        app(SetCommentStatus::class)->execute($ids, 'approved', $this->context());
        app(SetCommentStatus::class)->execute($ids, 'pending', $this->context());

        $comment->refresh();

        $this->assertSame(CommentStatus::Pending, $comment->status());
        // いつ一度承認されたかは残す
        $this->assertNotNull($comment->approved_at);
    }

    public function test_まとめて承認できる(): void
    {
        $ids = [
            (int) $this->comment()->getKey(),
            (int) $this->comment()->getKey(),
            (int) $this->comment()->getKey(),
        ];

        $result = app(SetCommentStatus::class)->execute($ids, 'approved', $this->context());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(3, Comment::query()->where('status', 'approved')->count());
    }

    public function test_1件でも見つからなければ何も変えない(): void
    {
        $comment = $this->comment();

        $result = app(SetCommentStatus::class)
            ->execute([(int) $comment->getKey(), 999999], 'approved', $this->context());

        $this->assertSame(OperationStatus::NotFound, $result->status);
        $this->assertSame(CommentStatus::Pending, $comment->refresh()->status());
    }

    public function test_知らない状態は受けない(): void
    {
        $result = app(SetCommentStatus::class)
            ->execute([(int) $this->comment()->getKey()], 'deleted', $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_物理削除の口が無い(): void
    {
        // ゴミ箱までしか作らない（OG7）
        $this->assertFalse(class_exists('AgentCms\\Core\\Application\\Comments\\DeleteComment'));
    }

    // ── 返信（§D.5・OG4） ─────────────────────────────────

    public function test_管理画面から返信できる(): void
    {
        $this->enableComments('post');
        $parent = $this->comment();

        $result = app(ReplyToComment::class)
            ->execute((int) $parent->getKey(), 'ご意見ありがとうございます', $this->context());

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        $reply = Comment::query()->where('parent_id', $parent->getKey())->firstOrFail();

        $this->assertTrue($reply->is_staff);
        // 書いた人が既に管理者なので、自分の返信を自分で承認させない
        $this->assertSame(CommentStatus::Approved, $reply->status());
        $this->assertSame($parent->content_type, $reply->content_type);
    }

    public function test_返信への返信はできない(): void
    {
        $this->enableComments('post');
        $parent = $this->comment();

        app(ReplyToComment::class)->execute((int) $parent->getKey(), '1 段目', $this->context());
        $reply = Comment::query()->where('parent_id', $parent->getKey())->firstOrFail();

        $result = app(ReplyToComment::class)->execute((int) $reply->getKey(), '2 段目', $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('parent_id', $result->errors);
    }

    public function test_空の返信は受けない(): void
    {
        $this->enableComments('post');

        $result = app(ReplyToComment::class)
            ->execute((int) $this->comment()->getKey(), "  \n ", $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_型がoffなら返信もできない(): void
    {
        $result = app(ReplyToComment::class)
            ->execute((int) $this->comment()->getKey(), '返信', $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
    }

    // ── 一覧と 1 件（§D.1） ────────────────────────────────

    public function test_一覧の既定は承認待ちだけ(): void
    {
        $this->comment();
        $approved = $this->comment();
        app(SetCommentStatus::class)->execute([(int) $approved->getKey()], 'approved', $this->context());

        $result = app(ListComments::class)->execute($this->context());

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->data['comments']);
        $this->assertSame(1, $result->meta['pending']);
    }

    public function test_一覧は本文を全部積まない(): void
    {
        $this->comment(body: str_repeat('あ', 300));

        $result = app(ListComments::class)->execute($this->context());

        $this->assertSame(80, mb_strlen($result->data['comments'][0]['excerpt']));
        $this->assertArrayNotHasKey('body', $result->data['comments'][0]);
    }

    public function test_一覧にメールアドレスを出さない(): void
    {
        $this->comment();

        $result = app(ListComments::class)->execute($this->context());

        $this->assertStringNotContainsString(
            'taro@example.test',
            json_encode($result->data, JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    public function test_1件は管理画面用にメールを出す(): void
    {
        $comment = $this->comment();

        $result = app(GetComment::class)->execute((int) $comment->getKey(), $this->context());

        // 返信するために要る。**公開面の形には入っていない**（26D で確かめる）
        $this->assertSame('taro@example.test', $result->data['comment']['author_email']);
        $this->assertFalse($result->data['comment']['has_ip']);
    }

    // ── 権限（§E.2・OG9） ─────────────────────────────────

    public function test_閲覧者は承認できない(): void
    {
        $viewer = User::factory()->create(['role' => Role::Viewer->value]);
        $comment = $this->comment();

        $result = app(SetCommentStatus::class)->execute(
            [(int) $comment->getKey()],
            'approved',
            OperationContext::admin((int) $viewer->getKey(), (string) $viewer->name),
        );

        $this->assertSame(OperationStatus::Forbidden, $result->status);
    }

    public function test_編集者は承認できる(): void
    {
        // 記事を書く人が自分の記事のコメントを捌けないと運用が回らない
        $editor = User::factory()->create(['role' => Role::Editor->value]);
        $comment = $this->comment();

        $result = app(SetCommentStatus::class)->execute(
            [(int) $comment->getKey()],
            'approved',
            OperationContext::admin((int) $editor->getKey(), (string) $editor->name),
        );

        $this->assertTrue($result->isSuccess());
    }

    // ── 監査（§E.5） ──────────────────────────────────────

    public function test_監査に本文と個人情報を入れない(): void
    {
        $comment = $this->comment(body: '本文ここにあり');

        app(SetCommentStatus::class)->execute([(int) $comment->getKey()], 'spam', $this->context());

        $row = DB::table('audit_logs')->where('operation', 'comment.status')->firstOrFail();
        $dump = json_encode((array) $row, JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString('本文ここにあり', $dump);
        $this->assertStringNotContainsString('taro@example.test', $dump);
        $this->assertStringNotContainsString('山田太郎', $dump);
    }

    /**
     * **型を切り替えても、書かれたものは消えない**（§K 受入条件 4）。
     *
     * OFF は「受け付けない・見せない」であって「捨てる」ではない。
     * ここが崩れると、**型を一度 OFF にしただけで過去のコメントが消える**。
     * 消えたことは OFF のあいだ誰にも見えないので、**気づくのは ON に戻したとき**である。
     */
    public function test_型をoffにしてもコメントは消えない(): void
    {
        $this->enableComments('post');
        $comment = $this->comment();

        // **直接 DB を書かず、実際の操作を通す。**
        // 「OFF にしたら片付ける」を足すなら、足す場所はここだからである
        $this->setTypeComments('post', false);

        $this->assertSame(1, Comment::query()->count(), 'OFF にしたら消えた');

        $this->setTypeComments('post', true);

        $comment->refresh();
        $this->assertSame('よい記事でした', $comment->body, 'ON に戻したら中身が変わっていた');
    }

    /**
     * **ゴミ箱から戻せる**（§K 受入条件 6）。
     *
     * 4 状態のうち `trash` だけ、**operation を通して設定する経路が
     * 1 度も通っていなかった**（`spam` は通っていた）。
     * 「消さずに畳む」と言う以上、**畳んだものを開けられる**ことまでが条件である。
     */
    public function test_ゴミ箱へ入れて戻せる(): void
    {
        $comment = $this->comment();
        $ids = [(int) $comment->getKey()];

        $this->assertTrue(
            app(SetCommentStatus::class)->execute($ids, 'trash', $this->context())->isSuccess(),
        );
        $this->assertSame(CommentStatus::Trash, $comment->refresh()->status());

        $this->assertTrue(
            app(SetCommentStatus::class)->execute($ids, 'approved', $this->context())->isSuccess(),
        );
        $this->assertSame(CommentStatus::Approved, $comment->refresh()->status());
    }

    /**
     * **公開向けの形にメールアドレスが現れない**（§K 受入条件 10）。
     *
     * いまの守りは**画面の側**にある — presenter に `author_email` を足しても
     * **どの試験も赤くならない**（画面が出さない限り漏れないため）。
     *
     * それでは「画面を書き換えた人」が守りを丸ごと外せる。
     * **出どころの側でも押さえる。**
     */
    public function test_公開向けの形にメールアドレスを入れない(): void
    {
        $comment = $this->comment();

        $public = CommentPresenter::publicView($comment);

        $this->assertArrayNotHasKey('author_email', $public);
        $this->assertStringNotContainsString(
            'taro@example.test',
            (string) json_encode($public, JSON_UNESCAPED_UNICODE),
            '公開向けの形にメールアドレスが混ざっている',
        );
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function comment(
        string $contentType = 'post',
        int $contentId = 1,
        ?int $parentId = null,
        string $body = 'よい記事でした',
    ): Comment {
        $id = DB::table('content_comments')->insertGetId([
            'content_type' => $contentType,
            'content_id' => $contentId,
            'parent_id' => $parentId,
            'status' => CommentStatus::Pending->value,
            'author_name' => '山田太郎',
            'author_email' => 'taro@example.test',
            'body' => $body,
            'is_staff' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Comment::query()->findOrFail($id);
    }

    /**
     * 型側の受付可否を **操作を通して**切り替える。
     *
     * 直接 DB を書くと、「OFF にしたら片付ける」を足されても気づけない。
     * **足すとしたらここ**なので、ここを通す。
     */
    private function setTypeComments(string $contentType, bool $enabled): void
    {
        /*
         * **読み直させる。** 設定の読み手は 1 度読んだら覚えたままで、
         * 操作を通しても忘れない（1 リクエスト = 1 instance なので実運用では困らない）。
         * 試験は 1 つの process で往復するので、ここで捨てる。
         */
        app()->forgetInstance(ContentTypeSettingsResolver::class);

        $version = (int) DB::table('content_types')
            ->where('content_type_id', $contentType)->value('lock_version');

        $result = app(SetContentTypeComments::class)
            ->execute($contentType, $enabled, $version, $this->context());

        if ($result->confirmation !== null) {
            $result = app(SetContentTypeComments::class)->execute(
                $contentType,
                $enabled,
                $version,
                $this->context()->withConfirmationToken((string) $result->confirmation->token),
            );
        }

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    /** 型側の opt-in（9.7 で入った設定を直接立てる） */
    private function enableComments(string $contentType): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $contentType],
            [
                'comments_enabled' => true,
                'status' => 'active',
                'lifecycle' => 'ready',
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('core/database/migrations/2026_08_24_000010_create_comment_tables.php');

        return $migration;
    }
}
