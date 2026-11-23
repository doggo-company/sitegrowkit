<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\FieldGroups\ArchiveFieldGroup;
use AgentCms\Core\Application\FieldGroups\CreateFieldGroup;
use AgentCms\Core\Application\FieldGroups\ReorderFieldGroups;
use AgentCms\Core\Application\FieldGroups\RestoreFieldGroup;
use AgentCms\Core\Application\FieldGroups\UpdateFieldGroup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 9.5-B — Field Group の書き込み（ADR-0016）。
 *
 * 固定するのは「作れる」ことだけではない。**作れてはいけないものが作れない**こと、
 * 危険な変更が確認を通ること、値が消えないことを同じ重さで固定する。
 */
final class FieldGroupWriteTest extends FieldGroupTestCase
{
    /**
     * 次のリクエストとして実行する。
     *
     * 読み取り側（repository / registry / overlay）は 1 リクエスト内でメモ化される。
     * 本番では 1 操作 = 1 リクエストなので、テストでも境界を作らないと
     * 「同じリクエストの中で 2 回書く」という起こらない状況を試すことになる。
     */
    private ?int $actorId = null;

    private function context(): OperationContext
    {
        app()->forgetScopedInstances();

        // **同じ actor で通す。** 確認トークンは actor へ束縛されるので、
        // 呼ぶたびに利用者を作り直すと 2 回目の送信が別人扱いになる
        $this->actorId ??= (int) $this->owner()->getKey();

        return OperationContext::admin($this->actorId, 'テスト管理者');
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'key' => 'product_spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [['key' => 'spec_weight', 'type' => 'text', 'label' => '重量']],
            ...$overrides,
        ];
    }

    private function create(array $overrides = []): Result
    {
        return app(CreateFieldGroup::class)->execute($this->payload($overrides), $this->context());
    }

    // ── 作成 ────────────────────────────────────────────────

    public function test_作成すると即座に投稿タイプへ項目が出る(): void
    {
        $result = $this->create();

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);

        app()->forgetScopedInstances();

        $this->assertContains('spec_weight', app(SchemaRegistry::class)->get('news')->fieldKeys());
        $this->assertSame('published', DB::table('field_groups')->value('status'));
    }

    public function test_項目にimmutableなidが振られる(): void
    {
        $this->create();

        app()->forgetScopedInstances();

        $field = app(SchemaRegistry::class)->get('news')->field('spec_weight');

        $this->assertNotNull($field->id);
        $this->assertSame(26, strlen((string) $field->id));
    }

    public function test_作成が版として残る(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $revision = DB::table('field_group_revisions')->where('field_group_id', $id)->first();

        $this->assertNotNull($revision);
        $this->assertSame(1, (int) $revision->revision);

        $snapshot = json_decode((string) $revision->snapshot, true);
        $this->assertSame('product_spec', $snapshot['key']);
        $this->assertSame([['kind' => 'content_type', 'value' => 'news']], $snapshot['locations']);
    }

    public function test_監査に値を残さない(): void
    {
        $this->create(['fields' => [
            ['key' => 'spec_pick', 'type' => 'select', 'label' => '選択', 'choices' => ['社外秘A', '社外秘B']],
        ]]);

        $audit = DB::table('audit_logs')->where('operation', 'field_group.create')->first();

        $this->assertNotNull($audit);
        $metadata = (string) $audit->metadata;

        $this->assertStringNotContainsString('社外秘', $metadata);
        $this->assertStringContainsString('product_spec', $metadata);
        $this->assertStringContainsString('fieldCount', $metadata);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function rejectedPayloads(): iterable
    {
        yield 'キーが機械名でない' => [['key' => 'Product Spec'], 'key'];
        /*
         * 🔴 **「キーが空」はここから外した**（WP 一周 10 周目 2026-09-04）。
         *
         * 空のキーは**表示名から作る**ようになった（分類の #20 と同じ形）。
         * 拒否されるのは「機械名でないものを書いたとき」だけである。
         * 埋まる側の保証は `FieldGroupKeyOptionalTest` が持つ。
         */
        yield '表示名が空' => [['label' => '  '], 'label'];
        yield '項目が 0 件' => [['fields' => []], 'fields'];
        // file は 9.5-D（ADR-0018）で開いた。未対応の代表は json（保存形式を決めていない）
        yield '未対応の型' => [['fields' => [['key' => 'x', 'type' => 'json', 'label' => 'x']]], 'fields.0.type'];
        yield 'slug は選べない' => [['fields' => [['key' => 'x', 'type' => 'slug', 'label' => 'x']]], 'fields.0.type'];
        yield '予約キー' => [['fields' => [['key' => 'taxonomies', 'type' => 'text', 'label' => 'x']]], 'fields.0.key'];
        yield '項目キーの重複' => [['fields' => [
            ['key' => 'dup', 'type' => 'text', 'label' => 'a'],
            ['key' => 'dup', 'type' => 'text', 'label' => 'b'],
        ]], 'fields.1.key'];
        yield 'ファイル schema と衝突' => [['fields' => [['key' => 'title', 'type' => 'text', 'label' => 'x']]], 'fields'];
        yield '存在しない投稿タイプ' => [['locations' => [['kind' => 'content_type', 'value' => 'nope']]], 'locations.0.value'];
        yield 'page_template は受け取らない' => [['locations' => [['kind' => 'page_template', 'value' => 'landing']]], 'locations.0.kind'];
        yield '割当先の重複' => [['locations' => [
            ['kind' => 'content_type', 'value' => 'news'],
            ['kind' => 'content_type', 'value' => 'news'],
        ]], 'locations.1'];
        yield '未知のキー' => [['status' => 'archived'], 'status'];
        yield 'sortOrder は指定できない' => [['sortOrder' => 3], 'sortOrder'];
        yield 'storage は指定できない' => [['fields' => [
            ['key' => 'x', 'type' => 'text', 'label' => 'x', 'storage' => 'column'],
        ]], 'fields.0'];
        yield 'choices の形が違う' => [['fields' => [
            ['key' => 'x', 'type' => 'select', 'label' => 'x', 'choices' => 'a,b'],
        ]], 'fields.0'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rejectedPayloads')]
    public function test_作れてはいけないものは作れない(array $overrides, string $errorKey): void
    {
        $result = $this->create($overrides);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey($errorKey, $result->errors);
        $this->assertSame(0, DB::table('field_groups')->count());
    }

    public function test_同じキーは2つ作れない(): void
    {
        $this->create();

        $second = $this->create(['fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']]]);

        $this->assertSame(OperationStatus::ValidationError, $second->status);
        $this->assertArrayHasKey('key', $second->errors);
    }

    public function test_他の束と同じ項目キーは作れない(): void
    {
        $this->create();

        $second = $this->create(['key' => 'another']);

        $this->assertSame(OperationStatus::ValidationError, $second->status);
        $this->assertArrayHasKey('fields', $second->errors);
    }

    public function test_フォーム割当ではrichtextを選べない(): void
    {
        $result = $this->create([
            'locations' => [['kind' => 'form', 'value' => 'estimate']],
            'fields' => [['key' => 'body', 'type' => 'richtext', 'label' => '本文']],
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.type', $result->errors);
    }

    // ── 更新 ────────────────────────────────────────────────

    public function test_安全な変更は確認なしで通る(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];
        app()->forgetScopedInstances();

        $fieldId = app(SchemaRegistry::class)->get('news')->field('spec_weight')->id;

        $result = app(UpdateFieldGroup::class)->execute($id, $this->payload([
            'label' => '商品スペック（改）',
            'fields' => [
                ['id' => $fieldId, 'key' => 'spec_weight', 'type' => 'text', 'label' => '重さ'],
                ['key' => 'spec_size', 'type' => 'text', 'label' => 'サイズ'],
            ],
        ]), $this->context());

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);

        app()->forgetScopedInstances();
        $this->assertContains('spec_size', app(SchemaRegistry::class)->get('news')->fieldKeys());
    }

    public function test_項目の削除は確認を要求する(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $payload = $this->payload(['fields' => [['key' => 'spec_size', 'type' => 'text', 'label' => 'サイズ']]]);

        $first = app(UpdateFieldGroup::class)->execute($id, $payload, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertNotNull($first->confirmation);
        $this->assertSame('destructive', $first->data['plan']['level']);

        // まだ何も変わっていない
        app()->forgetScopedInstances();
        $this->assertContains('spec_weight', app(SchemaRegistry::class)->get('news')->fieldKeys());

        // 影響の説明で「値が消える」と誤解させない
        $this->assertContains(
            '入力済みの値が消えることはありません。',
            $first->confirmation->impact,
        );

        $second = app(UpdateFieldGroup::class)->execute(
            $id,
            $payload,
            $this->context()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertSame(OperationStatus::Success, $second->status, (string) $second->message);

        app()->forgetScopedInstances();
        $keys = app(SchemaRegistry::class)->get('news')->fieldKeys();
        $this->assertNotContains('spec_weight', $keys);
        $this->assertContains('spec_size', $keys);
    }

    public function test_改名も確認を要求しidは変わらない(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];
        app()->forgetScopedInstances();

        $fieldId = app(SchemaRegistry::class)->get('news')->field('spec_weight')->id;

        $payload = $this->payload(['fields' => [
            ['id' => $fieldId, 'key' => 'spec_mass', 'type' => 'text', 'label' => '重量'],
        ]]);

        $first = app(UpdateFieldGroup::class)->execute($id, $payload, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertSame('migration_required', $first->data['plan']['level']);

        $second = app(UpdateFieldGroup::class)->execute(
            $id, $payload, $this->context()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertSame(OperationStatus::Success, $second->status);

        app()->forgetScopedInstances();
        // key は変わったが **同じ項目**（binding が切れない）
        $this->assertSame($fieldId, app(SchemaRegistry::class)->get('news')->field('spec_mass')->id);
    }

    public function test_他の束のidを持ち込めない(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $result = app(UpdateFieldGroup::class)->execute($id, $this->payload(['fields' => [
            ['id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'key' => 'spec_weight', 'type' => 'text', 'label' => '重量'],
        ]]), $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.id', $result->errors);
    }

    // ── アーカイブ / 復元 ───────────────────────────────────

    public function test_アーカイブは確認を要求し値を消さない(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $first = app(ArchiveFieldGroup::class)->execute($id, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertSame('published', DB::table('field_groups')->value('status'));

        $second = app(ArchiveFieldGroup::class)->execute(
            $id, $this->context()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertSame(OperationStatus::Success, $second->status);
        $this->assertSame('archived', DB::table('field_groups')->value('status'));

        // 定義は 1 バイトも消えていない
        $fields = json_decode((string) DB::table('field_groups')->value('fields'), true);
        $this->assertSame('spec_weight', $fields[0]['key']);

        app()->forgetScopedInstances();
        $this->assertFalse(app(SchemaRegistry::class)->get('news')->hasField('spec_weight'));
    }

    public function test_復元すると元の項目が戻る(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $confirm = app(ArchiveFieldGroup::class)->execute($id, $this->context());
        app(ArchiveFieldGroup::class)->execute(
            $id, $this->context()->withConfirmationToken($confirm->confirmation->token),
        );

        $restored = app(RestoreFieldGroup::class)->execute($id, $this->context());

        $this->assertSame(OperationStatus::Success, $restored->status, (string) $restored->message);
        $this->assertSame('published', DB::table('field_groups')->value('status'));

        app()->forgetScopedInstances();
        $this->assertContains('spec_weight', app(SchemaRegistry::class)->get('news')->fieldKeys());
    }

    public function test_復元先で衝突していれば拒否される(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $confirm = app(ArchiveFieldGroup::class)->execute($id, $this->context());
        app(ArchiveFieldGroup::class)->execute(
            $id, $this->context()->withConfirmationToken($confirm->confirmation->token),
        );

        // アーカイブ中に同じキーの束が増える
        $this->create(['key' => 'rival']);

        $restored = app(RestoreFieldGroup::class)->execute($id, $this->context());

        $this->assertSame(OperationStatus::Conflict, $restored->status);
        $this->assertStringContainsString('spec_weight', (string) $restored->message);
        $this->assertSame('archived', DB::table('field_groups')->where('id', $id)->value('status'));
    }

    public function test_二重アーカイブは拒否される(): void
    {
        $id = (string) $this->create()->data['fieldGroup']['id'];

        $confirm = app(ArchiveFieldGroup::class)->execute($id, $this->context());
        app(ArchiveFieldGroup::class)->execute(
            $id, $this->context()->withConfirmationToken($confirm->confirmation->token),
        );

        $again = app(ArchiveFieldGroup::class)->execute($id, $this->context());

        $this->assertSame(OperationStatus::Conflict, $again->status);
    }

    // ── 並べ替え ────────────────────────────────────────────

    public function test_並べ替えは全件を要求する(): void
    {
        $first = (string) $this->create()->data['fieldGroup']['id'];
        $second = (string) $this->create([
            'key' => 'second', 'fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']],
        ])->data['fieldGroup']['id'];

        app()->forgetScopedInstances();

        $partial = app(ReorderFieldGroups::class)->execute(['orderedIds' => [$second]], $this->context());
        $this->assertSame(OperationStatus::ValidationError, $partial->status);

        app()->forgetScopedInstances();

        $ok = app(ReorderFieldGroups::class)->execute(
            ['orderedIds' => [$second, $first]], $this->context(),
        );

        $this->assertSame(OperationStatus::Success, $ok->status, (string) $ok->message);
        $this->assertSame(0, (int) DB::table('field_groups')->where('id', $second)->value('sort_order'));
        $this->assertSame(1, (int) DB::table('field_groups')->where('id', $first)->value('sort_order'));
    }

    /**
     * **非 string を黙って捨てない**（CORRECTION）。
     *
     * 全 ID に加えて null / 数値 / 配列を混ぜると、直前の実装は
     * `array_filter(..., is_string(...))` でそれらを落としてから照合していた。
     * 「入力を黙って捨てない」「全 ID とちょうど一致」という自分の契約に反する。
     */
    public function test_並べ替えに非stringが混ざれば拒否される(): void
    {
        $first = (string) $this->create()->data['fieldGroup']['id'];
        $second = (string) $this->create([
            'key' => 'second', 'fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']],
        ])->data['fieldGroup']['id'];

        $before = DB::table('field_groups')->orderBy('id')->pluck('sort_order', 'id')->all();

        foreach ([null, 0, 1.5, true, ['x'], ''] as $intruder) {
            $result = app(ReorderFieldGroups::class)->execute(
                ['orderedIds' => [$second, $first, $intruder]],
                $this->context(),
            );

            $this->assertSame(
                OperationStatus::ValidationError,
                $result->status,
                '非 string が混ざったのに通っている: '.var_export($intruder, true),
            );
            $this->assertArrayHasKey('orderedIds', $result->errors);
        }

        // 1 件も並び順が動いていない
        $this->assertSame($before, DB::table('field_groups')->orderBy('id')->pluck('sort_order', 'id')->all());
    }

    public function test_並べ替えの重複idは拒否される(): void
    {
        $first = (string) $this->create()->data['fieldGroup']['id'];
        $this->create(['key' => 'second', 'fields' => [['key' => 'other', 'type' => 'text', 'label' => 'x']]]);

        $result = app(ReorderFieldGroups::class)->execute(
            ['orderedIds' => [$first, $first]], $this->context(),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_並べ替えの未知キーは拒否される(): void
    {
        $result = app(ReorderFieldGroups::class)->execute(
            ['orderedIds' => [], 'force' => true], $this->context(),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }
}
