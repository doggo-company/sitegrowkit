<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\Schema\SchemaFieldOverlay;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Reference\ReferenceInspector;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.5-A CORRECTION — overlay の寿命（ADR-0015 / memoization）。
 *
 * ## 直前の誤り
 *
 * `SchemaRegistry` / overlay / repository を **singleton** にしたうえで
 * 「container は 1 リクエスト 1 つなので process を跨いだ保持は無い」と書いていた。
 * これは PHP-FPM でしか成り立たない。Octane や長寿命 worker では
 * singleton が process に残り、**次のリクエストでも古い Field Group を返す**。
 *
 * ## 正しい寿命
 *
 * runtime の DB overlay に関わる 3 つ（と、それを捕まえる `ReferenceInspector`）は
 * **scoped** にする。scoped は
 *
 * - 1 リクエスト内では singleton と同じ（同じ instance・DB は 1 度だけ）
 * - リクエスト / job の境界で捨てられる（Octane が `forgetScopedInstances()` を呼ぶ）
 *
 * ファイルだけを読む registry（`ComponentRegistry` 等）は singleton のままでよい。
 * こちらは runtime に変わらない。
 */
final class FieldGroupLifetimeTest extends FieldGroupTestCase
{
    /** @return list<class-string> */
    private function scopedBindings(): array
    {
        return [
            SchemaRegistry::class,
            SchemaFieldOverlay::class,
            FieldGroupRepository::class,
            // effective registry を provider の中に捕まえるので、同じ境界で捨てる
            ReferenceInspector::class,
        ];
    }

    public function test_1リクエスト内では同じinstanceを返す(): void
    {
        foreach ($this->scopedBindings() as $abstract) {
            $this->assertSame(
                app($abstract),
                app($abstract),
                "{$abstract} が 1 リクエスト内で作り直されている",
            );
        }
    }

    public function test_リクエスト境界で捨てられる(): void
    {
        $before = [];

        foreach ($this->scopedBindings() as $abstract) {
            $before[$abstract] = app($abstract);
        }

        // Octane / queue worker がリクエスト・job の切れ目で呼ぶもの
        app()->forgetScopedInstances();

        foreach ($this->scopedBindings() as $abstract) {
            $this->assertNotSame(
                $before[$abstract],
                app($abstract),
                "{$abstract} がリクエスト境界を越えて残っている",
            );
        }
    }

    public function test_1リクエスト内ではdbを1度しか読まない(): void
    {
        $this->makeGroup(
            'memo',
            [['key' => 'x_first', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        DB::enableQueryLog();

        $first = app(SchemaRegistry::class)->get('news')->fieldKeys();
        $afterFirst = count(DB::getQueryLog());

        // 別の解決経路（container 越し）でも同じ instance なので引き直さない
        app(SchemaRegistry::class)->get('news');
        app(SchemaRegistry::class)->all();

        $this->assertSame($afterFirst, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->assertContains('x_first', $first);
    }

    public function test_次のリクエストでは新しいfield_groupが反映される(): void
    {
        $this->makeGroup(
            'memo',
            [['key' => 'x_first', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $this->assertContains('x_first', app(SchemaRegistry::class)->get('news')->fieldKeys());

        $this->makeGroup(
            'memo2',
            [['key' => 'x_second', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
            sortOrder: 1,
        );

        // 同じリクエストの中では見えない（1 リクエスト 1 回の読み取り）
        $this->assertNotContains('x_second', app(SchemaRegistry::class)->get('news')->fieldKeys());

        app()->forgetScopedInstances();

        // 次のリクエストでは見える
        $keys = app(SchemaRegistry::class)->get('news')->fieldKeys();
        $this->assertContains('x_first', $keys);
        $this->assertContains('x_second', $keys);
    }

    /**
     * HTTP を 2 回叩いても新しい定義が反映される。
     *
     * テストの container はリクエストを跨いで生き続けるので、
     * 途中で明示的に境界を作る（本番の Octane が同じことをする）。
     */
    public function test_http越しでも次のリクエストで反映される(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('groups', []));

        $this->makeGroup(
            'later',
            [['key' => 'x_later', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        app()->forgetScopedInstances();

        $this->actingAs($owner)->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('groups', 1));
    }

    /**
     * ファイルだけを読む registry は singleton のままでよい
     * （runtime に変わらないものまで作り直さない）。
     */
    public function test_ファイル読み取りのregistryはsingletonのまま(): void
    {
        $before = app(ComponentRegistry::class);

        app()->forgetScopedInstances();

        $this->assertSame($before, app(ComponentRegistry::class));
    }

    /** route / model discovery はファイル宣言だけを読む契約のまま */
    public function test_型の集合はscoped化で変わらない(): void
    {
        $this->makeGroup(
            'no_new_types',
            [['key' => 'x_type', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        app()->forgetScopedInstances();

        $registry = app(SchemaRegistry::class);

        $this->assertSame(
            array_keys($registry->declared()),
            array_keys($registry->all()),
        );
    }
}
