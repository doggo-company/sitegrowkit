<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\Content\EntryLockVersion;
use AgentCms\Core\Application\Content\RestoreContent;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Trash\TrashColumns;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Post\Models\Post;
use Tests\TestCase;

/**
 * 楽観ロック（Phase 9.8 / STAGE 21C・Owner Decision OG11）。
 *
 * 守りたいのは「復元できるが、消えた原因が上書きだった」を作らないことである。
 * ゴミ箱があっても、**上書きは元に戻せない**。
 */
final class OptimisticLockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_古い版での更新は通らず内容も変わらない(): void
    {
        $id = $this->entry('locked');

        // 誰かが先に保存した
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 3]);

        $result = app(UpdateContent::class)->execute(
            'post', $id, ['title' => '上書き'], $this->context(), partial: true, lockVersion: 0,
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame(EntryLockVersion::CONFLICT_MESSAGE, $result->message);

        // **上書きされていない**
        $this->assertSame('locked', DB::table('post')->where('id', $id)->value('title'));
    }

    public function test_競合の知らせに誰かは含めない(): void
    {
        $id = $this->entry('who');
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 1]);

        $result = app(UpdateContent::class)->execute(
            'post', $id, ['title' => 'x'], $this->context(), partial: true, lockVersion: 0,
        );

        $message = (string) $result->message;

        // 誰が更新したかは、その人が何を見ているかを漏らす
        $this->assertStringNotContainsString((string) $this->owner->name, $message);
        $this->assertStringNotContainsString((string) $this->owner->email, $message);
        $this->assertStringNotContainsString((string) $this->owner->getKey(), $message);
    }

    public function test_正しい版なら通り新しい版が返る(): void
    {
        $id = $this->entry('fresh');

        $result = app(UpdateContent::class)->execute(
            'post', $id, ['title' => '更新後'], $this->context(), partial: true, lockVersion: 0,
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame(1, $result->data['lock_version']);
        $this->assertSame(1, (int) DB::table('post')->where('id', $id)->value(TrashColumns::LOCK_VERSION));
    }

    public function test_版を指定しない更新は移行期間中だけ通り監査に印が残る(): void
    {
        $id = $this->entry('legacy-client');

        $result = app(UpdateContent::class)->execute(
            'post', $id, ['title' => '版なし'], $this->context(), partial: true,
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.update')
            ->orderByDesc('id')
            ->value('metadata');

        // 移行がどこまで進んだかは、ここでしか測れない
        $this->assertStringContainsString(EntryLockVersion::UNSPECIFIED_AUDIT, $metadata);
    }

    public function test_版を指定した更新には印が付かない(): void
    {
        $id = $this->entry('modern-client');

        app(UpdateContent::class)->execute(
            'post', $id, ['title' => '版あり'], $this->context(), partial: true, lockVersion: 0,
        );

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.update')
            ->orderByDesc('id')
            ->value('metadata');

        $this->assertStringNotContainsString(EntryLockVersion::UNSPECIFIED_AUDIT, $metadata);
    }

    public function test_古い版ではゴミ箱へ入れられない(): void
    {
        $id = $this->entry('stale-trash');
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 2]);

        $token = (string) app(TrashContent::class)
            ->execute('post', $id, $this->context(), 2)->confirmation?->token;

        $result = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($token), 1,
        );

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_古い版では戻せない(): void
    {
        $id = $this->entry('stale-restore');
        $this->trash($id);

        // いまの版は 1。0 を渡す
        $result = app(RestoreContent::class)->execute('post', $id, $this->context(), 0);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNotNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_同時にゴミ箱へ入れると片方だけ成功する(): void
    {
        $id = $this->entry('race');

        $tokenA = (string) app(TrashContent::class)->execute('post', $id, $this->context(), 0)->confirmation?->token;
        $tokenB = (string) app(TrashContent::class)->execute('post', $id, $this->context(), 0)->confirmation?->token;

        $first = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($tokenA), 0,
        );
        $second = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($tokenB), 0,
        );

        $this->assertTrue($first->isSuccess(), (string) $first->message);

        /*
         * 2 つ目は通らない。**理由は版である。**
         *
         * 1 つ目が成功した時点で版が進むので、2 つ目が持っている確認は
         * その版に束縛されたまま食い違う。確認の取り直しから始まり、
         * 取り直せば「既にゴミ箱にあります」で止まる。
         *
         * どちらの段で止まるにせよ、**両方が成功することは無い**。
         */
        $this->assertFalse($second->isSuccess());
        $this->assertSame(OperationStatus::ConfirmationRequired, $second->status);

        // 取り直しても通らない
        $retryToken = (string) app(TrashContent::class)
            ->execute('post', $id, $this->context())->confirmation?->token;
        $retry = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($retryToken),
        );

        $this->assertSame(OperationStatus::Conflict, $retry->status);
        $this->assertStringContainsString('既にゴミ箱', (string) $retry->message);
    }

    public function test_版の列はfillableに入っていない(): void
    {
        $model = new Post;

        foreach (TrashColumns::ALL as $column) {
            $this->assertFalse(
                $model->isFillable($column),
                "{$column} が一括代入できる（利用者の入力で状態を書き換えられてしまう）",
            );
        }
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug, 'slug' => $slug, 'body' => '<p>本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function trash(int $id): void
    {
        $token = (string) app(TrashContent::class)->execute('post', $id, $this->context())->confirmation?->token;

        $result = app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($token),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }
}
