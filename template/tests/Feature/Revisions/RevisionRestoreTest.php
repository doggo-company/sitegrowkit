<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use AgentCms\Core\Application\Content\AutosaveContent;
use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\Revisions\ListContentRevisions;
use AgentCms\Core\Application\Content\Revisions\RestoreContentRevision;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Revisions\RevisionWriter;
use AgentCms\Core\Revisions\SchemaManifestRepository;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 過去の版へ戻す（Phase 9.8 / STAGE 21D・SPEC §D.2.2・§F.2.2・§F.4・§F.5）。
 *
 * ## 迷ったら止める
 *
 * 定義が無い、壊れている、いまの内容が最新版と食い違う、参照先が消えている。
 * どれも「戻した結果が正しい」と言えない状態である。**黙って部分復元しない。**
 */
final class RevisionRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_確認を経て過去の版へ戻る(): void
    {
        $id = $this->create('もとの題名');
        $this->update($id, ['title' => 'あとの題名']);

        $preview = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 1);

        $this->assertSame(OperationStatus::ConfirmationRequired, $preview->status);
        // **この時点では 1 バイトも書いていない**
        $this->assertSame('あとの題名', DB::table('post')->where('id', $id)->value('title'));
        $this->assertSame(1, (int) DB::table('post')->where('id', $id)->value('lock_version'));

        $confirmation = $preview->confirmation;
        $this->assertNotNull($confirmation);
        $this->assertStringContainsString('版 1 の内容へ戻します', $confirmation->summary);
        $this->assertContains('復元した内容は新しい版として記録され、過去の版は変わりません', $confirmation->impact);

        $applied = app(RestoreContentRevision::class)->execute(
            'post', $id, 1, $this->context()->withConfirmationToken($confirmation->token), 1,
        );

        $this->assertTrue($applied->isSuccess(), (string) $applied->message);
        $this->assertSame('もとの題名', DB::table('post')->where('id', $id)->value('title'));
        $this->assertSame(1, $applied->data['restored_from']);
        $this->assertSame(3, $applied->data['revision']);
        $this->assertSame(2, $applied->data['lock_version']);
    }

    public function test_復元は新しい版を1件だけ作り過去の版を書き換えない(): void
    {
        $id = $this->create('版 1');
        $this->update($id, ['title' => '版 2']);

        $before = DB::table(RevisionWriter::TABLE)->orderBy('revision')->get()->toArray();

        $this->restore($id, 1, 1);

        $rows = DB::table(RevisionWriter::TABLE)->orderBy('revision')->get()->all();

        $this->assertCount(3, $rows);
        $this->assertSame('revision_restore', $rows[2]->reason);

        // 過去の 2 件は 1 バイトも変わっていない
        $this->assertEquals($before[0], $rows[0]);
        $this->assertEquals($before[1], $rows[1]);
    }

    public function test_版の指定は必須(): void
    {
        $id = $this->create('版なし');

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('lock_version', $result->errors);
    }

    public function test_古い版では戻せない(): void
    {
        $id = $this->create('競合');
        $this->update($id, ['title' => '更新後']);

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 0);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertSame('更新後', DB::table('post')->where('id', $id)->value('title'));
    }

    public function test_確認トークンは対象の版と現在の版に束縛される(): void
    {
        $id = $this->create('束縛');
        $this->update($id, ['title' => '2 つ目']);
        $this->update($id, ['title' => '3 つ目']);

        $token = (string) app(RestoreContentRevision::class)
            ->execute('post', $id, 1, $this->context(), 2)->confirmation?->token;

        /*
         * **別の版へは流用できない。**
         *
         * 同意を持って来たのに通らなかったなら、押したときと状況が違う。
         * 新しい確認を返して「押し直せば通る」ように見せず、**変わったこと自体**を
         * conflict として伝える（STAGE 21D CORRECTION）。
         */
        $other = app(RestoreContentRevision::class)->execute(
            'post', $id, 2, $this->context()->withConfirmationToken($token), 2,
        );
        $this->assertSame(OperationStatus::Conflict, $other->status);
        $this->assertStringContainsString('状態が変わった', (string) $other->message);

        // 版が動いていたら通らない
        $this->update($id, ['title' => '4 つ目']);
        $stale = app(RestoreContentRevision::class)->execute(
            'post', $id, 1, $this->context()->withConfirmationToken($token), 3,
        );
        $this->assertSame(OperationStatus::Conflict, $stale->status);
    }

    public function test_ゴミ箱の投稿は先に戻してもらう(): void
    {
        $id = $this->create('ゴミ箱');
        $this->update($id, ['title' => '2 つ目']);

        $token = (string) app(TrashContent::class)->execute('post', $id, $this->context())->confirmation?->token;
        app(TrashContent::class)->execute('post', $id, $this->context()->withConfirmationToken($token));

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 3);

        $this->assertSame(OperationStatus::NotFound, $result->status);
    }

    public function test_履歴が無ければ戻せない(): void
    {
        $id = DB::table('post')->insertGetId([
            'title' => '履歴なし', 'slug' => 'no-history', 'body' => '<p>b</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 0);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('履歴がありません', (string) $result->message);
    }

    public function test_いまの内容が最新版と食い違えば止める(): void
    {
        $id = $this->create('契約内');
        $this->update($id, ['title' => '契約内 2']);

        // **契約外の書き込み**（直接 SQL）。記録に無い変更が入った状態
        DB::table('post')->where('id', $id)->update(['title' => '直接書き換えた']);

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 1);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('一致しない', (string) $result->message);
        // 診断は**項目名だけ**。値は出さない
        $this->assertSame(['title'], $result->data['mismatched']);
        $this->assertStringNotContainsString('直接書き換えた', (string) json_encode($result->data));
    }

    /**
     * **自動保存があっても、履歴から復元できる**（Phase 9.15 / STAGE 28B の回帰）。
     *
     * 守りは「いまの内容が**最新の版**と食い違うなら止める」形だった。
     * ところが自動保存（`kind = autosave`）は **わざと記事本体を書かない**ので、
     * 1 件でも積まれた瞬間に**必ず食い違う**。
     *
     * その結果、**自動保存が入った時点で「履歴から復元」が丸ごと使えなくなっていた**。
     * Feature 試験も verify も緑のままで、e2e で初めて出た。
     *
     * 比べる相手は「**本体が一致しているべき版**」= 最後の手動保存である。
     */
    public function test_自動保存があっても履歴から戻せる(): void
    {
        $id = $this->create('もとの見出し');
        $this->update($id, ['title' => '2 つ目の見出し']);

        // 書きかけを自動保存する（本体は書き換わらない）
        $this->assertTrue(app(AutosaveContent::class)->execute(
            'post', $id, ['title' => '書きかけ'], $this->context(),
        )->isSuccess());

        // 復元は **必ず確認を通る**（`requireAlways`）
        $token = (string) app(RestoreContentRevision::class)
            ->execute('post', $id, 1, $this->context(), 1)->confirmation?->token;

        $this->assertNotSame('', $token, '確認が返っていない（守りで止まっている）');

        $result = app(RestoreContentRevision::class)->execute(
            'post', $id, 1, $this->context()->withConfirmationToken($token), 1,
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame('もとの見出し', DB::table('post')->where('id', $id)->value('title'));
    }

    /**
     * **自動保存を基準にしても、契約外の書き込みは見逃さない。**
     *
     * 基準を手動保存へ移したことで守りが緩んでいないことを確かめる。
     */
    public function test_自動保存があっても契約外の書き込みは止める(): void
    {
        $id = $this->create('契約内');
        $this->update($id, ['title' => '契約内 2']);

        $this->assertTrue(app(AutosaveContent::class)->execute(
            'post', $id, ['title' => '書きかけ'], $this->context(),
        )->isSuccess());

        // **契約外の書き込み**（直接 SQL）
        DB::table('post')->where('id', $id)->update(['title' => '直接書き換えた']);

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 1);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('一致しない', (string) $result->message);
    }

    public function test_定義が見つからなければ止める(): void
    {
        $id = $this->create('定義なし');
        $this->update($id, ['title' => '2 つ目']);

        /*
         * **定義は消せない。** 版が指しているあいだは外部キーが止める
         * （それ自体が安全網として効いていることの確認でもある）。
         */
        $this->assertThrows(
            fn () => DB::table(SchemaManifestRepository::TABLE)->delete(),
            QueryException::class,
        );

        // 読めない行になった場合は、定義が無いのと同じ扱いにする
        // **妥当な JSON だが配列でない**もので壊す（MySQL は不正バイトを拒む）
        DB::table(SchemaManifestRepository::TABLE)->update(['manifest' => '"読めない中身"']);

        $result = app(RestoreContentRevision::class)->execute('post', $id, 1, $this->context(), 1);

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertStringContainsString('項目定義が見つからない', (string) $result->message);
    }

    public function test_参照先が消えていれば止める(): void
    {
        $work = DB::table('works')->insertGetId([
            'title' => '実績', 'slug' => 'w-1', 'category' => 'web', 'body' => '<p>b</p>',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CreateContent::class)->execute('product', [
            'title' => '参照つき',
            'description' => '<p>説明</p>',
            'price' => 1000,
            'status' => 'draft',
            'related_work' => $work,
        ], $this->context());

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        $id = (int) $result->data['item']['id'];

        app(UpdateContent::class)->execute('product', $id, ['title' => '2 つ目'], $this->context(), partial: true);

        // 参照先が物理的に消えた（purge 相当）
        DB::table('works')->where('id', $work)->delete();

        $restore = app(RestoreContentRevision::class)->execute('product', $id, 1, $this->context(), 1);

        $this->assertSame(OperationStatus::Conflict, $restore->status, (string) $restore->message);
        $this->assertStringContainsString('参照先が見つかりません', (string) $restore->message, json_encode($restore->data, JSON_UNESCAPED_UNICODE));
    }

    public function test_権限が無ければ拒否する(): void
    {
        $id = $this->create('権限');
        $this->update($id, ['title' => '2 つ目']);

        $viewer = User::factory()->create(['role' => 'viewer']);

        $result = app(RestoreContentRevision::class)->execute(
            'post', $id, 1, OperationContext::admin((int) $viewer->getKey(), (string) $viewer->name), 1,
        );

        $this->assertSame(OperationStatus::Forbidden, $result->status);
    }

    public function test_aiへは公開しない(): void
    {
        $this->assertFalse(OperationCatalog::allows('content.revision.restore'));
        $this->assertFalse(OperationCatalog::allows('content.revision.list'));
        $this->assertFalse(OperationCatalog::allows('content.revision.baseline'));
    }

    public function test_履歴は0件でも落ちない(): void
    {
        $id = DB::table('post')->insertGetId([
            'title' => '履歴なし', 'slug' => 'empty-history', 'body' => '<p>b</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(ListContentRevisions::class)->execute('post', $id, $this->context());

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $result->data['items']);
    }

    public function test_壊れた版があっても一覧は落ちない(): void
    {
        $id = $this->create('壊れた版');

        // **妥当な JSON だが配列でない**もので壊す（MySQL は不正バイトを拒む）
        DB::table(RevisionWriter::TABLE)->where('content_id', $id)->update(['snapshot' => '"読めない中身"']);

        $result = app(ListContentRevisions::class)->execute('post', $id, $this->context());

        $this->assertTrue($result->isSuccess());
        // 隠さず印を付ける。落としも、無かったことにもしない
        $this->assertFalse($result->data['items'][0]['readable']);
    }

    public function test_管理画面の裏側に履歴と復元の入口がある(): void
    {
        $id = $this->create('route');
        $this->update($id, ['title' => '2 つ目']);

        $this->actingAs($this->owner)->getJson("/admin/post/{$id}/revisions")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // 1 回目は確認を返して止まる
        $this->actingAs($this->owner)
            ->post("/admin/post/{$id}/revisions/1/restore", ['lock_version' => 1])
            ->assertRedirect();

        $this->assertSame('2 つ目', DB::table('post')->where('id', $id)->value('title'));
    }

    private function restore(int $id, int $revision, int $lockVersion): void
    {
        $token = (string) app(RestoreContentRevision::class)
            ->execute('post', $id, $revision, $this->context(), $lockVersion)->confirmation?->token;

        $result = app(RestoreContentRevision::class)->execute(
            'post', $id, $revision, $this->context()->withConfirmationToken($token), $lockVersion,
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function create(string $title): int
    {
        $result = app(CreateContent::class)->execute('post', [
            'title' => $title, 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return (int) $result->data['item']['id'];
    }

    /** @param array<string, mixed> $attributes */
    private function update(int $id, array $attributes): void
    {
        $result = app(UpdateContent::class)->execute('post', $id, $attributes, $this->context(), partial: true);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }
}
