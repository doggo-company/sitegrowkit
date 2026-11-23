<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\ListPageVersions;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Pages\RestorePageVersion;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 版の一覧と復元（Phase 9.6 / P0-C6c・SPEC §4.12）。
 *
 * ## 復元は**過去を書き換えない**
 *
 * 「その版の中身で新しい draft を作る」だけである。公開中の版も、戻す元の版も
 * 1 バイトも変わらない。**ここが崩れると、戻したあとに「本当は何だったか」を
 * 誰も言えなくなる。**
 */
final class PageVersionRestoreTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    // ── 非破壊であること ────────────────────────────────────

    public function test_復元しても公開中のページが変わらない(): void
    {
        [$page, $first] = $this->pageWithHistory();

        app(PublishPage::class)->execute($page->id, $this->admin());

        $page = $page->fresh();
        $publishedId = (int) $page->published_version_id;
        $before = PageVersion::query()->findOrFail($publishedId)->document;

        $this->restore($page, $first);

        $this->assertSame(
            $publishedId,
            (int) $page->fresh()->published_version_id,
            '公開が別の版を指すようになりました',
        );
        $this->assertSame(
            $before,
            PageVersion::query()->findOrFail($publishedId)->document,
            '公開中の版の中身が変わりました',
        );
    }

    public function test_復元しても戻す元の版が変わらない(): void
    {
        [$page, $first] = $this->pageWithHistory();

        $source = $this->versionAt($page, $first);
        $before = $source->document;

        $this->restore($page, $first);

        $this->assertSame(
            $before,
            $this->versionAt($page, $first)->document,
            '戻す元の版が書き換わりました',
        );
    }

    public function test_復元は新しい版を作りその中身が復元元と一致する(): void
    {
        [$page, $first] = $this->pageWithHistory();

        $latest = (int) PageVersion::query()->where('page_id', $page->id)->max('revision');
        $source = $this->versionAt($page, $first)->document;

        $this->restore($page, $first);

        $created = (int) PageVersion::query()->where('page_id', $page->id)->max('revision');

        $this->assertSame($latest + 1, $created, '新しい版が作られていません');
        $this->assertSame($source, $this->versionAt($page, $created)->document);

        // 編集の続きは新しい版の上で行う
        $this->assertSame($created, (int) $this->versionAt($page, $created)->revision);
        $this->assertSame(
            $this->versionAt($page, $created)->id,
            (int) $page->fresh()->draft_version_id,
        );
    }

    /**
     * **復元が作った版は間引かれない**（§4.11 との噛み合わせ）。
     */
    public function test_復元が作った版は自動保存ではなく間引きでも消えない(): void
    {
        [$page, $first] = $this->pageWithHistory();

        $this->restore($page, $first);

        $created = (int) PageVersion::query()->where('page_id', $page->id)->max('revision');

        $this->assertFalse(
            (bool) $this->versionAt($page, $created)->autosave,
            '復元で作った版が自動保存として記録されています',
        );

        $this->artisan('agentcms:page-version-prune', ['--keep' => 1])->assertExitCode(0);

        $this->assertNotNull(
            PageVersion::query()->where('page_id', $page->id)->where('revision', $created)->first(),
            '復元で作った版が間引かれました',
        );
    }

    // ── 一覧が返すもの ──────────────────────────────────────

    public function test_一覧は版の中身を返さない(): void
    {
        [$page] = $this->pageWithHistory();

        $result = app(ListPageVersions::class)->execute($page->id, $this->admin());

        $this->assertTrue($result->isSuccess());

        $encoded = (string) json_encode($result->data, JSON_UNESCAPED_UNICODE);

        // 版の本文が応答に載っていない（大きさと中身の両方の理由 — §4.12）
        $this->assertStringNotContainsString('本文', $encoded);
        $this->assertStringNotContainsString('document', $encoded);

        foreach ($result->data['versions'] as $version) {
            $this->assertArrayNotHasKey('document', $version);
        }
    }

    public function test_一覧は自動保存かどうかを返す(): void
    {
        [$page, $first] = $this->pageWithHistory();

        $this->restore($page, $first);

        $versions = app(ListPageVersions::class)->execute($page->id, $this->admin())->data['versions'];

        // 新しい順。先頭が復元で作った版で、それは自動保存ではない
        $this->assertFalse($versions[0]['autosave']);
        $this->assertTrue($versions[1]['autosave'], '編集で増えた版が自動保存として出ていません');

        // どれが編集中かも分かる
        $this->assertTrue($versions[0]['draft']);
    }

    // ── 頁送り（F-16）────────────────────────────────────────

    /**
     * **50 件を超えても、古い版へ届く。**
     *
     * `MAX_PER_PAGE` は**応答の大きさの上限**であって、到達できる範囲の上限ではない。
     */
    public function test_50件を超える版でも続きを辿れる(): void
    {
        [$page] = $this->pageWithHistory(60);

        $first = app(ListPageVersions::class)->execute($page->id, $this->admin())->data;
        $meta = app(ListPageVersions::class)->execute($page->id, $this->admin())->meta;

        $this->assertCount(50, $first['versions']);
        $this->assertNotNull($meta['nextBefore'], '続きの取り方が返っていません');

        $second = app(ListPageVersions::class)
            ->execute($page->id, $this->admin(), 50, $meta['nextBefore'])->data;

        $this->assertNotSame([], $second['versions'], '2 頁目が空です');

        // 1 頁目と 2 頁目に**同じ版が二度出ない**
        $revisions = array_merge(
            array_column($first['versions'], 'revision'),
            array_column($second['versions'], 'revision'),
        );

        $this->assertSame(count($revisions), count(array_unique($revisions)), '版が二度出ています');
    }

    /**
     * **間引きが永久に残す版へ、実際に届く**（F-16 の核心）。
     *
     * 残るのは復元・公開が作った版で、増え続けるのは自動保存である。
     * つまり**残る版ほど新しい 50 件から押し出される** — 辿れなければ
     * 「消えないのに二度と見えない版」ができる。
     */
    public function test_押し出された復元の版にも辿り着ける(): void
    {
        [$page, $first] = $this->pageWithHistory();

        // 古い版から復元し、そのあと編集を重ねて 1 頁目から押し出す
        $this->restore($page, $first);
        $restored = (int) PageVersion::query()->where('page_id', $page->id)->max('revision');

        $this->pushOut($page, 60);

        $this->assertNotContains(
            $restored,
            array_column(
                app(ListPageVersions::class)->execute($page->id, $this->admin())->data['versions'],
                'revision',
            ),
            '前提が崩れています（押し出せていない）',
        );

        $this->assertContains($restored, $this->allRevisions($page), '復元した版へ辿り着けません');
    }

    /**
     * **辿っている途中で間引きが走っても、残っている版は漏れなく出る。**
     *
     * 件数で飛ばす形だと、前の方が消えたぶんだけ**版を飛ばす**。
     * revision を鍵にすれば、消えても番号は戻らないので飛びも重複も起きない。
     */
    public function test_辿る途中で間引きが走っても残った版は漏れない(): void
    {
        [$page] = $this->pageWithHistory(60);

        $meta = app(ListPageVersions::class)->execute($page->id, $this->admin())->meta;

        // 1 頁読んだところで間引きが走る（古い自動保存が消える）
        $this->artisan('agentcms:page-version-prune', ['--keep' => 20])->assertExitCode(0);

        $second = app(ListPageVersions::class)
            ->execute($page->id, $this->admin(), 50, $meta['nextBefore'])->data['versions'];

        $remaining = PageVersion::query()
            ->where('page_id', $page->id)
            ->where('revision', '<', $meta['nextBefore'])
            ->pluck('revision')
            ->map(intval(...))
            ->sort()
            ->values()
            ->all();

        $returned = array_column($second, 'revision');
        sort($returned);

        $this->assertSame($remaining, $returned, '間引きの後に残った版が漏れています');
    }

    // ── 入口 ────────────────────────────────────────────────

    public function test_未確認の復元は確認を求める(): void
    {
        [$page, $first] = $this->pageWithHistory();

        $before = PageVersion::query()->where('page_id', $page->id)->count();

        $result = app(RestorePageVersion::class)->execute($page->id, $first, $this->admin());

        $this->assertNotNull($result->confirmation, '確認を求めずに戻しています');
        $this->assertSame(
            $before,
            PageVersion::query()->where('page_id', $page->id)->count(),
            '確認前に版が作られました',
        );
    }

    public function test_無い版は戻せない(): void
    {
        [$page] = $this->pageWithHistory();

        $result = app(RestorePageVersion::class)->execute($page->id, 9999, $this->admin());

        $this->assertFalse($result->isSuccess());
    }

    public function test_復元の監査は版の番号だけを運ぶ(): void
    {
        [$page, $first] = $this->pageWithHistory();

        $this->restore($page, $first);

        $log = DB::table('audit_logs')->where('operation', 'page.document.restore')->latest('id')->first();

        $this->assertNotNull($log);

        /*
         * **形そのものを固定する。**
         *
         * 「本文が載っていない」を文字列で見ても意味が薄い —
         * `DatabaseAuditRecorder::safe()` が連想配列をキー名だけへ畳むので、
         * 何を渡しても中身は落ちる（それは**あちらの守り**で、ここの守りではない）。
         * ここが決めているのは**何を渡すか**なので、渡した鍵の集合を見る。
         */
        $metadata = json_decode((string) $log->metadata, true);

        /*
         * **鍵の集合を見る。並びは見ない。**
         *
         * MySQL の JSON 列は object のキー順を正規化するので、保存した順では返らない。
         * ここが決めているのは「**何を渡すか**」であって、並びは保存側の都合である。
         */
        $this->assertEqualsCanonicalizing(['restoredFrom', 'created'], array_keys((array) $metadata));
        $this->assertSame($first, $metadata['restoredFrom']);
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    private function versionAt(Page $page, int $revision): PageVersion
    {
        return PageVersion::query()
            ->where('page_id', $page->id)
            ->where('revision', $revision)
            ->firstOrFail();
    }

    /** 確認を通してから戻す */
    private function restore(Page $page, int $revision): Result
    {
        $first = app(RestorePageVersion::class)->execute($page->id, $revision, $this->admin());

        if ($first->confirmation === null) {
            // **黙って失敗を通さない。** 確認が要らなかったなら、成功しているはずである
            $this->assertTrue($first->isSuccess(), (string) $first->message.' / '.$first->status->value);

            return $first;
        }

        $result = app(RestorePageVersion::class)->execute(
            $page->id,
            $revision,
            $this->admin()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return $result;
    }

    /**
     * そのページの全 revision（頁送りで辿り切る）。
     *
     * **進まなくなったら止めて落とす。** 続きの鍵が前へ進まない実装だと、
     * ここは永久に回り続ける — **止まらない試験は、落ちる試験より悪い**
     * （CI が待ち続けるだけで、何が壊れたか誰にも分からない）。
     */
    private function allRevisions(Page $page): array
    {
        $all = [];
        $before = null;

        do {
            $result = app(ListPageVersions::class)->execute($page->id, $this->admin(), 50, $before);
            $all = [...$all, ...array_column($result->data['versions'], 'revision')];

            $next = $result->meta['nextBefore'];

            $this->assertNotSame($before, $next, '続きの鍵が進んでいません（辿れない一覧です）');

            $before = $next;
        } while ($before !== null);

        return $all;
    }

    /** 自動保存を積んで、古い版を 1 頁目から押し出す */
    private function pushOut(Page $page, int $edits): void
    {
        $context = $this->admin();
        $section = (string) $this->versionAt($page, (int) PageVersion::query()
            ->where('page_id', $page->id)->max('revision'))->document['root']['children'][0]['id'];

        for ($i = 0; $i < $edits; $i++) {
            app(PatchPageDocument::class)->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => $section,
                'component' => ['type' => 'text', 'props' => ['text' => "押し出し {$i}"]],
            ], $context);
        }
    }

    /** 版が 3 つ以上あるページ。**最初に中身が入った版の revision** を返す */
    private function pageWithHistory(int $extra = 0): array
    {
        $context = $this->admin();

        $created = app(CreatePage::class)->execute(['title' => '復元', 'slug' => 'restore'], $context);
        $page = Page::query()->findOrFail($created->data['page']['id']);

        $document = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => 'page',
            'component' => ['type' => 'section', 'props' => []],
        ], $context)->data['document'];

        $section = (string) $document['root']['children'][0]['id'];

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => ['type' => 'text', 'props' => ['text' => '本文 1']],
        ], $context);

        $first = (int) PageVersion::query()->where('page_id', $page->id)->max('revision');

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => ['type' => 'text', 'props' => ['text' => '本文 2']],
        ], $context);

        for ($i = 0; $i < $extra; $i++) {
            app(PatchPageDocument::class)->execute($page->id, [
                'operation' => 'component.insert',
                'parent' => $section,
                'component' => ['type' => 'text', 'props' => ['text' => "追加 {$i}"]],
            ], $context);
        }

        return [$page->fresh(), $first];
    }
}
