<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\RestoreContent;
use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 監査（Phase 9.8 / STAGE 21C・SPEC §G.2）。
 *
 * **結末ごとに 1 行**残す。成功だけを記録すると、拒否されたことも、
 * 競合したことも、確認で止まったことも、後から追えない。
 *
 * 残すのは「何を操作しようとしたか」だけである。本文も個人情報も、
 * 確認トークンも SQL も入れない — 監査ログは**漏れたときに一番痛い場所**である。
 */
final class TrashAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_呼ばれた名前のまま記録する(): void
    {
        $viaDelete = $this->entry('via-delete');
        $viaTrash = $this->entry('via-trash');

        $this->confirmAnd(DeleteContent::class, $viaDelete);
        $this->confirmAnd(TrashContent::class, $viaTrash);

        /*
         * **`content.delete` を `content.trash` として記録しない。**
         * 中身は同じ処理でも、誰がどちらの名前で呼んだかは後から意味を持つ
         * （移行がどこまで進んだかは、これでしか測れない）。
         */
        $this->assertSame(1, $this->auditCount('content.delete', 'success', $viaDelete));
        $this->assertSame(1, $this->auditCount('content.trash', 'success', $viaTrash));
        $this->assertSame(0, $this->auditCount('content.trash', 'success', $viaDelete));
        $this->assertSame(0, $this->auditCount('content.delete', 'success', $viaTrash));
    }

    public function test_結末ごとに残る(): void
    {
        $id = $this->entry('outcomes');

        // 確認待ち
        app(TrashContent::class)->execute('post', $id, $this->context());
        $this->assertSame(1, $this->auditCount('content.trash', 'confirmation_required', $id));

        /*
         * 権限なし。
         *
         * この段では**まだ対象を解決していない**ので、対象の ID は残さない
         * （既存のどの operation も同じ作法である）。確かめたいのは
         * 「拒否したことが記録に残るか」であって、何を狙ったかではない。
         */
        $viewer = User::factory()->create(['role' => 'viewer']);
        app(TrashContent::class)->execute(
            'post', $id, OperationContext::admin((int) $viewer->getKey(), (string) $viewer->name),
        );
        $this->assertSame(1, DB::table('audit_logs')
            ->where('operation', 'content.trash')->where('result', 'forbidden')->count());

        // 見つからない
        app(TrashContent::class)->execute('post', 999999, $this->context());
        $this->assertSame(1, $this->auditCount('content.trash', 'not_found', 999999));

        // 成功
        $this->confirmAnd(TrashContent::class, $id);
        $this->assertSame(1, $this->auditCount('content.trash', 'success', $id));

        // 競合（既にゴミ箱にある）
        $this->confirmAnd(TrashContent::class, $id);
        $this->assertSame(1, $this->auditCount('content.trash', 'conflict', $id));

        // 戻すのも結末ごとに残る
        app(RestoreContent::class)->execute('post', $id, $this->context());
        $this->assertSame(1, $this->auditCount('content.restore', 'success', $id));
    }

    public function test_監査に本文もトークンも個人情報も残さない(): void
    {
        $id = $this->entry('secretive');

        DB::table('post')->where('id', $id)->update([
            'body' => '<p>社外秘の本文。ここは監査に出てはいけない</p>',
        ]);

        $token = (string) app(TrashContent::class)
            ->execute('post', $id, $this->context())->confirmation?->token;

        app(TrashContent::class)->execute(
            'post', $id, $this->context()->withConfirmationToken($token),
        );

        $rows = DB::table('audit_logs')->where('operation', 'content.trash')->get();

        $this->assertNotSame(0, $rows->count());

        foreach ($rows as $row) {
            $metadata = (string) $row->metadata;

            $this->assertStringNotContainsString('社外秘', $metadata, '本文が監査に残っている');
            $this->assertStringNotContainsString($token, $metadata, '確認トークンが監査に残っている');
            $this->assertStringNotContainsString((string) $this->owner->email, $metadata);
            $this->assertStringNotContainsString('select ', strtolower($metadata));
            $this->assertStringNotContainsString('insert ', strtolower($metadata));
        }
    }

    public function test_監査に残すのは最小限の項目だけ(): void
    {
        $id = $this->entry('minimal');

        $this->confirmAnd(TrashContent::class, $id);

        $metadata = json_decode((string) DB::table('audit_logs')
            ->where('operation', 'content.trash')
            ->where('result', 'success')
            ->value('metadata'), true);

        $this->assertIsArray($metadata);

        /*
         * **これで全部である。** 版を指定しなかったときの印だけが増える。
         * ここに本文や利用者の情報が紛れ込んでいないことが、この検査の目的である。
         */
        // **並びは見ない**（MySQL の JSON 列はキー順を正規化する）。見たいのは集合である
        $this->assertEqualsCanonicalizing(
            ['title', 'state', 'lock_version', 'lock_version_note'],
            array_keys($metadata),
        );
        $this->assertSame('trashed', $metadata['state']);
        $this->assertSame(1, $metadata['lock_version']);
    }

    public function test_版を指定しないゴミ箱移動に印を残す(): void
    {
        $id = $this->entry('no-version');

        $this->confirmAnd(TrashContent::class, $id);

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.trash')
            ->where('result', 'success')
            ->value('metadata');

        $this->assertStringContainsString('版の指定なし', $metadata);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    /** @param class-string $operation */
    private function confirmAnd(string $operation, int $id): void
    {
        $token = (string) app($operation)->execute('post', $id, $this->context())->confirmation?->token;

        app($operation)->execute('post', $id, $this->context()->withConfirmationToken($token));
    }

    private function auditCount(string $operation, string $status, int $id): int
    {
        return DB::table('audit_logs')
            ->where('operation', $operation)
            ->where('result', $status)
            ->where('resource_id', (string) $id)
            ->count();
    }

    private function entry(string $slug): int
    {
        return DB::table('post')->insertGetId([
            'title' => $slug, 'slug' => $slug, 'body' => '<p>本文</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
