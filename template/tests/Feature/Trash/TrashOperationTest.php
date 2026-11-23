<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\RestoreContent;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Trash\TrashColumns;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Post\Models\Post;
use Tests\TestCase;

/**
 * ゴミ箱へ入れる / 戻す（Phase 9.8 / STAGE 21C・OG2・OG3・OG4・OG5・OG6・OG9・OG11）。
 *
 * 見たいのは「状態が変わること」だけではない。**何が変わらないか**が同じくらい重要で、
 * 分類の割り当ても slug も内容も、ゴミ箱へ入れただけでは 1 バイトも動かない。
 */
final class TrashOperationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_確認を経てゴミ箱へ移る(): void
    {
        $id = $this->entry('kept');

        // 1 回目は確認内容だけを返し、何も変えない
        $first = app(TrashContent::class)->execute('post', $id, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));

        $confirmation = $first->confirmation;
        $this->assertNotNull($confirmation);
        $this->assertStringContainsString('ゴミ箱へ移動します', $confirmation->summary);
        $this->assertContains('ゴミ箱へ移動します。ゴミ箱からは元に戻せます', $confirmation->impact);
        $this->assertContains('公開ページからは表示されなくなります', $confirmation->impact);

        $result = $this->confirm(TrashContent::class, $id, $confirmation->token);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($id, $result->data['id']);
        $this->assertSame('trashed', $result->data['state']);
        $this->assertSame(1, $result->data['lock_version']);

        $row = DB::table('post')->where('id', $id)->first();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame((int) $this->owner->getKey(), (int) $row->trashed_by);
    }

    public function test_ゴミ箱へ移しても内容とslugは動かない(): void
    {
        $id = $this->entry('keep-my-slug');
        $before = (array) DB::table('post')->where('id', $id)->first();

        $this->trash($id);

        $after = (array) DB::table('post')->where('id', $id)->first();

        foreach (['title', 'slug', 'body', 'status', 'published_at'] as $column) {
            $this->assertSame($before[$column], $after[$column], "{$column} が変わっている");
        }
    }

    public function test_ゴミ箱の投稿と同じslugでは作れず理由が出る(): void
    {
        $this->entry('taken');
        $trashed = (int) DB::table('post')->where('slug', 'taken')->value('id');
        $this->trash($trashed);

        $result = app(CreateContent::class)->execute('post', [
            'title' => '同じスラッグ', 'slug' => 'taken', 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);

        // 黙って別の slug へ書き換えない。**理由が読める**ことが要点である
        $this->assertStringContainsString(
            'ゴミ箱',
            implode(' ', $result->errors['slug'] ?? []),
            'ゴミ箱にあることが理由として出ていない',
        );
    }

    public function test_activeな投稿とのslug衝突は今までどおりの理由になる(): void
    {
        $this->entry('active-slug');

        $result = app(CreateContent::class)->execute('post', [
            'title' => 'かぶり', 'slug' => 'active-slug', 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringNotContainsString(
            'ゴミ箱',
            implode(' ', $result->errors['slug'] ?? []),
            'ゴミ箱にない相手をゴミ箱のせいにしている',
        );
    }

    public function test_ゴミ箱の投稿をもう一度捨てられない(): void
    {
        $id = $this->entry('gone');
        $this->trash($id);

        $result = $this->confirmFresh(TrashContent::class, $id);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('既にゴミ箱', (string) $result->message);
    }

    public function test_ゴミ箱から戻せる(): void
    {
        $id = $this->entry('back');
        $this->trash($id);

        // 戻すのに確認は要らない（何も失わないため）
        $result = app(RestoreContent::class)->execute('post', $id, $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame('active', $result->data['state']);
        $this->assertSame(2, $result->data['lock_version']);
        $this->assertNotNull(Post::query()->find($id));
    }

    public function test_公開状態を持つ型は下書きへ戻る(): void
    {
        $id = $this->entry('was-published', 'published');
        $this->trash($id);

        $result = app(RestoreContent::class)->execute('post', $id, $this->context());

        // 戻しただけで公開されない（OG6 = (a)）
        $this->assertSame('draft', $result->data['status']);
        $this->assertSame('draft', DB::table('post')->where('id', $id)->value('status'));
    }

    public function test_activeな投稿は戻せない(): void
    {
        $id = $this->entry('already-active');

        $result = app(RestoreContent::class)->execute('post', $id, $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('ゴミ箱にありません', (string) $result->message);
    }

    public function test_無効にした投稿タイプでは出し入れできない(): void
    {
        $id = $this->entry('archived-type');

        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'archived', 'lifecycle' => 'ready',
            'menu_order' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->forgetScopedInstances();

        $trash = $this->confirmFresh(TrashContent::class, $id);
        $this->assertSame(OperationStatus::Conflict, $trash->status);

        $restore = app(RestoreContent::class)->execute('post', $id, $this->context());
        $this->assertSame(OperationStatus::Conflict, $restore->status);
    }

    public function test_deleteの確認をtrashへ流用できない(): void
    {
        $id = $this->entry('token-swap');

        $token = (string) app(DeleteContent::class)->execute('post', $id, $this->context())->confirmation?->token;

        $result = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($token),
        );

        // 指紋に operation ID が入っているので通らない。もう一度確認を取り直させる
        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_trashの確認をdeleteへ流用できない(): void
    {
        $id = $this->entry('token-swap-2');

        $token = (string) app(TrashContent::class)->execute('post', $id, $this->context())->confirmation?->token;

        $result = app(DeleteContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($token),
        );

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
    }

    public function test_確認を取ったあとに版が変わっていたら通さない(): void
    {
        $id = $this->entry('moving-target');

        $token = (string) app(TrashContent::class)->execute('post', $id, $this->context())->confirmation?->token;

        // 誰かが先に更新した（版が進む）
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 5]);

        $result = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($token),
        );

        // 指紋に版が入っているので、取り直しになる
        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_型とidを跨いで操作できない(): void
    {
        $id = $this->entry('mine');

        // `news` の ID として post の ID を当てても、その組では見つからない
        $result = app(TrashContent::class)->execute('news', $id, $this->context());

        $this->assertSame(OperationStatus::NotFound, $result->status);
    }

    public function test_権限が無ければ拒否する(): void
    {
        $id = $this->entry('no-permission');

        $viewer = User::factory()->create(['role' => 'viewer']);
        $context = OperationContext::admin((int) $viewer->getKey(), (string) $viewer->name);

        $result = app(TrashContent::class)->execute('post', $id, $context);

        $this->assertSame(OperationStatus::Forbidden, $result->status);
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function entry(string $slug, string $status = 'draft'): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug,
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => $status,
            'published_at' => $status === 'published' ? now()->subDay() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 確認を取ってから実行する（正規の 2 回呼び） */
    private function trash(int $id): void
    {
        $result = $this->confirmFresh(TrashContent::class, $id);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    /** @param class-string $operation */
    private function confirmFresh(string $operation, int $id): Result
    {
        $token = (string) app($operation)->execute('post', $id, $this->context())->confirmation?->token;

        return $this->confirm($operation, $id, $token);
    }

    /** @param class-string $operation */
    private function confirm(string $operation, int $id, string $token): Result
    {
        return app($operation)->execute('post', $id, $this->context()->withConfirmationToken($token));
    }
}
