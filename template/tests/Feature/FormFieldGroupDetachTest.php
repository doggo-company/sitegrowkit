<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Forms\SaveForm;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Forms\Form;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 共有している項目の束を、フォームの画面から切り離す（B-31）。
 *
 * ## 何を固定するか
 *
 * オーナー判断（2026-08-24）は 2 つある。**どちらも「利用者が見ていないものを
 * 勝手に変えない」から出ている**ので、その 2 つをそのまま番人にする。
 *
 * 1. **黙って複製しない** — 確認を返す前は、束もフォームも 1 バイトも変わらない
 * 2. **元の束を指していた他のフォームは据え置き** — 複製しても向き先を付け替えない
 *
 * 加えて、複製した先が**このフォーム専用**になっていること（＝画面の中で編集できる
 * ようになること）まで見る。ここが目的そのものなので、途中で止まっていないか確かめる。
 */
final class FormFieldGroupDetachTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private const SHARED_FIELDS = [
        ['key' => 'your_name', 'type' => 'text', 'label' => 'お名前', 'id' => '01J0000000000000000000000A'],
        ['key' => 'your_email', 'type' => 'email', 'label' => 'メールアドレス', 'id' => '01J0000000000000000000000B'],
    ];

    // ── 1. 黙って複製しない ────────────────────────────

    public function test_確認を返すだけの往路では何も変わらない(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $before = $this->snapshot($shared);

        $this->actingAs($this->operator())
            ->from('/admin/forms/inquiry')
            ->post('/admin/forms/inquiry/fields/detach')
            ->assertRedirect('/admin/forms/inquiry');

        $flash = $this->inertiaFlash();

        $this->assertArrayHasKey('confirmation', $flash, '利用者に聞かずに進んでいます');

        $this->assertSame(1, DB::table('field_groups')->count(), '確認の前に束が複製されています');
        $this->assertSame($shared, Form::query()->where('key', 'inquiry')->value('field_group_id'));
        $this->assertSame($before, $this->snapshot($shared), '確認の前に元の束が書き換わっています');
    }

    public function test_確認の文が_他の使い道は変わらないことを言う(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $this->actingAs($this->operator())
            ->from('/admin/forms/inquiry')
            ->post('/admin/forms/inquiry/fields/detach');

        /** @var array{summary: string, impact: list<string>} $confirmation */
        $confirmation = $this->inertiaFlash()['confirmation'];

        $this->assertStringContainsString('複製', $confirmation['summary']);
        $this->assertNotSame(
            [],
            array_filter(
                $confirmation['impact'],
                static fn (string $line): bool => str_contains($line, '変わりません'),
            ),
            '「ほかの使い道は変わらない」を利用者に見せていません',
        );
    }

    // ── 2. 複製しても、元の束と他のフォームは据え置き ────

    public function test_承認すると専用の束ができて元の束は変わらない(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $before = $this->snapshot($shared);

        $this->detach('inquiry')->assertRedirect('/admin/forms/inquiry');

        $this->assertSame(2, DB::table('field_groups')->count(), '複製されていません');

        $copyId = (string) Form::query()->where('key', 'inquiry')->value('field_group_id');

        $this->assertNotSame($shared, $copyId, 'フォームが新しい束を指していません');
        $this->assertSame($before, $this->snapshot($shared), '元の束が書き換わっています');
    }

    public function test_元の束を指していた他のフォームは据え置き(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $before = $this->snapshot($shared);

        $this->detach('inquiry');

        $this->assertSame(
            $shared,
            Form::query()->where('key', 'estimate')->value('field_group_id'),
            '利用者が見ていないフォームの向き先を変えています',
        );

        /*
         * **向き先だけでは足りない。** 指したままでも、指している先を書き換えたら
         * 「お見積もり」の画面は黙って変わる。**その人が見ている姿ごと**据え置く。
         */
        $this->assertSame($before, $this->snapshot($shared), '据え置くはずの束を書き換えています');
        $this->assertFalse($this->fieldEditor('estimate')['editable'], '共有のままのはずです');
    }

    // ── 3. 目的: 複製した先はこの画面で編集できる ────────

    public function test_複製したあとはこの画面で項目を編集できる(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $this->assertFalse($this->fieldEditor('inquiry')['editable'], '前提が崩れています');

        $this->detach('inquiry');

        $editor = $this->fieldEditor('inquiry');

        $this->assertTrue($editor['editable'], '複製したのに、まだ編集できません');
        $this->assertSame(
            ['your_name', 'your_email'],
            array_column($editor['group']['fields'], 'key'),
            '項目の中身が引き継がれていません',
        );
    }

    public function test_複製した項目には新しい識別子が付く(): void
    {
        /*
         * **他の束の項目 ID を持ち込ませない**（`FieldGroupDraft::fieldId()` と同じ理由）。
         * 同じ ULID が 2 つの束に居ると、片方を直したときにもう片方の binding が
         * 巻き添えになる。key は据え置く — 受信済みのデータは key で読むからである。
         */
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $this->detach('inquiry');

        $copy = $this->group((string) Form::query()->where('key', 'inquiry')->value('field_group_id'));

        $ids = array_map(static fn ($field): ?string => $field->id, $copy->fields);

        $this->assertSame(['your_name', 'your_email'], array_map(static fn ($f): string => $f->key, $copy->fields));
        $this->assertSame([], array_intersect($ids, array_column(self::SHARED_FIELDS, 'id')));
    }

    public function test_複製した束はこのフォームだけを指す(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $this->detach('inquiry');

        $copy = $this->group((string) Form::query()->where('key', 'inquiry')->value('field_group_id'));

        $this->assertSame(
            [['form', 'inquiry']],
            array_map(
                static fn ($location): array => [$location->kind->value, $location->value],
                $copy->locations,
            ),
        );
    }

    // ── 4. 出口を出す条件（押せるものを出して嘘をつかない） ──

    public function test_共有の束には切り離しの出口を出す(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $this->assertTrue($this->fieldEditor('inquiry')['detachable']);
    }

    public function test_専用の束には切り離しの出口を出さない(): void
    {
        // すでに編集できる。出しても押した先で断られるだけである
        $this->createForm('inquiry', 'お問い合わせ');

        $this->assertFalse($this->fieldEditor('inquiry')['detachable']);
    }

    public function test_定義が読めない束には切り離しの出口を出さない(): void
    {
        /*
         * 読めない定義を写しても、**壊れた束が 2 つになるだけ**である。
         * 画面が出口を出さないことと、直接叩かれても断ることを両方固定する。
         */
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        DB::table('field_groups')->where('id', $shared)->update(['fields' => '{"not":"a list"}']);

        $this->assertFalse($this->fieldEditor('inquiry')['detachable']);

        $this->detach('inquiry');

        $this->assertSame(1, DB::table('field_groups')->count(), '壊れた定義を写しています');
    }

    // ── 5. 断るべきところで断る ────────────────────────

    public function test_すでに専用の束なら複製しない(): void
    {
        // R-8A ① で作られた「そのフォームだけの束」。ここで複製すると束が無限に増える
        $this->createForm('inquiry', 'お問い合わせ');

        $before = (string) Form::query()->where('key', 'inquiry')->value('field_group_id');

        $this->detach('inquiry');

        $this->assertSame(1, DB::table('field_groups')->count(), '専用の束を複製しています');
        $this->assertSame($before, Form::query()->where('key', 'inquiry')->value('field_group_id'));
    }

    public function test_設定を触れない利用者は複製できない(): void
    {
        $shared = $this->sharedGroup();
        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);
        $this->createForm('estimate', 'お見積もり', ['field_group_id' => $shared]);

        $this->actingAs($this->userWithRole(Role::Editor))
            ->post('/admin/forms/inquiry/fields/detach')
            ->assertForbidden();

        $this->assertSame(1, DB::table('field_groups')->count());
        $this->assertSame($shared, Form::query()->where('key', 'inquiry')->value('field_group_id'));
    }

    // ── helpers ──────────────────────────────

    private function sharedGroup(): string
    {
        return app(FieldGroupWriter::class)->create(
            'shared_fields',
            '共有の項目',
            self::SHARED_FIELDS,
            [
                ['kind' => 'form', 'value' => 'inquiry'],
                ['kind' => 'form', 'value' => 'estimate'],
            ],
            $this->context(),
        );
    }

    /** @param array<string, mixed> $extra */
    private function createForm(string $key, string $name, array $extra = []): void
    {
        $result = app(SaveForm::class)->execute(
            ['key' => $key, 'name' => $name, 'status' => 'draft'] + $extra,
            $this->context(),
        );

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
    }

    /** 確認まで通した切り離し（画面と同じ 2 往復） */
    private function detach(string $formKey): TestResponse
    {
        $this->app->forgetInstance(FieldGroupRepository::class);

        $this->actingAs($this->operator())
            ->from("/admin/forms/{$formKey}")
            ->post("/admin/forms/{$formKey}/fields/detach");

        $token = $this->inertiaFlash()['confirmation']['token'] ?? null;

        $this->app->forgetInstance(FieldGroupRepository::class);

        return $this->actingAs($this->operator())
            ->from("/admin/forms/{$formKey}")
            ->post("/admin/forms/{$formKey}/fields/detach", array_filter([
                'confirmation_token' => $token,
            ]));
    }

    /**
     * 元の束の**保存されている姿**。差分が出たら「触った」ということ。
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $id): array
    {
        return [
            'group' => (array) DB::table('field_groups')->where('id', $id)->first(),
            'locations' => DB::table('field_group_locations')
                ->where('field_group_id', $id)
                ->orderBy('id')
                ->get(['kind', 'value'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function fieldEditor(string $formKey): array
    {
        $this->app->forgetInstance(FieldGroupRepository::class);

        /** @var array<string, mixed>|null $editor */
        $editor = $this->actingAs($this->operator())
            ->get("/admin/forms/{$formKey}")
            ->assertOk()
            ->inertiaProps('fieldEditor');

        $this->assertNotNull($editor, '編集の材料が渡っていません');

        return $editor;
    }

    private function group(string $id): FieldGroupRecord
    {
        $this->app->forgetInstance(FieldGroupRepository::class);

        foreach (app(FieldGroupRepository::class)->all() as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        $this->fail("束 [{$id}] が見つかりません");
    }

    /** @return array<string, mixed> */
    private function inertiaFlash(): array
    {
        /** @var array<string, mixed> $flash */
        $flash = session()->get(SessionKey::FLASH_DATA, []);

        return $flash;
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'admin');
    }
}
