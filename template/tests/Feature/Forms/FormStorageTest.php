<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Application\Forms\DeleteForm;
use AgentCms\Core\Application\Forms\GetForm;
use AgentCms\Core\Application\Forms\GetSubmission;
use AgentCms\Core\Application\Forms\ListForms;
use AgentCms\Core\Application\Forms\ListSubmissions;
use AgentCms\Core\Application\Forms\SaveForm;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormStatus;
use AgentCms\Core\Forms\FormSubmission;
use App\Models\User;
use App\Support\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * フォームの storage と定義の扱い（Phase 9.12 / STAGE 25B）。
 *
 * ここで固定するのは **保存される形**と、**公開してよい状態かの判定**である。
 * 公開面の送信は 25C。
 */
final class FormStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner->value]);
    }

    // ── 表の形 ─────────────────────────────────────────────

    public function test_表と索引ができている(): void
    {
        $this->assertTrue(Schema::hasTable('forms'));
        $this->assertTrue(Schema::hasTable('form_submissions'));

        $forms = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('forms'),
        );

        $this->assertContains('uq_form_key', $forms);

        $submissions = array_map(
            static fn (array $i): string => (string) $i['name'],
            Schema::getIndexes('form_submissions'),
        );

        $this->assertContains('idx_submission_form', $submissions);
    }

    public function test_downは行が残っていれば止まる(): void
    {
        $this->form('contact-us');

        $this->assertThrows(fn () => $this->migration()->down(), \RuntimeException::class);

        $this->assertTrue(Schema::hasTable('forms'));
    }

    public function test_downは空なら通って戻せる(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('form_submissions'));
        $this->assertFalse(Schema::hasTable('forms'));

        // 弾いた件数の表は書き込み口で共用しているので、フォームと一緒に落ちない
        $this->assertTrue(Schema::hasTable('spam_blocks'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('forms'));
    }

    public function test_受信が残っているフォームはdbも消させない(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form, ['name' => '山田']);

        // 問い合わせの中身だけが宙に浮く状態を作らない
        $this->assertThrows(
            fn () => DB::table('forms')->where('id', $form->getKey())->delete(),
            QueryException::class,
        );
    }

    // ── 保存 ────────────────────────────────────────────────

    public function test_作れる(): void
    {
        $result = $this->save(['key' => 'contact-us', 'name' => 'お問い合わせ']);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));

        $form = Form::query()->firstOrFail();

        $this->assertSame('contact-us', $form->key);
        // 作りかけは下書きから始まる
        $this->assertSame(FormStatus::Draft->value, $form->status);
        $this->assertTrue($form->confirm_step);
    }

    public function test_識別子の形を守らせる(): void
    {
        // 大文字も断る（黙って小文字へ直すと、入力と違う URL が配られる）
        foreach (['A-BC', '1form', 'a', 'form key', 'フォーム', str_repeat('a', 80)] as $key) {
            $result = $this->save(['key' => $key, 'name' => 'x']);

            $this->assertSame(OperationStatus::ValidationError, $result->status, $key);
            $this->assertArrayHasKey('key', $result->errors, $key);
        }
    }

    public function test_同じ識別子は2つ作れない(): void
    {
        $this->save(['key' => 'contact-us', 'name' => 'A']);

        $result = $this->save(['key' => 'contact-us', 'name' => 'B']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(1, Form::query()->count());
    }

    public function test_識別子はあとから変えられない(): void
    {
        $this->save(['key' => 'contact-us', 'name' => 'A']);

        // 配ってしまった URL を死なせない
        $result = app(SaveForm::class)->execute(
            ['key' => 'renamed', 'name' => 'A'],
            $this->context(),
            'contact-us',
            0,
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('key', $result->errors);
        $this->assertSame('contact-us', Form::query()->value('key'));
    }

    public function test_名前は変えられる(): void
    {
        $this->save(['key' => 'contact-us', 'name' => '旧']);

        $result = app(SaveForm::class)->execute(
            ['name' => '新'],
            $this->context(),
            'contact-us',
            0,
        );

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame('新', Form::query()->value('name'));
        $this->assertSame(1, (int) Form::query()->value('lock_version'));
    }

    public function test_古い版では保存できない(): void
    {
        $this->save(['key' => 'contact-us', 'name' => 'A']);

        app(SaveForm::class)->execute(['name' => 'B'], $this->context(), 'contact-us', 0);
        $stale = app(SaveForm::class)->execute(['name' => 'C'], $this->context(), 'contact-us', 0);

        $this->assertSame(OperationStatus::Conflict, $stale->status);
        $this->assertSame('B', Form::query()->value('name'));
    }

    // ── 公開の条件 ──────────────────────────────────────────

    public function test_項目が無いフォームは公開できない(): void
    {
        $result = $this->save([
            'key' => 'contact-us', 'name' => 'お問い合わせ',
            'status' => FormStatus::Published->value,
        ]);

        // 入力欄が 1 つも無いフォームを外へ出さない
        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('field_group_id', $result->errors);
    }

    public function test_下書きなら項目が無くても保存できる(): void
    {
        // 作りかけを保存できないと使えない
        $result = $this->save(['key' => 'contact-us', 'name' => 'お問い合わせ']);

        $this->assertTrue($result->isSuccess());
    }

    public function test_項目のある束なら公開できる(): void
    {
        $group = $this->fieldGroup();

        $result = $this->save([
            'key' => 'contact-us', 'name' => 'お問い合わせ',
            'field_group_id' => $group, 'status' => FormStatus::Published->value,
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
    }

    public function test_投稿タイプ用の束はフォームに使えない(): void
    {
        $group = $this->fieldGroup(kind: 'content_type', value: 'post');

        $result = $this->save([
            'key' => 'contact-us', 'name' => 'お問い合わせ',
            'field_group_id' => $group, 'status' => FormStatus::Published->value,
        ]);

        // 割当先が違えば、使ってよい項目の種類も違う
        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('割り当て', implode(' ', $result->errors['field_group_id']));
    }

    public function test_下書きの束では公開できない(): void
    {
        $group = $this->fieldGroup(status: 'draft');

        $result = $this->save([
            'key' => 'contact-us', 'name' => 'お問い合わせ',
            'field_group_id' => $group, 'status' => FormStatus::Published->value,
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    // ── 自動返信の宛先 ──────────────────────────────────────

    public function test_自動返信の宛先はemailの項目だけ(): void
    {
        $group = $this->fieldGroup();

        $ok = $this->save([
            'key' => 'form-a', 'name' => 'A', 'field_group_id' => $group,
            'autoreply_field' => 'email',
        ]);

        $this->assertTrue($ok->isSuccess(), json_encode($ok->errors, JSON_UNESCAPED_UNICODE));

        // 名前から推測せず、型で判断する
        $wrongType = $this->save([
            'key' => 'form-b', 'name' => 'B', 'field_group_id' => $group,
            'autoreply_field' => 'name',
        ]);

        $this->assertSame(OperationStatus::ValidationError, $wrongType->status);
        $this->assertArrayHasKey('autoreply_field', $wrongType->errors);

        $missing = $this->save([
            'key' => 'form-c', 'name' => 'C', 'field_group_id' => $group,
            'autoreply_field' => 'nope',
        ]);

        $this->assertSame(OperationStatus::ValidationError, $missing->status);
    }

    // ── 削除 ────────────────────────────────────────────────

    public function test_受信があるフォームは消せない(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form, ['name' => '山田']);

        $result = app(DeleteForm::class)->execute('contact-us', $this->context());

        $this->assertSame(OperationStatus::Conflict, $result->status);
        // 何をすればよいかまで言う
        $this->assertStringContainsString('受付終了', (string) $result->message);
        $this->assertSame(1, Form::query()->count());
    }

    public function test_受信が無ければ確認のうえ消せる(): void
    {
        $this->form('contact-us');

        $first = app(DeleteForm::class)->execute('contact-us', $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertStringContainsString('受付終了', implode(' ', $first->confirmation?->impact ?? []));

        $token = (string) $first->confirmation?->token;
        $second = app(DeleteForm::class)->execute('contact-us', $this->context()->withConfirmationToken($token));

        $this->assertTrue($second->isSuccess());
        $this->assertSame(0, Form::query()->count());
    }

    // ── 受信の読み ──────────────────────────────────────────

    public function test_受信はそのときの表示名で読める(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form, ['name' => '山田'], ['name' => 'お名前']);

        // あとから定義が変わっても、届いた内容は読める
        $result = app(GetSubmission::class)->execute((int) $submission->getKey(), $this->context());

        $fields = $result->data['submission']['fields'];

        $this->assertSame('name', $fields[0]['key']);
        $this->assertSame('お名前', $fields[0]['label']);
        $this->assertSame('山田', $fields[0]['value']);
    }

    public function test_受信の一覧は本文を全部積まない(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form, ['name' => '山田', 'message' => str_repeat('あ', 500)]);

        $result = app(ListSubmissions::class)->execute('contact-us', $this->context());

        $row = $result->data['submissions'][0];

        $this->assertSame(1, $result->meta['total']);
        $this->assertArrayNotHasKey('fields', $row);
        $this->assertLessThan(120, mb_strlen((string) $row['summary']));
    }

    public function test_受信はipを出さない(): void
    {
        $form = $this->form('contact-us');
        $submission = $this->submission($form, ['name' => '山田'], ip: hash('sha256', 'x'));

        $result = app(GetSubmission::class)->execute((int) $submission->getKey(), $this->context());

        $this->assertArrayNotHasKey('ip_hash', $result->data['submission']);
        $this->assertTrue($result->data['submission']['has_ip']);
    }

    public function test_一覧は受信件数を添える(): void
    {
        $form = $this->form('contact-us');
        $this->submission($form, ['name' => '山田']);
        $this->form('survey');

        $result = app(ListForms::class)->execute($this->context());

        $byKey = collect($result->data['forms'])->keyBy('key');

        $this->assertSame(1, $byKey['contact-us']['submissions']);
        $this->assertSame(0, $byKey['survey']['submissions']);
        // なぜ公開できないかを画面で言えるようにする
        $this->assertNotNull($byKey['survey']['field_problem']);
    }

    public function test_無いフォームはnot_found(): void
    {
        $this->assertSame(
            OperationStatus::NotFound,
            app(GetForm::class)->execute('nope', $this->context())->status,
        );
    }

    // ── 権限 ────────────────────────────────────────────────

    public function test_編集者はフォームを作れないが読める(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value]);
        $context = OperationContext::admin((int) $editor->getKey(), (string) $editor->name);

        $this->assertTrue(app(ListForms::class)->execute($context)->isSuccess());

        $result = app(SaveForm::class)->execute(['key' => 'x', 'name' => 'X'], $context);

        $this->assertSame(OperationStatus::Forbidden, $result->status);
    }

    // ── helpers ────────────────────────────────────────────

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    /** @param  array<string, mixed>  $input */
    private function save(array $input): Result
    {
        return app(SaveForm::class)->execute($input, $this->context());
    }

    private function form(string $key, string $status = 'draft'): Form
    {
        $id = DB::table('forms')->insertGetId([
            'key' => $key, 'name' => $key, 'status' => $status,
            'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Form::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels
     */
    private function submission(Form $form, array $values, array $labels = [], ?string $ip = null): FormSubmission
    {
        $id = DB::table('form_submissions')->insertGetId([
            'form_id' => $form->getKey(),
            'data' => json_encode($values + [FormSubmission::LABELS_KEY => FormSubmission::labelPairs($labels)], JSON_UNESCAPED_UNICODE),
            'status' => 'received',
            'ip_hash' => $ip,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return FormSubmission::query()->findOrFail($id);
    }

    /** フォーム用の Field Group を 1 つ作る（項目編集の画面は 9.5-A のものを使う） */
    private function fieldGroup(string $status = 'published', string $kind = 'form', string $value = 'contact-us'): string
    {
        $id = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $id,
            'key' => 'form_'.substr($id, -6),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => $status,
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
                ['id' => (string) Str::ulid(), 'key' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => $id, 'kind' => $kind, 'value' => $value,
        ]);

        app()->forgetScopedInstances();

        return $id;
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('core/database/migrations/2026_08_21_000010_create_form_tables.php');

        return $migration;
    }
}
