<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\FieldGroups\CreateFieldGroup;
use AgentCms\Core\Application\FieldGroups\UpdateFieldGroup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\FieldGroups\FieldGroupLimits;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\Role;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 9.5-C — 入れ子と参照の **定義**（ADR-0017）。
 *
 * 値の入力は `FieldGroupNestedValueTest` が固定する。ここで見るのは
 * 「どんな束を作れて、どんな束が作れないか」だけ。
 *
 * 上限は管理画面でも crafted request でも同じ契約を通る。
 * トップレベルだけ厳しくても、入れ子が緩ければ回避できてしまう。
 */
final class FieldGroupNestedDefinitionTest extends FieldGroupTestCase
{
    private ?int $actorId = null;

    private function context(): OperationContext
    {
        app()->forgetScopedInstances();

        $this->actorId ??= (int) User::factory()->create(['role' => Role::Owner->value])->getKey();

        return OperationContext::admin($this->actorId, 'テスト管理者');
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array{kind: string, value: string}>|null  $locations
     */
    private function create(array $fields, ?array $locations = null, string $key = 'spec'): Result
    {
        return app(CreateFieldGroup::class)->execute([
            'key' => $key,
            'label' => '商品スペック',
            'locations' => $locations ?? [['kind' => 'content_type', 'value' => 'news']],
            'fields' => $fields,
        ], $this->context());
    }

    /** 深さ n の group を作る（深さ 1 = トップレベル） */
    private function nested(int $depth): array
    {
        $field = ['key' => 'leaf', 'type' => 'text', 'label' => '葉'];

        for ($i = $depth - 1; $i >= 1; $i--) {
            $field = ['key' => "g{$i}", 'type' => 'group', 'label' => "階層{$i}", 'fields' => [$field]];
        }

        return [$field];
    }

    // ── 5 型が定義できる ────────────────────────────────

    public function test_5型を定義できる(): void
    {
        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'weight', 'type' => 'text', 'label' => '重量'],
            ]],
            ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => 10, 'fields' => [
                ['key' => 'row_title', 'type' => 'text', 'label' => '見出し'],
            ]],
            ['key' => 'spec_one', 'type' => 'relation', 'label' => '参照', 'target' => 'works'],
            ['key' => 'spec_many', 'type' => 'relations', 'label' => '複数参照', 'target' => 'works'],
            ['key' => 'spec_images', 'type' => 'images', 'label' => '画像'],
        ]);

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);

        app()->forgetScopedInstances();
        $schema = app(SchemaRegistry::class)->get('news');

        foreach (['spec_group', 'spec_rows', 'spec_one', 'spec_many', 'spec_images'] as $key) {
            $this->assertTrue($schema->hasField($key), "{$key} が出ていない");
        }

        // 入れ子も overlay 経由で読める
        $this->assertSame('weight', $schema->field('spec_group')->fields[0]->key);
    }

    /**
     * Phase 9.5-D（ADR-0018）で `file` を開けた。
     *
     * 9.5-C では「Media 基盤が画像専用だから選べない」としていた。
     * Media が種別を持ち、一般ファイルを private disk へ置けるようになったので、
     * その理由が無くなった。
     */
    public function test_fileが定義できる(): void
    {
        $result = $this->create([['key' => 'spec_file', 'type' => 'file', 'label' => '資料']]);

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);
        $this->assertTrue(app(SchemaRegistry::class)->get('news')->hasField('spec_file'));
    }

    public function test_定義_u_iの型一覧にfileが出る(): void
    {
        $this->assertContains('file', FieldGroupDraft::ALLOWED_TYPES);

        foreach (['group', 'repeater', 'relation', 'relations', 'images'] as $type) {
            $this->assertContains($type, FieldGroupDraft::ALLOWED_TYPES);
        }

        // 「まだ選べない型」が消えたわけではない
        $this->assertNotContains('images_file', FieldGroupDraft::ALLOWED_TYPES);
        $this->assertNotContains('json', FieldGroupDraft::ALLOWED_TYPES);
    }

    // ── 子 ULID ────────────────────────────────────────

    public function test_子fieldにもulidが振られ改名しても維持される(): void
    {
        $id = (string) $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'weight', 'type' => 'text', 'label' => '重量'],
            ]],
        ])->data['fieldGroup']['id'];

        app()->forgetScopedInstances();
        $child = app(SchemaRegistry::class)->get('news')->field('spec_group')->fields[0];

        $this->assertNotNull($child->id);
        $this->assertSame(26, strlen((string) $child->id));

        $parentId = app(SchemaRegistry::class)->get('news')->field('spec_group')->id;

        $updated = app(UpdateFieldGroup::class)->execute($id, [
            'key' => 'spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [
                ['id' => $parentId, 'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    // key を変えても ID は同じ
                    ['id' => $child->id, 'key' => 'mass', 'type' => 'text', 'label' => '質量'],
                ]],
            ],
        ], $this->context());

        // 改名は migration_required なので確認を挟む
        $this->assertSame(OperationStatus::ConfirmationRequired, $updated->status);

        $applied = app(UpdateFieldGroup::class)->execute($id, [
            'key' => 'spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [
                ['id' => $parentId, 'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    ['id' => $child->id, 'key' => 'mass', 'type' => 'text', 'label' => '質量'],
                ]],
            ],
        ], $this->context()->withConfirmationToken($updated->confirmation->token));

        $this->assertSame(OperationStatus::Success, $applied->status, (string) $applied->message);

        app()->forgetScopedInstances();
        $renamed = app(SchemaRegistry::class)->get('news')->field('spec_group')->fields[0];

        $this->assertSame('mass', $renamed->key);
        $this->assertSame($child->id, $renamed->id, '改名で子の ID が変わっている');
    }

    public function test_他の束の子idは持ち込めない(): void
    {
        $id = (string) $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'weight', 'type' => 'text', 'label' => '重量'],
            ]],
        ])->data['fieldGroup']['id'];

        app()->forgetScopedInstances();
        $parentId = app(SchemaRegistry::class)->get('news')->field('spec_group')->id;

        $result = app(UpdateFieldGroup::class)->execute($id, [
            'key' => 'spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [
                ['id' => $parentId, 'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    ['id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'key' => 'weight', 'type' => 'text', 'label' => '重量'],
                ]],
            ],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.fields.0.id', $result->errors);
    }

    public function test_既存idを2つの項目へ使い回せない(): void
    {
        $id = (string) $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'weight', 'type' => 'text', 'label' => '重量'],
            ]],
        ])->data['fieldGroup']['id'];

        app()->forgetScopedInstances();
        $parent = app(SchemaRegistry::class)->get('news')->field('spec_group');
        $childId = (string) $parent->fields[0]->id;

        // 同じ子 ID を 2 つの項目へ付けると、片方が識別子として上書きされ、
        // 削除や改名を「変更なし」に見せられる
        $result = app(UpdateFieldGroup::class)->execute($id, [
            'key' => 'spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [
                ['id' => $parent->id, 'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    ['id' => $childId, 'key' => 'weight', 'type' => 'text', 'label' => '重量'],
                    ['id' => $childId, 'key' => 'mass', 'type' => 'text', 'label' => '質量'],
                ]],
            ],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.fields.1.id', $result->errors);
    }

    public function test_親と子で同じidを使い回せない(): void
    {
        $id = (string) $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'weight', 'type' => 'text', 'label' => '重量'],
            ]],
        ])->data['fieldGroup']['id'];

        app()->forgetScopedInstances();
        $parentId = (string) app(SchemaRegistry::class)->get('news')->field('spec_group')->id;

        $result = app(UpdateFieldGroup::class)->execute($id, [
            'key' => 'spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [
                ['id' => $parentId, 'key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    ['id' => $parentId, 'key' => 'weight', 'type' => 'text', 'label' => '重量'],
                ]],
            ],
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.fields.0.id', $result->errors);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function repeaterMaxValues(): iterable
    {
        yield '整数' => [10, true];
        yield '十進整数の文字列' => ['10', true];
        yield '未指定' => [null, true];
        yield '小数' => [1.5, false];
        yield '小数の文字列' => ['1.5', false];
        yield '指数表記' => ['1e2', false];
        yield '符号付き' => ['+10', false];
        yield '負の数' => [-1, false];
        yield '空文字' => ['', false];
        yield '数値でない文字列' => ['ten', false];
    }

    /**
     * `max` は整数契約。`is_numeric` を許すと `"1.5"` が `(int)` で 1 になり、
     * **書いた値と効く値が黙って食い違う**。
     */
    #[DataProvider('repeaterMaxValues')]
    public function test_repeaterのmaxは整数だけ受理する(mixed $max, bool $valid): void
    {
        $field = ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'fields' => [
            ['key' => 'row_title', 'type' => 'text', 'label' => '見出し'],
        ]];

        if ($max !== null) {
            $field['max'] = $max;
        }

        $result = $this->create([$field], key: 'maxcase');

        $this->assertSame(
            $valid ? OperationStatus::Success : OperationStatus::ValidationError,
            $result->status,
            'max='.var_export($max, true).' の判定が違う',
        );
    }

    public function test_読み込み時もmaxの曖昧な表記を拒否する(): void
    {
        $this->makeGroup(
            'spec',
            [['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => '1.5', 'fields' => [
                ['key' => 'row_title', 'type' => 'text', 'label' => '見出し'],
            ]]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $group = $this->repository()->all()[0];

        $this->assertFalse($group->isValid());
        $this->assertStringContainsString('max', (string) $group->invalidReason);
    }

    // ── 上限（Owner 決定） ──────────────────────────────

    public function test_深さ3は通り4は日本語で拒否される(): void
    {
        $this->assertSame(3, FieldGroupLimits::MAX_DEPTH);

        $ok = $this->create($this->nested(3));
        $this->assertSame(OperationStatus::Success, $ok->status, (string) $ok->message);

        $ng = $this->create($this->nested(4), key: 'deep');
        $this->assertSame(OperationStatus::ValidationError, $ng->status);

        $messages = implode(' / ', array_merge(...array_values($ng->errors)));
        $this->assertStringContainsString('3', $messages, '許容値が読めない');
        $this->assertMatchesRegularExpression('/入れ子|階層|深/u', $messages, '原因が読めない');
    }

    public function test_総数200は通り201は拒否される(): void
    {
        $this->assertSame(200, FieldGroupLimits::MAX_FIELDS);

        $make = static fn (int $n): array => array_map(
            static fn (int $i): array => ['key' => "f{$i}", 'type' => 'text', 'label' => "項目{$i}"],
            range(1, $n),
        );

        $ok = $this->create($make(200));
        $this->assertSame(OperationStatus::Success, $ok->status, (string) $ok->message);

        $ng = $this->create($make(201), key: 'wide');
        $this->assertSame(OperationStatus::ValidationError, $ng->status);
        $this->assertStringContainsString('200', implode(' / ', array_merge(...array_values($ng->errors))));
    }

    public function test_入れ子の子も総数に数える(): void
    {
        $children = array_map(
            static fn (int $i): array => ['key' => "c{$i}", 'type' => 'text', 'label' => "子{$i}"],
            range(1, 200),
        );

        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => $children],
        ]);

        // 親 1 + 子 200 = 201
        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_repeaterのmaxは必須で既定50へ正規化される(): void
    {
        $this->assertSame(50, FieldGroupLimits::REPEATER_DEFAULT_MAX);
        $this->assertSame(500, FieldGroupLimits::REPEATER_MAX);

        $this->create([
            ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'fields' => [
                ['key' => 'row_title', 'type' => 'text', 'label' => '見出し'],
            ]],
        ]);

        app()->forgetScopedInstances();

        $this->assertSame(50, app(SchemaRegistry::class)->get('news')->field('spec_rows')->max);
    }

    public function test_repeaterのmaxは500まで通り501は拒否される(): void
    {
        $row = ['key' => 'row_title', 'type' => 'text', 'label' => '見出し'];

        $ok = $this->create([
            ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => 500, 'fields' => [$row]],
        ]);
        $this->assertSame(OperationStatus::Success, $ok->status, (string) $ok->message);

        $ng = $this->create([
            ['key' => 'spec_rows', 'type' => 'repeater', 'label' => '行', 'max' => 501, 'fields' => [$row]],
        ], key: 'over');

        $this->assertSame(OperationStatus::ValidationError, $ng->status);
        $this->assertStringContainsString('500', implode(' / ', array_merge(...array_values($ng->errors))));
    }

    // ── 入れ子への再帰適用（crafted request で回避できないこと） ──

    public function test_入れ子でも未対応の型は拒否される(): void
    {
        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'inner', 'type' => 'json', 'label' => '生データ'],
            ]],
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.fields.0.type', $result->errors);
    }

    /**
     * `file` は multipart ではなく **Media の ID を選ぶ**ので、入れ子でも定義できる
     * （`image` を入れ子に置けない理由は `<key>_file` の回収がトップレベル専用だから）。
     */
    public function test_入れ子のfileは定義できる(): void
    {
        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'inner_file', 'type' => 'file', 'label' => '資料'],
            ]],
        ]);

        $this->assertSame(OperationStatus::Success, $result->status, (string) $result->message);

        $group = app(SchemaRegistry::class)->get('news')->field('spec_group');
        $this->assertSame('file', $group->fields[0]->type);
    }

    public function test_入れ子でも予約キーは拒否される(): void
    {
        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'taxonomies', 'type' => 'text', 'label' => 'x'],
            ]],
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.fields.0.key', $result->errors);
    }

    public function test_入れ子でもキーの重複は拒否される(): void
    {
        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'dup', 'type' => 'text', 'label' => 'a'],
                ['key' => 'dup', 'type' => 'text', 'label' => 'b'],
            ]],
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.fields.1.key', $result->errors);
    }

    public function test_入れ子でも未知キーは拒否される(): void
    {
        $result = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'inner', 'type' => 'text', 'label' => 'x', 'storage' => 'column'],
            ]],
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /**
     * **フォームに割り当てる束は、公開フォームで出せる種類だけ**（R-12 / 2026-08-30）。
     *
     * 以前は `richtext` 1 つだけを名指しで拒否していたが、**公開の門
     * （`FormFields::PUBLIC_TYPES`）は 12 種しか通さない**ので、
     * 「選べるのに公開できない」種類が 8 つ残っていた。判断を公開の門へ寄せた。
     *
     * ▷ その結果、**入れ子の入れ物（`group` / `repeater`）自体が公開フォームでは使えない**。
     *   中身まで見に行かず、外側で断る。中身だけ直しても公開できないからである。
     */
    public function test_form割当では公開できない種類を外側で断る(): void
    {
        $result = $this->create(
            [
                ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                    ['key' => 'body', 'type' => 'richtext', 'label' => '本文'],
                ]],
            ],
            [['kind' => 'form', 'value' => 'estimate']],
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('fields.0.type', $result->errors);
    }

    /** **12 種は通る**（拒否が広すぎないこと。1 つも通らなければ上の試験は無意味） */
    public function test_form割当でも公開できる種類は通る(): void
    {
        $result = $this->create(
            [
                ['key' => 'spec_name', 'type' => 'text', 'label' => 'お名前'],
                ['key' => 'spec_mail', 'type' => 'email', 'label' => 'メール'],
                ['key' => 'spec_agree', 'type' => 'boolean', 'label' => '同意'],
            ],
            [['kind' => 'form', 'value' => 'estimate']],
        );

        $this->assertSame(OperationStatus::Success, $result->status, (string) json_encode($result->errors));
    }

    public function test_relationはtargetが必須で実在する型のみ(): void
    {
        $missing = $this->create([['key' => 'spec_one', 'type' => 'relation', 'label' => '参照']]);
        $this->assertSame(OperationStatus::ValidationError, $missing->status);

        $unknown = $this->create(
            [['key' => 'spec_one', 'type' => 'relation', 'label' => '参照', 'target' => 'no_such_type']],
            key: 'unknown',
        );
        $this->assertSame(OperationStatus::ValidationError, $unknown->status);
        $this->assertArrayHasKey('fields.0.target', $unknown->errors);
    }

    public function test_groupとrepeaterは子を1つ以上要求する(): void
    {
        foreach (['group', 'repeater'] as $type) {
            $result = $this->create(
                [['key' => 'spec_empty', 'type' => $type, 'label' => '空', 'fields' => []]],
                key: "empty_{$type}",
            );

            $this->assertSame(OperationStatus::ValidationError, $result->status, "{$type} が子なしで通っている");
        }
    }

    /**
     * `image` は入れ子に置けない。
     *
     * 値がアップロードそのもの（`<key>_file`）で、投稿フォームはトップレベルの
     * キーしか回収しない。入れ子に置くと **入力欄は出るのに保存されない**。
     */
    public function test_imageは入れ子に置けずmediaなら置ける(): void
    {
        $ng = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'inner_image', 'type' => 'image', 'label' => '画像'],
            ]],
        ]);

        $this->assertSame(OperationStatus::ValidationError, $ng->status);
        $this->assertArrayHasKey('fields.0.fields.0.type', $ng->errors);

        // トップレベルなら置ける
        $top = $this->create(
            [['key' => 'spec_image', 'type' => 'image', 'label' => '画像']],
            key: 'top_image',
        );
        $this->assertSame(OperationStatus::Success, $top->status, (string) $top->message);

        // 入れ子で画像を持ちたいときは media
        $ok = $this->create([
            ['key' => 'spec_group', 'type' => 'group', 'label' => 'まとまり', 'fields' => [
                ['key' => 'inner_media', 'type' => 'media', 'label' => 'メディア'],
            ]],
        ], key: 'nested_media');

        $this->assertSame(OperationStatus::Success, $ok->status, (string) $ok->message);
    }

    // ── 読み込み側でも同じ上限（crafted DB row） ──────────

    public function test_読み込み時も上限を超えた定義は無効になる(): void
    {
        $this->makeGroup('deep', $this->nested(4), [['kind' => 'content_type', 'value' => 'news']]);

        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        $this->assertSame($before, $this->fieldKeys($this->registryWithOverlay(), 'news'));

        $group = $this->repository()->all()[0];
        $this->assertFalse($group->isValid());
        $this->assertStringContainsString('3', (string) $group->invalidReason);
    }

    public function test_読み込み時もjson_decodeの深さに依存しない(): void
    {
        // 深さ 15（9.5-B までは「Maximum stack depth exceeded」になっていた）
        $record = FieldGroupRecord::fromRow(
            ['status' => 'published', 'fields' => json_encode($this->nested(15), JSON_THROW_ON_ERROR)],
            [],
        );

        $this->assertFalse($record->isValid());
        // 原因が読める日本語で返る
        $this->assertStringNotContainsString('stack depth', (string) $record->invalidReason);
        $this->assertStringContainsString('3', (string) $record->invalidReason);
    }

    public function test_同梱schemaは上限に触れない(): void
    {
        $deepest = 0;
        $largest = 0;

        foreach ($this->registryWithoutOverlay()->declared() as $schema) {
            $deepest = max($deepest, $this->depthOf($schema->fields));
            $largest = max($largest, $this->totalFieldsOf($schema->fields));
        }

        $this->assertLessThanOrEqual(FieldGroupLimits::MAX_DEPTH, $deepest);
        $this->assertLessThanOrEqual(FieldGroupLimits::MAX_FIELDS, $largest);
    }

    /** @param  list<Field>  $fields */
    private function depthOf(array $fields, int $depth = 1): int
    {
        $max = $fields === [] ? 0 : $depth;

        foreach ($fields as $field) {
            if ($field->fields !== []) {
                $max = max($max, $this->depthOf($field->fields, $depth + 1));
            }
        }

        return $max;
    }

    /** @param  list<Field>  $fields */
    private function totalFieldsOf(array $fields): int
    {
        $count = 0;

        foreach ($fields as $field) {
            $count += 1 + $this->totalFieldsOf($field->fields);
        }

        return $count;
    }
}
