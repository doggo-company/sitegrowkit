<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Components\ArchiveReusableComponent;
use AgentCms\Core\Application\Components\CreateReusableComponent;
use AgentCms\Core\Application\Components\ListReusableComponents;
use AgentCms\Core\Application\Components\PublishReusableComponent;
use AgentCms\Core\Application\Components\UpdateReusableComponent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 再利用 component の操作（Phase 9.6 / P0-C7・SPEC §4.5・§5.2）。
 *
 * ## 見どころ
 *
 * - **未公開の変更が公開ページに出ない**（2 document 案の要点）
 * - **確認を求める場所が正しい**: `publish` は訊く／`update` は訊かない
 * - **archive は参照が 1 つでも残っていれば断り、参照元を返す**
 * - **patch 経由で作った参照も、同じ検証を通る**
 */
final class ReusableComponentOperationsTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    /** @param array<string, mixed> $extra */
    private function node(string $type, array $extra = []): array
    {
        return array_merge(['id' => NodeId::generate(), 'type' => $type, 'props' => [], 'children' => []], $extra);
    }

    private function subtree(string $text = '見出し'): array
    {
        return $this->node('section', ['children' => [
            $this->node('heading', ['props' => ['text' => $text, 'level' => 2]]),
        ]]);
    }

    private function create(string $key = 'header'): ReusableComponent
    {
        $result = app(CreateReusableComponent::class)->execute([
            'key' => $key,
            'label' => 'ヘッダー',
            'document' => $this->subtree(),
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return ReusableComponent::query()->findOrFail($result->data['component']['id']);
    }

    /** 確認ゲートを通して実行する */
    private function confirmed(callable $run): Result
    {
        $first = $run($this->context());

        if ($first->confirmation === null) {
            return $first;
        }

        return $run($this->context()->withConfirmationToken($first->confirmation->token));
    }

    // ── 作る ────────────────────────────────────────────

    public function test_作った時点では公開されていない(): void
    {
        $component = $this->create();

        $this->assertFalse($component->isPublished(), '作っただけで公開になっています');
        $this->assertNull($component->published_document);
    }

    public function test_キーの重複は断る(): void
    {
        $this->create('dup');

        $result = app(CreateReusableComponent::class)->execute([
            'key' => 'dup',
            'label' => '別のもの',
            'document' => $this->subtree(),
        ], $this->context());

        $this->assertFalse($result->isSuccess());
        $this->assertArrayHasKey('key', $result->errors ?? []);
    }

    public function test_未登録の型は入れられない(): void
    {
        $result = app(CreateReusableComponent::class)->execute([
            'key' => 'sneaky',
            'label' => 'だめなもの',
            'document' => $this->node('section', ['children' => [$this->node('not-registered')]]),
        ], $this->context());

        $this->assertFalse($result->isSuccess(), '未登録の型が通っています');
    }

    // ── 直す（確認を求めない）──────────────────────────

    /**
     * **下書きを直しても、どのページの見た目も変わらない。**
     *
     * だから確認を求めない。変わらない操作で訊くと、確認そのものが読まれなくなる。
     */
    public function test_直すときは確認を求めない(): void
    {
        $component = $this->create();

        $result = app(UpdateReusableComponent::class)->execute(
            $component->id,
            ['document' => $this->subtree('直した見出し')],
            $this->context(),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertNull($result->confirmation, '変わらない操作で確認を求めています');
    }

    // ── 公開（確認を求める）────────────────────────────

    public function test_公開は確認を求め公開中のページ数を言う(): void
    {
        $component = $this->create();
        $page = $this->pageUsing($component, 'uses-header');
        app(PublishPage::class)->execute($page->id, $this->context());

        $first = app(PublishReusableComponent::class)->execute($component->id, $this->context());

        $this->assertNotNull($first->confirmation, '公開で確認を求めていません');
        $this->assertStringContainsString('公開中 1', (string) $first->confirmation->summary);
    }

    /** **これが 2 document 案の要点である。** */
    public function test_公開するまで公開ページは変わらない(): void
    {
        $component = $this->create();
        $page = $this->pageUsing($component, 'live');
        app(PublishPage::class)->execute($page->id, $this->context());

        // 公開してから下書きを直す
        $this->confirmed(fn (OperationContext $c): Result => app(PublishReusableComponent::class)->execute($component->id, $c));

        app(UpdateReusableComponent::class)->execute(
            $component->id,
            ['document' => $this->subtree('あとで公開する見出し')],
            $this->context(),
        );

        $fresh = $component->fresh();

        $this->assertNotNull($fresh);
        // 公開に出るものは、まだ古いまま
        $this->assertStringContainsString(
            '見出し',
            json_encode($fresh->published_document, JSON_UNESCAPED_UNICODE) ?: '',
        );
        $this->assertStringNotContainsString(
            'あとで公開する',
            json_encode($fresh->published_document, JSON_UNESCAPED_UNICODE) ?: '',
            '未公開の変更が公開側へ出ています',
        );
    }

    // ── しまう（参照が残っていれば断る）────────────────

    public function test_使われているものはしまえず参照元を返す(): void
    {
        $component = $this->create();
        $page = $this->pageUsing($component, 'still-used');

        $result = app(ArchiveReusableComponent::class)->execute($component->id, $this->context());

        $this->assertFalse($result->isSuccess(), '使われているのにしまえています');

        $references = $result->meta['references'] ?? [];

        $this->assertCount(1, $references, '参照元を返していません');
        $this->assertSame($page->id, $references[0]['pageId']);
    }

    public function test_使われていないものはしまえる(): void
    {
        $component = $this->create();

        $result = $this->confirmed(
            fn (OperationContext $c): Result => app(ArchiveReusableComponent::class)->execute($component->id, $c),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertNotNull($component->fresh()?->archived_at);
    }

    public function test_しまったものは一覧に出ない(): void
    {
        $component = $this->create();
        $this->confirmed(fn (OperationContext $c): Result => app(ArchiveReusableComponent::class)->execute($component->id, $c));

        $listed = app(ListReusableComponents::class)->execute($this->context())->data['components'];

        $this->assertSame([], $listed);

        $withArchived = app(ListReusableComponents::class)
            ->execute($this->context(), includeArchived: true)->data['components'];

        $this->assertCount(1, $withArchived);
        $this->assertTrue($withArchived[0]['archived']);
    }

    // ── patch 経由の参照も同じ検証を通る ────────────────

    /**
     * **`instanceOf` は patch でそのまま通る**（`prepareNode()` は未知のキーを落とさない）。
     *
     * だから「操作から作った参照」だけでなく「patch から作った参照」も
     * **同じ検証**を通らなければならない。ここが抜けると、
     * 検証の掛かっていない参照を作る裏口ができる。
     */
    public function test_patch経由で作った参照も同じ検証を通る(): void
    {
        $page = $this->page('patched');
        $section = $this->section($page);

        // 存在しない実体を指す参照は、patch でも作れない
        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => ['type' => 'container', 'instanceOf' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'],
        ], $this->context());

        $this->assertFalse($result->isSuccess(), 'patch から未登録の参照が作れています');
    }

    /** **参照する入れ物の type は、実体の起点と同じでなければならない** */
    public function test_型の違う入れ物では参照できない(): void
    {
        $component = $this->create();
        $page = $this->page('wrong-holder');
        $section = $this->section($page);

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            // 実体の起点は section なのに container で参照している
            'component' => ['type' => 'container', 'instanceOf' => $component->id],
        ], $this->context());

        $this->assertFalse($result->isSuccess(), '型の違う入れ物で参照できています');
    }

    // ── 切り離し ────────────────────────────────────────

    public function test_切り離しは確認を求め戻せないと言う(): void
    {
        $component = $this->create();
        $page = $this->pageUsing($component, 'to-detach');
        $document = app(GetPageDocument::class)->execute($page->id, $this->context())->data['document'];
        $target = (string) $document['root']['children'][0]['id'];

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.detach',
            'target' => $target,
            'expectedVersion' => $this->version($page),
        ], $this->context());

        $this->assertNotNull($result->confirmation, '切り離しで確認を求めていません');
        $this->assertContains('この操作は取り消せません', $result->confirmation->impact);
    }

    public function test_切り離した後は実体を直しても変わらない(): void
    {
        $component = $this->create();
        $page = $this->pageUsing($component, 'detached');
        $document = app(GetPageDocument::class)->execute($page->id, $this->context())->data['document'];
        $target = (string) $document['root']['children'][0]['id'];

        $detached = $this->confirmed(fn (OperationContext $c): Result => app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.detach',
            'target' => $target,
            'expectedVersion' => $this->version($page),
        ], $c));

        $this->assertTrue($detached->isSuccess(), (string) $detached->message);

        $after = app(GetPageDocument::class)->execute($page->id, $this->context())->data['document'];
        $node = $after['root']['children'][0];

        $this->assertArrayNotHasKey('instanceOf', $node, '参照が残っています');
        $this->assertNotSame([], $node['children'], '中身が写っていません');

        // 実体を直しても、切り離した側は変わらない
        app(UpdateReusableComponent::class)->execute(
            $component->id,
            ['document' => $this->subtree('別の見出し')],
            $this->context(),
        );

        $again = app(GetPageDocument::class)->execute($page->id, $this->context())->data['document'];

        $this->assertSame(
            json_encode($node, JSON_UNESCAPED_UNICODE),
            json_encode($again['root']['children'][0], JSON_UNESCAPED_UNICODE),
        );
    }

    // ── ヘルパー ────────────────────────────────────────

    private function page(string $slug): Page
    {
        $result = app(CreatePage::class)->execute(['title' => $slug, 'slug' => $slug], $this->context());

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    private function section(Page $page): string
    {
        $document = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $this->context())->data['document'];

        return (string) $document['root']['children'][0]['id'];
    }

    /**
     * 実体を参照するページ。
     *
     * **参照する node の type は実体の起点と同じ**（`section`）でなければならない。
     * 揃っていないと、展開したときに置き場所の規則に反する木ができる（§4.5）。
     */
    private function pageUsing(ReusableComponent $component, string $slug): Page
    {
        $page = $this->page($slug);

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'instanceOf' => $component->id],
        ], $this->context());

        return $page->fresh() ?? $page;
    }

    private function version(Page $page): int
    {
        return (int) (app(GetPageDocument::class)->execute($page->id, $this->context())->meta['version'] ?? 0);
    }
}
