<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 再利用 component の描画（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 見るもの
 *
 * - **公開は `published_document`、preview は `draft_document`**（未公開の変更が漏れない）
 * - **出せないものは出さない**（fail closed）。ページ全体は壊さない
 * - **問い合わせが参照の数で増えない**（N+1）
 */
final class ReusableComponentRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    /** @param array<string, mixed> $extra */
    private function node(string $type, array $extra = []): array
    {
        return array_merge(['id' => NodeId::generate(), 'type' => $type, 'props' => [], 'children' => []], $extra);
    }

    private function subtree(string $text): array
    {
        return $this->node('section', ['children' => [
            $this->node('heading', ['props' => ['text' => $text, 'level' => 2]]),
        ]]);
    }

    /** @param array<string, mixed> ...$children */
    private function document(array ...$children): array
    {
        return [
            'schemaVersion' => 1,
            'root' => ['id' => NodeId::generate(), 'type' => 'page', 'props' => [], 'children' => $children],
        ];
    }

    /** @param array<string, mixed> $document */
    private function render(array $document, bool $preview = false): string
    {
        // 描画に要るのは page の title / slug だけ（binding が読む）。保存しない
        $page = new Page(['slug' => 'render', 'title' => '描画']);

        return (string) app(DocumentRenderer::class)
            ->render($document, new RenderContext($page, [], preview: $preview));
    }

    private function published(string $draft, string $public): ReusableComponent
    {
        return ReusableComponent::query()->create([
            'key' => 'header-'.NodeId::generate(),
            'label' => 'ヘッダー',
            'draft_document' => $this->subtree($draft),
            'published_document' => $this->subtree($public),
            'published_at' => now(),
        ]);
    }

    // ── 公開と下書きの読み分け ──────────────────────────

    /** **これが 2 document 案の要点である。** */
    public function test_公開側は公開版を描く(): void
    {
        $component = $this->published('編集中の見出し', '公開中の見出し');

        $html = $this->render($this->document(
            $this->node('section', ['instanceOf' => $component->id]),
        ));

        $this->assertStringContainsString('公開中の見出し', $html);
        $this->assertStringNotContainsString('編集中の見出し', $html, '未公開の変更が公開側へ出ています');
    }

    public function test_previewは下書きを描く(): void
    {
        $component = $this->published('編集中の見出し', '公開中の見出し');

        $html = $this->render($this->document(
            $this->node('section', ['instanceOf' => $component->id]),
        ), preview: true);

        $this->assertStringContainsString('編集中の見出し', $html);
    }

    // ── 出せないものは出さない ──────────────────────────

    /**
     * **入れ物ごと出ない。**
     *
     * 中身が無いことだけを見ていると、**空の入れ物が描かれていても緑になる**
     * （実際、変異でそれを通した）。空の `section` は余白を作るので、
     * 「出さない」と言いながら見た目が動く。だから**その node の id が出力に無い**ことを見る。
     */
    public function test_未公開のものは公開側に出ない(): void
    {
        $component = ReusableComponent::query()->create([
            'key' => 'not-published',
            'label' => '未公開',
            'draft_document' => $this->subtree('まだ公開していない'),
        ]);

        $holder = $this->node('section', ['instanceOf' => $component->id]);
        $html = $this->render($this->document($holder));

        $this->assertStringNotContainsString('まだ公開していない', $html);
        $this->assertStringNotContainsString($holder['id'], $html, '空の入れ物が描かれています');
    }

    public function test_未公開でもpreviewには出る(): void
    {
        $component = ReusableComponent::query()->create([
            'key' => 'draft-only',
            'label' => '未公開',
            'draft_document' => $this->subtree('下書きの中身'),
        ]);

        $html = $this->render($this->document(
            $this->node('section', ['instanceOf' => $component->id]),
        ), preview: true);

        $this->assertStringContainsString('下書きの中身', $html);
    }

    /**
     * **実体が消えていても、ページ全体は壊さない。**
     *
     * 参照側に外部キーは張れない（参照は JSON の中）ので、DB を直接操作すれば
     * 実体は消え得る。そのときその node だけが空になる（54f91de の向き）。
     */
    public function test_実体が消えていてもページは壊れない(): void
    {
        $component = $this->published('x', '消える中身');
        DB::table('reusable_components')->where('id', $component->id)->delete();

        $holder = $this->node('section', ['instanceOf' => $component->id]);

        $html = $this->render($this->document(
            $holder,
            $this->node('section', ['children' => [
                $this->node('heading', ['props' => ['text' => '隣の見出し', 'level' => 2]]),
            ]]),
        ));

        $this->assertStringNotContainsString('消える中身', $html);
        $this->assertStringNotContainsString($holder['id'], $html, '空の入れ物が描かれています');
        $this->assertStringContainsString('隣の見出し', $html, '隣の内容まで消えています');
    }

    public function test_しまわれたものは描かない(): void
    {
        $component = $this->published('x', 'しまわれた中身');
        $component->update(['archived_at' => now()]);

        $holder = $this->node('section', ['instanceOf' => $component->id]);
        $html = $this->render($this->document($holder));

        $this->assertStringNotContainsString('しまわれた中身', $html);
        $this->assertStringNotContainsString($holder['id'], $html, '空の入れ物が描かれています');
    }

    // ── 描く側の上限（参照は生き物）──────────────────────

    /**
     * **保存時に通ったページでも、参照先が後から育てば描画は膨らむ。**
     *
     * 500 node で保存したページの参照先を 20 万 node に育てると、**誰も
     * そのページを触っていないのに**描画が 20 万 node を展開する。
     * だから描く側にも上限があり、超えたら**その node だけ描かない**。
     *
     * **落ちてはいけない。** 描いている最中に例外を投げると、参照が育ったせいで
     * ページごと消える。
     */
    public function test_展開が上限を超えても落ちずにその部分だけ消える(): void
    {
        Log::spy();

        // 育った実体（上限 5000 を 1 つで超える）
        $children = [];

        for ($i = 0; $i < 6000; $i++) {
            $children[] = $this->node('heading', ['props' => ['text' => "行 {$i}", 'level' => 2]]);
        }

        $grown = ReusableComponent::query()->create([
            'key' => 'grown',
            'label' => '育ったもの',
            'draft_document' => $this->node('section', ['children' => $children]),
            'published_document' => $this->node('section', ['children' => $children]),
            'published_at' => now(),
        ]);

        $holder = $this->node('section', ['instanceOf' => $grown->id]);

        $html = $this->render($this->document(
            $holder,
            $this->node('section', ['children' => [
                $this->node('heading', ['props' => ['text' => '隣の見出し', 'level' => 2]]),
            ]]),
        ));

        // **ページは出る**
        $this->assertStringContainsString('隣の見出し', $html, '上限超過でページごと消えています');
        // その node だけ消える
        $this->assertStringNotContainsString($holder['id'], $html, '上限を超えたのに描いています');
        $this->assertStringNotContainsString('行 0', $html);

        // 画面に出ない不具合になるので、理由は記録に残す
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ── 古い版と、参照の先 ──────────────────────────────

    /**
     * **古い版を描いても、参照の先はいまの公開版で展開される**（§4.10 の帰結）。
     *
     * 版が持っているのは `instanceOf` という参照だけで、実体の中身は持っていない。
     * だから「版 A を復元しても、実体が新しければ表示は元へ戻らない」。
     * **参照は生き物**である、という設計をここで固定する。
     */
    public function test_古い版でも参照の先はいまの公開版で描かれる(): void
    {
        $component = $this->published('編集中', '最初の中身');

        // その時点の document（= 古い版に相当するもの）
        $old = $this->document($this->node('section', ['instanceOf' => $component->id]));

        $this->assertStringContainsString('最初の中身', $this->render($old));

        // 実体だけを公開し直す（ページの document は 1 バイトも変えない）
        $component->update([
            'published_document' => $this->subtree('あとの中身'),
            'published_at' => now(),
        ]);

        $html = $this->render($old);

        $this->assertStringContainsString('あとの中身', $html, '参照の先が古いままです');
        $this->assertStringNotContainsString('最初の中身', $html);
    }

    // ── N+1 ─────────────────────────────────────────────

    /**
     * **問い合わせが参照の数で増えない。**
     *
     * 数を書かずに見る: **1 か所参照したときと 10 か所参照したときで同じ**であること。
     * 数で固定すると、周りの都合（seeder・設定）が変わるたびに嘘になる。
     */
    public function test_参照の数で問い合わせが増えない(): void
    {
        $component = $this->published('x', '中身');

        $one = $this->countQueries(fn (): string => $this->render($this->document(
            $this->node('section', ['instanceOf' => $component->id]),
        )));

        $children = [];

        for ($i = 0; $i < 10; $i++) {
            $children[] = $this->node('section', ['instanceOf' => $component->id]);
        }

        $ten = $this->countQueries(fn (): string => $this->render($this->document(...$children)));

        $this->assertSame($one, $ten, '参照の数だけ問い合わせが増えています');
    }

    /** 別の実体を 3 つ参照しても、引くのは 1 回である */
    public function test_複数の実体でも問い合わせは1回(): void
    {
        $components = [];

        for ($i = 0; $i < 3; $i++) {
            $components[] = $this->node('section', [
                'instanceOf' => $this->published('x', "中身 {$i}")->id,
            ]);
        }

        $queries = $this->countQueries(fn (): string => $this->render($this->document(...$components)));

        $this->assertSame(1, $queries, '実体の数だけ引いています');
    }

    private function countQueries(callable $run): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $run();

        // listen は外せないので、この test 内での増分だけを見る
        return $count;
    }
}
