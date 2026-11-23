<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Console\Commands\ImportPageCommand;
use App\Models\ImportedPageLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 取り込んだ節をページとして起こす（R-12 Stage 2 / NEW-2）。
 *
 * ここで固定するのは 5 つ:
 *   1. **作ると本当に画面へ出せる状態になる**（並び順どおりに document へ入り、公開できる）
 *   2. **既に在れば触らない**（運用者が組み替えたものを import が上書きしない）
 *   3. **通らないものは黙って直さない**（置けない場所・無い種類はその場で止まる）
 *   4. **途中で止まったら何も残さない**（作成 → ブロック → 公開は 1 トランザクション）
 *   5. **トップページには繋がない**（口を 2 つ持たない）
 */
final class ImportPageCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. 作れる ────────────────────────────────────────

    public function test_ブロックが並び順どおりに入る(): void
    {
        $out = $this->importPage($this->payload());

        $this->assertSame('success', $out['status']);
        $this->assertSame('created', $out['action']);
        $this->assertSame(2, $out['blocks']);

        $children = $this->document((int) $out['id'])['root']['children'];

        $this->assertCount(2, $children);
        $this->assertSame('section', $children[0]['type']);
        $this->assertSame('section', $children[1]['type']);

        // **並び順は原本の順** — 入れ替わると画面が別物になる
        $this->assertSame('{colors.bg}', $children[0]['props']['background']);
        $this->assertSame('{colors.bgAlt}', $children[1]['props']['background']);
    }

    public function test_既定は下書き(): void
    {
        $out = $this->importPage($this->payload());

        $this->assertFalse($out['published']);
        $this->assertSame(PageStatus::Draft, $this->page((int) $out['id'])->status);
    }

    public function test_公開を指定すれば公開まで通る(): void
    {
        $out = $this->importPage($this->payload(['publish' => true]));

        $this->assertTrue($out['published']);
        $this->assertTrue($this->page((int) $out['id'])->isPublicallyVisible());
    }

    // ── 2. 既に在れば触らない ────────────────────────────

    public function test_同じ_slug_のページが在れば触らない(): void
    {
        $first = $this->importPage($this->payload());

        $again = $this->importPage($this->payload([
            'title' => '別の題',
            'blocks' => [$this->block('{colors.bgAlt}')],
        ]));

        $this->assertSame('kept', $again['action']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(0, $again['blocks']);

        // 題も中身も変わっていない
        $page = $this->page((int) $first['id']);
        $this->assertSame('ホーム', $page->title);
        $this->assertCount(2, $this->document((int) $first['id'])['root']['children']);
    }

    public function test_ページは_1_つしか作られない(): void
    {
        $this->importPage($this->payload());
        $this->importPage($this->payload());

        $this->assertSame(1, Page::query()->where('slug', 'home')->count());
    }

    public function test_一覧は_binding_ごと入る(): void
    {
        // 🔴 binding が落ちると、一覧は**空で出る**。
        //    「まだ登録がありません」と見分けが付かない
        $this->withImportedCollection();

        $out = $this->importPage($this->payload([
            'blocks' => [[
                'type' => 'imported_news',
                'props' => [],
                'binding' => ['source' => 'collection', 'type' => 'news'],
            ]],
        ]));

        $this->assertSame('created', $out['action']);

        $node = $this->document((int) $out['id'])['root']['children'][0];
        $this->assertSame(
            ['source' => 'collection', 'type' => 'news'],
            $node['binding'],
        );
    }

    public function test_一覧に_binding_を付けなければ断る(): void
    {
        $this->withImportedCollection();

        $out = $this->importPage($this->payload([
            'blocks' => [['type' => 'imported_news', 'props' => []]],
        ]), expectFailure: true);

        $this->assertStringContainsString('1 個目', $out['message']);
        $this->assertStringContainsString(
            '一覧に表示するコンテンツが指定されていません',
            json_encode($out['errors'], JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /**
     * 一覧を宣言した component を 1 つ足したレジストリに差し替える。
     *
     * 🔴 **`content/components/` へファイルを置かない。**同梱物を書き換えると
     * ほかの試験を巻き込む（この試験一式を書いた日に、実際に 39 件落とした）。
     * 同梱物を写した一時ディレクトリを見せる。
     */
    private function withImportedCollection(): void
    {
        $dir = sys_get_temp_dir().'/agentcms-components-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);
        File::copyDirectory(base_path('content/components'), $dir);
        File::put($dir.'/imported_news.json', (string) json_encode([
            'type' => 'imported_news',
            'label' => 'お知らせ（一覧）',
            'category' => 'content',
            'since' => 1,
            'propsSchema' => new \stdClass,
            'styleSchema' => ['visibility'],
            'bindable' => [],
            'allowedChildren' => [],
            'allowedParents' => ['page'],
            'renderer' => ['kind' => 'blade', 'view' => 'components.document.text'],
            'capabilities' => ['responsive' => ['visibility'], 'container' => false, 'collection' => true],
        ], JSON_UNESCAPED_UNICODE));

        $this->app->instance(ComponentRegistry::class, new ComponentRegistry($dir));
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($dir));
    }

    public function test_binding_の形が違えば断る(): void
    {
        $out = $this->importPage($this->payload([
            'blocks' => [['type' => 'collection', 'props' => [], 'binding' => 'news']],
        ]), expectFailure: true);

        $this->assertStringContainsString('binding', $out['message']);
        $this->assertSame(0, Page::query()->count(), 'ページを作ってから断っている');
    }

    // ── 3. 通らないものは黙って直さない ──────────────────

    public function test_無い種類のブロックは断る(): void
    {
        $out = $this->importPage($this->payload([
            'blocks' => [['type' => 'imported_nowhere', 'props' => []]],
        ]), expectFailure: true);

        $this->assertNotSame('success', $out['status']);
        $this->assertStringContainsString('1 個目', $out['message']);
        $this->assertStringContainsString('imported_nowhere', $out['message']);
    }

    public function test_置けない場所のブロックは断る(): void
    {
        // `text` の `allowedParents` は section / container で、page ではない
        $out = $this->importPage($this->payload([
            'blocks' => [['type' => 'text', 'props' => ['text' => 'あ']]],
        ]), expectFailure: true);

        $this->assertNotSame('success', $out['status']);
        $this->assertStringContainsString('置けませんでした', $out['message']);
    }

    public function test_断るときは原因が読める(): void
    {
        $out = $this->importPage($this->payload([
            'blocks' => [['type' => 'text', 'props' => ['text' => 'あ']]],
        ]), expectFailure: true);

        // **直し方の分からない断りにしない** — errors を落とすと原因を知る手段が無くなる
        $this->assertNotEmpty($out['errors'] ?? [], '原因が返っていない');
    }

    public function test_受け付けないキーは断る(): void
    {
        $out = $this->importPage($this->payload(['managedBy' => 'blade']), expectFailure: true);

        $this->assertStringContainsString('managedBy', $out['message']);
    }

    public function test_props_がオブジェクトでなければ断る(): void
    {
        // 断らないと `component.insert` が黙って空の props に落とす。
        // **運用者が原本から運んだ文言が、何も言われずに消える**
        $out = $this->importPage($this->payload([
            'blocks' => [['type' => 'section', 'props' => 'あ']],
        ]), expectFailure: true);

        $this->assertStringContainsString('props', $out['message']);
        $this->assertSame(0, Page::query()->count(), 'ページを作ってから断っている');
    }

    public function test_ブロックが空なら断る(): void
    {
        $out = $this->importPage($this->payload(['blocks' => []]), expectFailure: true);

        $this->assertStringContainsString('1 つ以上', $out['message']);
    }

    public function test_ブロックが多すぎれば断る(): void
    {
        $out = $this->importPage($this->payload([
            'blocks' => array_fill(0, 101, $this->block('{colors.bgAlt}')),
        ]), expectFailure: true);

        $this->assertStringContainsString('多すぎ', $out['message']);
        $this->assertSame(0, Page::query()->count(), 'ページを作ってから断っている');
    }

    // ── 4. 途中で止まったら何も残らない（1 トランザクション） ──

    public function test_途中で止まったら何も残らない(): void
    {
        $out = $this->importPage($this->payload([
            'publish' => true,
            'blocks' => [$this->block('{colors.bg}'), ['type' => 'text', 'props' => ['text' => 'あ']]],
        ]), expectFailure: true);

        $this->assertStringContainsString('2 個目', $out['message']);
        $this->assertStringContainsString('取り消しました', $out['message']);

        // 🔴 以前は下書きを残していた。残った下書きは次の実行で kept になり、
        //    運用者の下書きと見分けが付かない（ゴミ箱に入れても slug は予約されたまま）。
        //    **1 行も残さない**のが唯一の後始末（Codex 指摘 ④）
        $this->assertSame(0, Page::query()->count(), 'ページが残っている');
        $this->assertSame(0, PageVersion::query()->count(), '版が残っている');
    }

    public function test_公開の直前でプロセスが死んでも何も残らない(): void
    {
        // 公開（published_version_id の書き込み）の瞬間に落ちる = 一番遅い時点での kill
        Page::saving(static function (Page $page): void {
            if ($page->isDirty('published_version_id')) {
                throw new \RuntimeException('killed');
            }
        });

        try {
            $this->importPage($this->payload(['publish' => true]), expectFailure: true);
            $this->fail('例外が外へ出ていない');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }

        $this->assertSame(0, Page::query()->count(), 'commit 前の行が残っている');
        $this->assertSame(0, PageVersion::query()->count());
    }

    public function test_失敗のあとの再実行は_kept_にならず作れる(): void
    {
        $this->importPage($this->payload([
            'blocks' => [$this->block('{colors.bg}'), ['type' => 'text', 'props' => ['text' => 'あ']]],
        ]), expectFailure: true);

        // 前回の失敗が何も残していないので、同じ slug で普通に作れる
        $out = $this->importPage($this->payload(['publish' => true]));

        $this->assertSame('created', $out['action']);
        $this->assertTrue($out['published']);
        $this->assertSame(1, Page::query()->where('slug', 'home')->count());
    }

    public function test_ゴミ箱のページも_kept_として数える(): void
    {
        // slug はゴミ箱の中でも予約され続ける（CreatePage）。「捨てて再実行」は成り立たない —
        // 呼び出し側（CLI）はこの kept を「自分のものではない未公開」として失敗にする
        $first = $this->importPage($this->payload());
        Page::query()->whereKey($first['id'])->update([
            'status' => PageStatus::Trashed->value,
            'trashed_at' => now(),
        ]);

        $again = $this->importPage($this->payload());

        $this->assertSame('kept', $again['action']);
        $this->assertFalse($again['published']);
    }

    // ── 6. 枠（layout）はページの id に結び付ける ──────────

    public function test_layout_はページの_id_に結び付けて同じ取引で残る(): void
    {
        $out = $this->importPage($this->payload(['layout' => 'layouts.preserved.home']));

        $this->assertSame('layouts.preserved.home', $out['layout']);
        $this->assertSame(
            'layouts.preserved.home',
            ImportedPageLayout::query()->where('page_id', $out['id'])->value('view'),
        );
        // 元の URL の所有者はこの 1 枚
        $this->assertSame(
            'layouts.preserved.home',
            ImportedPageLayout::query()->where('page_id', $out['id'])->value('owned_view'),
        );
    }

    public function test_layout_を渡さなければ結び付けない(): void
    {
        $out = $this->importPage($this->payload());

        $this->assertNull($out['layout']);
        $this->assertSame(0, ImportedPageLayout::query()->count());
    }

    public function test_layout_の名前は_preserved_の形だけ受ける(): void
    {
        // 任意の view 名を受けると、取り込みが別の layout を横取りできてしまう
        $out = $this->importPage($this->payload(['layout' => 'layouts.public']), expectFailure: true);

        $this->assertStringContainsString('layouts.preserved.', $out['message']);
        $this->assertSame(0, Page::query()->count(), 'ページを作ってから断っている');
    }

    public function test_kept_のときは既に在るページに枠を当てない(): void
    {
        $this->importPage($this->payload());

        // 🔴 Codex 続き ①: 取り込みの前から在った同名ページに、新しい枠が当たってはいけない
        $again = $this->importPage($this->payload(['layout' => 'layouts.preserved.home']));

        $this->assertSame('kept', $again['action']);
        $this->assertSame(0, ImportedPageLayout::query()->count());
    }

    public function test_途中で止まったら枠の結び付きも残らない(): void
    {
        $this->importPage($this->payload([
            'layout' => 'layouts.preserved.home',
            'blocks' => [$this->block('{colors.bg}'), ['type' => 'text', 'props' => ['text' => 'あ']]],
        ]), expectFailure: true);

        $this->assertSame(0, ImportedPageLayout::query()->count());
    }

    // ── 5. トップページには繋がない ──────────────────────

    public function test_トップページの設定は触らない(): void
    {
        $out = $this->importPage($this->payload(['publish' => true]));

        // `front_page_id` を立てるのは `agentcms:site-setting` の仕事。
        // ここで立てると、同じ判断を持つ口が 2 つになる
        $this->assertNull(app(SettingsStore::class)->get('front_page_id'));
        $this->assertIsInt($out['id'], '呼び出し側が繋げるように id を返していない');
    }

    // ── 道具 ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function block(string $background): array
    {
        return ['type' => 'section', 'props' => ['background' => $background]];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        // **左が勝つ**ので override を先に置く（後ろに置くと黙って無視される）
        return ['page' => $override + [
            'title' => 'ホーム',
            'slug' => 'home',
            'blocks' => [$this->block('{colors.bg}'), $this->block('{colors.bgAlt}')],
        ]];
    }

    /** id で 1 枚引く（`findOrFail` の返り型が Page|Collection なので受け口を 1 つにする） */
    private function page(int $pageId): Page
    {
        $page = Page::query()->find($pageId);
        $this->assertInstanceOf(Page::class, $page, "ページ [{$pageId}] が無い");

        return $page;
    }

    /** @return array<string, mixed> */
    private function document(int $pageId): array
    {
        $page = $this->page($pageId);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);

        /** @var array<string, mixed> $document */
        $document = $version->document;

        return $document;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function importPage(array $payload, bool $expectFailure = false): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command = app(ImportPageCommand::class);
        $command->setLaravel(app());

        $exit = $command->run($input, $output);
        $raw = trim($output->fetch());
        fclose($stream);

        $this->assertSame($expectFailure ? 1 : 0, $exit, "終了コードが違う: {$raw}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "JSON を返していない: {$raw}");

        return $decoded;
    }
}
