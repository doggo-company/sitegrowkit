<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Phase 9.5-A / ADR-0015 — 管理画面の境界（必須テスト 8〜10）。
 *
 * この Stage の面は **一覧 1 本だけ**。
 * 作成・編集・archive の route が「無いこと」もここで固定する。
 */
final class FieldGroupAdminTest extends FieldGroupTestCase
{
    /** 必須テスト 8 */
    public function test_未認証はloginへ送られる(): void
    {
        $this->get('/admin/field-groups')->assertRedirect('/login');
    }

    public function test_未verifiedは拒否される(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/admin/field-groups')
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    /** 作りの画面なので、開けるのは制作者だけ。オーナーも 403（UX 一周 2026-09-28） */
    public function test_制作者は開けるがオーナーを含むほかのロールは403(): void
    {
        $expected = [
            Role::Developer->value => 200,
            Role::Owner->value => 403,
            Role::Administrator->value => 403,
            Role::Editor->value => 403,
            Role::Viewer->value => 403,
        ];

        foreach ($expected as $role => $status) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get('/admin/field-groups')
                ->assertStatus($status, "role={$role}");
        }
    }

    /** 必須テスト 9 — メニューの出し分けは capability に従う */
    public function test_sidebarはcapabilityで出し分けられる(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/field-groups')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.capabilities', fn (Collection $c): bool => $c->contains('content_type.manage')));

        $this->actingAs(User::factory()->create(['role' => Role::Editor->value]))
            ->get('/admin/news')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.capabilities', fn (Collection $c): bool => ! $c->contains('content_type.manage')));
    }

    /** 必須テスト 10 — 空状態と「未導入」を混同しない */
    public function test_1件も無いときは空状態として描画される(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/field-groups/Index')
                ->where('installed', true)
                ->where('groups', []));
    }

    public function test_テーブル未作成は空ではなく未導入として描画される(): void
    {
        Schema::dropIfExists('field_group_revisions');
        Schema::dropIfExists('field_group_locations');
        Schema::dropIfExists('field_groups');

        $this->actingAs($this->owner())
            ->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installed', false)
                ->where('groups', []));
    }

    /** 必須テスト 10 — 割当先・項目数・状態が読める形で出る */
    public function test_一覧に割当先と項目数と状態が出る(): void
    {
        $this->makeGroup(
            'spec',
            [
                ['key' => 'spec_weight', 'type' => 'text', 'label' => '重量'],
                ['key' => 'spec_size', 'type' => 'text', 'label' => 'サイズ'],
            ],
            [
                ['kind' => 'content_type', 'value' => 'news'],
                ['kind' => 'form', 'value' => 'contact'],
                ['kind' => 'page_template', 'value' => 'landing'],
            ],
            label: '商品スペック',
        );

        $this->actingAs($this->owner())
            ->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups', 1)
                ->where('groups.0.label', '商品スペック')
                ->where('groups.0.key', 'spec')
                ->where('groups.0.status', 'published')
                ->where('groups.0.fieldCount', 2)
                ->where('groups.0.valid', true)
                ->where('groups.0.active', true)
                // 投稿タイプは表示名まで解決する（保存はしていない）
                ->where('groups.0.locations.0.kindLabel', '投稿タイプ')
                ->where('groups.0.locations.0.label', 'ニュース')
                ->where('groups.0.locations.1.kindLabel', 'フォーム')
                // 扱えない割当先は黙って消さず、kindLabel を null にして出す
                ->where('groups.0.locations.2.kind', 'page_template')
                ->where('groups.0.locations.2.kindLabel', null));
    }

    public function test_壊れた定義は一覧に理由付きで出る(): void
    {
        $this->makeGroup('broken', self::BROKEN_FIELDS, [['kind' => 'content_type', 'value' => 'news']]);

        $this->actingAs($this->owner())
            ->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.valid', false)
                ->where('groups.0.active', false)
                ->where('groups.0.fieldCount', 0)
                ->where('groups.0.invalidReason', fn (?string $r): bool => $r !== null && $r !== ''));
    }

    /**
     * **物理削除の口は無い**ことが仕様である（Phase 9.5-B）。
     *
     * アーカイブと復元だけを置き、DELETE 動詞の route は 1 本も作らない。
     * 定義も入力済みの値も消さないという契約を、route の形で表している。
     */
    public function test_物理削除のrouteは存在しない(): void
    {
        $this->actingAs($this->owner());

        $id = $this->makeGroup('spec', [['key' => 'x', 'type' => 'text', 'label' => 'x']]);

        $this->delete("/admin/field-groups/{$id}")->assertNotFound();
        $this->delete('/admin/field-groups')->assertNotFound();
        $this->post("/admin/field-groups/{$id}/delete")->assertNotFound();
        $this->post("/admin/field-groups/{$id}/purge")->assertNotFound();

        // 束は 1 件も減っていない
        $this->assertCount(1, $this->repository()->all());
    }

    public function test_ulid以外のidはrouteに入らない(): void
    {
        $this->actingAs($this->owner());

        $this->get('/admin/field-groups/not-a-ulid')->assertNotFound();
        $this->patch('/admin/field-groups/1', ['key' => 'x'])->assertNotFound();
    }
}
