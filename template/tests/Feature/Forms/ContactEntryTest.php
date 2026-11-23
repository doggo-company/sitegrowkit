<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Forms\Form;
use App\Models\User;
use App\Support\ContactEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `/contact` をフォームへ一本化する（R-12 Stage 1）。
 *
 * ## 予約 key
 *
 * 識別子が **`contact`** の公開中フォームが在れば、`/contact` はそれを出す
 * （`header` / `footer` メニューと同じ「システムが名前で参照する予約 key」）。
 *
 * ## 落とさない
 *
 * 取り込み済みの案件と、`contact` のフォームが無い / 下書きのときは**従来のまま**。
 * 「設定を 1 つ忘れると問い合わせ窓口が消える」は作らない。
 *
 * ## 黙らない
 *
 * 従来のままのときは、サイト設定の画面で**理由を 1 行**言う。
 * 黙ると運用者は「一本化されたつもり」で、窓口が 2 つ在るまま納品する。
 */
final class ContactEntryTest extends TestCase
{
    use RefreshDatabase;

    /** 取り込みの記録。**在ると「取り込み済みの案件」になる** */
    private string $manifest = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest = base_path('.agentcms/manifest/template.json');
    }

    // ── 出す側 ──────────────────────────────

    public function test_予約識別子の公開フォームがあればそれを出す(): void
    {
        $this->withoutImportRecord(function (): void {
            $this->form(ContactEntry::RESERVED_KEY, 'published');

            $this->assertNewForm($this->get('/contact')->assertOk()->getContent() ?: '');
        });
    }

    /** **予約 key が効きすぎない**（ほかの識別子では従来のまま） */
    public function test_識別子が違えば従来のまま(): void
    {
        $this->withoutImportRecord(function (): void {
            $this->form('inquiry', 'published');

            $this->assertLegacy($this->get('/contact')->assertOk()->getContent() ?: '');
        });
    }

    // ── 落とさない側 ──────────────────────────

    public function test_フォームが無ければ従来のまま(): void
    {
        $this->withoutImportRecord(function (): void {
            $this->assertLegacy($this->get('/contact')->assertOk()->getContent() ?: '');
        });
    }

    /**
     * 窓口のフォームを下書きにしたら、従来（同梱の見本）の窓口に戻さず閉じる（UX 一周 6 周目 R6-1）。
     * 以前は従来の窓口に戻り、見本の電話番号つきで受け付けを続け、通知は見本の宛先へ飛んでいた。
     * フォームがまったく無い案件だけが従来のまま（上の試験）。
     */
    public function test_下書きなら閉じて従来の窓口に戻さない(): void
    {
        $this->withoutImportRecord(function (): void {
            $this->form(ContactEntry::RESERVED_KEY, 'draft');

            $this->get('/contact')->assertNotFound();
        });
    }

    /** 取り込んだデザインを消さない（切り替えは移行の作業で） */
    public function test_取り込み済みの案件では従来のまま(): void
    {
        $this->form(ContactEntry::RESERVED_KEY, 'published');

        $this->withImportRecord(function (): void {
            $this->assertLegacy($this->get('/contact')->assertOk()->getContent() ?: '');
        });
    }

    // ── 黙らない側 ──────────────────────────

    public function test_従来のままなら設定画面で理由を言う(): void
    {
        $this->withoutImportRecord(function (): void {
            $this->actingAs($this->owner())
                ->get('/admin/site')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('contactNotice', fn (?string $v): bool => is_string($v)
                        && str_contains($v, ContactEntry::RESERVED_KEY)));
        });
    }

    public function test_取り込み済みなら取り込みを理由として言う(): void
    {
        $this->withImportRecord(fn () => $this->actingAs($this->owner())
            ->get('/admin/site')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contactNotice', fn (?string $v): bool => is_string($v)
                    && str_contains($v, '取り込'))));
    }

    public function test_フォームで出しているなら黙る(): void
    {
        $this->withoutImportRecord(function (): void {
            $this->form(ContactEntry::RESERVED_KEY, 'published');

            $this->actingAs($this->owner())
                ->get('/admin/site')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('contactNotice', null));
        });
    }

    // ── helpers ──────────────────────────────

    /**
     * **どちらを出しているか**を、出力の実物で見分ける。
     *
     * 見るのは**送信先**である。新しいフォームは `/forms/<key>/…` へ送り、
     * 従来は `/contact` へ送る。`cf-turnstile` は**両方に出る**ので目印にならない
     * （最初そう書いて、従来側の試験が何も見ていない状態になりかけた）。
     */
    private function assertNewForm(string $html): void
    {
        $this->assertStringContainsString('/forms/'.ContactEntry::RESERVED_KEY, $html);
    }

    private function assertLegacy(string $html): void
    {
        $this->assertStringNotContainsString('/forms/'.ContactEntry::RESERVED_KEY, $html);
        $this->assertStringContainsString('cf-turnstile', $html); // 従来の画面も出てはいる
    }

    // ── 取り込み済みでも、意匠が残せるなら切り替える（Stage 2 / D） ──

    /**
     * **Stage 1 の前提が変わったところ。**
     *
     * Stage 1 は「取り込み済みの案件は従来のまま」に落としていた。切り替えると
     * 取り込んだデザインが消えるからである。Stage 2 で `template apply` が
     * **原本の意匠でフォームを描く view** を作れるようになったので、
     * それが在るなら**切り替えても意匠は 1 つも失われない**。
     */
    public function test_取り込み済みでも意匠のviewが在ればフォームで出す(): void
    {
        $this->withImportRecord(function (): void {
            $this->withImportedFormView(function (): void {
                $this->form(ContactEntry::RESERVED_KEY, 'published');

                $this->assertTrue(app(ContactEntry::class)->usesForm(), '意匠が在るのに従来のまま');
                $this->assertNull(app(ContactEntry::class)->reason(), '切り替えたのに理由を言っている');
            });
        });
    }

    /** **意匠の view が無ければ、これまでどおり従来のまま**（窓口も意匠も消さない） */
    public function test_取り込み済みで意匠のviewが無ければ従来のまま(): void
    {
        $this->withImportRecord(function (): void {
            $this->form(ContactEntry::RESERVED_KEY, 'published');

            $this->assertFalse(app(ContactEntry::class)->usesForm());
            $this->assertStringContainsString(
                'template apply',
                (string) app(ContactEntry::class)->reason(),
                '切り替え方を言っていない',
            );
        });
    }

    /** **意匠の view が在っても、フォームが無ければ出さない**（窓口を消さない） */
    public function test_意匠のviewが在ってもフォームが無ければ従来のまま(): void
    {
        $this->withImportRecord(function (): void {
            $this->withImportedFormView(function (): void {
                $this->assertFalse(app(ContactEntry::class)->usesForm());
            });
        });
    }

    /** **意匠の view が在れば、フォームの画面はそれで描く**（kit 既定に落ちない） */
    public function test_意匠のviewが在ればそれで描く(): void
    {
        $this->withImportRecord(function (): void {
            $this->withImportedFormView(function (): void {
                $this->form(ContactEntry::RESERVED_KEY, 'published');

                $html = $this->get('/contact')->assertOk()->getContent() ?: '';

                $this->assertStringContainsString('取り込んだ意匠の目印', $html, 'kit 既定で描いている');
            });
        });
    }

    /**
     * **確認・完了画面が、載る layout に関係なく同じに見える**（R-12 / 2026-09-02）。
     *
     * 🔴 入力画面は案件の意匠で描かれるのに、**確認画面だけ素の HTML** になっていた。
     * `.form-confirm` や `.form-page__submit` は kit 既定の `public.css` にしか無く、
     * 案件の意匠（`layouts.imported`）はそれを読まないためである。
     * ボタンはブラウザ既定の灰色、項目は字下げだけ — **送った瞬間に別サイトへ見える。**
     *
     * ここで見るのは 2 つ:
     *   1. どちらの layout でも `forms.css` を読んでいる（＝確認画面ぶんの見た目が付く）
     *   2. ボタンが**意匠側の `btn`** も持っている（案件の意匠が持っていれば、そちらが勝つ）
     */
    public function test_確認画面はどちらの意匠でも素の_htm_lにならない(): void
    {
        foreach (['confirm', 'complete'] as $view) {
            $src = (string) file_get_contents(resource_path("views/forms/{$view}.blade.php"));

            $this->assertStringContainsString(
                "asset('forms.css')",
                $src,
                "forms/{$view}.blade.php が forms.css を読んでいない（案件の意匠だと素の HTML になる）",
            );
        }

        $confirm = (string) file_get_contents(resource_path('views/forms/confirm.blade.php'));

        foreach (['btn form-page__submit', 'btn btn-outline form-page__back'] as $classes) {
            $this->assertStringContainsString(
                $classes,
                $confirm,
                "確認画面のボタンが意匠側の class を持っていない（{$classes}）",
            );
        }
    }

    /**
     * 取り込んだ意匠の view を置く（この試験が置いて、必ず片づける）。
     *
     * **中身は目印だけ**で足りる — ここで見たいのは「どちらの view を選んだか」であって、
     * 意匠そのものの正しさは importer 側（`cms-form-substitute.test.ts`）が見ている。
     */
    private function withImportedFormView(callable $body): void
    {
        $path = resource_path('views/forms/imported.blade.php');

        /*
         * 🔴 **在っても必ず置き換える**（R-12 / 受付の一本化）。
         *
         * kit は見本サイトの意匠で `forms/imported.blade.php` を**同梱している**ので、
         * 「無いときだけ書く」だと**同梱見本が残ったまま**になる。同梱見本は
         * `hasOwnImportedView()` が数えないので、**この試験の前提（案件が自分の意匠を
         * 持っている）が成り立たなくなる** — 実際そうなって 2 本が赤くなった。
         */
        $backup = is_file($path) ? (string) file_get_contents($path) : null;

        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, '<p>取り込んだ意匠の目印</p>');
        touch($path, time() + 1);

        try {
            $body();
        } finally {
            if ($backup === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $backup);
                touch($path, time() + 2);
            }
        }
    }

    /**
     * **利用者の素の生成物**（取り込んでいない）で測る。
     *
     * 記録の在り処は `base_path()` の下 — 生成プロジェクトではそこが案件の根になる。
     * kit では repo の根に在るので `base_path()` からは見えない（＝ kit は「未取り込み」と判定される）。
     * どちらの環境でも同じ結果になるよう、**この試験が記録を作り消しする**。
     */
    private function withoutImportRecord(callable $body): void
    {
        $backup = $this->manifest.'.r12-backup';
        $moved = is_file($this->manifest) && rename($this->manifest, $backup);

        try {
            $body();
        } finally {
            if ($moved) {
                rename($backup, $this->manifest);
            }
        }
    }

    /** 取り込み済みの案件を作る（この試験が置いて、必ず片づける） */
    private function withImportRecord(callable $body): void
    {
        $existed = is_file($this->manifest);

        if (! $existed) {
            @mkdir(dirname($this->manifest), 0o755, true);
            file_put_contents($this->manifest, '{"version":2}');
        }

        try {
            $body();
        } finally {
            if (! $existed) {
                @unlink($this->manifest);
            }
        }
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner']);
    }

    private function form(string $key, string $status): Form
    {
        $groupId = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.strtolower(substr($groupId, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('forms')->insertGetId([
            'key' => $key, 'name' => 'お問い合わせ', 'field_group_id' => $groupId,
            'status' => $status, 'confirm_step' => true, 'submit_label' => '送信する',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Form::query()->findOrFail($id);
    }
}
