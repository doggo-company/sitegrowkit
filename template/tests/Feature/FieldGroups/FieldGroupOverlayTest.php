<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.5-A / ADR-0015 — overlay の契約（必須テスト 2〜7）。
 *
 * ここが崩れると **全 Content Type が同時に壊れる**（SPEC §8 Step 2）。
 * だから「空なら現状と同値」を最初に固定する。
 */
final class FieldGroupOverlayTest extends FieldGroupTestCase
{
    /** 必須テスト 2 — overlay 空で内容も順序も同値 */
    public function test_overlayが空なら既存registryと完全に同値(): void
    {
        $this->assertSame(0, DB::table('field_groups')->count());

        $this->assertSame(
            $this->snapshot($this->registryWithoutOverlay()),
            $this->snapshot($this->registryWithOverlay()),
        );
    }

    /** 必須テスト 3 — テーブル未作成でも同値。ただし本物の DB 障害は握りつぶさない */
    public function test_テーブル未作成でも既存registryと同値(): void
    {
        $before = $this->snapshot($this->registryWithoutOverlay());

        Schema::dropIfExists('field_group_revisions');
        Schema::dropIfExists('field_group_locations');
        Schema::dropIfExists('field_groups');

        $this->assertFalse($this->repository()->isInstalled());
        $this->assertSame($before, $this->snapshot($this->registryWithOverlay()));
    }

