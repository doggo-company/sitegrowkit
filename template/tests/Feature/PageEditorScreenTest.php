<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Pages\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C2: Canvas + Layers の入口（`PHASE9_6_SPEC.md` §4.1 / §4.2）。
 *
 * ここで固定するのは **画面が開くこと**と、**画面が受け取ってよいもの**の範囲。
 * document の変更経路は `api/v1` の `page.document.patch` 1 本だけで、
 * 管理画面側には mutation の route を増やさない（同 §5.1 / Owner Gate V2）。
 */
final class PageEditorScreenTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->findOrFail($this->operatorId());
    }

    private function makePage(string $slug = 'editor-page', string $title = '編集対象'): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => $title, 'slug' => $slug],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    public function test_editor_screen_hands_over_the_document_and_the_canvas_url(): void
    {
        $page = $this->makePage();

        $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('admin/pages/Editor')
                ->where('page.id', $page->id)
                ->has('document.root')
                // 楽観ロックの基準。これが無いと編集が silent overwrite になる
                ->where('version', 1)
                ->where('canvasUrl', "/admin/pages/{$page->id}/preview?canvas=1")
                ->has('components')
                ->etc(),
            );
    }

    /**
     * 追加できる component を **サーバーが決める**。
     *
     * `html` は sanitize 済みでも「任意の HTML を貼れる面」なので、
     * 初期版のパレットには出さない（`PHASE9_6_SPEC.md` §3.1 / Owner Gate V1）。
     * 画面側で隠すのではなく、**渡さない**。
     */
    public function test_editor_palette_does_not_offer_the_raw_html_component(): void
    {
        $page = $this->makePage();

        /** @var list<array<string, mixed>> $components */
        $components = (array) $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->inertiaProps('components');

        $types = array_column($components, 'type');

        $this->assertNotContains('html', $types, 'パレットに html が出ています');
        $this->assertContains('heading', $types);
        $this->assertContains('form', $types);
        // Aのcoverを追加。htmlは引き続きパレットから除外する。
        $this->assertContains('cover', $types);
        $this->assertCount(12, $types, 'cover・フォームを含み、html を除いた12種であること');
    }

    /**
     * パレットの 8 種が **そのまま追加できる**こと。
     *
     * 既定値をサーバーが決めるのは、追加した瞬間に検証で弾かれる component を
     * パレットへ出さないため。ここが崩れると「押せるのに置けない」ボタンができる。
     */
    public function test_every_component_in_the_palette_can_actually_be_inserted(): void
    {
        $page = $this->makePage();

        /** @var list<array<string, mixed>> $components */
        $components = (array) $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->inertiaProps('components');

        $context = OperationContext::admin($this->operatorId(), 'tester');
        $patch = app(PatchPageDocument::class);

        // まず section を 1 つ置く（leaf の親になる）
        $result = $patch->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $context);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $sectionId = (string) $result->data['document']['root']['children'][0]['id'];

        foreach ($components as $spec) {
            $type = (string) $spec['type'];
            $parent = in_array('page', (array) $spec['allowedParents'], true) ? 'page' : $sectionId;

            /** @var array<string, mixed> $defaults */
            $defaults = (array) ($spec['defaults'] ?? []);

            $result = $patch->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => $parent,
                'component' => array_merge($defaults, ['type' => $type]),
            ], $context);

            $this->assertTrue(
                $result->isSuccess(),
                "[{$type}] を既定値のまま追加できません: ".json_encode($result->errors, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    /**
     * 空のページの 1 手目（UX 一周 2026-09-27）。
     *
     * 空のページで「見出し」を押しても何も起きなかった。画面は **セクションごと
     * 1 本の command で**置くようにしたので、その形をサーバーが受け付けることを固める。
     * 根に置けない部品は全部この形で置けること・入れ子の両方に ID が振られること。
     */
    public function test_a_leaf_block_on_an_empty_page_is_placed_together_with_its_section(): void
    {
        /** @var list<array<string, mixed>> $components */
        $components = (array) $this->actingAs($this->admin())
            ->get("/admin/pages/{$this->makePage('probe')->id}/editor")
            ->inertiaProps('components');

        $context = OperationContext::admin($this->operatorId(), 'tester');
        $patch = app(PatchPageDocument::class);
        $leaves = array_filter(
            $components,
            fn (array $spec): bool => ! in_array('page', (array) $spec['allowedParents'], true),
        );

        $this->assertNotEmpty($leaves);

        foreach ($leaves as $spec) {
            $type = (string) $spec['type'];
            $page = $this->makePage("empty-{$type}");

            /** @var array<string, mixed> $defaults */
            $defaults = (array) ($spec['defaults'] ?? []);

            $result = $patch->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => 'page',
                'component' => [
                    'type' => 'section',
                    'props' => [],
                    'children' => [array_merge($defaults, ['type' => $type])],
                ],
            ], $context);

            $this->assertTrue(
                $result->isSuccess(),
                "[{$type}] を空のページへセクションごと置けません: ".json_encode($result->errors, JSON_UNESCAPED_UNICODE),
            );

            $section = $result->data['document']['root']['children'][0];
            $this->assertSame('section', $section['type']);
            $this->assertSame($type, $section['children'][0]['type']);
            $this->assertNotSame('', (string) $section['children'][0]['id']);
            $this->assertNotSame($section['id'], $section['children'][0]['id']);
        }
    }

    /**
     * Canvas の中に「編集画面へ戻る」を描かない（UX 一周 2026-09-27）。
     *
     * `hidden` 属性で隠していたが、案内の CSS（display: flex）が勝って見えていた。
     * 押すと **iframe の中に管理画面が開き、画面が入れ子で二重に出た**。
     * 描かないことと、単独のプレビューでは枠の外（`_top`）へ戻ることを固める。
     */
    public function test_the_canvas_does_not_render_the_back_link_and_the_preview_leaves_the_frame(): void
    {
        $page = $this->makePage();

        $canvas = $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/preview?canvas=1")
            ->assertOk();

        $canvas->assertDontSee('page-preview-notice', false);
        $canvas->assertDontSee('page-preview-back', false);

        $preview = $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/preview")
            ->assertOk();

        $preview->assertSee('data-testid="page-preview-notice"', false);
        $this->assertMatchesRegularExpression(
            '/<a [^>]*target="_top"[^>]*data-testid="page-preview-back"/',
            (string) $preview->getContent(),
        );
    }

    /**
     * Inspector は **宣言から**入力欄を組み立てる（`PHASE9_6_SPEC.md` §4.3）。
     *
     * 画面が独自に項目を持たないよう、style の表示名と選べる値、
     * token 型 prop の選択肢は**サーバーが渡す**。
     */
    public function test_editor_hands_over_the_declarations_the_inspector_builds_from(): void
    {
        $page = $this->makePage();

        $response = $this->actingAs($this->admin())->get("/admin/pages/{$page->id}/editor");

        /** @var array<string, array<string, mixed>> $styles */
        $styles = (array) $response->inertiaProps('styles');

        // 値の集合が閉じているものは選択肢が返る
        $this->assertSame(['section', 'gap', 'gapSmall'], $styles['spaceTop']['values']);
        $this->assertSame(['hidden'], $styles['visibility']['values']);
        $this->assertSame('上の余白', $styles['spaceTop']['label']);

        $this->assertSame(['left', 'center', 'right'], $styles['textAlign']['values']);

        // 閉じていないものは自由入力（選択肢を作れない）
        $this->assertNull($styles['color']['values']);

        /** @var array<string, list<string>> $tokens */
        $tokens = (array) $response->inertiaProps('tokens');

        $this->assertSame(['text', 'textMuted', 'bg', 'bgAlt', 'primary', 'primaryContrast', 'border'], $tokens['colors']);
        $this->assertSame(['section', 'gap', 'gapSmall'], $tokens['spacing']);

        /** @var list<array<string, mixed>> $breakpoints */
        $breakpoints = (array) $response->inertiaProps('breakpoints');

        // 名前も条件も幅も theme.json 由来。画面側には持たせない
        $this->assertSame(
            [
                ['name' => 'mobile', 'label' => 'スマホ', 'condition' => '(max-width: 767px)', 'width' => 767],
                ['name' => 'tablet', 'label' => 'タブレット', 'condition' => '(min-width: 768px) and (max-width: 1199px)', 'width' => 768],
                ['name' => 'desktop', 'label' => 'パソコン', 'condition' => '(min-width: 1200px)', 'width' => 1200],
            ],
            $breakpoints,
        );
    }

    /**
     * 画面側に画面幅を焼き込まない（実装条件 1）。
     *
     * `mobile` / `tablet` / `desktop` や px を書くと、
     * 案件が theme.json を変えたときに Editor だけ古い前提のまま残る。
     */
    public function test_the_editor_screen_does_not_hardcode_breakpoints(): void
    {
        foreach ([
            'resources/js/pages/admin/pages/Editor.vue',
            'resources/js/components/page-editor/InspectorPanel.vue',
            'resources/js/components/page-editor/CanvasFrame.vue',
        ] as $path) {
            $code = (string) File::get(base_path($path));

            // 画面幅の**名前**
            foreach (['mobile', 'tablet', 'desktop'] as $name) {
                $this->assertStringNotContainsString(
                    $name,
                    $code,
                    "{$path} が画面幅の名前 [{$name}] を直接持っている",
                );
            }

            // 画面幅の**境目**（`min-width: 768px` のような具体値）。
            // 何が足りなかったかを説明する散文まで禁じても意味が無いので、
            // 見るのは「値として書かれているか」だけにする
            $this->assertDoesNotMatchRegularExpression(
                '/(min|max)-width\s*:\s*\d/',
                $code,
                "{$path} が画面幅の境目を直接持っている",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\b\d{3,4}px\b/',
                $code,
                "{$path} が画面幅らしき px を直接持っている",
            );
        }
    }

    /**
     * **パレットに出さないことと、既にあるものを編集できないことは別。**
     *
     * `html` は新規追加させないが、既に document にある node は
     * 名前を出して編集できないと直せなくなる（Owner Gate V1）。
     */
    public function test_existing_html_blocks_stay_editable_even_though_they_cannot_be_added(): void
    {
        $page = $this->makePage();

        $response = $this->actingAs($this->admin())->get("/admin/pages/{$page->id}/editor");

        $palette = array_column((array) $response->inertiaProps('components'), 'type');
        $specs = array_column((array) $response->inertiaProps('specs'), 'type');

        $this->assertNotContains('html', $palette);
        $this->assertContains('html', $specs);
        $this->assertCount(13, $specs, 'coverを含む登録13種すべてを渡すこと');
    }

    /**
     * 止めているあいだは **画像も触らせない**。
     *
     * 見た目だけ無効にしても、選択ダイアログが開けば変更はそこからだけ通ってしまう。
     * `MediaPicker` は受け取った `disabled` で **実際の動作も止める**必要がある。
     */
    public function test_the_media_picker_can_be_switched_off_and_the_inspector_switches_it_off(): void
    {
        $picker = (string) File::get(base_path('resources/js/components/schema/MediaPicker.vue'));

        // 既定は false（他の画面の呼び出しを変えない）。
        // Phase 9.5-D で `kind` が足されたので、props の宣言全体ではなく既定値を見る
        $this->assertStringContainsString('disabled?: boolean', $picker);
        $this->assertStringContainsString('disabled: false', $picker);

        // 見た目だけでなく、開く・選ぶ・外すのすべてを止める
        foreach (['function openPicker', 'function choose', 'function clear'] as $guarded) {
            $position = strpos($picker, $guarded);

            $this->assertNotFalse($position, "{$guarded} が無い");
            $this->assertStringContainsString(
                'props.disabled',
                substr($picker, $position, 160),
                "{$guarded} が disabled を見ていない",
            );
        }

        /*
         * **読み込みは止めない**（Phase 9.5-C で訂正）。
         *
         * `disabled` は「変更させない」であって「見せない」ではない。
         * 一覧の取得まで止めると現在値のサムネイルも名前も引けず、
         * 選ばれているのに「未設定」と表示してしまう。
         * 止めるのは変更の入口（開く・選ぶ・外す）だけで、それは上で固定している。
         */
        $this->assertStringNotContainsString('loaded.value || props.disabled', $picker);
        $this->assertMatchesRegularExpression(
            '/async function load\(\)[^}]*loadLibrary\(\)/s',
            $picker,
            '一覧の取得が共有経路を通っていない',
        );

        $inspector = (string) File::get(base_path('resources/js/components/page-editor/InspectorPanel.vue'));

        $this->assertMatchesRegularExpression(
            '/<MediaPicker[^>]*:disabled="!editable\(key\)"/s',
            $inspector,
            'Inspector が MediaPicker へ停止状態を渡していない',
        );
    }

    /** コードで管理されたページには document が無いので、開く対象が無い */
    public function test_editor_is_not_available_for_code_managed_pages(): void
    {
        $page = $this->makePage('blade-page', 'コード管理');
        $page->forceFill(['managed_by' => Page::MANAGED_BY_BLADE])->save();

        $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->assertNotFound();
    }

    public function test_editor_requires_authentication(): void
    {
        $page = $this->makePage();

        $this->get("/admin/pages/{$page->id}/editor")->assertRedirect('/login');
    }

    /**
     * 画面を足しても **document の変更経路は増やさない**。
     * 管理画面から document を直接 PATCH する route は無いままであること。
     */
    public function test_the_editor_does_not_add_a_second_change_path(): void
    {
        $page = $this->makePage();

        $this->assertContains(
            $this->actingAs($this->admin())->patch("/admin/pages/{$page->id}/document")->status(),
            [404, 405],
        );
    }
}
