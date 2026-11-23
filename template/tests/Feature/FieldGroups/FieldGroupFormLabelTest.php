<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\FieldGroups\ListFieldGroups;
use AgentCms\Core\Application\OperationContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 割当先を、**フォームの名前で呼ぶ**（WP 一周 6 周目 2026-09-03）。
 *
 * ## 何が出ていたか
 *
 * 入れたての生成 project でカスタムフィールドの一覧を開くと、
 * 同梱の「お問い合わせ」の割当先がこう出る:
 *
 * > フォーム / contact **（未解決）**
 *
 * **壊れていない。** フォーム `contact` は在るし、割り当ても正しく、
 * 実際に送信もできる。それでも初見では「何かおかしい」と読む —
 * しかも**入れたてで唯一ある束がこれ**である。
 *
 * ## 原因
 *
 * `FieldGroupPresenter::targetLabel()` が **投稿タイプ以外は必ず `null`** を返し、
 * 画面は「`label` が無い ＝ 未解決」と描いていた。
 * つまりフォームに割り当てた束は、**構造上いつでも未解決**だった。
 *
 * ここで直すのは呼び名だけで、割り当ての判定には触れていない。
 */
final class FieldGroupFormLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_フォームに割り当てた束は_フォームの名前で出る(): void
    {
        $this->formWithGroup('shiryo', '資料請求');

        $location = $this->firstLocation();

        $this->assertSame('form', $location['kind']);
        $this->assertSame('フォーム', $location['kindLabel']);
        $this->assertSame(
            '資料請求',
            $location['label'],
            'フォームに割り当てた束が「未解決」のまま出ている',
        );
    }

    /**
     * 🔴 **無いものを在るように見せない。**
     *
     * 消えたフォームを指したままの束は、これまでどおり「未解決」でよい。
     * ここで嘘の名前を作ると、消えたことに気づけなくなる。
     */
    public function test_無いフォームを指していれば未解決のまま(): void
    {
        $this->formWithGroup('shiryo', '資料請求');

        DB::table('field_group_locations')->update(['value' => 'nai-form']);

        $this->assertNull($this->firstLocation()['label']);
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @return array{kind: string, kindLabel: string|null, value: string, label: string|null} */
    private function firstLocation(): array
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $result = app(ListFieldGroups::class)->execute(
            OperationContext::admin((int) $admin->getKey(), (string) $admin->name),
        );

        $this->assertTrue($result->isSuccess());

        /** @var list<array<string, mixed>> $groups */
        $groups = $result->data['groups'] ?? [];

        $this->assertNotEmpty($groups, 'カスタムフィールドが 1 つも無い');

        /** @var list<array{kind: string, kindLabel: string|null, value: string, label: string|null}> $locations */
        $locations = $groups[0]['locations'] ?? [];

        $this->assertNotEmpty($locations, '割当先が読めていない');

        return $locations[0];
    }

    private function formWithGroup(string $key, string $name): void
    {
        $groupId = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.$key,
            'label' => $name,
            'sort_order' => 1,
            'status' => 'published',
            'fields' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => $groupId,
            'kind' => 'form',
            'value' => $key,
        ]);

        DB::table('forms')->insert([
            'key' => $key,
            'name' => $name,
            'field_group_id' => $groupId,
            'status' => 'published',
            'confirm_step' => false,
            'submit_label' => '送信',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
