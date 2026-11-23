<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Components\ReusableReferences;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 誰がこの再利用 component を使っているか（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 走査は 1 か所・数え方は消費側
 *
 * 「参照が在るか」（archive の拒否）と「何件か」（影響数）を別々に書くと、
 * 「削除は 3 件・publish は 5 件」のずれが必ず起き、**どちらが本当かを利用者が
 * 決められない**。だから歩くのは 1 か所で、**向きの違いは filter で表す**:
 *
 * - `publish` … **公開に出るものを主に**（過大な警告は読まれなくなる）
 * - `archive` … **下書きでもゴミ箱でも、参照は参照**（1 つでも残っていれば断る）
 *
 * **この試験は、その 2 つの向きが実際に別の答えを返すことを見る。**
 */
final class ReusableReferencesTest extends TestCase
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

    private function reusable(): ReusableComponent
    {
        return ReusableComponent::query()->create([
            'key' => 'header',
            'label' => 'ヘッダー',
            'draft_document' => [
                'id' => NodeId::generate(),
                'type' => 'section',
                'props' => [],
                'children' => [[
                    'id' => NodeId::generate(),
                    'type' => 'heading',
                    'props' => ['text' => '見出し', 'level' => 2],
                    'children' => [],
                ]],
            ],
        ]);
    }

    /** その実体を参照する section を 1 つ持つページ */
    /**
     * 実体を参照するページ。
     *
     * **参照する入れ物の type は実体の起点と同じ**（`section`）にする。
     * 揃っていないと、展開したときに置き場所の規則に反する木ができる（§4.5）。
     */
    private function pageUsing(ReusableComponent $reusable, string $slug): Page
    {
        $page = $this->page($slug);

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'instanceOf' => $reusable->id],
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        // 参照が入っていること（前提の確認）
        $this->assertContains($reusable->id, ReusableReferences::instanceIds($result->data['document']));

        return $page->fresh() ?? $page;
    }

    /**
     * **id を本文に書いただけのページは、参照ではない**（F-22）。
     *
     * 粗く絞るための `LIKE` は、本文に id 文字列が入っているだけで通る。
     * **決めるのは木を歩く側**である、という判断がここで検査される。
     *
     * これが無いと、決める側を「`LIKE` の結果をそのまま返す」に変えても緑のままだった。
     * 実害は 2 つ: **archive が永久に断られる**／**影響数が過大になる**。
     */
    public function test_本文にidを書いただけのページは参照ではない(): void
    {
        $reusable = $this->reusable();
        $page = $this->page('decoy');

        // 本文に id をそのまま含める（`instanceOf` では使わない）
        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $this->context());

        $document = app(GetPageDocument::class)->execute($page->id, $this->context())->data['document'];
        $section = (string) $document['root']['children'][0]['id'];

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => ['type' => 'text', 'props' => ['text' => "この id を貼ります: {$reusable->id}"]],
        ], $this->context());

        // 粗い絞り込みは通ってしまう側であることを確かめる（通らなければ試験の意味が無い）
        $this->assertTrue(
            DB::table('page_versions')->where('document', 'like', '%'.$reusable->id.'%')->exists(),
            '粗い絞り込みに引っかかっていません（この試験が何も見ていません）',
        );

        $this->assertSame([], app(ReusableReferences::class)->pagesUsing($reusable->id));
    }

    private function page(string $slug): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => $slug, 'slug' => $slug],
            $this->context(),
        );

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    public function test_参照していないページは出てこない(): void
    {
        $reusable = $this->reusable();

        app(CreatePage::class)->execute(['title' => '無関係', 'slug' => 'unrelated'], $this->context());

        $this->assertSame([], app(ReusableReferences::class)->pagesUsing($reusable->id));
    }

    /**
     * **下書きにだけ在る参照は、公開の見た目を変えない。**
     *
     * publish の確認が数えるのは「いま公開されている見た目がいくつ変わるか」なので、
     * ここを混ぜると**過大な警告**になる。
     */
    public function test_下書きだけの参照は公開に影響しない(): void
    {
        $reusable = $this->reusable();
        $page = $this->pageUsing($reusable, 'draft-only');

        $references = app(ReusableReferences::class)->pagesUsing($reusable->id);

        $this->assertCount(1, $references);
        $this->assertTrue($references[0]->inDraft);
        $this->assertFalse($references[0]->inPublished, '公開していないのに公開扱いです');
        $this->assertFalse($references[0]->affectsPublic());
        $this->assertSame($page->id, $references[0]->pageId);
    }

    public function test_公開したページは公開に影響する(): void
    {
        $reusable = $this->reusable();
        $page = $this->pageUsing($reusable, 'published-page');

        app(PublishPage::class)->execute($page->id, $this->context());

        $references = app(ReusableReferences::class)->pagesUsing($reusable->id);

        $this->assertCount(1, $references);
        $this->assertTrue($references[0]->inPublished);
        $this->assertTrue($references[0]->affectsPublic());
    }

    /**
     * **ゴミ箱のページは公開の見た目を変えないが、参照は参照である。**
     *
     * publish の数からは外れ、archive の拒否には効く。**向きが逆**であることの実地。
     */
    public function test_ゴミ箱のページは公開に影響しないが参照は残る(): void
    {
        $reusable = $this->reusable();
        $page = $this->pageUsing($reusable, 'trashed-page');

        app(PublishPage::class)->execute($page->id, $this->context());
        DB::table('pages')->where('id', $page->id)->update(['trashed_at' => now()]);

        $references = app(ReusableReferences::class)->pagesUsing($reusable->id);

        $this->assertCount(1, $references, '参照が消えています（archive を通してしまいます）');
        $this->assertTrue($references[0]->trashed);
        $this->assertFalse($references[0]->affectsPublic(), 'ゴミ箱を公開の影響に数えています');
    }

    /** 同じ実体を 2 度参照しても、ページは 1 件である */
    public function test_同じページの複数の参照は1件に畳む(): void
    {
        $reusable = $this->reusable();
        $page = $this->pageUsing($reusable, 'twice');

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'instanceOf' => $reusable->id],
        ], $this->context());

        $this->assertCount(1, app(ReusableReferences::class)->pagesUsing($reusable->id));
    }
}
