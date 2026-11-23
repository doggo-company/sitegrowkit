<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Application\Forms\ExportSubmissions;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use App\Models\User;
use App\Support\OperationCatalog;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * フォームの管理画面（Phase 9.12 / STAGE 25D・SPEC §D）。
 *
 * ここで固定するのは **誰が何を触れるか**と、**書き出しが監査に残ること**、
 * そして **CSV の見出しが受信のものになる**ことである。
 */
final class FormAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    // ── 画面 ────────────────────────────────────────────────

    public function test_一覧と編集が開く(): void
    {
        $this->form('contact-us');

        $this->actingAs($this->owner)->get('/admin/forms')->assertOk();
        $this->actingAs($this->owner)->get('/admin/forms/contact-us')->assertOk();
        $this->actingAs($this->owner)->get('/admin/forms/new')->assertOk();
    }

    public function test_newは編集画面に食われない(): void
    {
        // `forms/{key}` を先に置くと、この URL が「key = new の編集」になる
        $this->actingAs($this->owner)->get('/admin/forms/new')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/forms/Edit')->where('form', null));
    }

    public function test_作成して編集できる(): void
    {
        $group = $this->fieldGroup();

        $this->actingAs($this->owner)
            ->post('/admin/forms', ['key' => 'contact-us', 'name' => 'お問い合わせ', 'field_group_id' => $group])
            ->assertRedirect('/admin/forms/contact-us');

        $this->actingAs($this->owner)
            ->patch('/admin/forms/contact-us', [
                'name' => '変更後', 'field_group_id' => $group,
                'status' => 'published', 'lock_version' => 0,
            ])
            ->assertRedirect();

        $this->assertSame('変更後', Form::query()->firstOrFail()->name);
    }

    /**
     * 通知先の複数対応と**入力検証は同じ 1 回**（R-11 ⑤）。
     * 片方だけ入れると「書けるのに届かない」が残る。
     */
    public function test_通知先は複数書けて正規化される(): void
    {
        $group = $this->fieldGroup();

        $this->actingAs($this->owner)
            ->post('/admin/forms', [
                'key' => 'contact-us', 'name' => 'お問い合わせ', 'field_group_id' => $group,
                'notify_to' => "ops@example.test\n sales@example.test \nops@example.test",
            ])
            ->assertRedirect('/admin/forms/contact-us');

        // 保存はカンマ区切り 1 カラム。重複は落ちる
        $this->assertSame(
            'ops@example.test,sales@example.test',
            Form::query()->firstOrFail()->notify_to,
        );
    }

    public function test_メールアドレスとして読めない通知先は断る(): void
    {
        $group = $this->fieldGroup();

        $this->actingAs($this->owner)
            ->post('/admin/forms', [
                'key' => 'contact-us', 'name' => 'お問い合わせ', 'field_group_id' => $group,
                'notify_to' => "ops@example.test\nnot-an-address",
            ])
            ->assertSessionHasErrors('notify_to');

        // 断ったのだから、1 件も作らない
        $this->assertSame(0, Form::query()->count());
    }

    /**
     * 桁の上限は**黙って切らない**（指摘 35）。
     *
     * 列は `varchar(255)`（`create_form_tables` の `string('notify_to', 255)`）。
     * MySQL の strict mode は溢れた INSERT を**エラーにする**（非 strict なら黙って切る）。
     * どちらにしても利用者から見れば「保存したのに届かない宛先がある」なので、
     * **入口で断って、超えたと言う**。
     */
    public function test_通知先が桁を超えたら断る(): void
    {
        $group = $this->fieldGroup();

        // 40 文字 × 7 = 280 文字（区切りを入れると 286）。どれも綴りは正しい
        $addresses = [];

        for ($i = 0; $i < 7; $i++) {
            $addresses[] = str_pad("ops{$i}", 28, 'x').'@example.test';
        }

        $this->actingAs($this->owner)
            ->post('/admin/forms', [
                'key' => 'contact-us', 'name' => 'お問い合わせ', 'field_group_id' => $group,
                'notify_to' => implode("\n", $addresses),
            ])
            ->assertSessionHasErrors('notify_to');

        $this->assertSame(0, Form::query()->count());
    }

    /** 上限のすぐ内側は通る（境界の対照実験） */
    public function test_桁の内側なら通る(): void
    {
        $group = $this->fieldGroup();

        $this->actingAs($this->owner)
            ->post('/admin/forms', [
                'key' => 'contact-us', 'name' => 'お問い合わせ', 'field_group_id' => $group,
                'notify_to' => "ops@example.test\nsales@example.test",
            ])
            ->assertSessionHasNoErrors();

        $stored = (string) Form::query()->firstOrFail()->notify_to;

        $this->assertLessThanOrEqual(255, mb_strlen($stored));
    }

    /**
     * 識別子は**書かせない**（R-11 ①）。空なら名前から作る。
     */
    public function test_識別子が空なら名前から作る(): void
    {
        $group = $this->fieldGroup();

        $this->actingAs($this->owner)
            ->post('/admin/forms', ['name' => 'Contact Us', 'field_group_id' => $group])
            ->assertRedirect('/admin/forms/contact-us');

        $this->assertSame('contact-us', Form::query()->firstOrFail()->key);
    }

    /**
     * **無理に音訳しない。** 日本語からは 1 文字も取れないので通し番号へ落とす
     * （読み方を推測すると、利用者の意図と違う URL が配られる）。
     */
    public function test_日本語の名前なら通し番号へ落とす(): void
    {
        $group = $this->fieldGroup();

        $this->actingAs($this->owner)
            ->post('/admin/forms', ['name' => 'お問い合わせ', 'field_group_id' => $group])
            ->assertRedirect('/admin/forms/form-1');

        $this->assertSame('form-1', Form::query()->firstOrFail()->key);
    }

    /**
     * **手で打って断られるものを、作りもしない**（監査 指摘 41）。
     *
     * 以前は `C` を手で打つと「2〜64 文字」で断られるのに、
     * 名前から作ると通って `/forms/c` が配られていた。
     * **識別子はあとから変えられない**ので、ずれた分がそのまま配られる。
     */
    public function test_手で打って断られる識別子は自動でも作らない(): void
    {
        $group = $this->fieldGroup();

        // 手打ち: 断る
        $this->actingAs($this->owner)
            ->post('/admin/forms', ['key' => 'c', 'name' => 'C', 'field_group_id' => $group])
            ->assertSessionHasErrors('key');

        $this->assertSame(0, Form::query()->count());

        // 自動: 同じ規則なので、通し番号へ落ちる（`c` にはならない）
        $this->actingAs($this->owner)
            ->post('/admin/forms', ['name' => 'C', 'field_group_id' => $group])
            ->assertRedirect('/admin/forms/form-1');

        $this->assertSame('form-1', Form::query()->firstOrFail()->key);
    }

    /** 識別子は公開 URL で、あとから変えられない。**既に在るものを奪わない** */
    public function test_同じ名前が続いたら連番になる(): void
    {
        $group = $this->fieldGroup();

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->owner)
                ->post('/admin/forms', ['name' => 'Contact Us', 'field_group_id' => $group]);
        }

        $this->assertSame(
            ['contact-us', 'contact-us-1'],
            Form::query()->orderBy('id')->pluck('key')->all(),
        );
    }

    public function test_識別子はあとから変えられない(): void
    {
        $this->form('contact-us');

        $this->actingAs($this->owner)
            ->patch('/admin/forms/contact-us', ['key' => 'other', 'name' => 'x', 'lock_version' => 0])
            ->assertSessionHasErrors('key');
    }

    public function test_受信が残っているフォームは消せない(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form);

        $this->actingAs($this->owner)
            ->post('/admin/forms/contact-us/delete')
            // 「外部キー制約違反」ではなく、次の手が分かる文面を返す
            ->assertSessionHas('error');

        $this->assertSame(1, Form::query()->count());
    }

    // ── 受信 ────────────────────────────────────────────────

    public function test_受信の一覧と詳細が開く(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form);

        $this->actingAs($this->owner)
            ->get('/admin/forms/contact-us/submissions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/forms/Submissions')->has('submissions', 1));

        $this->actingAs($this->owner)
            ->get("/admin/forms/contact-us/submissions/{$submission->getKey()}")
            ->assertOk();
    }

    public function test_別のフォームの受信は覗けない(): void
    {
        $a = $this->form('form-a');
        $b = $this->form('form-b');
        $submission = $this->submission($a);

        // URL のフォームと中身が食い違っていたら見せない
        $this->actingAs($this->owner)
            ->get("/admin/forms/{$b->key}/submissions/{$submission->getKey()}")
            ->assertNotFound();
    }

    public function test_状態だけ変えられる(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form);

        $this->actingAs($this->owner)
            ->patch("/admin/forms/contact-us/submissions/{$submission->getKey()}", ['status' => 'completed'])
            ->assertRedirect();

        $submission->refresh();

        $this->assertSame('completed', $submission->status);
        // 届いた本文は書き換わっていない
        $this->assertSame('山田太郎', $submission->values()['name']);
    }

    /**
     * **受信を消せる**（R-12 / 2026-08-30）。
     *
     * 🔴 受信は個人情報である。「消してほしい」と言われたときに応じる手段が
     * **route ごと無かった**（読む・書き出す・状態を変えるだけ）。
     * 消せないと、依頼に応えるには DB を直接触るしかない。
     */
    public function test_受信を消せる(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form);
        $id = $submission->getKey();

        // 1 回目は**確認を求めて、まだ消さない**（戻せない操作だから）
        $this->actingAs($this->owner)
            ->post("/admin/forms/contact-us/submissions/{$id}/delete")
            ->assertRedirect();

        $this->assertNotNull(
            FormSubmission::query()->find($id),
            '確認を求める前に消している',
        );

        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $token = (string) $flash['confirmation']['token'];

        $this->actingAs($this->owner)
            ->post("/admin/forms/contact-us/submissions/{$id}/delete", ['confirmation_token' => $token])
            ->assertRedirect('/admin/forms/contact-us/submissions');

        $this->assertNull(
            FormSubmission::query()->find($id),
            '確認したのに消えていない',
        );
    }

    /** **読むだけの人には消させない**（`contact.write` の軸で守る） */
    public function test_読むだけの役割は受信を消せない(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form);
        $id = $submission->getKey();

        $viewer = User::factory()->create(['role' => Role::Viewer->value]);

        $this->actingAs($viewer)
            ->post("/admin/forms/contact-us/submissions/{$id}/delete")
            ->assertForbidden();

        $this->assertNotNull(FormSubmission::query()->find($id), '権限が無いのに消えている');
    }

    public function test_知らない状態は受けない(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form);

        $this->actingAs($this->owner)
            ->patch("/admin/forms/contact-us/submissions/{$submission->getKey()}", ['status' => 'shipped'])
            ->assertSessionHasErrors('status');
    }

    // ── CSV（OG7） ──────────────────────────────────────────

    public function test_csvの見出しは受信のものになる(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form, ['name' => '山田太郎'], ['name' => 'お名前']);

        // 定義が改名されても、届いたときの見出しで出る
        DB::table('field_groups')->update([
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => '氏名（変更後）'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->actingAs($this->owner)->get('/admin/forms/contact-us/submissions/export');

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringStartsWith(ExportSubmissions::BOM, $csv);
        $this->assertStringContainsString('お名前', $csv);
        $this->assertStringNotContainsString('氏名（変更後）', $csv);
    }

    public function test_csvはipを出さない(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form, ['name' => '山田太郎'], ['name' => 'お名前'], ip: str_repeat('a', 64));

        $csv = $this->actingAs($this->owner)
            ->get('/admin/forms/contact-us/submissions/export')
            ->streamedContent();

        // 画面に出さないものを、書き出しでだけ出す理由が無い
        $this->assertStringNotContainsString(str_repeat('a', 64), $csv);
    }

    public function test_csvは数式として実行させない(): void
    {
        $form = $this->form('contact-us');
        // 中身は未認証の相手が書いたもの
        $this->submission($form, ['name' => '=1+1'], ['name' => 'お名前']);

        $csv = $this->actingAs($this->owner)
            ->get('/admin/forms/contact-us/submissions/export')
            ->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_csvの持ち出しは監査に残る(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form);

        $this->actingAs($this->owner)->get('/admin/forms/contact-us/submissions/export')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'form.submission.export',
            'resource_type' => 'form',
            'resource_id' => 'contact-us',
            'result' => 'success',
        ]);
    }

    // ── 権限（§E.5） ────────────────────────────────────────

    public function test_フォームの定義はsettings_manageが要る(): void
    {
        $this->form('contact-us');
        $editor = User::factory()->create(['role' => Role::Editor->value]);

        // 見るのは ContentRead で足りるが、変えるのは SettingsManage
        $this->actingAs($editor)->get('/admin/forms')->assertOk();
        $this->actingAs($editor)
            ->patch('/admin/forms/contact-us', ['name' => 'x', 'lock_version' => 0])
            ->assertForbidden();
    }

    public function test_受信はaiへ公開しない(): void
    {
        $ids = OperationCatalog::ids();

        // 本文と連絡先そのもの。404 の記録すら出さない基準に合わせる（OG8）
        $this->assertNotContains('form.submission.list', $ids);
        $this->assertNotContains('form.submission.get', $ids);
        $this->assertNotContains('form.submission.export', $ids);
        $this->assertNotContains('form.submission.status', $ids);
        // 書き込みも出さない。外部入力の口の形を AI が変えてよい理由が無い
        $this->assertNotContains('form.save', $ids);
        $this->assertNotContains('form.delete', $ids);
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function form(string $key, string $status = 'published'): Form
    {
        $id = DB::table('forms')->insertGetId([
            'key' => $key, 'name' => 'お問い合わせ',
            'field_group_id' => $this->fieldGroup(),
            'status' => $status, 'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Form::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels
     */
    private function submission(
        Form $form,
        array $values = ['name' => '山田太郎'],
        array $labels = ['name' => 'お名前'],
        ?string $ip = null,
    ): FormSubmission {
        $id = DB::table('form_submissions')->insertGetId([
            'form_id' => $form->getKey(),
            'data' => json_encode($values + [FormSubmission::LABELS_KEY => FormSubmission::labelPairs($labels)], JSON_UNESCAPED_UNICODE),
            'status' => 'received',
            'ip_hash' => $ip,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return FormSubmission::query()->findOrFail($id);
    }

    private function fieldGroup(): string
    {
        $existing = DB::table('field_groups')->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => 'form_'.strtolower(substr($id, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
                ['id' => (string) Str::ulid(), 'key' => 'email', 'type' => 'email', 'label' => 'メールアドレス'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => $id, 'kind' => 'form', 'value' => 'contact-us',
        ]);

        app()->forgetScopedInstances();

        return $id;
    }
}
