<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Schema\FieldStorage;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.5-B — Field Group で足した項目に **値を入れられる**こと（owner 決定 G2-(a)）。
 *
 * ## 9.5-A に空いていた穴
 *
 * `FieldStorage::forType()` は型だけで保存先を決めるので、overlay で足した `text` は
 * 「カラムに入れる」と判定されていた。しかし `news` にはそのカラムも `data` も無く、
 * **published な Field Group を割り当てた瞬間に投稿の作成が conflict で失敗**していた。
 *
 * 9.5-A は書き込み経路が無いので製品から到達できなかったが、DB に 1 行入れば壊れる。
 * ここで固定するのは「overlay の項目は型に関わらず `data` JSON へ入り、
 * 投稿の作成・読み取り・更新が通る」こと。
 */
final class FieldGroupStorageTest extends FieldGroupTestCase
{
    private function makeSpecGroup(string $type = 'text'): void
    {
        $this->makeGroup(
            'spec',
            [['key' => 'spec_weight', 'type' => $type, 'label' => '重量']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        app()->forgetScopedInstances();
    }

    public function test_受け皿のdataカラムがある(): void
    {
        foreach (['news', 'works'] as $table) {
            $this->assertContains('data', Schema::getColumnListing($table), "{$table} に data が無い");
        }
    }

    public function test_overlayの項目は型に関わらずjson保存になる(): void
    {
        // column-backed の代表（text）でも json 側へ回る
        $this->makeSpecGroup('text');

        $field = app(SchemaRegistry::class)->get('news')->field('spec_weight');

        $this->assertSame(FieldStorage::Json, $field->storage());
        $this->assertFalse($field->isColumnBacked());
        $this->assertTrue(app(SchemaRegistry::class)->get('news')->needsDataColumn());
    }

    public function test_ファイル由来の項目の保存先は変わらない(): void
    {
        $this->makeSpecGroup();

        $schema = app(SchemaRegistry::class)->get('news');

        // 既存フィールドは 1 つも保存先が変わっていない
        foreach (['title', 'slug', 'body', 'status'] as $key) {
            $this->assertTrue($schema->field($key)->isColumnBacked(), "{$key} の保存先が変わっている");
            $this->assertNull($schema->field($key)->declaredStorage, "{$key} に storage が付いている");
        }
    }

    public function test_overlayの項目に値を入れて読み書きできる(): void
    {
        $this->makeSpecGroup();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '新製品', 'slug' => 'spec-'.uniqid(), 'body' => '本文',
            'status' => 'draft', 'spec_weight' => '1.2kg',
        ], OperationContext::cli());

        $this->assertSame(OperationStatus::Success, $created->status, (string) $created->message);

        $id = (int) ($created->data['item']['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        // 値は data JSON の中にある（カラムは増えていない）
        $row = (array) DB::table('news')->where('id', $id)->first();
        $this->assertArrayNotHasKey('spec_weight', $row);
        $this->assertSame(['spec_weight' => '1.2kg'], json_decode((string) $row['data'], true));

        // 読み取りでも出る
        $read = app(GetContent::class)->execute('news', $id, OperationContext::cli());
        $this->assertSame('1.2kg', $read->data['item']['spec_weight'] ?? null);

        // 更新できる
        $updated = app(UpdateContent::class)->execute(
            'news', $id, ['spec_weight' => '1.5kg'], OperationContext::cli(), partial: true,
        );
        $this->assertSame(OperationStatus::Success, $updated->status, (string) $updated->message);

        $row = (array) DB::table('news')->where('id', $id)->first();
        $this->assertSame(['spec_weight' => '1.5kg'], json_decode((string) $row['data'], true));
    }

    /** 束を外しても、入っていた値は消えない（owner 決定 G3-(b)） */
    public function test_束を外しても値は消えない(): void
    {
        $this->makeSpecGroup();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '保持', 'slug' => 'keep-'.uniqid(), 'body' => '本文',
            'status' => 'draft', 'spec_weight' => '1.2kg',
        ], OperationContext::cli());

        $id = (int) ($created->data['item']['id'] ?? 0);

        // 束をアーカイブ相当にする = overlay から外れる
        DB::table('field_groups')->update(['status' => 'archived']);
        app()->forgetScopedInstances();

        $this->assertFalse(app(SchemaRegistry::class)->get('news')->hasField('spec_weight'));

        // 値は data JSON にそのまま残っている（見えなくなるだけ）
        $row = (array) DB::table('news')->where('id', $id)->first();
        $this->assertSame(['spec_weight' => '1.2kg'], json_decode((string) $row['data'], true));
    }

    /** DB の宣言から storage を選ばせない（カラム化は migration を伴う opt-in） */
    public function test_dbの宣言でカラム保存を選べない(): void
    {
        $this->makeGroup(
            'forced',
            [['key' => 'spec_forced', 'type' => 'text', 'label' => 'x', 'storage' => 'column']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $group = $this->repository()->all()[0];

        $this->assertFalse($group->isValid());
        $this->assertStringContainsString('storage', (string) $group->invalidReason);
        $this->assertFalse(app(SchemaRegistry::class)->get('news')->hasField('spec_forced'));
    }

    /** 既存の投稿は data が NULL のままでも壊れない */
    public function test_既存投稿はdataがnullでも読める(): void
    {
        $created = app(CreateContent::class)->execute('news', [
            'title' => '既存', 'slug' => 'legacy-'.uniqid(), 'body' => '本文', 'status' => 'draft',
        ], OperationContext::cli());

        $id = (int) ($created->data['item']['id'] ?? 0);
        DB::table('news')->where('id', $id)->update(['data' => null]);

        $this->makeSpecGroup();

        $read = app(GetContent::class)->execute('news', $id, OperationContext::cli());

        $this->assertSame(OperationStatus::Success, $read->status);
        $this->assertNull($read->data['item']['spec_weight'] ?? null);
    }
}
