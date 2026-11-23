<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 自動保存で増えた版の間引き（Phase 9.6 / P0-C6b・SPEC §4.11 / Owner Gate V8）。
 *
 * ## ここは沈黙する破壊の隣にある
 *
 * `pages.draft_version_id` / `published_version_id` は外部キーではないので、
 * **今そのページが指している版を消しても DB は止めない**。消えた後は
 * `PageRepository::draft()` が公開版から複製し直す（無ければ空 draft）ので、
 * **例外も警告も出ないまま、編集していた内容が消える**。
 *
 * だから守りは間引きの側にしかない。**この試験は「消えてはいけないものが消える」側を見る。**
 */
final class PageVersionPruneTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    // ── 守るもの ────────────────────────────────────────────

    /**
     * **公開が指している版は消えない。**
     *
     * 消えると公開面が参照先を失い、次の編集は空の draft から始まる。
     */
    public function test_公開が指している版は間引かれない(): void
    {
        [$page] = $this->pageWithVersions(10);

        /*
         * **自動保存の版**のうち古いものを公開版として指させる。
         *
         * `CreatePage` が作る最初の版は自動保存ではないので、そこを指しても
         * **間引きの候補に入らず、守りを一度も通らない**（この試験は最初それで
         * 空振りしていた）。守りを見るには、**消える側に居る版**を指す必要がある。
         */
        $published = PageVersion::query()
            ->where('page_id', $page->id)
            ->where('autosave', true)
            ->orderBy('revision')
            ->first();

        $this->assertNotNull($published);

        DB::table('pages')->where('id', $page->id)->update(['published_version_id' => $published->id]);

        $this->prune(keep: 1);

        $this->assertDatabaseHas('page_versions', ['id' => $published->id]);
    }

    /**
     * **いま編集している版（draft の head）は消えない。**
     */
    public function test_編集中の版は間引かれない(): void
    {
        [$page] = $this->pageWithVersions(10);

        /*
         * **古い版を編集中にして見る。**
         *
         * 普通は編集中の版がいちばん新しいので、直近 N の中に入って自然に残る。
         * それだけを見ていると、**守りが働いているのか、たまたま新しいだけなのか**が
         * 分からない。ここでは編集中を古い方へ動かして、守りそのものを通す。
         *
         * この状況は「復元が編集中を過去の版へ向ける」形になれば実際に起こり得る。
         */
        $old = PageVersion::query()
            ->where('page_id', $page->id)
            ->where('autosave', true)
            ->orderBy('revision')
            ->first();

        $this->assertNotNull($old);

        DB::table('pages')->where('id', $page->id)->update(['draft_version_id' => $old->id]);

        $this->prune(keep: 1);

        $this->assertDatabaseHas('page_versions', ['id' => $old->id]);
    }

    /**
     * **自動保存でない版は 1 件も消えない**（公開・復元・導入前の版）。
     */
    public function test_自動保存でない版は間引かれない(): void
    {
        [$page] = $this->pageWithVersions(10);

        // 導入前に作られた版を模す（既定 false のまま）
        DB::table('page_versions')
            ->where('page_id', $page->id)
            ->orderBy('revision')
            ->limit(4)
            ->update(['autosave' => false]);

        $kept = DB::table('page_versions')->where('page_id', $page->id)->where('autosave', false)->count();

        $this->prune(keep: 1);

        $this->assertSame(
            $kept,
            DB::table('page_versions')->where('page_id', $page->id)->where('autosave', false)->count(),
            '自動保存でない版が消えました',
        );
    }

    // ── 消すもの ────────────────────────────────────────────

    /**
     * **直近 N を超えた分だけが消える。** 境界を数で固定する。
     */
    public function test_直近_n件を残して残りが消える(): void
    {
        [$page] = $this->pageWithVersions(10);

        // 守りの影響を外して数だけを見る（公開版は無し・draft は最新 = 直近 N に入る）
        $this->prune(keep: 3);

        $this->assertSame(
            3,
            DB::table('page_versions')->where('page_id', $page->id)->where('autosave', true)->count(),
            '残る件数が指定と違います',
        );
    }

    public function test_n件以下なら何も消えない(): void
    {
        [$page] = $this->pageWithVersions(3);

        $before = DB::table('page_versions')->where('page_id', $page->id)->count();

        $this->prune(keep: 50);

        $this->assertSame($before, DB::table('page_versions')->where('page_id', $page->id)->count());
    }

    // ── 入口 ────────────────────────────────────────────────

    /**
     * **`--dry-run` は 1 行も消さない。**
     */
    public function test_dry_runは1行も消さない(): void
    {
        [$page] = $this->pageWithVersions(10);

        $before = DB::table('page_versions')->where('page_id', $page->id)->count();

        $this->artisan('agentcms:page-version-prune', ['--keep' => 1, '--dry-run' => true])
            ->expectsOutputToContain('--dry-run')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('page_versions')->where('page_id', $page->id)->count());
    }

    /**
     * **`--keep=0` は受け取らない。**
     *
     * 打ち間違いで履歴が全部消える方向へ倒れる指定を、そのまま実行しない。
     */
    public function test_keep0は断る(): void
    {
        [$page] = $this->pageWithVersions(5);

        $before = DB::table('page_versions')->where('page_id', $page->id)->count();

        $this->artisan('agentcms:page-version-prune', ['--keep' => 0])->assertExitCode(1);

        $this->assertSame($before, DB::table('page_versions')->where('page_id', $page->id)->count());
    }

    public function test_ページを指定すると他のページは触らない(): void
    {
        [$one] = $this->pageWithVersions(8, 'ひとつめ', 'one');
        [$two] = $this->pageWithVersions(8, 'ふたつめ', 'two');

        $before = DB::table('page_versions')->where('page_id', $two->id)->count();

        $this->prune(keep: 2, page: $one->id);

        $this->assertSame($before, DB::table('page_versions')->where('page_id', $two->id)->count());
        $this->assertSame(
            2,
            DB::table('page_versions')->where('page_id', $one->id)->where('autosave', true)->count(),
        );
    }

    // ── 編集画面の版が自動保存として記録されること ──────────

    public function test_編集画面の変更は自動保存として記録される(): void
    {
        [$page] = $this->pageWithVersions(2);

        $this->assertGreaterThan(
            0,
            DB::table('page_versions')->where('page_id', $page->id)->where('autosave', true)->count(),
            '編集画面の変更が自動保存として記録されていません',
        );
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function prune(int $keep, ?int $page = null): void
    {
        $options = ['--keep' => $keep];

        if ($page !== null) {
            $options['--page'] = $page;
        }

        $this->artisan('agentcms:page-version-prune', $options)->assertExitCode(0);
    }

    /** patch を重ねて版を増やしたページ */
    private function pageWithVersions(int $edits, string $title = '間引き', string $slug = 'prune'): array
    {
        $context = OperationContext::admin($this->operatorId(), 'tester');

        $result = app(CreatePage::class)->execute(['title' => $title, 'slug' => $slug], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);

        $document = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $context)->data['document'];

        $section = (string) $document['root']['children'][0]['id'];

        for ($i = 0; $i < $edits; $i++) {
            app(PatchPageDocument::class)->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => $section,
                'component' => ['type' => 'text', 'props' => ['text' => "本文 {$i}"]],
            ], $context);
        }

        return [$page->fresh()];
    }
}
