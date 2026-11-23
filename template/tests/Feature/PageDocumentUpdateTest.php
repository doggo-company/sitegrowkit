<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Page;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C3: Inspector が乗る `component.update` の意味。
 *
 * Inspector は入力欄 1 つの変更を `component.update` へ落とす。
 * **入力を空にしたときに何が起きるか**が決まっていないと、
 * 「一度付けた見た目を外せない」画面になる。ここでその意味を固定する。
 */
final class PageDocumentUpdateTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    /** @param array<string, mixed> $command */
    private function command(Page $page, array $command): Result
    {
        return app(PatchPageDocument::class)->execute($page->id, $command, $this->admin());
    }

    /** @param array<string, mixed> $command */
    private function apply(Page $page, array $command): array
    {
        $result = $this->command($page, $command);

        $this->assertTrue(
            $result->isSuccess(),
            (string) $result->message.' '.json_encode($result->errors, JSON_UNESCAPED_UNICODE),
        );

        return $result->data['document'];
    }

    /** section > heading を 1 つずつ持つページ。heading の id を返す */
    private function pageWithHeading(): array
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '編集', 'slug' => 'inspector'],
            $this->admin(),
        );

        $page = Page::query()->findOrFail($result->data['page']['id']);

        $document = $this->apply($page, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ]);

        $document = $this->apply($page, [
            'operation' => 'component.insert',
            'parent' => (string) $document['root']['children'][0]['id'],
            'component' => ['type' => 'heading', 'props' => ['text' => '元の見出し', 'level' => 2]],
        ]);

        return [$page, (string) $document['root']['children'][0]['children'][0]['id']];
    }

    /** @param array<string, mixed> $document */
    private function heading(array $document): array
    {
        return $document['root']['children'][0]['children'][0];
    }

    public function test_updating_a_prop_keeps_the_other_props(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['props' => ['text' => '新しい見出し']],
        ]);

        $heading = $this->heading($document);

        $this->assertSame('新しい見出し', $heading['props']['text']);
        $this->assertSame(2, $heading['props']['level'], '触っていない設定が消えました');
    }

    /**
     * **空にしたら外せる**こと。
     *
     * 送った値が `null` のキーは消える。`{}` を送っても現在値と混ぜられるので、
     * 「外す」を表す方法がこれ以外に無い。
     */
    public function test_sending_null_removes_the_key(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['style' => ['spaceTop' => 'gap']],
        ]);

        $this->assertSame('gap', $this->heading($document)['style']['spaceTop']);

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['style' => ['spaceTop' => null]],
        ]);

        $this->assertArrayNotHasKey(
            'spaceTop',
            $this->heading($document)['style'] ?? [],
            '外した見た目が残っています',
        );
    }

    public function test_removing_one_style_keeps_the_others(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['style' => ['spaceTop' => 'gap', 'textAlign' => 'center']],
        ]);

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['style' => ['spaceTop' => null]],
        ]);

        $style = $this->heading($document)['style'] ?? [];

        $this->assertArrayNotHasKey('spaceTop', $style);
        $this->assertSame('center', $style['textAlign'] ?? null);
    }

    // ── 画面幅ごとの上書き（入れ子の削除） ────────────────────────────

    /** @param array<string, mixed> $document */
    private function responsive(array $document): array
    {
        return $this->heading($document)['responsive'] ?? [];
    }

    public function test_a_breakpoint_override_is_stored_per_breakpoint(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['mobile' => ['visibility' => 'hidden']]],
        ]);

        $this->assertSame(['mobile' => ['visibility' => 'hidden']], $this->responsive($document));
    }

    /**
     * **画面幅ごとの上書きは入れ子**なので、浅い差し替えでは壊れる。
     *
     * 1 つの breakpoint に 2 つ指定してあるとき、片方だけ送っても
     * もう片方が消えてはいけない。
     */
    public function test_updating_one_key_of_a_breakpoint_keeps_the_others(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['mobile' => ['visibility' => 'hidden', 'spaceTop' => 'gap']]],
        ]);

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['mobile' => ['spaceTop' => 'section']]],
        ]);

        /*
         * **中身で比べる。並びでは比べない。**
         *
         * MySQL の JSON 列は object のキー順を正規化するので、保存した順では返らない。
         * ここが見たいのは「**触っていない指定が残っているか**」であって、並びではない。
         *
         * **緩めたのは並びだけで、型ではない。** `assertEquals` は `5` と `"5"` を
         * 通すが、型はこの試験のほかの `assertSame` が押さえている。
         */
        $this->assertEquals(
            ['mobile' => ['visibility' => 'hidden', 'spaceTop' => 'section']],
            $this->responsive($document),
        );
    }

    public function test_null_inside_a_breakpoint_removes_only_that_key(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => [
                'mobile' => ['visibility' => 'hidden', 'spaceTop' => 'gap'],
                'tablet' => ['spaceTop' => 'section'],
            ]],
        ]);

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['mobile' => ['visibility' => null]]],
        ]);

        $this->assertSame(
            [
                'mobile' => ['spaceTop' => 'gap'],
                'tablet' => ['spaceTop' => 'section'],
            ],
            $this->responsive($document),
            '他の指定まで壊れています',
        );
    }

    /** 最後の 1 つを外したら、その画面幅の入れ物ごと片付ける */
    public function test_emptying_a_breakpoint_removes_the_breakpoint(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => [
                'mobile' => ['visibility' => 'hidden'],
                'tablet' => ['spaceTop' => 'section'],
            ]],
        ]);

        $document = $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['mobile' => ['visibility' => null]]],
        ]);

        $this->assertSame(['tablet' => ['spaceTop' => 'section']], $this->responsive($document));
    }

    public function test_an_undeclared_breakpoint_is_rejected(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['watch' => ['visibility' => 'hidden']]],
        ]);

        $this->assertFalse($result->isSuccess(), 'テーマに無い画面幅が保存できました');
    }

    public function test_a_key_that_is_not_responsive_is_rejected(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            // 見出しの color は画面幅ごとに変えられると宣言していない
            'changes' => ['responsive' => ['mobile' => ['color' => '{colors.primary}']]],
        ]);

        $this->assertFalse($result->isSuccess(), '宣言に無いキーが画面幅ごとに保存できました');
    }

    public function test_a_value_that_is_not_declared_is_rejected_per_breakpoint(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['responsive' => ['mobile' => ['spaceTop' => '10px']]],
        ]);

        $this->assertFalse($result->isSuccess(), '宣言に無い値が保存できました');
    }

    // ── 宣言に無いもの・危険なものは通さない ──────────────────────────

    public function test_a_prop_that_is_not_declared_is_rejected(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['props' => ['onclick' => 'alert(1)']],
        ]);

        $this->assertFalse($result->isSuccess(), '宣言に無い設定が保存できました');
    }

    public function test_a_style_that_is_not_declared_is_rejected(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['style' => ['position' => 'fixed']],
        ]);

        $this->assertFalse($result->isSuccess(), '宣言に無いスタイルが保存できました');
    }

    public function test_a_dangerous_link_is_rejected(): void
    {
        $result = app(CreatePage::class)->execute(
            ['title' => 'リンク', 'slug' => 'inspector-link'],
            $this->admin(),
        );

        $page = Page::query()->findOrFail($result->data['page']['id']);

        $document = $this->apply($page, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ]);

        $document = $this->apply($page, [
            'operation' => 'component.insert',
            'parent' => (string) $document['root']['children'][0]['id'],
            'component' => ['type' => 'button', 'props' => ['label' => 'ボタン', 'href' => '#']],
        ]);

        $buttonId = (string) $document['root']['children'][0]['children'][0]['id'];

        foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>'] as $href) {
            $rejected = $this->command($page, [
                'operation' => 'component.update',
                'target' => $buttonId,
                'changes' => ['props' => ['href' => $href]],
            ]);

            $this->assertFalse($rejected->isSuccess(), "危険なリンク先が保存できました: {$href}");
        }
    }

    public function test_a_value_longer_than_declared_is_rejected(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            // heading.text は max 200
            'changes' => ['props' => ['text' => str_repeat('あ', 201)]],
        ]);

        $this->assertFalse($result->isSuccess(), '長さの制限が効いていません');
    }
}
