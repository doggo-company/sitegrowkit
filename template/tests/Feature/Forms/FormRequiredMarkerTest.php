<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 公開フォームが、**必須の項目にそう書く**（WP 一周 7 周目 2026-09-03 / 効くもの ②）。
 *
 * ## 何が起きていたか
 *
 * 管理画面で「必須にする」にチェックし、確認ダイアログも正しく出る。
 * ところが公開ページはこうだった:
 *
 * ```
 * 飼い主のお名前
 * （この欄は入力しないでください）
 * 確認画面へ
 * ```
 *
 * **「必須」の印がどこにも無い。** しかも案件の意匠の view は `novalidate` なので、
 * ブラウザも止めない — **送信を押して、サーバーに叱られて初めて分かる**。
 *
 * ## 揃っていなかった
 *
 * kit 既定（`forms/partials/field.blade.php`）は**出していた**。
 * コメント欄（`components/comments.blade.php`）も出していた。
 * 出していないのは**案件の意匠の view**（`forms/imported.blade.php`）だけで、
 * しかも同梱の見本サイトはそちらを使う — **買った人が最初に見るのは出ない側**である。
 *
 * ## ここで固定すること
 *
 * **どちらの view でも出る。** 片方だけ直しても、案件によって出たり出なかったりする。
 */
final class FormRequiredMarkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_案件の意匠でも必須の項目にそう書く(): void
    {
        $form = $this->form('toiawase');

        $response = $this->get("/forms/{$form->key}");

        $response->assertOk();

        // 見本サイトの意匠（`forms/imported.blade.php`）が使われていること
        $response->assertSee('contact-form', false);

        $html = $response->getContent() ?: '';

        $this->assertStringContainsString(
            '必須',
            $this->labelFor($html, 'field_hissu'),
            '必須の項目に「必須」と書いていない',
        );
    }

    /** 🔴 **任意の項目に「必須」と書かない**（書いたら嘘になる） */
    public function test_任意の項目には書かない(): void
    {
        $form = $this->form('toiawase');

        $html = ($this->get("/forms/{$form->key}")->getContent() ?: '');

        $this->assertStringNotContainsString(
            '必須',
            $this->labelFor($html, 'field_nini'),
            '任意の項目に「必須」と書いている',
        );
    }

    /**
     * 印は**読み上げにも乗る**。
     *
     * `aria-hidden` で隠したり、CSS だけで出したりすると、
     * 目で見ている人にしか届かない。`<label>` の中の文字にする。
     */
    public function test_印は_labelの中にある(): void
    {
        $form = $this->form('toiawase');

        $html = ($this->get("/forms/{$form->key}")->getContent() ?: '');

        $label = $this->labelFor($html, 'field_hissu');

        $this->assertStringContainsString('必須', $label);
        $this->assertStringNotContainsString('aria-hidden', $label);
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** `for="f-<key>"` の label をそのまま切り出す */
    private function labelFor(string $html, string $key): string
    {
        preg_match('#<label[^>]*for="f-'.preg_quote($key, '#').'".*?</label>#s', $html, $m);

        $this->assertNotEmpty($m, "label が見つからない: {$key}");

        return $m[0];
    }

    private function form(string $key): Form
    {
        $groupId = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.strtolower(substr($groupId, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                [
                    'id' => (string) Str::ulid(),
                    'key' => 'field_hissu',
                    'type' => 'text',
                    'label' => '飼い主のお名前',
                    'required' => true,
                ],
                [
                    'id' => (string) Str::ulid(),
                    'key' => 'field_nini',
                    'type' => 'text',
                    'label' => 'ご住所',
                    'required' => false,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => $groupId,
            'kind' => 'form',
            'value' => $key,
        ]);

        $id = DB::table('forms')->insertGetId([
            'key' => $key,
            'name' => '健康診断のご予約',
            'field_group_id' => $groupId,
            'status' => 'published',
            'confirm_step' => true,
            'submit_label' => '確認画面へ',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Form::query()->findOrFail($id);
    }
}
