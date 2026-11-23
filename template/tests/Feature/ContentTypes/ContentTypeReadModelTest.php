<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\ContentTypes\ListContentTypeDefinitions;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB の登録とファイルの schema を **1 つの一覧**に合成する（Phase 9.7 / STAGE 20B）。
 *
 * ここで固定するのは 3 つ。
 *
 * 1. 設定行が無くても既存のファイル型は**これまでどおり出る**（非破壊）
 * 2. 並びは **`menu_order` → ID の辞書順**。`0` は未設定なので後ろ
 * 3. 定義が無いのに設定行だけある型（orphan）を**黙って捨てない**
 */
final class ContentTypeReadModelTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function insertRow(string $id, array $overrides = []): void
    {
        DB::table('content_types')->insert(array_merge([
            'content_type_id' => $id,
            'status' => 'active',
            'lifecycle' => 'ready',
            'menu_order' => 0,
            'comments_enabled' => false,
            'revisions_enabled' => true,
            'public_slug' => null,
            'registration' => null,
            'display_overrides' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function list(): array
    {
        $user = User::factory()->create();
        $result = app(ListContentTypeDefinitions::class)->execute(
            OperationContext::admin($user->id, $user->name),
        );

        $this->assertTrue($result->isSuccess(), '一覧は落とさない');

        return $result->data;
    }

    public function test_設定行が無くても既存のファイル型は出る(): void
    {
        $data = $this->list();
        $ids = array_column($data['types'], 'id');

        $this->assertContains('news', $ids);
        $this->assertContains('works', $ids);

        $news = $data['types'][array_search('news', $ids, true)];

        $this->assertSame('active', $news['settings']['status']);
        $this->assertSame('ready', $news['settings']['lifecycle']);
        $this->assertFalse($news['settings']['stored'], '行を作っていないのに stored になっている');
        $this->assertTrue($news['capabilities']['canWrite']);
    }

    public function test_並びはmenu_orderのあとに_i_dの辞書順(): void
    {
        // works を先頭へ。news は未設定（0）のまま
        $this->insertRow('works', ['menu_order' => 1]);

        $ids = array_column($this->list()['types'], 'id');

        $this->assertSame('works', $ids[0], 'menu_order を保存した型が先頭に来ない');
        $this->assertLessThan(
            array_search('product', $ids, true),
            array_search('news', $ids, true),
            'menu_order が同じなら ID の辞書順で安定する',
        );
    }

    public function test_menu_orderが0の型は既定順の後ろへ回る(): void
    {
        // ID の辞書順では news < product < works。works だけ順位を付ける
        $this->insertRow('works', ['menu_order' => 1]);

        $ids = array_values(array_filter(
            array_column($this->list()['types'], 'id'),
            static fn (string $id): bool => in_array($id, ['news', 'product', 'works'], true),
        ));

        $this->assertSame(['works', 'news', 'product'], $ids);
    }

    public function test_表示名は上書きできる(): void
    {
        $this->insertRow('news', ['display_overrides' => json_encode(['label' => 'お知らせ（改）'])]);

        $types = $this->list()['types'];
        $news = $types[array_search('news', array_column($types, 'id'), true)];

        $this->assertSame('お知らせ（改）', $news['label']);
    }

    public function test_定義が無いのに設定行だけある型を捨てない(): void
    {
        $this->insertRow('ghost_type', ['menu_order' => 5]);

        $types = $this->list()['types'];
        $index = array_search('ghost_type', array_column($types, 'id'), true);

        $this->assertNotFalse($index, 'orphan を黙って捨てている');
        $this->assertSame('orphan_settings', $types[$index]['status']);
        $this->assertFalse($types[$index]['capabilities']['canWrite'], 'orphan へは書かせない');
    }

    public function test_readinessを黙って隠さない(): void
    {
        $this->assertSame('ready', $this->list()['readiness']);

        Schema::drop('content_types');
        app()->forgetScopedInstances();

        $data = $this->list();

        $this->assertSame('unmigrated', $data['readiness'], '表が無いことを一覧が言えない');
        $this->assertNotNull($data['readinessMessage']);
        $this->assertNotSame([], $data['types'], '設定が読めなくても型の一覧は返す');
    }

    public function test_archivedな型はサイドバーに出ない(): void
    {
        $this->insertRow('news', ['status' => 'archived']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/works');
        $response->assertOk();

        $types = $response->viewData('page')['props']['nav']['contentTypes'] ?? [];
        $ids = array_column($types, 'id');

        $this->assertNotContains('news', $ids, 'archived な型が日常運用の導線に残っている');
        $this->assertContains('works', $ids, '他の型まで消えている');
    }

    public function test_サイドバーは設定が無ければこれまでどおり出る(): void
    {
        $user = User::factory()->create();

        $types = $this->actingAs($user)->get('/admin/works')
            ->viewData('page')['props']['nav']['contentTypes'] ?? [];

        $ids = array_column($types, 'id');

        $this->assertContains('news', $ids);
        $this->assertContains('works', $ids);
    }

    public function test_サイドバーは1リクエストで設定を1回しか読まない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/works')->assertOk();

        // 同じリクエストの中で resolver が何度呼ばれても、読みに行くのは 1 回である
        $resolver = app(ContentTypeSettingsResolver::class);
        $resolver->for('news');
        $resolver->for('works');

        $this->assertSame(1, $resolver->loadCount());
    }
}
