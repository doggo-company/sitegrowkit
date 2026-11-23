<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use AgentCms\Core\Application\Content\AutosaveContent;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 記事の自動保存（Phase 9.15 / STAGE 28B・SPEC §C）。
 *
 * **自動保存は、記事そのものを書き換えない。**
 *
 * ページは draft と published が別の版なので本体を書いてよいが、
 * **記事は本体が 1 行しかない**。書き換えると
 * **公開中の内容が編集途中の状態で外に出る**。だから履歴に積むだけにする。
 *
 * ここで固定するのは 3 つ:
 *
 * 1. 版が `kind = autosave` で 1 つ増える
 * 2. **記事本体が 1 バイトも変わらない**（`updated_at` も含めて）
 * 3. 公開面に編集途中の内容が出ない
 */
final class EntryAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_自動保存はautosaveの版を1つ増やす(): void
    {
        $id = $this->publishedNews('もとの見出し');

        $result = app(AutosaveContent::class)->execute(
            'news', $id, ['title' => '書きかけの見出し'], $this->context(),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $rows = DB::table('content_entry_revisions')
            ->where('content_type', 'news')->where('content_id', $id)->get();

        $this->assertCount(1, $rows->where('kind', 'autosave'), 'autosave の版が 1 つではない');
    }

    /**
     * **公開中の内容が編集途中の状態で外に出ない。**
     *
     * これが「別の仕組み」の核心である。ここが崩れると、
     * 自動保存が入った瞬間に**書きかけが公開される**。
     */
    public function test_自動保存は記事そのものを書き換えない(): void
    {
        $id = $this->publishedNews('公開ずみの見出し');
        $before = (array) DB::table('news')->where('id', $id)->first();

        // **成功したことを先に確かめる。** 何も起きなければ、この試験は
        // 「書き換えない」を確かめずに緑になる
        $this->assertTrue(app(AutosaveContent::class)->execute(
            'news', $id, ['title' => '書きかけの見出し', 'body' => '<p>書きかけ</p>'], $this->context(),
        )->isSuccess());

        $after = (array) DB::table('news')->where('id', $id)->first();

        $this->assertSame($before, $after, '記事本体が書き換わった');
    }

    public function test_書きかけの内容は公開面に出ない(): void
    {
        $slug = 'live-'.uniqid();
        $id = $this->publishedNews('公開ずみの見出し', $slug);

        $this->assertTrue(app(AutosaveContent::class)->execute(
            'news', $id, ['title' => '書きかけの見出し'], $this->context(),
        )->isSuccess());

        $this->get("/news/{$slug}")
            ->assertOk()
            ->assertSee('公開ずみの見出し', false)
            ->assertDontSee('書きかけの見出し', false);
    }

    /**
     * **`reason` に `autosave` を足さない。**
     *
     * `kind` が「どういう保存か」を、`reason` が「なぜ書いたか」を持つ。
     * 1 つの enum に直交する 2 つの軸を入れると、
     * 「api から来た自動保存」のような組み合わせが表せなくなる。
     */
    public function test_kindとreasonは別の軸のまま(): void
    {
        $id = $this->publishedNews('見出し');

        $this->assertTrue(
            app(AutosaveContent::class)->execute('news', $id, ['title' => '書きかけ'], $this->context())->isSuccess(),
        );

        $row = DB::table('content_entry_revisions')
            ->where('content_type', 'news')->where('content_id', $id)
            ->where('kind', 'autosave')->first();

        $this->assertNotNull($row);
        $this->assertSame('update', $row->reason, 'reason が kind の軸を持ってしまっている');
    }

    /**
     * **30 秒ごとに画面を動かさない。**
     *
     * redirect を返すと、編集している人の操作を奪う。JSON で静かに返す。
     */
    public function test_自動保存の応答は画面を動かさない(): void
    {
        $id = $this->publishedNews('見出し');

        $this->actingAs($this->owner)
            ->postJson("/admin/news/{$id}/autosave", ['title' => '書きかけ'])
            ->assertOk()
            ->assertJsonPath('data.saved', true);
    }

    /**
     * **同じ内容なら版を増やさない。**
     *
     * 30 秒ごとに送るので、ここが無いと**触っていない内容の版が積み続ける**。
     */
    public function test_内容が変わっていなければ版を増やさない(): void
    {
        $id = $this->publishedNews('見出し');

        $this->actingAs($this->owner)
            ->postJson("/admin/news/{$id}/autosave", ['title' => '書きかけ'])
            ->assertJsonPath('data.saved', true);

        $this->actingAs($this->owner)
            ->postJson("/admin/news/{$id}/autosave", ['title' => '書きかけ'])
            ->assertJsonPath('data.saved', false);

        $this->assertSame(1, DB::table('content_entry_revisions')
            ->where('content_type', 'news')->where('content_id', $id)
            ->where('kind', 'autosave')->count());
    }

    /**
     * **成功は監査に残さない。拒否は残す。**
     *
     * 30 秒ごとに 1 行積めば、**監査が自動保存で埋まって人の操作が読めなくなる**。
     * 自動保存は人の操作ではないので残さない。
     * 一方、**「誰が入れなかったか」は人の操作の記録**なので残す。
     */
    public function test_成功は監査に残さず拒否は残す(): void
    {
        $id = $this->publishedNews('見出し');

        $this->assertTrue(
            app(AutosaveContent::class)->execute('news', $id, ['title' => '書きかけ'], $this->context())->isSuccess(),
        );

        $this->assertSame(0, DB::table('audit_logs')->where('operation', 'content.autosave')->count(),
            '成功が監査に積まれている（30 秒ごとに 1 行増える）');

        $this->app->instance(OperationPolicy::class, new class implements OperationPolicy
        {
            public function allows(string $operationId, OperationClassification $classification, OperationContext $context): bool
            {
                return false;
            }

            public function denialReason(string $operationId, OperationContext $context): string
            {
                return '試験のため断りました';
            }
        });

        app(AutosaveContent::class)->execute('news', $id, ['title' => 'もう一度'], $this->context());

        $this->assertSame(1, DB::table('audit_logs')->where('operation', 'content.autosave')->count(),
            '拒否が残っていない');
    }

    /**
     * **手で保存したあとの自動保存だけを知らせる**（SPEC §C.3）。
     *
     * 手で保存したあとに何も書いていなければ、知らせることは何も無い。
     * ここを間違えると、**開くたびに「復元しますか」が出る**。
     */
    public function test_手で保存したあとの自動保存だけを知らせる(): void
    {
        $id = $this->publishedNews('見出し');

        // まだ自動保存が無い
        $this->actingAs($this->owner)->get("/admin/news/{$id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('pendingAutosave', null));

        $this->assertTrue(app(AutosaveContent::class)
            ->execute('news', $id, ['title' => '書きかけ'], $this->context())->isSuccess());

        $this->actingAs($this->owner)->get("/admin/news/{$id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('pendingAutosave.revision', 1));

        // **手で保存したら、それより古い自動保存は知らせない**
        DB::table('content_entry_revisions')->insert([
            'content_type' => 'news', 'content_id' => $id, 'revision' => 2, 'kind' => 'manual',
            'snapshot' => '{}', 'schema_fingerprint' => (string) DB::table('content_entry_revisions')
                ->where('content_id', $id)->value('schema_fingerprint'),
            'reason' => 'update', 'created_interface' => 'admin', 'created_by' => null, 'created_at' => now(),
        ]);

        $this->actingAs($this->owner)->get("/admin/news/{$id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('pendingAutosave', null));
    }

    /**
     * **自動保存でない版を、自動保存として知らせない。**
     *
     * いま `RevisionKind` は manual と autosave の 2 つしかないので、
     * 「最後の手動保存より新しい」だけでも結果は同じになる。
     * **だから `kind` の絞り込みは、いまの 2 つでは検査で殺せない。**
     *
     * しかし `kind` は varchar で CHECK も無く、3 つ目が増えたときに
     * **それが「自動保存があります」として出てしまう**。
     * 3 つ目を直接書いて、そのときも黙っていることを固定する。
     */
    public function test_自動保存でない版を自動保存として知らせない(): void
    {
        $id = $this->publishedNews('見出し');

        $this->assertTrue(app(AutosaveContent::class)
            ->execute('news', $id, ['title' => '書きかけ'], $this->context())->isSuccess());

        $fingerprint = (string) DB::table('content_entry_revisions')
            ->where('content_id', $id)->value('schema_fingerprint');

        // 手動保存でも自動保存でもない版（将来の 3 つ目）
        DB::table('content_entry_revisions')->insert([
            'content_type' => 'news', 'content_id' => $id, 'revision' => 9, 'kind' => 'import',
            'snapshot' => '{}', 'schema_fingerprint' => $fingerprint,
            'reason' => 'update', 'created_interface' => 'admin', 'created_by' => null, 'created_at' => now(),
        ]);

        $this->actingAs($this->owner)->get("/admin/news/{$id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('pendingAutosave.revision', 1));
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->id, (string) $this->owner->name);
    }

    private function publishedNews(string $title, ?string $slug = null): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => $slug ?? 'n-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
