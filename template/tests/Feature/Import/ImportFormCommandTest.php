<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use AgentCms\Core\Application\Forms\GetForm;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\Forms\FormFields;
use App\Console\Commands\ImportFormCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 取り込んだフォームを CMS のフォームとして起こす（R-12 Stage 2 / D）。
 *
 * ここで固定するのは 4 つ:
 *   1. **作ると本当に使える状態になる**（束・割当・フォームが揃い、`FormFields` が項目を返す）
 *   2. **既に在れば触らない**（運用者が組み替えたものを import が上書きしない）
 *   3. **通らないものは黙って直さず、1 行も作らずに止まる**
 *   4. **片方だけ残らない**（束だけ在ってフォームが無い、を作らない）
 */
final class ImportFormCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. 作れる ────────────────────────────────────────

    public function test_束と割当とフォームが揃って項目が読める(): void
    {
        $out = $this->importForm($this->payload());

        $this->assertSame('success', $out['status']);
        $this->assertSame('created', $out['action']);
        $this->assertSame(3, $out['fields']);

        $form = GetForm::find('contact');
        $this->assertNotNull($form, 'フォームが作られていない');

        // **ここが本題** — 束と割当が揃っていないと項目は 1 つも返らない
        $labels = app(FormFields::class)->labelsFor($form);
        $this->assertSame(
            ['name' => 'お名前', 'email' => 'メールアドレス', 'message' => 'ご相談内容'],
            $labels,
            '項目が読めない（束か割当が欠けている）',
        );
    }

    public function test_既定は下書き(): void
    {
        $this->importForm($this->payload());

        $this->assertSame('draft', GetForm::find('contact')?->status);
    }

    public function test_公開を指定すれば公開で作る(): void
    {
        $this->importForm($this->payload(['status' => 'published']));

        $this->assertSame('published', GetForm::find('contact')?->status);
    }

    // ── 2. 既に在れば触らない ────────────────────────────

    public function test_同じ識別子のフォームが在れば触らない(): void
    {
        $this->importForm($this->payload());
        $before = DB::table('field_groups')->count();

        // 運用者が名前を変えたつもり
        DB::table('forms')->where('key', 'contact')->update(['name' => '運用者が直した名前']);

        $out = $this->importForm($this->payload(['name' => 'import が持ってきた名前']));

        $this->assertSame('success', $out['status']);
        $this->assertSame('kept', $out['action']);
        $this->assertSame('運用者が直した名前', GetForm::find('contact')?->name, '運用者の手を上書きしている');
        $this->assertSame($before, DB::table('field_groups')->count(), '束を余分に作っている');
    }

    // ── 3. 通らないものは止まる（1 行も作らない） ────────

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    public static function 止まる入力(): array
    {
        return [
            '公開フォームで使えない型' => ['image', ['fields' => [
                ['key' => 'photo', 'type' => 'image', 'label' => '写真'],
            ]]],
            '項目の識別子が機械名でない' => ['key', ['fields' => [
                ['key' => 'your-name', 'type' => 'text', 'label' => 'お名前'],
            ]]],
            'フォームの識別子が不正' => ['key', ['key' => 'Contact Form']],
            '項目が空' => ['fields', ['fields' => []]],
            '状態が不正' => ['status', ['status' => 'archived']],
            '知らないキー' => ['nope', ['nope' => 1]],
        ];
    }

    /**
     * `@dataProvider` の注釈はこの版では拾われない（実測で 0 引数のまま呼ばれた）。
     * **属性で書く。**
     *
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('止まる入力')]
    public function test_通らない入力は1行も作らずに止まる(string $mentions, array $override): void
    {
        $out = $this->importForm($this->payload($override), expectFailure: true);

        $this->assertSame('validation_error', $out['status']);
        $this->assertSame('none', $out['action']);
        $this->assertStringContainsString($mentions, (string) $out['message'], '何が原因か言っていない');

        $this->assertSame(0, DB::table('forms')->count(), 'フォームを作っている');
        $this->assertSame(0, DB::table('field_groups')->count(), '束を作っている');
    }

    // ── 4. 片方だけ残らない ──────────────────────────────

    /**
     * **束は作れるがフォームで落ちる**入力で、束まで巻き戻ることを見る。
     *
     * `forms.name` は 120 文字まで。束の表示名は 100 文字へ収める作りなので、
     * **束は通ってフォームだけが落ちる** — この試験が要るのはそのためである。
     */
    public function test_フォームで落ちたら束も残らない(): void
    {
        $out = $this->importForm($this->payload(['name' => str_repeat('あ', 121)]), expectFailure: true);

        $this->assertNotSame('success', $out['status']);
        $this->assertSame(1, $out['step'] ?? null, '束ではなくフォームで落ちたことを言っていない');
        $this->assertSame(0, DB::table('field_groups')->count(), '束だけ残っている（巻き戻っていない）');
        $this->assertSame(0, DB::table('forms')->count());
    }

    // ── 上限（自己レビューで見つけた 2 件） ──────────────

    /** form の key は 63 文字まで在り得る。`form_` を足すと束の上限（64）を超える */
    public function test_識別子が長くても束の上限を超えない(): void
    {
        $key = 'c'.str_repeat('x', 62); // 63 文字

        $out = $this->importForm($this->payload(['key' => $key]));

        $this->assertSame('created', $out['action']);
        $groupKey = (string) DB::table('field_groups')->value('key');
        $this->assertLessThanOrEqual(FieldGroupDraft::KEY_MAX, mb_strlen($groupKey), '束の識別子が上限を超えた');
        $this->assertStringStartsWith('form_', $groupKey);
    }

    /** `forms.name` は 120 文字まで。束の表示名の上限は 100 */
    public function test_名前が長くても束の表示名の上限を超えない(): void
    {
        $out = $this->importForm($this->payload(['name' => str_repeat('あ', 120)]));

        $this->assertSame('created', $out['action']);
        $label = (string) DB::table('field_groups')->value('label');
        $this->assertLessThanOrEqual(FieldGroupDraft::LABEL_MAX, mb_strlen($label), '束の表示名が上限を超えた');
        $this->assertStringEndsWith('の項目', $label, '組み立ての語尾まで落としている');
    }

    // ── 道具 ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        /*
         * **`$override` を左に置く。** `+` は**左を優先する union** なので、
         * 右に置くと `key` / `name` / `fields` の差し替えが**黙って無視される**
         * （実測でそれを踏んだ — 差し替えたはずの入力が既定のまま通ってしまった）。
         */
        return ['form' => $override + [
            'key' => 'contact',
            'name' => 'お問い合わせ',
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
                ['key' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'required' => true],
                ['key' => 'message', 'type' => 'textarea', 'label' => 'ご相談内容', 'required' => true],
            ],
        ]];
    }

    /**
     * payload を stdin ストリームとして渡してコマンドを実行し、応答の JSON を返す。
     *
     * **`run()` という名前は使えない** — `PHPUnit\\Framework\\TestCase::run()` が
     * final なので、上書きすると**クラスを読み込んだ瞬間に fatal になる**
     * （実装側でも `fail()` で同じことが起きた。**土台が持っている名前を private で塞がない**）。
     *
     * `$this->artisan()` では入力ストリームを差し替えられないので、Symfony の Input を
     * 組み立てて実行する（`ImportTermsCommandTest` と同じ作法）。
     * **argv には payload を載せない**という実装の契約はそのまま通る。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function importForm(array $payload, bool $expectFailure = false): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command = app(ImportFormCommand::class);
        $command->setLaravel(app());

        $exit = $command->run($input, $output);
        $raw = trim($output->fetch());
        fclose($stream);

        $this->assertSame($expectFailure ? 1 : 0, $exit, "終了コードが違う: {$raw}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "応答が JSON ではない: {$raw}");

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
