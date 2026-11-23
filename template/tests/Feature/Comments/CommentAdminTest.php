<?php

declare(strict_types=1);

namespace Tests\Feature\Comments;

use AgentCms\Core\Comments\Comment;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Comments\CommentStatus;
use App\Models\User;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * コメントの管理画面（Phase 9.13 / STAGE 26E・SPEC §D）。
 *
 * ここで固定するのは **承認待ちが最初に出ること**、
 * **まとめて承認できて、まとめて消せないこと**、
 * そして **AI へ 1 つも公開していないこと**である。
 */
final class CommentAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    // ── 画面 ────────────────────────────────────────────────

    public function test_一覧の既定は承認待ち(): void
    {
        $this->comment();
        $this->comment(status: CommentStatus::Approved);

        $this->actingAs($this->owner)->get('/admin/comments')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->component('admin/comments/Index')
                    ->has('comments', 1)
                    ->where('meta.status', 'pending')
                    ->where('meta.pending', 1),
            );
    }

    public function test_状態で絞り込める(): void
    {
        $this->comment();
        $this->comment(status: CommentStatus::Spam);

        $this->actingAs($this->owner)->get('/admin/comments?status=spam')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('comments', 1));
    }

    public function test_一覧に記事のタイトルを添える(): void
    {
        $post = $this->post_();
        $this->comment(contentId: $post);

        $this->actingAs($this->owner)->get('/admin/comments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('comments.0.entry_title', '記事'));
    }

    public function test_記事が消えていてもコメントは残して見せる(): void
    {
        // 黙って消さない（§D.4）
        $this->comment(contentId: 999999);

        $this->actingAs($this->owner)->get('/admin/comments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('comments.0.entry_title', null));
    }

    public function test_詳細が開く(): void
    {
        $comment = $this->comment();

        $this->actingAs($this->owner)->get("/admin/comments/{$comment->getKey()}")
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->component('admin/comments/Show')
                    // 返信するために要る。**公開面には出ない**
                    ->where('comment.author_email', 'taro@example.test')
                    ->where('entry.enabled', false),
            );
    }

    // ── moderation ─────────────────────────────────────────

    public function test_まとめて承認できる(): void
    {
        $ids = [
            (int) $this->comment()->getKey(),
            (int) $this->comment()->getKey(),
        ];

        $this->actingAs($this->owner)
            ->post('/admin/comments/status', ['ids' => $ids, 'status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(2, Comment::query()->where('status', 'approved')->count());
    }

    public function test_物理削除のrouteが無い(): void
    {
        $comment = $this->comment();

        // ゴミ箱までしか作らない（OG7）
        $this->actingAs($this->owner)
            ->delete("/admin/comments/{$comment->getKey()}")
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post("/admin/comments/{$comment->getKey()}/delete")
            ->assertNotFound();
    }

    public function test_知らない状態は受けない(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/comments/status', ['ids' => [(int) $this->comment()->getKey()], 'status' => 'deleted'])
            ->assertSessionHasErrors('status');
    }

    // ── 返信 ────────────────────────────────────────────────

    public function test_返信できる(): void
    {
        $this->enableComments();
        $parent = $this->comment();

        $this->actingAs($this->owner)
            ->post("/admin/comments/{$parent->getKey()}/reply", ['body' => 'ありがとうございます'])
            ->assertRedirect();

        $reply = Comment::query()->whereNotNull('parent_id')->firstOrFail();

        $this->assertTrue($reply->is_staff);
        $this->assertSame(CommentStatus::Approved, $reply->status());
    }

    public function test_型がoffなら返信できない(): void
    {
        $parent = $this->comment();

        $this->actingAs($this->owner)
            ->post("/admin/comments/{$parent->getKey()}/reply", ['body' => 'ありがとうございます'])
            ->assertSessionHas('error');

        $this->assertSame(1, Comment::query()->count());
    }

    // ── 記事ごとの受付 ──────────────────────────────────────

    public function test_記事ごとに受付を止められる(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->actingAs($this->owner)
            ->post("/admin/comments/entry/post/{$post}", ['is_open' => false])
            ->assertRedirect();

        $this->assertFalse(app(CommentGate::class)->acceptsNew('post', $post));
    }

    public function test_型に従うへ戻せる(): void
    {
        $this->enableComments();
        $post = $this->post_();

        $this->actingAs($this->owner)->post("/admin/comments/entry/post/{$post}", ['is_open' => false]);
        // 空の body は「型に従う」
        $this->actingAs($this->owner)->post("/admin/comments/entry/post/{$post}", []);

        $this->assertNull(app(CommentGate::class)->entryOpen('post', $post));
    }

    public function test_型がoffなら記事ごとの設定を断る(): void
    {
        $post = $this->post_();

        $this->actingAs($this->owner)
            ->post("/admin/comments/entry/post/{$post}", ['is_open' => true])
            ->assertSessionHas('error');
    }

    // ── 権限（§E.2・OG9） ─────────────────────────────────

    public function test_閲覧者は一覧を開けない(): void
    {
        $viewer = User::factory()->create(['role' => Role::Viewer->value]);

        $this->actingAs($viewer)->get('/admin/comments')->assertForbidden();
    }

    public function test_編集者は承認できる(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);
        $comment = $this->comment();

        $this->actingAs($editor)
            ->post('/admin/comments/status', ['ids' => [(int) $comment->getKey()], 'status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(CommentStatus::Approved, $comment->refresh()->status());
    }

    // ── AI へ出さない（OG10） ───────────────────────────────

    public function test_コメントはaiへ1つも公開しない(): void
    {
        $ids = OperationCatalog::ids();

        // 本文と連絡先そのもの。読むのも承認も削除も渡さない
        foreach (['comment.list', 'comment.get', 'comment.status', 'comment.reply', 'comment.entry.open'] as $id) {
            $this->assertNotContains($id, $ids);
        }
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function comment(
        CommentStatus $status = CommentStatus::Pending,
        string $contentType = 'post',
        int $contentId = 1,
    ): Comment {
        $id = DB::table('content_comments')->insertGetId([
            'content_type' => $contentType,
            'content_id' => $contentId,
            'status' => $status->value,
            'author_name' => '山田太郎',
            'author_email' => 'taro@example.test',
            'body' => 'よい記事でした',
            'is_staff' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Comment::query()->findOrFail($id);
    }

    private function post_(): int
    {
        return (int) DB::table('post')->insertGetId([
            'title' => '記事', 'slug' => 'hello', 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now(),
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enableComments(): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
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
}
