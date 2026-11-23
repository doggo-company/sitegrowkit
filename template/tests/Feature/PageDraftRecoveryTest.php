<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 編集中の版が居なくなったとき（Phase 9.6 / SPEC §4.11）。
 *
 * ## 2 つの別々の状況が、同じ扱いになっていた
 *
 * `PageRepository::draft()` は、
 *
 * - **A: `draft_version_id` が null**（まだ下書きが無い。正常）
 * - **B: 指しているのに、その版が居ない**（壊れている）
 *
 * のどちらでも黙って公開版から作り直していた。B では**編集していた内容が既に
 * 消えている**のに、例外も警告も出なかった。
 *
 * `pages.draft_version_id` は外部キーではないので、指している版を消しても DB は
 * 止めない（`PageVersionPruneTest` の docblock 参照）。守りは間引き側にしか無かった。
 *
 * ## 読みは復旧し、書きは断る
 *
 * 読めなくすると、**版の履歴へ行く道まで塞がる**（履歴の UI は編集画面の中にある）。
 * だから読みは復旧させ、**失われたことを応答で伝える**。黙って別物を書き足させない
 * のが書き込み側の仕事である。
 */
final class PageDraftRecoveryTest extends TestCase
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

    /** 下書きを持ち、その版が消えたページ（= B） */
    private function pageWithMissingDraft(): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '失われた下書き', 'slug' => 'lost-draft'],
            $this->context(),
        );

        $page = Page::query()->findOrFail($result->data['page']['id']);

        PageVersion::query()->where('id', $page->draft_version_id)->delete();

        return $page->fresh() ?? $page;
    }

    // ── B: 壊れている ────────────────────────────────────

    /** **黙って別物を書き足させない。** */
    public function test_編集中の版が居なければ書き込みを断る(): void
    {
        $page = $this->pageWithMissingDraft();

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $this->context());

        $this->assertFalse($result->isSuccess(), '失われた下書きの上に書き込めています');
    }

    public function test_編集中の版が居なければ公開も断る(): void
    {
        $page = $this->pageWithMissingDraft();

        $result = app(PublishPage::class)->execute($page->id, $this->context());

        $this->assertFalse($result->isSuccess(), '失われた下書きを公開しています');
    }

    /**
     * **読めるが、失われたことを言う。**
     *
     * 読めなくすると版の履歴へ行けなくなる（履歴の UI は編集画面の中）。
     */
    public function test_読みは復旧するが失われたことを伝える(): void
    {
        $page = $this->pageWithMissingDraft();

        $result = app(GetPageDocument::class)->execute($page->id, $this->context());

        $this->assertTrue($result->isSuccess(), '編集画面が開けません（履歴への道も塞がります）');

        $notice = $result->meta['draftRecovered'] ?? null;

        $this->assertIsString($notice, '失われたことを伝えていません');
        $this->assertStringContainsString('復元', $notice, '次に何をすればよいか言っていません');
    }

    /**
     * **順序で守りが変わる。**
     *
     * 読みが復旧させた瞬間、B は A に変わる。だから書き込みの拒否が効くのは
     * **読みより先に書きが来た場合**（API / MCP のような headless の経路）だけで、
     * 画面から入る経路では「読み時の知らせ」が守りの本体である。
     *
     * この順序を書いておかないと、後から「書き込み拒否が画面経路で再現できない」
     * という混乱になる。
     */
    public function test_読んだ後は書き込みが通る(): void
    {
        $page = $this->pageWithMissingDraft();

        app(GetPageDocument::class)->execute($page->id, $this->context());

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $this->context());

        $this->assertTrue($result->isSuccess(), '復旧した後も書き込めません');
    }

    /** 失われたことを**運用の記録にも**残す */
    public function test_失われた版を記録に残す(): void
    {
        Log::spy();

        $page = $this->pageWithMissingDraft();
        $missing = $page->draft_version_id;

        app(GetPageDocument::class)->execute($page->id, $this->context());

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($page, $missing): bool {
                return ($context['page_id'] ?? null) === $page->id
                    && ($context['missing_version_id'] ?? null) === $missing;
            });
    }

    // ── A: 正常（回帰止め）──────────────────────────────

    /** **下書きが無いのは壊れていない。** 公開版から作る */
    public function test_下書きが無いときは公開版から作る(): void
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '下書きなし', 'slug' => 'no-draft'],
            $this->context(),
        );

        $page = Page::query()->findOrFail($result->data['page']['id']);

        app(PublishPage::class)->execute($page->id, $this->context());

        // 公開したうえで draft の指し先だけ外す（版は残っている = A）
        DB::table('pages')->where('id', $page->id)->update(['draft_version_id' => null]);

        $read = app(GetPageDocument::class)->execute($page->id, $this->context());

        $this->assertTrue($read->isSuccess());
        $this->assertNull($read->meta['draftRecovered'] ?? null, '正常な経路で警告を出しています');
    }
}
