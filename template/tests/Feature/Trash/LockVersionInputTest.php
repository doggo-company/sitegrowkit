<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\Content\EntryLockVersion;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Trash\TrashColumns;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Post\Models\Post;
use Tests\TestCase;

/**
 * 版の入力は **書き込む面すべてで**同じ規則で断る（Phase 9.8 / STAGE 21C）。
 *
 * 規則そのものは `Tests\Unit\LockVersionInputTest` が固定している。
 * ここで見たいのは **経路ごとに実際どうなるか**である。1 か所で判定していても、
 * adapter が値を落としたり、確認より後で見たりすれば、そこだけ素通りする。
 *
 * 断ったときは **DB が 1 バイトも変わらない**ことまで見る。
 * 「エラーは返ったが半分書けていた」が一番たちが悪い。
 */
final class LockVersionInputTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    /**
     * 断られるべき入力。
     *
     * `null` と `''` は **キーが在るのに空**である。「送っていない」とは意味が違う。
     * 大きい 2 つは、cast すると丸められて「範囲内の指定」に化けるもの。
     *
     * @return array<string, mixed>
     */
    private static function 断る入力(): array
    {
        return [
            '明示のnull' => null,
            '空文字' => '',
            'PHP_INT_MAX超過' => '9223372036854775808',
            'JSで安全でない整数' => '9007199254740992',
            '非数値' => 'abc',
            '負数' => -1,
            '小数' => 1.5,
            '真偽値' => true,
        ];
    }

    // ── A. API（更新） ───────────────────────────────────────────

    public function test_api更新は壊れた版を422で断りdbを変えない(): void
    {
        foreach (self::断る入力() as $label => $value) {
            $id = $this->entry('api-'.md5($label));
            $before = $this->snapshot($id);

            $response = $this->actingAs($this->owner)->patchJson("/api/v1/content/post/{$id}", [
                'data' => ['title' => '上書き', 'body' => '<p>書き換え</p>'],
                'lock_version' => $value,
            ]);

            $response->assertStatus(422, $label);
            $this->assertUnchanged($id, $before, $label);
        }
    }

    public function test_api更新はキー省略なら移行互換で通る(): void
    {
        $id = $this->entry('api-omitted');

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/content/post/{$id}", ['data' => ['title' => '版なしで更新']])
            ->assertOk();

        $this->assertSame('版なしで更新', DB::table('post')->where('id', $id)->value('title'));
    }

    public function test_api更新は0と現在版で通り新しい版を返す(): void
    {
        $id = $this->entry('api-current');

        $first = $this->actingAs($this->owner)->patchJson("/api/v1/content/post/{$id}", [
            'data' => ['title' => '初版から'], 'lock_version' => 0,
        ]);

        $first->assertOk();
        $this->assertSame(1, $first->json('data.lock_version'));

        $second = $this->actingAs($this->owner)->patchJson("/api/v1/content/post/{$id}", [
            'data' => ['title' => '次の版へ'], 'lock_version' => 1,
        ]);

        $second->assertOk();
        $this->assertSame(2, $second->json('data.lock_version'));
    }

    public function test_api更新は古い版を409で断り内容を変えない(): void
    {
        $id = $this->entry('api-stale');
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 4]);
        $before = $this->snapshot($id);

        $this->actingAs($this->owner)->patchJson("/api/v1/content/post/{$id}", [
            'data' => ['title' => '上書き'], 'lock_version' => 1,
        ])->assertStatus(409);

        $this->assertUnchanged($id, $before, '古い版');
    }

    public function test_api更新の拒否に内部情報を含めない(): void
    {
        $id = $this->entry('api-no-leak');

        $body = (string) $this->actingAs($this->owner)->patchJson("/api/v1/content/post/{$id}", [
            'data' => ['title' => 'x'], 'lock_version' => 'abc',
        ])->getContent();

        foreach (['SQLSTATE', 'select ', '/var/www', (string) $this->owner->email] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    // ── B. 管理画面（更新） ──────────────────────────────────────

    public function test_管理画面の更新は壊れた版を入力エラーで断りdbを変えない(): void
    {
        foreach (self::断る入力() as $label => $value) {
            $id = $this->entry('admin-'.md5($label));
            $before = $this->snapshot($id);

            $this->actingAs($this->owner)
                ->put("/admin/post/{$id}", [
                    'title' => '上書き', 'body' => '<p>書き換え</p>', 'lock_version' => $value,
                ])
                ->assertSessionHasErrors('lock_version');

            $this->assertUnchanged($id, $before, $label);
        }
    }

    // ── C. OperationCatalog / MCP（更新） ────────────────────────

    public function test_mcp更新は壊れた版を検証エラーで断りdbを変えない(): void
    {
        foreach (self::断る入力() as $label => $value) {
            $id = $this->entry('mcp-'.md5($label));
            $before = $this->snapshot($id);

            $result = $this->invoke('content.update', [
                'type' => 'post', 'id' => $id,
                'data' => ['title' => '上書き', 'body' => '<p>書き換え</p>'],
                'lock_version' => $value,
            ]);

            $this->assertSame(OperationStatus::ValidationError, $result->status, $label);
            $this->assertUnchanged($id, $before, $label);
        }
    }

    public function test_mcp更新は現在版で通り古い版を競合で断る(): void
    {
        $id = $this->entry('mcp-versions');
        DB::table('post')->where('id', $id)->update([TrashColumns::LOCK_VERSION => 2]);
        $before = $this->snapshot($id);

        $stale = $this->invoke('content.update', [
            'type' => 'post', 'id' => $id, 'data' => ['title' => '上書き'], 'lock_version' => 0,
        ]);

        $this->assertSame(OperationStatus::Conflict, $stale->status);
        $this->assertUnchanged($id, $before, '古い版');

        $fresh = $this->invoke('content.update', [
            'type' => 'post', 'id' => $id, 'data' => ['title' => '正しい版'], 'lock_version' => 2,
        ]);

        $this->assertTrue($fresh->isSuccess(), (string) $fresh->message);
        $this->assertSame(3, $fresh->data['lock_version']);
    }

    // ── D. ゴミ箱へ移す ─────────────────────────────────────────

    public function test_ゴミ箱への移動は確認を出す前に壊れた版を断る(): void
    {
        foreach (self::断る入力() as $label => $value) {
            $id = $this->entry('trash-'.md5($label));
            $before = $this->snapshot($id);

            /*
             * **確認ヘッダーを付けずに**投げる。ここで確認要求（409）が返ってしまうと、
             * 壊れた指定のまま利用者に確認を押させることになる。
             */
            $response = $this->actingAs($this->owner)
                ->postJson("/api/v1/content/post/{$id}/trash", ['lock_version' => $value]);

            $response->assertStatus(422, $label);
            $this->assertNull($response->json('confirmation'), $label.': 確認へ進んでいる');
            $this->assertUnchanged($id, $before, $label);
        }
    }

    public function test_ゴミ箱への移動は正しい版なら通る(): void
    {
        $id = $this->entry('trash-ok');

        $token = (string) app(TrashContent::class)
            ->execute('post', $id, $this->context())->confirmation?->token;

        $this->actingAs($this->owner)
            ->withHeader('X-Confirmation', $token)
            ->postJson("/api/v1/content/post/{$id}/trash", ['lock_version' => 0])
            ->assertOk();

        $this->assertNotNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    // ── E. content.delete（互換 wrapper） ───────────────────────

    public function test_互換deleteも壊れた版を断り確認を返さない(): void
    {
        foreach (self::断る入力() as $label => $value) {
            $id = $this->entry('delete-'.md5($label));
            $before = $this->snapshot($id);

            $result = $this->invoke('content.delete', [
                'type' => 'post', 'id' => $id, 'lock_version' => $value,
            ]);

            $this->assertSame(OperationStatus::ValidationError, $result->status, $label);
            $this->assertNull($result->confirmation, $label.': 確認を返している');
            $this->assertUnchanged($id, $before, $label);
        }
    }

    public function test_互換deleteは正しい版ならゴミ箱へ移す(): void
    {
        $id = $this->entry('delete-ok');

        // 互換 wrapper も確認を通る（`content.trash` の確認は流用できない）
        $first = $this->invoke('content.delete', ['type' => 'post', 'id' => $id, 'lock_version' => 0]);
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);

        $trashToken = (string) app(TrashContent::class)
            ->execute('post', $id, $this->agent())->confirmation?->token;

        $crossed = $this->invoke(
            'content.delete',
            ['type' => 'post', 'id' => $id, 'lock_version' => 0],
            $trashToken,
        );
        $this->assertSame(OperationStatus::ConfirmationRequired, $crossed->status, '確認が流用できてしまう');

        $applied = $this->invoke(
            'content.delete',
            ['type' => 'post', 'id' => $id, 'lock_version' => 0],
            (string) $first->confirmation?->token,
        );

        $this->assertTrue($applied->isSuccess(), (string) $applied->message);
        $this->assertSame('trashed', $applied->data['state']);
    }

    // ── F. ゴミ箱から戻す ───────────────────────────────────────

    public function test_復元は壊れた版を422で断りゴミ箱のまま保つ(): void
    {
        foreach (self::断る入力() as $label => $value) {
            $id = $this->entry('restore-'.md5($label));
            $this->putInTrash($id);
            $before = $this->snapshot($id);

            $response = $this->actingAs($this->owner)
                ->postJson("/api/v1/content/post/{$id}/restore", ['lock_version' => $value]);

            $response->assertStatus(422, $label);
            $this->assertUnchanged($id, $before, $label);

            // active へ戻っていない
            $this->assertNull(Post::query()->find($id), $label.': 戻ってしまった');
        }
    }

    public function test_復元は古い版を409で断り現在版で通る(): void
    {
        $id = $this->entry('restore-versions');
        $this->putInTrash($id);
        $before = $this->snapshot($id);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/content/post/{$id}/restore", ['lock_version' => 0])
            ->assertStatus(409);

        $this->assertUnchanged($id, $before, '古い版');

        $ok = $this->actingAs($this->owner)
            ->postJson("/api/v1/content/post/{$id}/restore", ['lock_version' => 1]);

        $ok->assertOk();
        $this->assertSame('active', $ok->json('data.state'));
        $this->assertSame(2, $ok->json('data.lock_version'));
    }

    // ── 補助 ────────────────────────────────────────────────────

    /**
     * 断られたときに変わっていてはいけないもの。
     *
     * @return array<string, mixed>
     */
    private function snapshot(int $id): array
    {
        $row = DB::table('post')->where('id', $id)->first();

        return [
            'title' => $row->title,
            'body' => $row->body,
            TrashColumns::LOCK_VERSION => $row->{TrashColumns::LOCK_VERSION},
            TrashColumns::DELETED_AT => $row->{TrashColumns::DELETED_AT},
            TrashColumns::TRASHED_BY => $row->{TrashColumns::TRASHED_BY},
        ];
    }

    /** @param array<string, mixed> $before */
    private function assertUnchanged(int $id, array $before, string $label): void
    {
        $after = $this->snapshot($id);

        foreach ($before as $column => $value) {
            $this->assertSame($value, $after[$column], "{$label}: {$column} が変わっている");
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function invoke(string $operation, array $arguments, ?string $token = null): Result
    {
        $context = $this->agent();

        return app(OperationCatalog::class)->invoke(
            $operation,
            $arguments,
            $token === null ? $context : $context->withConfirmationToken($token),
        );
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'test-agent');
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug, 'slug' => $slug, 'body' => '<p>もとの本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** ゴミ箱に入った状態を作る（版も 1 進めて、実際に移したのと同じ形にする） */
    private function putInTrash(int $id): void
    {
        DB::table('post')->where('id', $id)->update([
            TrashColumns::DELETED_AT => now(),
            TrashColumns::TRASHED_BY => (int) $this->owner->getKey(),
            TrashColumns::LOCK_VERSION => 1,
        ]);

        $this->assertSame(1, (int) DB::table('post')->where('id', $id)->value(TrashColumns::LOCK_VERSION));
        $this->assertNotSame('', EntryLockVersion::CONFLICT_MESSAGE);
    }
}
