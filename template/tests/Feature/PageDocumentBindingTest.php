<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Content\ContentTypeRegistry;
use AgentCms\Core\Document\BindingTypes;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\TaxonomyFilterIndex;
use AgentCms\Core\Theme\ThemeTokens;
use App\Models\User;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * binding が「型の合うものしか書けない」こと（Phase 9.6 / P0-C5・SPEC §4.9 / Owner Gate V5）。
 *
 * ## 見ているのは「表示は出るが意味が壊れている」側
 *
 * 画像の Field を見出しへ流しても、画面は出る。壊れているのは文書の意味の方で、
 * **動かして分かる種類の壊れ方ではない**（V-7）。だから保存の時点で断る。
 */
final class PageDocumentBindingTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    /** @param array<string, mixed> ...$children */
    private function document(array ...$children): array
    {
        return [
            'schemaVersion' => 1,
            'root' => ['id' => NodeId::generate(), 'type' => 'page', 'props' => [], 'children' => $children],
        ];
    }

    /** @param array<string, mixed> $extra */
    private function node(string $type, array $extra = []): array
    {
        return array_merge(['id' => NodeId::generate(), 'type' => $type, 'props' => [], 'children' => []], $extra);
    }

    /** @param array<string, mixed> $node */
    private function errors(array $node): string
    {
        return $this->messages(
            app(DocumentValidator::class),
            $this->document($this->node('section', ['children' => [$node]])),
        );
    }

    /**
     * **検証用の component だけを載せた registry で見る。**
     *
     * `bindable` の規則を同梱 component で確かめようとすると、
     * 「宣言されていない prop」「型が合わない prop」の側で先に落ちてしまい、
     * **bindable を外しても試験が赤くならない**（実際に一度そうなった）。
     * 規則そのものを見るには、**その規則でしか落ちない prop** が要る。
     *
     * @param  array<string, mixed>  $node
     */
    private function probeErrors(array $node): string
    {
        $validator = new DocumentValidator(
            new ComponentRegistry(base_path('tests/fixtures/components-binding')),
            app(SchemaRegistry::class),
            app(ContentTypeRegistry::class),
            app(ThemeTokens::class),
            app(TaxonomyFilterIndex::class),
        );

        return $this->messages($validator, $this->document($node));
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function documentPage(): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '接続', 'slug' => 'binding-editor'],
            OperationContext::admin($this->operatorId(), 'tester'),
        );

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** @param array<string, mixed> $document */
    private function messages(DocumentValidator $validator, array $document): string
    {
        $result = $validator->validate($document);

        return implode(' / ', array_merge(...array_values($result ?: [[]])));
    }

    // ── prop が bindable であること ─────────────────────

    /**
     * **型は合っているのに bindable に入っていない prop** を使う。
     *
     * そうしないと型整合の側で落ちてしまい、bindable の規則を外しても赤くならない。
     */
    public function test_bindable_でない_prop_へは_binding_できない(): void
    {
        $errors = $this->probeErrors($this->node('probe', [
            'props' => ['text' => 'x', 'note' => 'y'],
            // note は string（news.title と型は合う）だが bindable に入っていない
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'note'],
        ]));

        $this->assertStringContainsString('流せる項目ではありません', $errors);
    }

    public function test_bindable_な_prop_へは_binding_できる(): void
    {
        $errors = $this->probeErrors($this->node('probe', [
            'props' => ['text' => 'x'],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'text'],
        ]));

        $this->assertSame('', $errors);
    }

    public function test_宣言に無い_prop_へは_binding_できない(): void
    {
        $errors = $this->errors($this->node('heading', [
            'props' => ['text' => '見出し', 'level' => 2],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'nope'],
        ]));

        $this->assertStringContainsString('nope', $errors);
    }

    /**
     * **`bindable` に居るのに宣言が無い**（registry 側の食い違い）。
     *
     * 同梱の component では起こらないが、生成 project は component を書ける。
     * 起きたときに黙って通すと、**存在しない prop への binding が保存される**。
     */
    public function test_bindable_に居るのに宣言の無い_prop_は拒否される(): void
    {
        $errors = $this->probeErrors($this->node('ghost', [
            'props' => ['text' => 'x'],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'missing'],
        ]));

        $this->assertStringContainsString('宣言されていません', $errors);
    }

    // ── prop の必須と禁止 ───────────────────────────────

    /**
     * 単一値の binding で `prop` を省くと、**宣言順を変えたときに流し先が黙って変わる**。
     */
    public function test_単一値の_binding_で_prop_を省けない(): void
    {
        foreach ([
            ['source' => 'entry', 'type' => 'news', 'field' => 'title'],
            ['source' => 'page', 'field' => 'title'],
            ['source' => 'settings', 'field' => 'site_name'],
        ] as $binding) {
            $errors = $this->errors($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'binding' => $binding,
            ]));

            $this->assertNotSame('', $errors, "[{$binding['source']}] で prop 省略が通ります");
        }
    }

    /**
     * 一覧はまるごと流し込むので、**流し先の prop が無い**。
     * 書けるようにすると「一覧をテキスト欄へ流す」が表現できてしまう。
     */
    public function test_collection_に_prop_は書けない(): void
    {
        $errors = $this->errors($this->node('collection', [
            'props' => ['layout' => 'list'],
            'binding' => ['source' => 'collection', 'type' => 'news', 'prop' => 'layout'],
        ]));

        $this->assertNotSame('', $errors);
    }

    public function test_collection_は_prop_が無ければ通る(): void
    {
        $errors = $this->errors($this->node('collection', [
            'props' => ['layout' => 'list'],
            'binding' => ['source' => 'collection', 'type' => 'news'],
        ]));

        $this->assertSame('', $errors);
    }

    // ── 型整合 ──────────────────────────────────────────

    public function test_型の合わない_binding_は拒否される(): void
    {
        // news.title は text。image.mediaId は media
        $errors = $this->errors($this->node('image', [
            'props' => ['mediaId' => 1, 'alt' => 'x'],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'mediaId'],
        ]));

        $this->assertNotSame('', $errors);
    }

    public function test_型の合う_binding_は通る(): void
    {
        $errors = $this->errors($this->node('heading', [
            'props' => ['text' => '見出し', 'level' => 2],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'text'],
        ]));

        $this->assertSame('', $errors);
    }

    // ── richtext の双方向 ───────────────────────────────

    /**
     * **sanitize を通っていない値が、raw で出す prop へ入る経路を作らない。**
     *
     * 同梱の renderer は描画時にも clean するので、ここだけで XSS になるわけではない。
     * 閉じておくのは、**生成 project が clean しない component を書ける**からである
     * （防御の重ね）。§4.9 参照。
     */
    public function test_richtext_でない_field_を_html_の_prop_へ流せない(): void
    {
        $errors = $this->errors($this->node('richtext', [
            'props' => ['html' => '<p>x</p>'],
            // news.title は text
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'html'],
        ]));

        $this->assertNotSame('', $errors);
    }

    public function test_richtext_は_html_の_prop_へ流せる(): void
    {
        $errors = $this->errors($this->node('richtext', [
            'props' => ['html' => '<p>x</p>'],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'body', 'prop' => 'html'],
        ]));

        $this->assertSame('', $errors);
    }

    public function test_richtext_を_見出しへは落とせない(): void
    {
        $errors = $this->errors($this->node('heading', [
            'props' => ['text' => '見出し', 'level' => 2],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'body', 'prop' => 'text'],
        ]));

        $this->assertNotSame('', $errors);
    }

    /**
     * **型が引けない source からは、html の prop へ流せない**（fail closed）。
     *
     * この非対称は故意である（§4.9）。他の prop では型が引けなければ検査を飛ばす。
     */
    public function test_型を引けない_source_から_html_の_prop_へは流せない(): void
    {
        foreach ([
            ['source' => 'settings', 'field' => 'site_name', 'prop' => 'html'],
            ['source' => 'page', 'field' => 'title', 'prop' => 'html'],
        ] as $binding) {
            $errors = $this->errors($this->node('richtext', [
                'props' => ['html' => '<p>x</p>'],
                'binding' => $binding,
            ]));

            $this->assertNotSame('', $errors, "[{$binding['source']}] から html の prop へ流せます");
        }
    }

    /** 型が引けない source でも、html でない prop へは流せる（開いている側） */
    public function test_型を引けない_source_でも_html_でなければ流せる(): void
    {
        $errors = $this->errors($this->node('heading', [
            'props' => ['text' => '見出し', 'level' => 2],
            'binding' => ['source' => 'settings', 'field' => 'site_name', 'prop' => 'text'],
        ]));

        $this->assertSame('', $errors);
    }

    // ── 代替テキストも記事から流せる ────────────────────

    /**
     * **画像の代替テキストは記事から流せる。**
     *
     * 画像そのものを記事から流すなら、その説明も記事から来るのが自然である。
     * `AccessibilityCheck` は「代替テキストが binding 由来なら中身が分からない」
     * という枝を持っており、`alt` が流せないとその枝へ**正しい document からは
     * 到達できない**（P0-C5 で保存を厳しくして初めて見えた）。
     */
    public function test_画像の代替テキストへ_binding_できる(): void
    {
        $errors = $this->errors($this->node('image', [
            'props' => ['mediaId' => 1, 'alt' => ''],
            'binding' => ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'alt'],
        ]));

        $this->assertSame('', $errors);
    }

    // ── 編集画面へ渡すもの ──────────────────────────────

    /**
     * **画面には表を渡さない。解いた結果だけを渡す。**
     *
     * 表を 2 つ持つと、片方だけ直したときに「UI では選べるのに保存できない」
     * （あるいはその逆）が生まれる。
     */
    public function test_component_の仕様に流せる型が入っている(): void
    {
        $specs = app(ComponentRegistry::class)->specs();
        $byType = array_column($specs, null, 'type');

        // HTML を受ける prop へは richtext だけ
        $this->assertSame(['html' => ['richtext']], $byType['richtext']['accepts']);

        // bindable でない prop は候補にも出てこない
        $this->assertSame(['mediaId', 'alt'], array_keys($byType['image']['accepts']));
        $this->assertSame(['image', 'media'], $byType['image']['accepts']['mediaId']);

        // 一覧は流し先を持たない
        $this->assertSame([], $byType['collection']['accepts']);
    }

    public function test_編集画面が接続先の候補を受け取る(): void
    {
        $page = $this->documentPage();

        $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/editor")
            ->assertInertia(fn (Assert $view) => $view
                ->has('bindingSources.entry')
                ->has('bindingSources.collection')
                // 画面に title / slug を焼き込まない。集合は PAGE_FIELDS が持つ
                ->where('bindingSources.page', array_map(
                    static fn (string $key): array => [
                        'key' => $key,
                        'label' => BindingTypes::PAGE_FIELDS[$key]['label'],
                        'type' => BindingTypes::PAGE_FIELDS[$key]['type'],
                    ],
                    array_keys(BindingTypes::PAGE_FIELDS),
                ))
                /*
                 * **`settings` は候補に無い。** schema が無く型が引けない source を
                 * UI から作れるようにすると、型検査の効かない binding を画面が量産する。
                 * 既にあるものは正当なものとして表示し、外すこともできる（作れないだけ）。
                 */
                ->missing('bindingSources.settings'),
            );
    }

    // ── page が解決できる field だけ ────────────────────

    /**
     * `BindingResolver` は `page` の `title` / `slug` しか解決しない。
     * ほかを通すと、**保存はできるが何も出ない binding** が作れる。
     */
    public function test_page_は_title_と_slug_以外を受けない(): void
    {
        $errors = $this->errors($this->node('heading', [
            'props' => ['text' => '見出し', 'level' => 2],
            'binding' => ['source' => 'page', 'field' => 'published_at', 'prop' => 'text'],
        ]));

        $this->assertStringContainsString('published_at', $errors);
    }

    public function test_page_の_title_と_slug_は受ける(): void
    {
        foreach (['title', 'slug'] as $field) {
            $errors = $this->errors($this->node('heading', [
                'props' => ['text' => '見出し', 'level' => 2],
                'binding' => ['source' => 'page', 'field' => $field, 'prop' => 'text'],
            ]));

            $this->assertSame('', $errors, "page.{$field} が拒否されました");
        }
    }
}
