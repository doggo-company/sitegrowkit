<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Components\ReusableComponent;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 再利用 component を指す node の検証（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## 見るもの
 *
 * - **循環**（自己参照・A→B→A・A→B→C→A）を拒否する。**推移閉包まで**
 * - **展開後**の深さと件数の上限で、**落ちるのではなく断る**
 * - **registry の allowlist を回避できない** — 実体の中も registered component だけ
 *
 * 循環と上限は**別々に見る**。500 node の document が 500 node の実体を参照すれば
 * 掛け算になるし、循環していない深さ 1 万の入れ子は循環検出では捕まらない。
 */
final class ReusableComponentValidationTest extends TestCase
{
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

    /** @param array<string, mixed> $document */
    private function errors(array $document): string
    {
        $result = app(DocumentValidator::class)->validate($document);

        return implode(' / ', array_merge(...array_values($result ?: [[]])));
    }

    /** 実体を 1 つ作る（draft の部分木だけ持つ） */
    private function reusable(string $key, array $document): ReusableComponent
    {
        return ReusableComponent::query()->create([
            'key' => $key,
            'label' => $key,
            'draft_document' => $document,
        ]);
    }

    /** section の中に heading を 1 つ持つ部分木 */
    private function subtree(array ...$extra): array
    {
        return $this->node('section', ['children' => $extra === [] ? [
            $this->node('heading', ['props' => ['text' => '見出し', 'level' => 2]]),
        ] : $extra]);
    }

    // ── 参照そのもの ────────────────────────────────────

    public function test_存在する実体への参照は通る(): void
    {
        $reusable = $this->reusable('header', $this->subtree());

        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $reusable->id]),
        ));

        $this->assertSame('', $errors);
    }

    public function test_存在しない実体への参照は拒否される(): void
    {
        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ']),
        ));

        $this->assertNotSame('', $errors, '存在しない実体を指せています');
    }

    public function test_参照の形式が不正なら拒否される(): void
    {
        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => 'not-a-ulid']),
        ));

        $this->assertNotSame('', $errors);
    }

    // ── 循環（4 通り）───────────────────────────────────
    //
    // **「何かエラーが出た」では足りない。** 循環していると展開が終わらないので、
    // 循環の検査を外しても**大きさの上限が先に鳴る**。それでは循環を見たことにならない
    // （実際、最初はそれで変異が緑のまま通った）。だから**循環としての文面**を見る。

    /** 自分自身を含む */
    public function test_自己参照を拒否する(): void
    {
        $reusable = $this->reusable('self', $this->subtree());

        // 自分自身を、同じ type の入れ物で指す
        $reusable->update(['draft_document' => $this->node('section', ['instanceOf' => $reusable->id])]);

        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $reusable->id]),
        ));

        $this->assertStringContainsString('自分自身を含んで', $errors, '自己参照が「循環として」断られていません');
    }

    /** A → B → A */
    public function test_2つの循環を拒否する(): void
    {
        $a = $this->reusable('a', $this->subtree());
        $b = $this->reusable('b', $this->subtree());

        $a->update(['draft_document' => $this->node('section', ['instanceOf' => $b->id])]);
        $b->update(['draft_document' => $this->node('section', ['instanceOf' => $a->id])]);

        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $a->id]),
        ));

        $this->assertStringContainsString('自分自身を含んで', $errors, 'A→B→A が「循環として」断られていません');
    }

    /** A → B → C → A（**推移閉包まで見る**） */
    public function test_3つの循環を拒否する(): void
    {
        $a = $this->reusable('a3', $this->subtree());
        $b = $this->reusable('b3', $this->subtree());
        $c = $this->reusable('c3', $this->subtree());

        foreach ([[$a, $b], [$b, $c], [$c, $a]] as [$from, $to]) {
            $from->update(['draft_document' => $this->node('section', ['instanceOf' => $to->id])]);
        }

        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $a->id]),
        ));

        $this->assertStringContainsString('自分自身を含んで', $errors, 'A→B→C→A が「循環として」断られていません');
    }

    /** **循環していない入れ子は通る**（止めてはいけない側） */
    public function test_循環していない入れ子は通る(): void
    {
        /*
         * **入れ子は、内側を `container` 起点にして作る。**
         * 入れ物の type は起点と同じでなければならないので、section 起点のものを
         * section の中へ入れることはできない（section は section の子になれない）。
         */
        $inner = $this->reusable('inner', $this->node('container', ['children' => [
            $this->node('heading', ['props' => ['text' => '内側', 'level' => 2]]),
        ]]));
        $outer = $this->reusable('outer', $this->node('section', ['children' => [
            $this->node('container', ['instanceOf' => $inner->id]),
        ]]));

        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $outer->id]),
        ));

        $this->assertSame('', $errors, '循環していない入れ子を拒否しています');
    }

    // ── 展開後の上限（循環とは別）───────────────────────

    /**
     * **展開すると件数の上限を超える**組み合わせを断る。
     *
     * 循環していないので循環検出では捕まらない。**落ちるのではなく断る。**
     */
    public function test_展開後に件数の上限を超えるものは断る(): void
    {
        $children = [];

        for ($i = 0; $i < 300; $i++) {
            $children[] = $this->node('heading', ['props' => ['text' => "見出し {$i}", 'level' => 2]]);
        }

        $big = $this->reusable('big', $this->node('section', ['children' => $children]));

        // 300 node の実体を 2 つ参照すると、展開後は 600 を超える（上限 500）
        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $big->id]),
            $this->node('section', ['instanceOf' => $big->id]),
        ));

        $this->assertStringContainsString('上限', $errors, '展開後の件数を見ていません');
    }

    /** **展開すると深さの上限を超える**組み合わせを断る */
    public function test_展開後に深さの上限を超えるものは断る(): void
    {
        // 深い実体（container を重ねる）
        $deep = $this->node('heading', ['props' => ['text' => '底', 'level' => 2]]);

        // 展開後に page 起点から数えて 12 段を超えるだけ重ねる
        for ($i = 0; $i < 15; $i++) {
            $deep = $this->node('container', ['children' => [$deep]]);
        }

        $reusable = $this->reusable('deep', $this->node('section', ['children' => [$deep]]));

        // 参照する側も深くしておくと、展開後に上限を超える
        $node = $this->node('section', ['instanceOf' => $reusable->id]);

        $errors = $this->errors($this->document($node));

        $this->assertNotSame('', $errors, '展開後の深さを見ていません');
    }

    // ── allowlist を回避できない ────────────────────────

    /**
     * **実体の中も registered component だけ。**
     *
     * ここが抜けると、再利用 component が **registry の allowlist を回避する経路**になる
     * （SPEC §4.5 の制約そのもの）。
     */
    public function test_実体の中に未登録の型は置けない(): void
    {
        $reusable = $this->reusable('sneaky', $this->node('section', ['children' => [
            $this->node('not-a-registered-type'),
        ]]));

        $errors = $this->errors($this->document(
            $this->node('section', ['instanceOf' => $reusable->id]),
        ));

        $this->assertNotSame('', $errors, '未登録の型が実体の中を通っています');
    }
}
