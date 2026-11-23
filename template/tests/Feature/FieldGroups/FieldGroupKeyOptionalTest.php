<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\FieldGroups\CreateFieldGroup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Result;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * カスタムフィールドも、**名前だけで作れる**（WP 一周 10 周目 2026-09-04 / 効くもの ③）。
 *
 * ## 何が起きていたか
 *
 * 「カスタムフィールドを追加」で表示名に「診療メニュー」、項目に「料金」とだけ入れて
 * 作成を押すと、**束と項目の両方**で止まった。
 *
 * ```
 * キーを入力してください
 * 項目のキーを入力してください
 * ```
 *
 * 6 周目に分類へ入れた手当て（#20 / `CreateTaxonomyDefinition`）が、
 * **ACF 相当のこちらには来ていなかった**。WordPress から来た人が最初に触る場所である。
 *
 * ## どうするか
 *
 * 分類と同じ形にする。空なら表示名から作り、日本語だけの名前からは通し番号へ倒す。
 * **自分で書いたキーには触らない**（形が違うときも黙って直さない — 直すと
 * 「書いたものと違うキーで作られた」になる）。
 */
final class FieldGroupKeyOptionalTest extends TestCase
{
    use RefreshDatabase;

    private function context(): OperationContext
    {
        $owner = User::factory()->create(['role' => Role::Owner->value]);

        return OperationContext::admin((int) $owner->getKey(), (string) $owner->name);
    }

    /** @param array<string, mixed> $input */
    private function create(array $input): Result
    {
        return app(CreateFieldGroup::class)->execute($input, $this->context());
    }

    /** 🔴 これが本体。日本語の名前だけで作れる */
    public function test_日本語の名前だけで作れる(): void
    {
        $result = $this->create([
            'label' => '診療メニュー',
            'fields' => [['label' => '料金', 'type' => 'text']],
            'locations' => [],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame('field_group_1', $result->data['fieldGroup']['key'] ?? null);
    }

    /** 英字の名前は、名前どおりのキーになる（通し番号へ落とさない） */
    public function test_英字の名前はそのままキーになる(): void
    {
        $result = $this->create([
            'label' => 'Product Spec',
            'fields' => [['label' => 'Price', 'type' => 'text']],
            'locations' => [],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame('product_spec', $result->data['fieldGroup']['key'] ?? null);
    }

    /** 自分で書いたキーには触らない */
    public function test_書いたキーはそのまま(): void
    {
        $result = $this->create([
            'key' => 'clinic_menu',
            'label' => '診療メニュー',
            'fields' => [['key' => 'price', 'label' => '料金', 'type' => 'text']],
            'locations' => [],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame('clinic_menu', $result->data['fieldGroup']['key'] ?? null);
    }

    /** 形が違うキーは黙って直さない（書いたものと違うキーで作らない） */
    public function test_形が違うキーは黙って直さない(): void
    {
        $result = $this->create([
            'key' => 'Clinic-Menu',
            'label' => '診療メニュー',
            'fields' => [['label' => '料金', 'type' => 'text']],
            'locations' => [],
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertArrayHasKey('key', $result->errors ?? []);
    }

    /** 束の中で重ならないように、項目も番号が進む */
    public function test_項目の番号は束の中で重ならない(): void
    {
        $result = $this->create([
            'label' => '診療メニュー',
            'fields' => [
                ['label' => '料金', 'type' => 'text'],
                ['label' => '所要時間', 'type' => 'text'],
            ],
            'locations' => [],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors ?? [], JSON_UNESCAPED_UNICODE));

        $stored = json_decode(
            (string) DB::table('field_groups')
                ->where('id', $result->data['fieldGroup']['id'])
                ->value('fields'),
            true,
        );

        $this->assertSame(['field_1', 'field_2'], array_column((array) $stored, 'key'));
    }

    /** 画面も「任意」と言う（分類と同じ言い方） */
    public function test_画面はキーが任意だと言う(): void
    {
        $source = File::get(base_path('resources/js/components/field-group/FieldGroupForm.vue'));

        $this->assertStringContainsString('キー（任意）', $source);
        $this->assertStringContainsString('空のままにすると名前から作ります', $source);
    }
}
