<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **逃げ道の識別子も、規則の中に収まる**（監査の参考指摘 → 実測で再現）。
 *
 * 同じ名前が 999 個ぶつかると、最後は時刻の下 6 桁を足して逃げる。
 * 足すぶんだけ base を削っていなかったので、base が 60 文字のときに
 * **67 文字**（`60 + "-" + 6 桁`）になっていた — 規則（64 文字）にも
 * `forms.key` の桁（64）にも収まらない。
 *
 * 実測（この試験を直す前に取った）: `/admin/forms` へ POST して **67 文字の key が保存された**。
 * SQLite は黙って保存するが、**MySQL では例外になる**。そのとき利用者に出るのは
 * unique 違反の文言で、**実態（長すぎる）と違う顔**になる。
 */
final class FormKeyOverflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_999個ぶつかっても規則に収まる識別子を作る(): void
    {
        $groupId = $this->fieldGroup();
        $base = str_repeat('a', FormKey::MAX_LENGTH);

        // base 本体 + `-1` 〜 `-999` を埋める（次の 1 件が逃げ道へ落ちる）
        $keys = array_merge(
            [$base],
            array_map(static fn (int $n): string => FormKey::suffixed($base, (string) $n), range(1, 999)),
        );

        foreach (array_chunk($keys, 200) as $chunk) {
            DB::table('forms')->insert(array_map(static fn (string $key): array => [
                'key' => $key, 'name' => 'x', 'field_group_id' => $groupId, 'status' => 'draft',
                'confirm_step' => true, 'submit_label' => '送信', 'lock_version' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }

        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->post('/admin/forms', ['name' => str_repeat('a', 80), 'field_group_id' => $groupId])
            ->assertSessionHasNoErrors();

        $created = (string) Form::query()->orderByDesc('id')->value('key');

        $this->assertLessThanOrEqual(FormKey::KEY_MAX, mb_strlen($created));
        $this->assertMatchesRegularExpression(FormKey::PATTERN, $created);
    }

    /** 削った端に区切りを残さない（`slugify()` と同じ理由） */
    public function test_削った端に区切りを残さない(): void
    {
        // `ab-` × 20（60 文字）を削ると端が `-` になる。そこを残すと `…ab--123456`
        $suffixed = FormKey::suffixed(str_repeat('ab-', 20), '123456');

        $this->assertStringEndsWith('ab-123456', $suffixed);
        $this->assertStringNotContainsString('--', $suffixed);
        $this->assertLessThanOrEqual(FormKey::KEY_MAX, mb_strlen($suffixed));
    }

    private function fieldGroup(): string
    {
        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => 'form_'.strtolower(substr($id, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
