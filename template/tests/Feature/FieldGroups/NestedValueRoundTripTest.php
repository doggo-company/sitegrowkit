<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\GetContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Phase 9.5-C — 5 型の **値** が往復すること（ADR-0017）。
 *
 * 定義できても値を入れられなければ意味が無い。作成 → 読み取り → 更新 →
 * 既存値の保持まで、投稿側の経路を固定する。
 */
final class NestedValueRoundTripTest extends FieldGroupScreenTestCase
{
    private function cli(): OperationContext
    {
        app()->forgetScopedInstances();

        return OperationContext::cli();
    }

    private function makeWork(string $slug): int
    {
        $result = app(CreateContent::class)->execute('works', [
            'title' => '参照される実績', 'slug' => $slug, 'body' => '本文',
            'category' => 'web', 'status' => 'draft',
        ], $this->cli());

        return (int) $result->data['item']['id'];
    }

    private function makeMedia(): int
    {
        return (int) DB::table('media')->insertGetId([
            'name' => '画像', 'alt' => null, 'path' => 'media/x.png', 'mime' => 'image/png',
            'size' => 10, 'width' => 10, 'height' => 10, 'has_webp' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 5 型をすべて持つ束を news へ割り当てる */
    private function makeAllTypes(): void
    {
        $this->actingAs($this->owner())->post('/admin/field-groups', [
            'key' => 'spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [
                ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    ['key' => 'weight', 'type' => 'text', 'label' => '重量'],
                    ['key' => 'inner_media', 'type' => 'media', 'label' => '画像'],
                ]],
                ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => 5, 'fields' => [
                    ['key' => 'row_title', 'type' => 'text', 'label' => '見出し'],
                ]],
                ['key' => 'spec_one', 'type' => 'relation', 'label' => '参照', 'target' => 'works'],
                ['key' => 'spec_many', 'type' => 'relations', 'label' => '複数参照', 'target' => 'works'],
                ['key' => 'spec_images', 'type' => 'images', 'label' => '画像'],
            ],
        ])->assertRedirect('/admin/field-groups');

