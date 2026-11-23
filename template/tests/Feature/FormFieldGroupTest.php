<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Forms\SaveForm;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\FieldGroups\FieldGroupWriter;
use AgentCms\Core\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * フォームと入力項目の結びつき（R-8A ①）。
 *
 * ここで固定するのは 3 つ:
 *
 * 1. **フォームを作ると、入力項目の置き場所がその場で付く**
 *    （別画面へ行って束を作り、戻ってきて選び直す往復を無くすための土台）
 * 2. **候補は「このフォームを指している束」だけ** — 以前は全フォームの束が全部出ていた
 * 3. **ただし、いま割り当てられている束は必ず候補に入る**
 *    （既存の value は手打ちで、一致だけで絞ると R-5 の監査 指摘 1 と同じ事故になる）
 */
final class FormFieldGroupTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    // ── 1. 作った時点で置き場所が付く ─────────────────

    public function test_フォームを作ると専用の束が付いてくる(): void
    {
        $this->createForm('inquiry', 'お問い合わせ');

        $form = Form::query()->where('key', 'inquiry')->firstOrFail();

        $this->assertNotNull($form->field_group_id, '入力項目の置き場所が付いていません');

        $record = $this->group((string) $form->field_group_id);

        $this->assertSame('お問い合わせ', $record->label);
        $this->assertSame(
            [['form', 'inquiry']],
            array_map(
                static fn ($location): array => [$location->kind->value, $location->value],
                $record->locations,
            ),
            '割当先はこのフォームの key を指しているはずです',
        );
    }

    public function test_束を明示したときは自動で作らない(): void
    {
        $shared = app(FieldGroupWriter::class)->create(
            'shared_fields', '共有の項目', [],
            [['kind' => 'form', 'value' => 'inquiry']],
            $this->context(),
        );

        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);

        $this->assertSame($shared, Form::query()->where('key', 'inquiry')->value('field_group_id'));
        $this->assertCount(1, app(FieldGroupRepository::class)->all(), '束が余分に作られています');
    }

    public function test_束の_key_がぶつかっても番号で避ける(): void
    {
        app(FieldGroupWriter::class)->create('form_inquiry', '先客', [], [], $this->context());

        $this->createForm('inquiry', 'お問い合わせ');

        $form = Form::query()->where('key', 'inquiry')->firstOrFail();

        $this->assertSame('form_inquiry_2', $this->group((string) $form->field_group_id)->key);
    }

    public function test_ハイフンを含む_key_のフォームでも自動生成は通る(): void
    {
        $this->createForm('contact-us', 'お問い合わせ');

        $form = Form::query()->where('key', 'contact-us')->firstOrFail();

        $this->assertNotNull($form->field_group_id);
        $this->assertSame(
            'contact-us',
            $this->group((string) $form->field_group_id)->locations[0]->value,
        );
        // key はハイフンを許さないので `_` へ寄せる
        $this->assertSame('form_contact_us', $this->group((string) $form->field_group_id)->key);
    }

    public function test_人が画面から割り当てるときもハイフンを書ける(): void
    {
        /*
         * **自動生成は `FieldGroupWriter` を直に呼ぶので、検証を通らない。**
         * ハイフンを弾いていたのは `FieldGroupDraft` のほう（人が画面から保存する道）で、
         * そこを通す試験が無いと **`FORM_TARGET_NAME` を戻しても誰も気づかない**
         * （実際、変異検査でここが緑のままだった）。
         */
        $this->createForm('contact-us', 'お問い合わせ');

        $this->actingAs($this->operator())
            ->post('/admin/field-groups', [
                'key' => 'extra_fields',
                'label' => '追加の項目',
                'locations' => [['kind' => 'form', 'value' => 'contact-us']],
                'fields' => [
                    ['key' => 'memo', 'label' => 'メモ', 'type' => 'text', 'required' => false],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertContains('追加の項目', $this->candidateLabels('contact-us'));
    }

    public function test_保存先が無い環境でもフォームは作れる(): void
    {
        /*
         * overlay を導入していない生成物では `field_groups` が無い。
         * **そこでフォームを作れなくしない** — これまでどおり
         * 「入力項目が未設定のフォーム」ができるだけである（R-8A ① 監査 指摘 14）。
         */
        Schema::drop('field_group_locations');
        Schema::drop('field_groups');
        $this->app->forgetInstance(FieldGroupRepository::class);

        $this->createForm('inquiry', 'お問い合わせ');

        $this->assertNull(Form::query()->where('key', 'inquiry')->value('field_group_id'));
    }

    // ── 2 / 3. 候補の絞り込み ───────────────────────

    public function test_候補は_このフォームを指している束だけ(): void
    {
        $this->createForm('inquiry', 'お問い合わせ');
        $this->createForm('estimate', 'お見積もり');

        $labels = $this->candidateLabels('inquiry');

        $this->assertSame(['お問い合わせ'], $labels, '別のフォームの束まで候補に出ています');
    }

    public function test_いま割り当てられている束は一致しなくても候補に出る(): void
    {
        /*
         * 既存サイトの value は手打ちで、実在しないフォーム名も入り得る。
         * ここで落とすと「選ばれているのに候補に無い」になり、
         * **無関係な項目まで保存できなくなる**（R-5 の監査 指摘 1 と同じ形）。
         */
        $stale = app(FieldGroupWriter::class)->create(
            'legacy_fields', '昔からある束', [],
            [['kind' => 'form', 'value' => 'inquiryy']], // 打ち間違えたまま保存された値
            $this->context(),
        );

        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $stale]);

        $this->assertContains('昔からある束', $this->candidateLabels('inquiry'));
    }

    // ── 4. 画面の中で項目を編集する材料（R-8A ②） ─────────

    public function test_専用の束ならこの画面で編集できる(): void
    {
        $this->createForm('inquiry', 'お問い合わせ');

        $editor = $this->fieldEditor('inquiry');

        $this->assertNotNull($editor);
        $this->assertTrue($editor['editable']);
        // 項目エディタが要る選択肢も一緒に渡る（写さず core から取る）
        $this->assertArrayHasKey('fieldTypes', $editor['options']);
    }

    public function test_新規作成の画面にも同じキーを渡す(): void
    {
        /*
         * 中身は null だが、**キーごと渡さないと画面では `undefined` になる**。
         * 実際それで `/admin/forms/new` が白い画面になった（ef9a466 の hotfix）。
         * 渡す側を揃えて、次に画面を足す人が同じ穴を踏まないようにする。
         */
        $props = [];

        $this->actingAs($this->operator())
            ->get('/admin/forms/new')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props): void {
                $props = $page->toArray()['props'] ?? [];
            });

        $this->assertArrayHasKey('fieldEditor', $props);
        $this->assertNull($props['fieldEditor']);
    }

    public function test_ほかでも使われている束は読み取りで見せる(): void
    {
        /*
         * 他のフォームや投稿タイプも指している束をこの画面で書き換えると、
         * **利用者が見ていない画面が壊れる**。中身は見せるが編集させない。
         */
        $shared = app(FieldGroupWriter::class)->create(
            'shared_fields', '共有の項目', [],
            [
                ['kind' => 'form', 'value' => 'inquiry'],
                ['kind' => 'content_type', 'value' => 'post'],
            ],
            $this->context(),
        );

        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $shared]);

        $editor = $this->fieldEditor('inquiry');

        $this->assertNotNull($editor);
        $this->assertFalse($editor['editable'], '共有の束を編集させてはいけません');
    }

    public function test_割当先が_1_つでも別のフォームを指す束は編集させない(): void
    {
        /*
         * **数だけ見ては足りない**（監査 指摘 15）。
         * 「割当先が 1 つ」で緑になる試験しか無いと、判定を
         * `count(...) === 1` だけへ弱めても気づけない。
         * ここは**割当先 1 つ・ただし別のフォームを指す**束で、数では区別できない。
         */
        $other = app(FieldGroupWriter::class)->create(
            'other_fields', 'よその項目', [],
            [['kind' => 'form', 'value' => 'estimate']],
            $this->context(),
        );

        $this->createForm('inquiry', 'お問い合わせ', ['field_group_id' => $other]);

        $editor = $this->fieldEditor('inquiry');

        $this->assertNotNull($editor);
        $this->assertFalse($editor['editable'], '別のフォームを指す束を編集させてはいけません');
    }

    public function test_束が無いフォームには編集の材料を渡さない(): void
    {
        Schema::drop('field_group_locations');
        Schema::drop('field_groups');
        $this->app->forgetInstance(FieldGroupRepository::class);

        $this->createForm('inquiry', 'お問い合わせ');

        $this->assertNull($this->fieldEditor('inquiry'));
    }

    // ── 5. 保存したあと、どこへ帰るか（監査 指摘 18） ──────

    public function test_フォームから項目を保存したらフォームへ帰る(): void
    {
        /*
         * 既定の戻り先は**カスタムフィールドの一覧**で、そこは
         * **利用者に見せないと決めた画面**である。往復 0 が最後の 1 押しで崩れていた。
         */
        $this->createForm('inquiry', 'お問い合わせ');

        $response = $this->patchGroup('inquiry', ['redirect_to' => '/admin/forms/inquiry']);

        $response->assertRedirect('/admin/forms/inquiry');
        $this->assertSame('入力項目を保存しました', session('success'));
    }

    public function test_カスタムフィールドの画面から保存したときは何も変わらない(): void
    {
        // **両経路を固定する。** 片方を直したついでに、もう片方を変えていないこと
        $this->createForm('inquiry', 'お問い合わせ');

        $response = $this->patchGroup('inquiry', []);

        $response->assertRedirect('/admin/field-groups');
        $this->assertStringContainsString('カスタムフィールド', (string) session('success'));
    }

    public function test_外へ飛ばす戻り先は受け付けない(): void
    {
        /*
         * **フォームは 1 つずつ作り直す。** 同じ束へ続けて保存すると、
         * 項目の `id` を送っていないぶん**毎回「消して足した」と判定され**、
         * 2 回目から確認を要求されて `back()` になる（`byIdentity()` は ULID で見る）。
         * ここで見たいのは戻り先の判定だけなので、その事情を持ち込まない。
         */
        $hostile = [
            'https://example.com/',
            '//example.com/',
            '/login',
            '/adminx/forms/inquiry',
            '\\/example.com',
            // 親ディレクトリ参照は `/admin/` で始まっていても外へ出られる（監査 指摘 25）
            '/admin/../../evil',
            '/admin/./../../evil',
            '/admin/..%2f..%2fevil',
        ];

        foreach ($hostile as $index => $value) {
            $key = 'inquiry'.$index;

            $this->createForm($key, 'お問い合わせ');

            $this->patchGroup($key, ['redirect_to' => $value])
                ->assertRedirect('/admin/field-groups');
        }
    }

    public function test_保存できたことを画面が言う(): void
    {
        /*
         * `with('success', …)` は 7 か所以上で使われていたのに、
         * **共有も描画も無く、どこにも出ていなかった**（監査 指摘 24）。
         * 「保存したのに画面が何も言わない」が管理画面全体の状態だった。
         */
        $this->createForm('inquiry', 'お問い合わせ');
        $this->patchGroup('inquiry', ['redirect_to' => '/admin/forms/inquiry']);

        $this->actingAs($this->operator())
            ->get('/admin/forms/inquiry')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', '入力項目を保存しました'));
    }

    // ── helpers ──────────────────────────────

    /** @param array<string, mixed> $extra */
    private function createForm(string $key, string $name, array $extra = []): void
    {
        $result = app(SaveForm::class)->execute(
            ['key' => $key, 'name' => $name, 'status' => 'draft'] + $extra,
            $this->context(),
        );

        $this->assertTrue($result->isSuccess(), json_encode($result->errors, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private function candidateLabels(string $formKey): array
    {
        $labels = [];

        // 本番は要求ごとに新しい容れ物だが、試験では 1 つを使い回すので忘れさせる
        $this->app->forgetInstance(FieldGroupRepository::class);

        $this->actingAs($this->operator())
            ->get("/admin/forms/{$formKey}")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$labels): void {
                /** @var list<array{label: string}> $groups */
                $groups = $page->toArray()['props']['fieldGroups'] ?? [];
                $labels = array_column($groups, 'label');
            });

        return $labels;
    }

    /**
     * そのフォームの束を、画面と同じ道（`PATCH /admin/field-groups/{id}`）で保存する。
     *
     * @param  array<string, mixed>  $extra
     */
    private function patchGroup(string $formKey, array $extra): TestResponse
    {
        $id = (string) Form::query()->where('key', $formKey)->value('field_group_id');
        $row = DB::table('field_groups')->where('id', $id)->first();

        // 本番は要求ごとに新しい容れ物。試験では 1 つを使い回すので忘れさせる
        $this->app->forgetInstance(FieldGroupRepository::class);

        return $this->actingAs($this->operator())->patch("/admin/field-groups/{$id}", [
            'key' => $row->key,
            'label' => $row->label,
            'locations' => [['kind' => 'form', 'value' => $formKey]],
            'fields' => [['key' => 'your_name', 'type' => 'text', 'label' => 'お名前']],
        ] + $extra);
    }

    /** @return array<string, mixed>|null */
    private function fieldEditor(string $formKey): ?array
    {
        $editor = null;

        $this->app->forgetInstance(FieldGroupRepository::class);

        $this->actingAs($this->operator())
            ->get("/admin/forms/{$formKey}")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$editor): void {
                /** @var array<string, mixed>|null $value */
                $value = $page->toArray()['props']['fieldEditor'] ?? null;
                $editor = $value;
            });

        return $editor;
    }

    private function group(string $id): FieldGroupRecord
    {
        // repository は 1 リクエスト分を覚えるので、書いた直後は忘れさせてから引く
        $this->app->forgetInstance(FieldGroupRepository::class);

        foreach (app(FieldGroupRepository::class)->all() as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        $this->fail("束 [{$id}] が見つかりません");
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'admin');
    }
}
