<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\DeleteContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use App\Models\User;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * Phase 9.1B / STEP 4・7: Content の Application Service。
 *
 * 「管理画面でできることは AI でも同じ検証・認可・監査を通って安全にできる」
 * という製品要件を固定する。News / Works を hardcode せず type 名で操作する。
 */
final class ContentOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'test-agent');
    }

    public function test_list_returns_items_for_a_registered_type(): void
    {
        $result = app(ListContent::class)->execute('news', $this->agent(), perPage: 5);

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertNotEmpty($result->data['items']);
        $this->assertSame(5, $result->meta['perPage']);
    }

    public function test_list_applies_schema_declared_search_columns(): void
    {
        app(CreateContent::class)->execute('news', [
            'title' => '検索用ユニーク見出し',
            'body' => '本文',
            'status' => 'draft',
        ], $this->agent());

        $result = app(ListContent::class)->execute('news', $this->agent(), search: 'ユニーク見出し');

        $this->assertSame(1, $result->meta['total']);
    }

    public function test_unknown_content_type_is_not_found_not_an_exception(): void
    {
        $result = app(ListContent::class)->execute('does-not-exist', $this->agent());

        $this->assertSame(OperationStatus::NotFound, $result->status);
    }

    public function test_create_derives_slug_from_schema_and_returns_the_item(): void
    {
        $result = app(CreateContent::class)->execute('news', [
            'title' => 'Agent Created Post',
            'body' => '本文',
            'status' => 'draft',
        ], $this->agent());

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertSame('agent-created-post', $result->data['item']['slug']);
    }

    public function test_create_rejects_invalid_input_with_field_errors(): void
    {
        $result = app(CreateContent::class)->execute('news', ['body' => '本文'], $this->agent());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('title', $result->errors);
    }

    public function test_partial_update_touches_only_the_given_field(): void
    {
        $item = News::query()->firstOrFail();
        $before = $item->body;

        $result = app(UpdateContent::class)->execute(
            'news',
            (int) $item->getKey(),
            ['title' => '差分更新されたタイトル'],
            $this->agent(),
            partial: true,
        );

        $this->assertSame(OperationStatus::Success, $result->status);
        $item->refresh();
        $this->assertSame('差分更新されたタイトル', $item->title);
        $this->assertSame($before, $item->body);
    }

    public function test_partial_update_rejects_a_field_that_is_not_in_the_schema(): void
    {
        $item = News::query()->firstOrFail();

        $result = app(UpdateContent::class)->execute(
            'news',
            (int) $item->getKey(),
            ['nonexistent_field' => 'x'],
            $this->agent(),
            partial: true,
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('nonexistent_field', $result->errors);
    }

    public function test_delete_requires_a_confirmation_before_it_removes_anything(): void
    {
        $item = News::query()->firstOrFail();
        $id = (int) $item->getKey();

        $result = app(DeleteContent::class)->execute('news', $id, $this->agent());

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertNotEmpty($result->confirmation->token);
        $this->assertNotEmpty($result->confirmation->impact);
        $this->assertDatabaseHas('news', ['id' => $id]);
    }

    /**
     * `content.delete` は **ゴミ箱へ移す**（Phase 9.8 / STAGE 21C・OG2 = (c)）。
     *
     * 9.8 より前は物理削除だった。呼び出しの形は変えていないので今までどおり
     * 呼べるが、**結果は変わる** — 行は残り、通常の読み取りから消える。
     * 「消えた」ではなく「戻せる場所へ移った」を確かめる。
     */
    public function test_delete_moves_the_entry_to_the_trash(): void
    {
        $id = (int) News::query()->firstOrFail()->getKey();
        $token = app(DeleteContent::class)->execute('news', $id, $this->agent())->confirmation->token;

        $result = app(DeleteContent::class)->execute('news', $id, $this->agent()->withConfirmationToken($token));

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertSame('trashed', $result->data['state']);

        // 行は消えていない（戻せる）
        $this->assertDatabaseHas('news', ['id' => $id]);
        $this->assertNotNull(DB::table('news')->where('id', $id)->value('deleted_at'));

        // それでも通常の読み取りからは消えている
        $this->assertNull(News::query()->find($id));
    }

    public function test_a_confirmation_token_cannot_be_reused_for_another_item(): void
    {
        $ids = News::query()->orderBy('id')->limit(2)->pluck('id')->all();
        $token = app(DeleteContent::class)->execute('news', (int) $ids[0], $this->agent())->confirmation->token;

        // 別対象へ使い回した token は受け付けず、再度確認を要求する
        $result = app(DeleteContent::class)->execute('news', (int) $ids[1], $this->agent()->withConfirmationToken($token));

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertDatabaseHas('news', ['id' => $ids[1]]);
    }

    public function test_a_confirmation_token_is_single_use(): void
    {
        $ids = News::query()->orderBy('id')->limit(2)->pluck('id')->all();
        $token = app(DeleteContent::class)->execute('news', (int) $ids[0], $this->agent())->confirmation->token;

        app(DeleteContent::class)->execute('news', (int) $ids[0], $this->agent()->withConfirmationToken($token));

        // 同じ token で作り直したレコードを消せない（1 回で使い切る）
        $recreated = app(CreateContent::class)->execute('news', [
            'title' => '再作成',
            'body' => '本文',
            'status' => 'draft',
        ], $this->agent());
        $newId = (int) $recreated->data['item']['id'];

        $result = app(DeleteContent::class)->execute('news', $newId, $this->agent()->withConfirmationToken($token));

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);
        $this->assertDatabaseHas('news', ['id' => $newId]);
    }

    public function test_mutations_are_audited_with_the_calling_interface(): void
    {
        app(CreateContent::class)->execute('news', [
            'title' => '監査対象',
            'body' => '本文',
            'status' => 'draft',
        ], $this->agent());

        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'mcp',
            'operation' => 'content.create',
            'classification' => 'write',
            'result' => 'success',
            'resource_type' => 'news',
        ]);
    }

    public function test_reads_are_not_audited(): void
    {
        app(ListContent::class)->execute('news', $this->agent());

        $this->assertDatabaseMissing('audit_logs', ['operation' => 'content.list']);
    }

    public function test_unauthenticated_admin_context_is_forbidden(): void
    {
        $result = app(GetContent::class)->execute(
            'news',
            (int) News::query()->firstOrFail()->getKey(),
            OperationContext::admin(null),
        );

        $this->assertSame(OperationStatus::Forbidden, $result->status);
    }

    public function test_admin_delete_goes_through_the_same_confirmation_gate(): void
    {
        $user = User::factory()->create();
        $id = (int) News::query()->firstOrFail()->getKey();

        // 1 回目: token なし → 削除せず確認内容を返す
        $this->actingAs($user)->delete("/admin/news/{$id}")->assertRedirect();
        $this->assertDatabaseHas('news', ['id' => $id]);

        $token = app(DeleteContent::class)
            ->execute('news', $id, OperationContext::admin((int) $user->getKey(), $user->name))
            ->confirmation->token;

        // 2 回目: token あり → ゴミ箱へ移る（9.8 / STAGE 21C。消えるのではない）
        $this->actingAs($user)
            ->delete("/admin/news/{$id}", ['confirmation_token' => $token])
            ->assertRedirect('/admin/news');

        $this->assertDatabaseHas('news', ['id' => $id]);
        $this->assertNotNull(DB::table('news')->where('id', $id)->value('deleted_at'));
        $this->assertNull(News::query()->find($id));

        // **呼ばれた名前で残る。** `content.trash` として記録しない
        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'admin',
            'operation' => 'content.delete',
            'classification' => 'destructive',
            'resource_type' => 'news',
        ]);
    }

    public function test_admin_store_and_update_are_handled_by_the_application_services(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/admin/news', ['title' => '管理画面から作成', 'body' => '本文', 'status' => 'draft'])
            ->assertRedirect('/admin/news');

        // 日本語タイトルは Str::slug で空になるため、既存挙動どおりランダム slug が入る
        $created = News::query()->where('title', '管理画面から作成')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $created->slug);

        $this->actingAs($user)
            ->put("/admin/news/{$created->id}", [
                'title' => '管理画面から更新',
                'slug' => $created->slug,
                'body' => '本文',
                'status' => 'published',
            ])
            ->assertRedirect('/admin/news');

        $this->assertSame('管理画面から更新', $created->refresh()->title);
        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'admin',
            'operation' => 'content.update',
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_admin_store_returns_validation_errors_to_the_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/news', ['body' => '本文'])
            ->assertSessionHasErrors('title');
    }
}