        app()->forgetScopedInstances();
    }

    public function test_5型の値が作成読み取り更新で往復する(): void
    {
        $this->makeAllTypes();

        $work = $this->makeWork('round-trip');
        $media = $this->makeMedia();
        $other = $this->makeMedia();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '全部入り', 'slug' => 'all-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['weight' => '1.2kg', 'inner_media' => $media],
            'spec_rows' => [['row_title' => '1 行目'], ['row_title' => '2 行目']],
            'spec_one' => $work,
            'spec_many' => [$work],
            'spec_images' => [$media, $other],
        ], $this->cli());

        $this->assertSame(OperationStatus::Success, $created->status, (string) $created->message);

        $id = (int) $created->data['item']['id'];
        $read = app(GetContent::class)->execute('news', $id, $this->cli())->data['item'];

        $this->assertSame('1.2kg', $read['spec_group']['weight']);
        $this->assertSame($media, $read['spec_group']['inner_media']);
        $this->assertCount(2, $read['spec_rows']);
        $this->assertSame('1 行目', $read['spec_rows'][0]['row_title']);
        $this->assertSame($work, $read['spec_one']);
        $this->assertSame([$work], $read['spec_many']);
        // 順序を保つ
        $this->assertSame([$media, $other], $read['spec_images']);

        // repeater の行には ID が振られている
        $rowId = $read['spec_rows'][0]['_id'];
        $this->assertSame(26, strlen((string) $rowId));

        // 一部だけ更新しても、触っていない値は残る
        $updated = app(UpdateContent::class)->execute('news', $id, [
            'spec_rows' => [['_id' => $rowId, 'row_title' => '1 行目（改）']],
        ], $this->cli(), partial: true);

        $this->assertSame(OperationStatus::Success, $updated->status, (string) $updated->message);

        $again = app(GetContent::class)->execute('news', $id, $this->cli())->data['item'];

        $this->assertSame('1 行目（改）', $again['spec_rows'][0]['row_title']);
        // 行の ID は維持される
        $this->assertSame($rowId, $again['spec_rows'][0]['_id']);
        // 触っていない項目はそのまま
        $this->assertSame('1.2kg', $again['spec_group']['weight']);
        $this->assertSame([$media, $other], $again['spec_images']);
    }

    public function test_空と解除ができる(): void
    {
        $this->makeAllTypes();

        $work = $this->makeWork('clearable');

        $created = app(CreateContent::class)->execute('news', [
            'title' => '解除', 'slug' => 'clear-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_one' => $work, 'spec_many' => [$work], 'spec_images' => [],
        ], $this->cli());

        $id = (int) $created->data['item']['id'];

        $cleared = app(UpdateContent::class)->execute('news', $id, [
            'spec_one' => null, 'spec_many' => [],
        ], $this->cli(), partial: true);

        $this->assertSame(OperationStatus::Success, $cleared->status, (string) $cleared->message);

        $read = app(GetContent::class)->execute('news', $id, $this->cli())->data['item'];

        $this->assertNull($read['spec_one']);
        $this->assertSame([], $read['spec_many']);
    }

    public function test_行数の上限を超えると拒否される(): void
    {
        $this->makeAllTypes();

        $rows = array_map(
            static fn (int $i): array => ['row_title' => "行 {$i}"],
            range(1, 6),
        );

        $result = app(CreateContent::class)->execute('news', [
            'title' => '多すぎ', 'slug' => 'many-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_rows' => $rows,
        ], $this->cli());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('spec_rows', $result->errors);
    }

    public function test_imagesの重複は拒否される(): void
    {
        $this->makeAllTypes();

        $media = $this->makeMedia();

        $result = app(CreateContent::class)->execute('news', [
            'title' => '重複', 'slug' => 'dup-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_images' => [$media, $media],
        ], $this->cli());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    // ── 定義 UI の往復 ──────────────────────────────────

    public function test_編集画面が入れ子と参照先と行数上限を返す(): void
    {
        $this->makeAllTypes();

        $id = (string) DB::table('field_groups')->value('id');

        $this->actingAs($this->owner())
            ->get("/admin/field-groups/{$id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/field-groups/Edit')
                ->has('group.fields', 5)
                // 入れ子の子がそのまま返る（編集で消えない）
                ->has('group.fields.0.fields', 2)
                ->where('group.fields.0.fields.0.key', 'weight')
                ->where('group.fields.0.fields.0.id', fn (?string $v): bool => is_string($v) && strlen($v) === 26)
                // repeater の行数上限
                ->where('group.fields.1.max', 5)
                // 参照先
                ->where('group.fields.2.target', 'works')
                // 画面が組み立てに使う選択肢もサーバーが渡す
                ->has('options.nestedTypes')
                ->has('options.referenceTargets')
                ->where('options.limits.depth', 3)
                ->where('options.limits.repeaterMax', 500));
    }

    /**
     * 編集画面が **入れ子の値を返す**。
     *
     * 生の Model を渡すと `data` JSON が `item.data` の中に埋まったままで、
     * フォームは空だと判断する。保存はできるのに開き直すと消えて見える。
     */
    public function test_投稿の編集画面が入れ子の値を返す(): void
    {
        $this->makeAllTypes();

        $work = $this->makeWork('edit-screen');
        $media = $this->makeMedia();

        $created = app(CreateContent::class)->execute('news', [
            'title' => '再編集', 'slug' => 'edit-'.uniqid(), 'body' => '本文', 'status' => 'draft',
            'spec_group' => ['weight' => '1.2kg'],
            'spec_rows' => [['row_title' => '1 行目']],
            'spec_one' => $work,
            'spec_many' => [$work],
            'spec_images' => [$media],
        ], $this->cli());

        $id = (int) $created->data['item']['id'];

        $this->actingAs($this->owner())
            ->get("/admin/news/{$id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/resource/Form')
                // **トップレベルへ引き上げられている**（item.data の中ではない）
                ->where('item.spec_group.weight', '1.2kg')
                ->where('item.spec_rows.0.row_title', '1 行目')
                ->where('item.spec_one', $work)
                ->where('item.spec_many', [$work])
                ->where('item.spec_images', [$media])
                ->missing('item.data'));
    }

    /** Phase 9.5-D: `file` は定義 UI にも値 UI にも出る */
    public function test_fileは定義_u_iにも値_u_iにも出る(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/field-groups/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Inertia は配列を Collection で渡す。型を書いておくと
                // collect(mixed) の TKey/TValue が解決できない問題も起きない
                ->where(
                    'options.fieldTypes',
                    fn (Collection $types): bool => $types->contains('file'),
                ));

        // 値の入力コンポーネントにも file の分岐がある
        $input = (string) File::get(base_path('resources/js/components/schema/SchemaFieldInput.vue'));
        $this->assertStringContainsString("field.type === 'file'", $input);
    }

    /**
     * repeater の行ごとに DOM の id が重複しない。
     *
     * `field.key` 固定にすると label が別の行の入力欄を指し、読み上げも誤る。
     */
    public function test_入力欄のidが行ごとに一意になる(): void
    {
        $input = (string) File::get(base_path('resources/js/components/schema/SchemaFieldInput.vue'));

        $this->assertStringContainsString('const inputId = computed(', $input);
        $this->assertStringContainsString('fieldPath.value.replace(', $input);
        $this->assertStringContainsString(':id="labelId" :for="inputId"', $input);
        // key 固定の id が残っていない
        $this->assertStringNotContainsString(':id="field.key"', $input);
    }

    /** 入れ子の error が画面へ届く（Form.vue が errors を渡している） */
    public function test_入れ子のerrorが画面へ渡る(): void
    {
        $form = (string) File::get(base_path('resources/js/pages/admin/resource/Form.vue'));

        $this->assertSame(2, substr_count($form, ':errors="form.errors"'));
    }

    /** images は一覧を 1 回だけ取る（N 件で N 回にしない） */
    public function test_imagesが一覧を共有して取得する(): void
    {
        $images = (string) File::get(base_path('resources/js/components/schema/ImagesInput.vue'));
        $picker = (string) File::get(base_path('resources/js/components/schema/MediaPicker.vue'));

        foreach ([$images, $picker] as $source) {
            $this->assertStringContainsString('useMediaLibrary', $source);
        }

        // 選択済みの行は自前の fetch を持たない
        $this->assertStringNotContainsString('/admin/media/select', $images);
    }
}
