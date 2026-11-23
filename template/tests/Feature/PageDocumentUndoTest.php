<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Document\JsonPatch;
use AgentCms\Core\Pages\Page;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.6 / P0-C6a: 取り消しとやり直し（SPEC §4.10）。
 *
 * ## 取り消しは**逆の semantic command** である
 *
 * 書き込みの口は `page.document.patch` の 1 本だけにする。逆 patch を生で受け取る
 * 口を足すと、validator も確認ゲートも監査も通らない 2 本目の経路ができる。
 * だから取り消しは「**さっきと逆のことを、同じ口から頼む**」形にする。
 *
 * 逆の command を**組み立てるのはサーバー**である。何を消したか・どこに在ったかを
 * 知っているのは document を持っている側で、クライアントに組み立てさせると
 * 「消える node の id を渡して送り返させる」ことになる（§4.10 の DoD）。
 *
 * ## 「戻る」は id を除いた同値
 *
 * `component.delete` を取り消すと node は作り直され、新しい id を持つ。
 * 木の形・並び・中身が一致することを見る（§4.10）。
 */
final class PageDocumentUndoTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    // ── V7: patch そのものが逆変換に耐えること ────────────

    /**
     * **`component.update` の逆変換が props を消さない**（V-2 / Owner Gate V7）。
     *
     * `updatePatch()` が既存キーへ `add` を出すかぎり、`invert()` は `add → remove` に
     * 写すので、**そのセクションが丸ごと消える**。取り消しを出す前に、
     * patch の側が RFC の意味どおりでなければならない。
     */
    public function test_更新の逆変換が元の値へ戻す(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $before = $this->document($page);
        $patch = $this->patchFor($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['props' => ['text' => '新しい見出し']],
        ]);

        $after = JsonPatch::apply($before, $patch);
        $restored = JsonPatch::apply($after, JsonPatch::invert($before, $patch));

        $this->assertSame(
            $before,
            $restored,
            '更新を逆変換したら元へ戻りませんでした（props が消えていませんか）',
        );
    }

    // ── 逆 command（5 種）────────────────────────────────

    public function test_更新は逆commandで元の値へ戻る(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->assertRoundTrip($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['props' => ['text' => '新しい見出し', 'level' => 3]],
        ]);
    }

    public function test_触っていない設定は逆commandでも動かない(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $undo = $this->undoOf($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['props' => ['text' => '新しい見出し']],
        ]);

        $this->apply($page, $undo);

        $heading = $this->heading($this->document($page));

        $this->assertSame('元の見出し', $heading['props']['text']);
        $this->assertSame(2, $heading['props']['level'], '触っていない設定が消えました');
    }

    public function test_追加は逆commandで消える(): void
    {
        [$page] = $this->pageWithHeading();

        $this->assertRoundTrip($page, [
            'operation' => 'component.insert',
            'parent' => (string) $this->document($page)['root']['children'][0]['id'],
            'component' => ['type' => 'text', 'props' => ['text' => '足したもの']],
        ]);
    }

    public function test_削除は逆commandで戻る(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->assertRoundTrip($page, [
            'operation' => 'component.delete',
            'target' => $id,
            'confirmationToken' => null,
        ], confirm: true);
    }

    public function test_移動は逆commandで元の位置へ戻る(): void
    {
        [$page] = $this->pageWithHeading();

        $section = (string) $this->document($page)['root']['children'][0]['id'];

        // 2 つ目の見出しを作り、並びを入れ替えて戻す
        $document = $this->apply($page, [
            'operation' => 'component.insert',
            'parent' => $section,
            'component' => ['type' => 'text', 'props' => ['text' => '2 つ目']],
        ]);

        $second = (string) $document['root']['children'][0]['children'][1]['id'];

        $this->assertRoundTrip($page, [
            'operation' => 'component.move',
            'target' => $second,
            'parent' => $section,
            'after' => null,
        ]);
    }

    public function test_複製は逆commandで消える(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->assertRoundTrip($page, [
            'operation' => 'component.duplicate',
            'target' => $id,
        ]);
    }

    /**
     * **足したキーは、取り消しで外れる。**
     *
     * セクションごと元の値を送り返す形だと、`merge()` は重ねるだけなので
     * **足したキーが残る**。逆は「送ったキーだけを、元の値か `null` へ」でなければならない。
     */
    public function test_足した設定は取り消しで外れる(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $undo = $this->undoOf($page, [
            'operation' => 'component.update',
            'target' => $id,
            // 見た目はまだ 1 つも付いていない（`style` ごと無い）
            'changes' => ['style' => ['textAlign' => 'center']],
        ]);

        $this->assertNotNull($undo);
        $this->apply($page, $undo);

        $this->assertArrayNotHasKey(
            'textAlign',
            $this->heading($this->document($page))['style'] ?? [],
            '足した設定が取り消しで外れていません',
        );
    }

    /**
     * **入れ子ごと複製したら、取り消しは一番上を消す。**
     *
     * 増えた id は子のぶんもある。どれかを選んで消すと、
     * 「親だけ残る」「子だけ消える」が起きる。
     */
    public function test_入れ子の複製も取り消しで丸ごと消える(): void
    {
        [$page] = $this->pageWithHeading();

        // section（中に heading を持つ）を複製する
        $section = (string) $this->document($page)['root']['children'][0]['id'];

        $this->assertRoundTrip($page, [
            'operation' => 'component.duplicate',
            'target' => $section,
        ]);
    }

    // ── 取り消しの取り消し（redo）────────────────────────

    /**
     * **やり直しは、取り消しを取り消すこと**である。別の仕掛けを持たない。
     *
     * 取り消しも 1 つの command なので、その取り消しが返る。それを送れば
     * やり直しになる — redo のための経路を新しく作らない。
     */
    public function test_取り消しの取り消しがやり直しになる(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $result = $this->command($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['props' => ['text' => '新しい見出し']],
        ]);

        $this->assertTrue($result->isSuccess());

        $afterEdit = $this->document($page);
        $undo = $result->meta['undo'];

        $undone = $this->command($page, $undo);
        $this->assertTrue($undone->isSuccess());

        // 取り消しの取り消し = やり直し
        $this->apply($page, $undone->meta['undo']);

        $this->assertSame(
            $this->withoutIds($afterEdit),
            $this->withoutIds($this->document($page)),
            'やり直しが編集後の状態へ戻りませんでした',
        );
    }

    // ── binding を外す（Phase 9.6 / P0-C5・SPEC §4.9）──────

    /**
     * **`binding: null` で外れる。**
     *
     * `props` の `merge()` が「null は外す」を採っているので、作法を揃える（§4.3）。
     * 外した後に `binding` のキーが**残っていない**ことまで見る — 残ると
     * 「binding が在るのか無いのか」を読む側が 2 通りの形を相手にすることになる。
     */
    public function test_bindingをnullで外せる(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['binding' => $this->binding()],
        ]);

        $this->assertArrayHasKey('binding', $this->heading($this->document($page)));

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['binding' => null],
        ]);

        $this->assertArrayNotHasKey(
            'binding',
            $this->heading($this->document($page)),
            'binding を外したのにキーが残っています',
        );
    }

    /**
     * **初めて付けた binding も取り消せる。**
     *
     * 外す表現が無かったあいだ、「元が無かった」場合の逆 command は組み立てられず、
     * `null`（押せない）を返していた（§4.10 — 嘘の取り消しを出すより押せない方がよい）。
     * 外せるようになったので、その制限はここで解ける。
     */
    public function test_初めて付けたbindingの取り消しが出る(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->assertRoundTrip($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['binding' => $this->binding()],
        ]);

        $this->assertArrayNotHasKey('binding', $this->heading($this->document($page)));
    }

    /** 外したことも取り消せる（外す → 元の binding が戻る） */
    public function test_外したbindingの取り消しで元へ戻る(): void
    {
        [$page, $id] = $this->pageWithHeading();

        $this->apply($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['binding' => $this->binding()],
        ]);

        $this->assertRoundTrip($page, [
            'operation' => 'component.update',
            'target' => $id,
            'changes' => ['binding' => null],
        ]);

        /*
         * **キーの順は見ない。**
         *
         * MySQL の JSON 列は object のキー順を正規化する（保存した順では返らない）。
         * 見たいのは「外した binding が元の中身で戻ったか」なので、
         * **並びは保存側の都合**として扱う（SQLite では素通りしていた — MySQL の掃引で分かった）。
         */
        $restored = $this->heading($this->document($page))['binding'];
        $expected = $this->binding();

        ksort($restored);
        ksort($expected);

        $this->assertSame($expected, $restored);
    }

    // ── ヘルパー ──────────────────────────────────────────

    /** @return array<string, mixed> 見出しへ流せる binding */
    private function binding(): array
    {
        return ['source' => 'entry', 'type' => 'news', 'field' => 'title', 'prop' => 'text'];
    }

    /**
     * 送って、返ってきた取り消しを送り返し、**id を除いて元へ戻る**ことを見る。
     *
     * @param  array<string, mixed>  $command
     */
    private function assertRoundTrip(Page $page, array $command, bool $confirm = false): void
    {
        $before = $this->document($page);

        $result = $confirm ? $this->confirmed($page, $command) : $this->command($page, $command);

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $undo = $result->meta['undo'] ?? null;

        $this->assertIsArray($undo, '取り消しの command が返っていません');

        $this->apply($page, $undo);

        $this->assertSame(
            $this->withoutIds($before),
            $this->withoutIds($this->document($page)),
            '取り消しても元の形へ戻りませんでした',
        );
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>|null
     */
    private function undoOf(Page $page, array $command, bool $fresh = true): ?array
    {
        $result = $this->command($page, $command);

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $undo = $result->meta['undo'] ?? null;

        return is_array($undo) ? $undo : null;
    }

    /** 確認ゲートを通してから実行する（削除は確認が要る） */
    private function confirmed(Page $page, array $command): Result
    {
        $first = $this->command($page, $command);

        if ($first->confirmation === null) {
            return $first;
        }

        return app(PatchPageDocument::class)->execute(
            $page->id,
            $command,
            $this->admin()->withConfirmationToken($first->confirmation->token),
        );
    }

    /** id は作り直しで変わる。**形と中身だけ**を見る（§4.10 の DoD） */
    private function withoutIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            if ($key === 'id') {
                continue;
            }

            $out[$key] = $this->withoutIds($item);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function patchFor(Page $page, array $command): array
    {
        $method = new \ReflectionMethod(PatchPageDocument::class, 'toPatch');

        return $method->invoke(
            app(PatchPageDocument::class),
            (string) $command['operation'],
            $this->document($page),
            $command,
        );
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
        $result = $this->confirmed($page, $command);

        $this->assertTrue(
            $result->isSuccess(),
            (string) $result->message.' '.json_encode($result->errors, JSON_UNESCAPED_UNICODE),
        );

        return $result->data['document'];
    }

    /** いまの draft の document（取り消しは draft の上で起きる） */
    private function document(Page $page): array
    {
        $draft = $page->fresh()?->draftVersion;

        return is_array($draft?->document) ? $draft->document : [];
    }

    /** section > heading を 1 つずつ持つページ。heading の id を返す */
    private function pageWithHeading(): array
    {
        $result = app(CreatePage::class)->execute(
            ['title' => '取り消し', 'slug' => 'undo'],
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
}
