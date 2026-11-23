<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\FieldGroups\FieldGroupRecord;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 9.5-A CORRECTION — **有効な JSON の中の不正な形**（ADR-0015 fail closed）。
 *
 * `FieldGroupOverlayTest` が固定したのは「JSON として壊れている」「未知の型」など、
 * `Field::fromArray()` が `InvalidArgumentException` にしてくれる入力だけだった。
 *
 * 抜けていたのは **JSON としては有効で、値の形だけが違う**入力である。
 *
 * | 入力 | 直前の挙動 |
 * | --- | --- |
 * | `choices` が文字列 | `array_map()` が **TypeError**（fail closed しない） |
 * | `fields` が文字列 | `array_values()` が **TypeError** |
 * | `label` / `type` が配列 | `Array to string conversion` 警告 → Laravel では **ErrorException** |
 *
 * どれも DB の 1 行で `SchemaRegistry` を使う**全画面が 500 になる**。
 * ここで固定するのは「例外が画面まで漏れず、その束だけが無効になる」こと。
 */
final class FieldGroupInputBoundaryTest extends FieldGroupTestCase
{
    /**
     * @return array<string, array{0: list<array<string, mixed>>}>
     */
    public static function malformedShapes(): array
    {
        return [
            'choices が文字列' => [[['key' => 'x', 'type' => 'select', 'label' => 'x', 'choices' => 'not-a-list']]],
            'choices が object' => [[['key' => 'x', 'type' => 'select', 'label' => 'x', 'choices' => ['a' => 'b']]]],
            'choices の要素が配列' => [[['key' => 'x', 'type' => 'select', 'label' => 'x', 'choices' => [['deep']]]]],
            'fields が文字列' => [[['key' => 'x', 'type' => 'group', 'label' => 'x', 'fields' => 'nope']]],
            'fields が object' => [[['key' => 'x', 'type' => 'group', 'label' => 'x', 'fields' => ['a' => 'b']]]],
            'fields の要素が文字列' => [[['key' => 'x', 'type' => 'group', 'label' => 'x', 'fields' => ['nope']]]],
            'nested fields の形が不正' => [[[
                'key' => 'x', 'type' => 'group', 'label' => 'x',
                'fields' => [['key' => 'y', 'type' => 'select', 'label' => 'y', 'choices' => 'bad']],
            ]]],
            'nested の入れ子まで不正' => [[[
                'key' => 'x', 'type' => 'repeater', 'label' => 'x',
                'fields' => [[
                    'key' => 'y', 'type' => 'group', 'label' => 'y',
                    'fields' => [['key' => 'z', 'type' => 'text', 'label' => ['配列']]],
                ]],
            ]]],
            'type が配列' => [[['key' => 'x', 'type' => ['text'], 'label' => 'x']]],
            'key が配列' => [[['key' => ['x'], 'type' => 'text', 'label' => 'x']]],
            'label が配列' => [[['key' => 'x', 'type' => 'text', 'label' => ['配列']]]],
            'max が配列' => [[['key' => 'x', 'type' => 'text', 'label' => 'x', 'max' => ['a']]]],
            'validation が文字列' => [[['key' => 'x', 'type' => 'text', 'label' => 'x', 'validation' => 'max:10']]],
            // list の validation は保存できてしまうのに、SchemaRules は文字キーしか
            // 見ないので **全ルールが黙って無視される**（書いた人は効いていると誤解する）
            'validation が list' => [[['key' => 'x', 'type' => 'text', 'label' => 'x', 'validation' => [1, 2]]]],
            'validation が文字列の list' => [[['key' => 'x', 'type' => 'text', 'label' => 'x', 'validation' => ['max:10']]]],
            'nested の validation が list' => [[[
                'key' => 'x', 'type' => 'group', 'label' => 'x',
                'fields' => [['key' => 'y', 'type' => 'text', 'label' => 'y', 'validation' => [1, 2]]],
            ]]],
            'target が配列' => [[['key' => 'x', 'type' => 'relation', 'label' => 'x', 'target' => ['news']]]],
            'help が配列' => [[['key' => 'x', 'type' => 'text', 'label' => 'x', 'help' => ['a']]]],
            'required が配列' => [[['key' => 'x', 'type' => 'text', 'label' => 'x', 'required' => ['yes']]]],
            'key が無い' => [[['type' => 'text', 'label' => 'x']]],
            'type が無い' => [[['key' => 'x', 'label' => 'x']]],
            'field が list' => [[['x', 'y']]],
            'field が文字列' => [['just-a-string']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    #[DataProvider('malformedShapes')]
    public function test_有効なjsonでも形が違えば例外にならず無効になる(array $fields): void
    {
        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        $this->makeGroup('shape', $fields, [['kind' => 'content_type', 'value' => 'news']]);

        // 例外が漏れない = ここで落ちない
        $this->assertSame($before, $this->fieldKeys($this->registryWithOverlay(), 'news'));

        $group = $this->repository()->all()[0];
        $this->assertFalse($group->isValid());
        $this->assertFalse($group->contributesFields());
        $this->assertSame([], $group->fields);
        $this->assertNotNull($group->invalidReason);
        $this->assertNotSame('', $group->invalidReason);
    }

    /** 直接呼んでも throw しない（レビューの再現手順そのもの） */
    public function test_from_rowはどの形でも投げない(): void
    {
        foreach (self::malformedShapes() as $name => [$fields]) {
            $record = FieldGroupRecord::fromRow(
                ['status' => 'published', 'fields' => json_encode($fields, JSON_THROW_ON_ERROR)],
                [],
            );

            $this->assertFalse($record->isValid(), "[{$name}] が有効と判定されている");
        }
    }

    /** 画面まで漏れず、行として読める形で出る */
    public function test_不正な形の束は一覧に無効として出る(): void
    {
        $this->makeGroup(
            'shape',
            [['key' => 'x', 'type' => 'select', 'label' => 'x', 'choices' => 'not-a-list']],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $this->actingAs($this->owner())
            ->get('/admin/field-groups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.valid', false)
                ->where('groups.0.active', false)
                ->where('groups.0.fieldCount', 0)
                ->where('groups.0.invalidReason', fn (?string $r): bool => $r !== null && $r !== ''));

        // 割当先の投稿タイプの編集画面も落ちない
        $this->actingAs($this->owner())->get('/admin/news')->assertOk();
    }

    /** 理由に値や秘密を載せない（監査規約と同じ作法） */
    public function test_invalid_reasonに値を載せない(): void
    {
        /*
         * 値は **断片から組み立てる**（`user-invitation.isolated.spec.ts` と同じ作法）。
         * 秘密スキャンは `$secret = "長い固定文字列"` を秘密の直書きとして拾うため、
         * 偽データでも同じ形にしない。
         */
        $confidential = implode('-', ['must', 'not', 'appear', 'in', 'any', 'message']);

        $this->makeGroup(
            'leak',
            [['key' => 'x', 'type' => 'select', 'label' => 'x', 'choices' => $confidential]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $reason = (string) $this->repository()->all()[0]->invalidReason;

        $this->assertStringNotContainsString($confidential, $reason);
        // どこが悪いかは分かる
        $this->assertStringContainsString('choices', $reason);
    }

    /** 正しい形は今までどおり通る（締めすぎていないこと） */
    public function test_正しい形は今までどおり受理される(): void
    {
        $before = $this->fieldKeys($this->registryWithoutOverlay(), 'news');

        $this->makeGroup(
            'valid',
            [
                ['key' => 'spec_pick', 'type' => 'select', 'label' => '選択', 'choices' => ['a', 'b'], 'required' => true],
                ['key' => 'spec_num', 'type' => 'number', 'label' => '数', 'max' => 10, 'validation' => ['min' => 0]],
                [
                    'key' => 'spec_rows', 'type' => 'repeater', 'label' => '行',
                    'fields' => [['key' => 'row_title', 'type' => 'text', 'label' => '見出し']],
                ],
                ['key' => 'spec_rel', 'type' => 'relation', 'label' => '参照', 'target' => 'works'],
                ['key' => 'spec_id', 'type' => 'text', 'label' => 'ID 付き', 'id' => '01JXXXXXXXXXXXXXXXXXXXXXXX'],
            ],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $group = $this->repository()->all()[0];
        $this->assertTrue($group->isValid(), (string) $group->invalidReason);

        $this->assertSame(
            [...$before, 'spec_pick', 'spec_num', 'spec_rows', 'spec_rel', 'spec_id'],
            $this->fieldKeys($this->registryWithOverlay(), 'news'),
        );
    }

    /**
     * `validation` は **JSON の object だけ**受理する（CORRECTION #2）。
     *
     * `json_decode($json, true)` は `{}` と `[]` をどちらも空配列にするため、
     * **空だけは区別できない**。空は「ルールなし」で害が無いので後方互換として通す。
     * 非空の list は通さない — 保存はできるのに `SchemaRules` が文字キーしか
     * 見ないので、全ルールが黙って無視されるため。
     */
    public function test_validationはobjectだけ受理し空は通す(): void
    {
        $cases = [
            // [宣言, 有効か]
            'object' => [['min' => 0, 'max' => 10], true],
            '空 object（= 空配列）' => [[], true],
            'null' => [null, true],
            '非空 list' => [[1, 2], false],
            '文字列の list' => [['max:10'], false],
            '文字列' => ['max:10', false],
        ];

        foreach ($cases as $name => [$validation, $valid]) {
            $record = FieldGroupRecord::fromRow(
                [
                    'status' => 'published',
                    'fields' => json_encode(
                        [['key' => 'x', 'type' => 'text', 'label' => 'x', 'validation' => $validation]],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
                [],
            );

            $this->assertSame($valid, $record->isValid(), "[{$name}] の判定が違う（{$record->invalidReason}）");
        }
    }

    /** 数値は JSON で数値のまま書けてよい（文字列以外を一律に弾いていない） */
    public function test_数値のlabelやchoicesも受理される(): void
    {
        $this->makeGroup(
            'numeric',
            [['key' => 'spec_year', 'type' => 'select', 'label' => 2026, 'choices' => [2026, 2027]]],
            [['kind' => 'content_type', 'value' => 'news']],
        );

        $this->assertTrue($this->repository()->all()[0]->isValid());
        $this->assertContains('spec_year', $this->fieldKeys($this->registryWithOverlay(), 'news'));
    }
}
