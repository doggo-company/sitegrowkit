<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\FrontPageChoices;
use AgentCms\Core\Settings\SettingsStore;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * トップページにページを指定する（R-5）。
 *
 * ここで固定するのは 4 つ:
 *
 * 1. **未選択なら 1 バイトも変わらない** — 既定のトップがそのまま出る
 * 2. **選んだら `/` がそのページになる**（route は増やさない）
 * 3. **`/` と `/page-slug` の両方が `/` を正規 URL と名乗る**（判断 (A)）
 * 4. **選んだ後に出せなくなっても、トップは消えない** — 既定へ戻り、理由は管理画面に出る
 *
 * ▷ さらに「候補に出るもの」「保存できるもの」「実際に出せるもの」の **3 つが一致**していることを
 *   見る。ここがずれると、**選べたのに何も変わらない**（画面からは壊れて見えない）。
 */
final class FrontPageTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** ページの中身。**既定のトップには出てこない文字列**にする */
    private const HEADING = 'ここはページで作ったトップです';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);
    }

    // ── 既定（未選択） ──────────────────────────────

    public function test_未選択ならこれまでどおりのトップが出る(): void
    {
        $this->makePage('company');

        $this->get('/')->assertOk()->assertSee('class="hero"', false);

        $this->assertNull(app(FrontPage::class)->selectedId());
        $this->assertNull(app(FrontPage::class)->resolve());
        $this->assertNull(app(FrontPage::class)->reason());
    }

    // ── 選んだとき ──────────────────────────────

    public function test_選ぶとトップにそのページが出る(): void
    {
        $this->selectFrontPage($this->makePage('company'));

        $response = $this->get('/')->assertOk();

        // **中身が出ていることを見る。** 題だけだと `<title>` に出るので、
        // 「描画されていないのに緑」を通してしまう（監査 指摘 2）
        $response->assertSee(self::HEADING);
        $response->assertDontSee('class="hero"', false);
    }

    public function test_どちらのurlでも正規urlはルートになる(): void
    {
        $this->selectFrontPage($this->makePage('company'));

        $canonical = '<link rel="canonical" href="'.url('/').'">';

        $this->get('/')->assertOk()->assertSee($canonical, false);
        // **元の URL も残る。** そちらも `/` を名乗ることで、同じ内容の 2 ページにしない
        $this->get('/company')->assertOk()->assertSee($canonical, false);
    }

    public function test_トップに指定していないページは自分のurlを名乗る(): void
    {
        $this->makePage('company');

        $this->get('/company')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/company').'">', false);
    }

    // ── 選んだ後に状態が変わったとき ────────────────────

    public function test_公開を取り消してもトップは消えない(): void
    {
        $id = $this->makePage('company');
        $this->selectFrontPage($id);

        DB::table('pages')->where('id', $id)->update(['status' => 'draft']);

        // **404 でも 500 でもなく、既定のトップへ戻る**
        $this->get('/')->assertOk()->assertSee('class="hero"', false);
        $this->assertSame(
            'トップページに指定したページは下書きです',
            app(FrontPage::class)->reason(),
        );
    }

    public function test_ゴミ箱へ入れてもトップは消えない(): void
    {
        $id = $this->makePage('company');
        $this->selectFrontPage($id);

        DB::table('pages')->where('id', $id)->update([
            'status' => 'trashed',
            'trashed_at' => now(),
        ]);

        $this->get('/')->assertOk()->assertSee('class="hero"', false);
        $this->assertSame(
            'トップページに指定したページがゴミ箱にあります',
            app(FrontPage::class)->reason(),
        );
    }

    public function test_消えたページを指していてもトップは消えない(): void
    {
        $id = $this->makePage('company');
        $this->selectFrontPage($id);

        DB::table('page_versions')->where('page_id', $id)->delete();
        DB::table('pages')->where('id', $id)->delete();

        $this->get('/')->assertOk()->assertSee('class="hero"', false);
        $this->assertSame(
            'トップページに指定したページがありません',
            app(FrontPage::class)->reason(),
        );
    }

    public function test_ビジュアルエディタ管理でないページはトップにしない(): void
    {
        $id = $this->makePage('company');
        DB::table('pages')->where('id', $id)->update(['managed_by' => 'blade']);
        $this->selectFrontPage($id);

        $this->get('/')->assertOk()->assertSee('class="hero"', false);
        $this->assertSame(
            'トップページに指定したページはビジュアルエディタで編集されていません',
            app(FrontPage::class)->reason(),
        );
    }

    // ── 候補・保存・表示の 3 つが一致していること ──────────────

    public function test_出せないページは候補にも出ず保存もできない(): void
    {
        $published = $this->makePage('company');

        $draft = $this->makePage('draft-page');
        DB::table('pages')->where('id', $draft)->update(['status' => 'draft']);

        $trashed = $this->makePage('trashed-page');
        DB::table('pages')->where('id', $trashed)->update([
            'status' => 'trashed', 'trashed_at' => now(),
        ]);

        $blade = $this->makePage('blade-page');
        DB::table('pages')->where('id', $blade)->update(['managed_by' => 'blade']);

        $offered = array_column(app(FrontPageChoices::class)->all(), 'id');

        $this->assertSame([$published], $offered, '候補に出るのは、実際にトップへ出せるものだけです');

        foreach ([$draft, $trashed, $blade] as $id) {
            $this->assertNotContains($id, $offered);
        }

        // **保存も断る。** 候補に無いものを直接送っても通らない
        foreach ([$draft, $trashed] as $id) {
            $result = $this->save($id);

            $this->assertSame(
                'validation_error',
                $result,
                "id={$id} は保存できてはいけません（保存できると、成功したのに何も変わらない指定ができます）",
            );
        }

        // 公開中のものは保存できる
        $this->assertSame('success', $this->save($published));
    }

    public function test_候補は公開中のページだけを名前つきで返す(): void
    {
        $id = $this->makePage('company');

        $this->assertSame(
            [['id' => $id, 'label' => '会社概要', 'slug' => 'company']],
            app(FrontPageChoices::class)->all(),
        );
    }

    // ── 管理画面 ──────────────────────────────

    public function test_サイト設定は候補と理由を画面へ渡す(): void
    {
        $id = $this->makePage('company');
        $this->selectFrontPage($id);
        DB::table('pages')->where('id', $id)->update(['status' => 'draft']);

        $this->actingAs($this->operator())
            ->get('/admin/site')
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('frontPageNotice', 'トップページに指定したページは下書きです')
                // 下書きになったので、候補からも消えている
                ->where('frontPageChoices', [])
                /*
                 * **保存されている値はそのまま渡す。** サーバーが黙って null へ書き換えると、
                 * 「見に行っただけで設定が消えた」になる。落とすのは画面が送るときで、
                 * その判断は `lib/page-choice` が持つ（同名の unit 試験）
                 */
                ->where('values.front_page_id', $id)
            );
    }

    // ── 出せなくなった後も、設定画面が使えること（監査 指摘 1） ──────────

    public function test_指定したページが下書きになってもサイト名は保存できる(): void
    {
        $id = $this->makePage('company');
        $this->selectFrontPage($id);
        DB::table('pages')->where('id', $id)->update(['status' => 'draft']);

        /*
         * 画面は「選ばない」を見せているので、送る値も null になる（`normalizePageValue`）。
         * **ここが落ちると、トップページと無関係な項目まで保存できなくなる** — 監査 指摘 1。
         */
        $this->actingAs($this->operator())
            ->put('/admin/site', [
                'site_name' => '新しいサイト名',
                'front_page_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('新しいサイト名', app(SettingsStore::class)->get('site_name'));
        $this->assertNull(app(SettingsStore::class)->get(FrontPage::SETTING_KEY));
    }

    public function test_選べないページを指定したときの断り方は日本語で理由を言う(): void
    {
        $draft = $this->makePage('draft-page');
        DB::table('pages')->where('id', $draft)->update(['status' => 'draft']);

        $result = app(UpdateSettings::class)->execute(
            [FrontPage::SETTING_KEY => $draft],
            OperationContext::admin($this->operatorId(), 'admin'),
            partial: true,
        );

        // 欄の名前は schema の label（`front_page_id` という内部名を出さない）
        $this->assertSame(
            ['front_page_id' => ['トップページには、ビジュアルエディタで編集された、公開中のページだけを指定できます。']],
            $result->errors,
        );
    }

    public function test_ビジュアルエディタ管理でないページは規則が断る(): void
    {
        $blade = $this->makePage('blade-page');
        DB::table('pages')->where('id', $blade)->update(['managed_by' => 'blade']);

        /*
         * 監査 指摘 5: 候補から外すだけでは、**画面を通らない経路**（MCP / CLI）が
         * 素通りする。`site.json` の `validation.managedBy` を規則が受けて断る。
         */
        $this->assertSame('validation_error', $this->save($blade));
    }

    // ── helpers ──────────────────────────────

    /**
     * 公開済みのページ（PageDocument 管理）を 1 つ作る。
     *
     * **document は実物と同じ形にする**（`{"root": {"type": "page", …}}`）。
     * 監査 指摘 2: 以前ここは `{"type": "root", "children": []}` という
     * **描画されない形**だったので、「トップに出た」を本文で確かめられていなかった
     * （`DocumentRenderer::render()` は `$document['root']` を見る）。
     */
    private function makePage(string $slug, string $heading = self::HEADING): int
    {
        $id = (int) DB::table('pages')->insertGetId([
            'slug' => $slug, 'title' => '会社概要', 'status' => 'published',
            'managed_by' => 'document',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $version = (int) DB::table('page_versions')->insertGetId([
            'page_id' => $id, 'revision' => 1, 'schema_version' => 1,
            'document' => json_encode([
                'root' => [
                    'type' => 'page',
                    'id' => (string) Str::ulid(),
                    'children' => [[
                        'type' => 'section',
                        'id' => (string) Str::ulid(),
                        'children' => [[
                            'type' => 'heading',
                            'id' => (string) Str::ulid(),
                            'props' => ['text' => $heading, 'level' => 1],
                        ]],
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_interface' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pages')->where('id', $id)->update(['published_version_id' => $version]);

        return $id;
    }

    /** 検証を通さずに設定へ書く（「保存できるか」は別の試験で見る） */
    private function selectFrontPage(int $id): void
    {
        app(SettingsStore::class)->put([FrontPage::SETTING_KEY => $id]);
    }

    /** 操作を通して保存したときの結果 */
    private function save(int $id): string
    {
        return app(UpdateSettings::class)->execute(
            [FrontPage::SETTING_KEY => $id],
            OperationContext::admin($this->operatorId(), 'admin'),
            partial: true,
        )->status->value;
    }
}
