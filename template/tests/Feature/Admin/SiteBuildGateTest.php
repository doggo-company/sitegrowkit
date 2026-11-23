<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * 作りの画面は制作者だけが開ける（UX 一周 2026-09-28・メンテナの方針）。
 *
 * メニューから外すだけでは、URL を直接開けば店主（オーナー）が入れてしまう。
 * だから route に `site.build` の関所を付けた。ここはその関所の番人である。
 *
 * - 関所の付いた route の集合を固定する（黙って増えも減りもしない）
 * - 店主が日々使う口（記事を書けるようにする 1 クリック・フォームの入力項目の保存）には付けない
 * - オーナーで開くと 403、制作者で開くと通る
 *
 * 一括置換（`/admin/content-replace`）も作りの画面だが、関所ではなく
 * `content.replace` を制作者だけに持たせて閉じている（拒否を監査に残すため）。
 * 403 になることはここでも確かめる。
 */
final class SiteBuildGateTest extends TestCase
{
    use RefreshDatabase;

    /** 関所の付いた route（名前）。増やす・減らすときは、ここを直して意図を残す */
    private const GATED = [
        'admin.content-types.index',
        'admin.content-types.create',
        'admin.content-types.store',
        'admin.content-types.reorder',
        'admin.content-types.edit',
        'admin.content-types.update',
        'admin.content-types.archive',
        'admin.content-types.restore',
        'admin.content-types.comments',
        'admin.field-groups.index',
        'admin.field-groups.create',
        'admin.field-groups.store',
        'admin.field-groups.reorder',
        'admin.field-groups.edit',
        'admin.field-groups.archive',
        'admin.field-groups.restore',
        'admin.reusable-components.index',
        'admin.reusable-components.editor',
        'admin.reusable-components.preview',
        'admin.redirects.index',
        'admin.redirects.simulate',
        'admin.redirects.not-found',
        'admin.redirects.store',
        'admin.redirects.update',
        'admin.redirects.delete',
        'admin.redirects.resolve',
    ];

    /** 店主の日常の口。**閉じてはいけない** */
    private const OWNER_DAILY = [
        // ダッシュボードの「記事を書けるようにする」1 クリック
        'admin.content-types.quick-start',
        // フォームの編集画面が入力項目を保存する先
        'admin.field-groups.update',
        // 分類（カテゴリ）は店主も日々使う
        'admin.taxonomies.index',
        // 一括置換は capability（`content.replace`）で閉じる。関所で弾くと拒否が監査に残らない
        'admin.content-replace.index',
        'admin.content-replace.preview',
        'admin.content-replace.apply',
    ];

    public function test_関所の付いたrouteの集合が決めたとおりである(): void
    {
        $gated = [];

        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            if (in_array('site.build', $route->gatherMiddleware(), true)) {
                $gated[] = (string) $route->getName();
            }
        }

        sort($gated);
        $expected = self::GATED;
        sort($expected);

        $this->assertSame($expected, $gated);
    }

    public function test_店主の日常の口には関所が付いていない(): void
    {
        foreach (self::OWNER_DAILY as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} が無い");
            $this->assertNotContains('site.build', $route->gatherMiddleware(), "{$name} に関所が付いている");
        }
    }

    public function test_オーナーは作りの画面を開くと403になる(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        foreach ($this->screens() as $href) {
            $this->actingAs($owner)->get($href)->assertForbidden();
        }

        // 書き込みの口も同じ（画面を隠すだけでなく、送っても通らない）
        $this->actingAs($owner)->post('/admin/content-types', [])->assertForbidden();
        $this->actingAs($owner)->post('/admin/redirects', [])->assertForbidden();
        $this->actingAs($owner)->postJson('/admin/content-replace/preview', [])->assertForbidden();
        $this->actingAs($owner)->postJson('/admin/content-replace/apply', [])->assertForbidden();
    }

    public function test_制作者は作りの画面を開ける(): void
    {
        $developer = User::factory()->create(['role' => Role::Developer->value]);

        foreach ($this->screens() as $href) {
            $this->actingAs($developer)->get($href)->assertOk();
        }
    }

    public function test_オーナーは分類とダッシュボードをいまも開ける(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        $this->actingAs($owner)->get('/admin/taxonomies')->assertOk();
        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    /** @return list<string> 引数の要らない作りの画面 */
    private function screens(): array
    {
        return [
            '/admin/content-types',
            '/admin/content-types/create',
            '/admin/field-groups',
            '/admin/field-groups/create',
            '/admin/reusable-components',
            '/admin/content-replace',
            '/admin/redirects',
            '/admin/not-found',
        ];
    }
}