    public function test_db障害は空扱いにせず例外になる(): void
    {
        config(['database.connections.agentcms_broken' => [
            'driver' => 'sqlite',
            // 存在しないディレクトリ = 接続そのものが失敗する
            'database' => '/agentcms-nonexistent-dir/broken.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $repository = new FieldGroupRepository(DB::connection('agentcms_broken'));

        $this->expectException(QueryException::class);

        $repository->isInstalled();
    }

    /** 必須テスト 4 — 追加した束が対象の投稿タイプへ項目を足す */
    public function test_公開中のfield_groupが投稿タイプへ項目を足す(): void
    {
        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        $this->makeGroup(
            'spec',
            [['key' => 'spec_weight', 'type' => 'text', 'label' => '重量']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $after = $this->fieldKeys($this->registryWithOverlay(), 'news');

        // ファイルの順序を保ったまま、**後ろに**足される
        $this->assertSame([...$before, 'spec_weight'], $after);

        // 割り当てていない投稿タイプは変わらない
        $this->assertSame(
            $this->fieldKeys($this->registryWithoutOverlay(), 'works'),
            $this->fieldKeys($this->registryWithOverlay(), 'works'),
        );
    }

    public function test_公開されていない束は項目を足さない(): void
    {
        foreach (['draft', 'archived'] as $status) {
            $this->makeGroup(
                "spec_{$status}",
                [['key' => "spec_{$status}", 'type' => 'text', 'label' => '重量']],
                [['kind' => 'content_type', 'value' => 'news']],
                status: $status,
            );
        }

        $this->assertSame(
            $this->fieldKeys($this->registryWithoutOverlay(), 'news'),
            $this->fieldKeys($this->registryWithOverlay(), 'news'),
        );
    }

    /** 必須テスト 5 — 同じ key はファイルが勝つ。DB から削除も型変更もできない */
    public function test_同じkeyはファイル側が勝つ(): void
    {
        $file = $this->registryWithoutOverlay()->get('news');
        $title = $file->field('title');

        $this->makeGroup(
            'hijack',
            [
                // 既存 key を別の型・別の必須条件で乗っ取ろうとする
                ['key' => 'title', 'type' => 'number', 'label' => '乗っ取り', 'required' => false],
                ['key' => 'spec_extra', 'type' => 'text', 'label' => '追加'],
            ],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $effective = $this->registryWithOverlay()->get('news');
        $merged = $effective->field('title');

        // 型も label も required もファイル側のまま
        $this->assertSame($title->type, $merged->type);
        $this->assertSame($title->label, $merged->label);
        $this->assertSame($title->required, $merged->required);

        // key の重複は起きない（衝突分は捨てられ、新しい key だけが足される）
        $this->assertSame(1, count(array_keys($effective->fieldKeys(), 'title', true)));
        $this->assertContains('spec_extra', $effective->fieldKeys());
    }

    public function test_dbからファイル側の項目を削除できない(): void
    {
        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        // 「消す」を表す構文は存在しない。何を書いても消えないことを固定する
        $this->makeGroup(
            'remove_attempt',
            [['key' => 'title', 'type' => 'text', 'label' => '', 'required' => false]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $this->assertSame($before, $this->fieldKeys($this->registryWithOverlay(), 'news'));
    }

    /** 必須テスト 6 — fail closed */
    public function test_壊れた定義は項目を1つも足さない(): void
    {
        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        $broken = [
            /*
             * **不正なバイト列では壊さない。**
             *
             * `'{ this is not json'` は SQLite なら入るが、**MySQL は拒む**
             * （`3140 Invalid JSON text`）— そして拒むのが正しい。本番でその行は作れない。
             * ここで見たいのは「**読めない定義は項目を 1 つも足さない**」なので、
             * **JSON としては妥当だが形が違う**もので壊す。
             */
            'wrong_shape' => '{"fields":"文字列"}',
            // list ではない
            'not_a_list' => '{"key":"x"}',
            // 未知の Field 型
            'unknown_type' => [['key' => 'x_unknown', 'type' => 'wysiwyg', 'label' => 'x']],
            // 型が要求する属性が無い（select は choices 必須）
            'missing_choices' => [['key' => 'x_choice', 'type' => 'select', 'label' => 'x']],
            // 予約 key（Content Type 側で例外になる = 全画面が落ちる）
            'reserved_key' => [['key' => ContentSchema::TAXONOMIES_KEY, 'type' => 'text', 'label' => 'x']],
            // 束の中で key が重複
            'duplicated_key' => [
                ['key' => 'x_dup', 'type' => 'text', 'label' => 'a'],
                ['key' => 'x_dup', 'type' => 'text', 'label' => 'b'],
            ],
        ];

        foreach ($broken as $key => $fields) {
            $this->makeGroup($key, $fields, [['kind' => 'content_type', 'value' => 'news']]);
        }

        // 例外にはならず、**1 つも足さない**
        $this->assertSame($before, $this->fieldKeys($this->registryWithOverlay(), 'news'));

        // 壊れていることは隠さない
        foreach ($this->repository()->all() as $group) {
            $this->assertFalse($group->isValid(), "[{$group->key}] が有効と判定されている");
            $this->assertNotNull($group->invalidReason);
        }
    }

    public function test_未知の状態は項目を足さず不正として見える(): void
    {
        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        $this->makeGroup(
            'weird_status',
            [['key' => 'x_weird', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
            status: 'live',
        );

        $this->assertSame($before, $this->fieldKeys($this->registryWithOverlay(), 'news'));

        $group = $this->repository()->all()[0];
        $this->assertFalse($group->isValid());
        $this->assertSame('live', $group->rawStatus);
    }

    /** 必須テスト 6 — page_template と未知の kind はどこにも一致しない（G11） */
    public function test_page_templateと未知のkindはどこにも当たらない(): void
    {
        $newsBefore = $this->fieldKeys($this->registryWithoutOverlay(), 'news');
        $worksBefore = $this->fieldKeys($this->registryWithoutOverlay(), 'works');

        $this->makeGroup(
            'reserved_kind',
            [['key' => 'x_reserved', 'type' => 'text', 'label' => 'x']],
            [
                ['kind' => 'page_template', 'value' => 'landing'],
                ['kind' => 'wp_post_type', 'value' => 'news'],
                // form は受理するが、9.5-A に実行面が無いので項目は増えない
                ['kind' => 'form', 'value' => 'contact'],
            ],
        );

        $registry = $this->registryWithOverlay();

        $this->assertSame($newsBefore, $this->fieldKeys($registry, 'news'));
        $this->assertSame($worksBefore, $this->fieldKeys($registry, 'works'));

        // 定義そのものは壊れていない（割当先が当たらないだけ）
        $this->assertTrue($this->repository()->all()[0]->isValid());
    }

    public function test_存在しない投稿タイプへの割当は何も起こさない(): void
    {
        $before = $this->snapshot($this->registryWithoutOverlay());

        $this->makeGroup(
            'ghost',
            [['key' => 'x_ghost', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'no_such_type']],
        );

        $this->assertSame($before, $this->snapshot($this->registryWithOverlay()));
    }

    /** 必須テスト 7 — 1 リクエスト内は 1 度だけ読み、次のリクエストは読み直す */
    public function test_1リクエスト内でメモ化され次のリクエストで反映される(): void
    {
        $this->makeGroup(
            'memo',
            [['key' => 'x_first', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $registry = $this->registryWithOverlay();

        DB::enableQueryLog();
        $first = $this->fieldKeys($registry, 'news');
        $queriesAfterFirst = count(DB::getQueryLog());

        // 同じ instance を 2 度読んでも DB を引き直さない
        $second = $this->fieldKeys($registry, 'news');
        $this->assertSame($queriesAfterFirst, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertContains('x_first', $first);

        // 別の instance（= 次のリクエスト）は更新を見る
        $this->makeGroup(
            'memo2',
            [['key' => 'x_second', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
            sortOrder: 1,
        );

        $this->assertNotContains('x_second', $this->fieldKeys($registry, 'news'));
        $this->assertContains('x_second', $this->fieldKeys($this->registryWithOverlay(), 'news'));
    }

    /** declared() は DB を 1 度も読まない（boot 中に DB へ触れない — ADR-0015） */
    public function test_declaredはoverlayを読まない(): void
    {
        $this->makeGroup(
            'declared_check',
            [['key' => 'x_declared', 'type' => 'text', 'label' => 'x']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $registry = $this->registryWithOverlay();

        DB::enableQueryLog();
        $declared = $registry->declared()['news']->fieldKeys();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'declared() が DB を読んでいる');
        $this->assertNotContains('x_declared', $declared);
        $this->assertContains('x_declared', $registry->get('news')->fieldKeys());
    }

    /**
     * 全 schema の内容と順序をそのまま文字列にする。
     * 「1 バイトも変わらない」を比較できる形にするためのもの。
     */
    private function snapshot(SchemaRegistry $registry): string
    {
        return json_encode(
            array_map(static fn (ContentSchema $s): array => $s->toAdminSpec(), $registry->all()),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );
    }
}
