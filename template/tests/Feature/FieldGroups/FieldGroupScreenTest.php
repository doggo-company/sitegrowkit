<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Phase 9.5-B — 書き込み画面の境界（ADR-0016）。
 *
 * Application Service の契約は `FieldGroupWriteTest` が固定する。
 * ここで見るのは **HTTP から見た振る舞い**（誰が入れるか / 何が返るか /
 * 確認をどう挟むか）だけ。
 */
final class FieldGroupScreenTest extends FieldGroupScreenTestCase
{
    public function test_ownerは作成画面を開ける(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/field-groups/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/field-groups/Create')
                // 選択肢はサーバーが authoritative
                ->has('options.contentTypes')
                ->has('options.fieldTypes')
                ->has('options.formForbiddenTypes'));
    }

    public function test_書き込みのrouteは権限の無いロールを拒否する(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);
        $id = $this->makeGroup('spec', [['key' => 'x', 'type' => 'text', 'label' => 'x']]);

        $this->actingAs($editor)->get('/admin/field-groups/create')->assertForbidden();
        $this->actingAs($editor)->post('/admin/field-groups', $this->payload())->assertForbidden();
        $this->actingAs($editor)->get("/admin/field-groups/{$id}")->assertForbidden();
        $this->actingAs($editor)->patch("/admin/field-groups/{$id}", $this->payload())->assertForbidden();
        $this->actingAs($editor)->post("/admin/field-groups/{$id}/archive")->assertForbidden();
        $this->actingAs($editor)->post("/admin/field-groups/{$id}/restore")->assertForbidden();
        $this->actingAs($editor)->post('/admin/field-groups/reorder', ['orderedIds' => []])->assertForbidden();

        // 拒否されたリクエストで DB は変わっていない
        $this->assertSame(1, DB::table('field_groups')->count());
        $this->assertSame('published', DB::table('field_groups')->value('status'));
    }

    public function test_未認証は全routeでloginへ送られる(): void
    {
        $id = $this->makeGroup('spec', [['key' => 'x', 'type' => 'text', 'label' => 'x']]);

        $this->get('/admin/field-groups/create')->assertRedirect('/login');
        $this->post('/admin/field-groups', $this->payload())->assertRedirect('/login');
        $this->patch("/admin/field-groups/{$id}", $this->payload())->assertRedirect('/login');
        $this->post("/admin/field-groups/{$id}/archive")->assertRedirect('/login');
    }

    public function test_作成して一覧へ戻る(): void
    {
        $this->actingAs($this->owner())
            ->post('/admin/field-groups', $this->payload())
            ->assertRedirect('/admin/field-groups')
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('field_groups')->count());
    }

    public function test_入力エラーは項目ごとに返る(): void
    {
        $this->actingAs($this->owner())
            ->post('/admin/field-groups', $this->payload(['key' => 'Bad Key']))
            ->assertSessionHasErrors('key');

        $this->assertSame(0, DB::table('field_groups')->count());
    }

    public function test_編集画面は項目の中身とidを返す(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());

        $id = (string) DB::table('field_groups')->value('id');

        $this->actingAs($this->owner())
            ->get("/admin/field-groups/{$id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/field-groups/Edit')
                ->where('group.key', 'product_spec')
                ->has('group.fields', 1)
                ->where('group.fields.0.key', 'spec_weight')
                // 画面が返してくる ID がそのまま同一性になる
                ->where('group.fields.0.id', fn (?string $v): bool => is_string($v) && strlen($v) === 26));
    }

    public function test_危険な変更は確認を挟んでから適用される(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());

        $id = (string) DB::table('field_groups')->value('id');
        $payload = $this->payload(['fields' => [['key' => 'spec_size', 'type' => 'text', 'label' => 'サイズ']]]);

        // 1 回目 — 確認が flash で返り、まだ何も変わらない
        $this->actingAs($this->owner())
            ->patch("/admin/field-groups/{$id}", $payload)
            ->assertRedirect();

        $confirmation = $this->flashedConfirmation();

        $this->assertIsArray($confirmation);
        $this->assertArrayHasKey('token', $confirmation);

        $fields = json_decode((string) DB::table('field_groups')->value('fields'), true);
        $this->assertSame('spec_weight', $fields[0]['key']);

        // 2 回目 — token を添えると適用される
        $this->actingAs($this->owner())
            ->patch("/admin/field-groups/{$id}", [
                ...$payload,
                'confirmation_token' => $confirmation['token'],
            ])
            ->assertRedirect('/admin/field-groups');

        $fields = json_decode((string) DB::table('field_groups')->value('fields'), true);
        $this->assertSame('spec_size', $fields[0]['key']);
    }

    public function test_アーカイブと復元が画面から行える(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());
        $id = (string) DB::table('field_groups')->value('id');

        $this->actingAs($this->owner())->post("/admin/field-groups/{$id}/archive")->assertRedirect();

        $confirmation = $this->flashedConfirmation();
        $this->assertIsArray($confirmation);
        $this->assertSame('published', DB::table('field_groups')->value('status'));

        $this->actingAs($this->owner())
            ->post("/admin/field-groups/{$id}/archive", ['confirmation_token' => $confirmation['token']])
            ->assertRedirect('/admin/field-groups');

        $this->assertSame('archived', DB::table('field_groups')->value('status'));

        // 復元は確認なし（元に戻すだけ）
        $this->actingAs($this->owner())
            ->post("/admin/field-groups/{$id}/restore")
            ->assertRedirect('/admin/field-groups');

        $this->assertSame('published', DB::table('field_groups')->value('status'));
    }

    public function test_並び替えは全件を送ると保存される(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload([
            'key' => 'second', 'fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']],
        ]));

        $ids = DB::table('field_groups')->orderBy('sort_order')->pluck('id')->all();

        $this->actingAs($this->owner())
            ->post('/admin/field-groups/reorder', ['orderedIds' => array_reverse($ids)])
            ->assertRedirect();

        $this->assertSame(
            array_reverse($ids),
            DB::table('field_groups')->orderBy('sort_order')->pluck('id')->all(),
        );
    }

    public function test_一部だけの並び替えは拒否される(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());
        $id = (string) DB::table('field_groups')->value('id');

        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload([
            'key' => 'second', 'fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']],
        ]));

        $this->actingAs($this->owner())
            ->post('/admin/field-groups/reorder', ['orderedIds' => [$id]])
            ->assertSessionHasErrors('orderedIds');
    }

    /**
     * route からも非 string を黙って捨てない（CORRECTION）。
     *
     * Application service だけを直しても、HTTP の入り口が別経路で
     * 落としていたら意味が無い。両方で固定する。
     */
    public function test_並び替えのrouteは非stringを黙って捨てない(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload([
            'key' => 'second', 'fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']],
        ]));

        $ids = DB::table('field_groups')->orderBy('sort_order')->pluck('id')->all();
        $before = DB::table('field_groups')->orderBy('id')->pluck('sort_order', 'id')->all();

        $this->actingAs($this->owner())
            ->post('/admin/field-groups/reorder', [
                'orderedIds' => [...array_reverse($ids), null],
            ])
            ->assertSessionHasErrors('orderedIds');

        $this->actingAs($this->owner())
            ->post('/admin/field-groups/reorder', [
                'orderedIds' => [...array_reverse($ids), ['nested']],
            ])
            ->assertSessionHasErrors('orderedIds');

        // 並び順は 1 件も動いていない
        $this->assertSame($before, DB::table('field_groups')->orderBy('id')->pluck('sort_order', 'id')->all());
    }

    /** 追加した項目が、その投稿タイプの編集画面に出る */
    public function test_割当先の編集画面に項目が出る(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', $this->payload());

        $this->actingAs($this->owner())
            ->get('/admin/news/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schema.fields', fn ($fields): bool => collect($fields)
                    ->pluck('key')
                    ->contains('spec_weight')));
    }
}
