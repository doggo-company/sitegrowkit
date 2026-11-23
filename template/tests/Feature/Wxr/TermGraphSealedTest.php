<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TermGraph;
use AgentCms\Core\Wxr\WxrTermDefinition;
use LogicException;
use Tests\TestCase;

/**
 * 封をした後の代表と alias を読む（Phase 9.14 / STAGE 27D-1・SPEC §C.3）。
 *
 * ## なぜ封の後だけなのか
 *
 * 保存する綴りは封をするときに決まる（重複を畳み、当てる綴りを決める）。
 * 封の前に読めると、**確かめていない代表**が外へ出る。
 *
 * ## 何を返さないか
 *
 * 名前も元の綴りも返さない。他人のサイトの分類名には個人名も屋号も入る。
 * 行動を積むのに要るのは、受け皿・保存する綴り・WXR 側の id だけである。
 */
final class TermGraphSealedTest extends TestCase
{
    public function test_封の前には代表を読めない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'a', 'A'));

        $this->expectException(LogicException::class);

        $graph->representatives();
    }

    public function test_封の後に代表を読める(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', 'お知らせ'));

        $this->assertSame(
            [['termId' => '1', 'target' => 'news_category', 'storageSlug' => 'oshirase']],
            $this->sealed($graph)->representatives(),
        );
    }

    public function test_当てた綴りも代表として読める(): void
    {
        // `Str::slug('お知らせ')` は空になるので、term_id から当てた綴りが返る
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('7', 'お知らせ', 'お知らせ'));

        $this->assertSame(
            [['termId' => '7', 'target' => 'news_category', 'storageSlug' => 'term-7']],
            $this->sealed($graph)->representatives(),
        );
    }

    public function test_名前も元の綴りも返さない(): void
    {
        /*
         * 他人のサイトの分類名には個人名も屋号も入る。
         * 行動を積むのに要らないものを返す口にしない。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', '山田太郎商店'));

        $flat = json_encode($this->sealed($graph)->representatives(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($flat);
        $this->assertStringNotContainsString('山田太郎商店', $flat);
    }

    public function test_重なった定義は代表に出ずaliasとして出る(): void
    {
        /*
         * 台帳は term_id ごとに 1 行を持つので、重なった id にも行が要る。
         * だが**対象を作るのは代表 1 件だけ**なので、代表の一覧には出さない。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'a', 'A'));
        $graph->define('news_category', $this->term('2', 'a', 'A'));

        $sealed = $this->sealed($graph);

        $this->assertSame(['1'], array_column($sealed->representatives(), 'termId'));
        $this->assertSame(['2' => '1'], $sealed->aliases());
    }

    public function test_数が多すぎたときは何も返さない(): void
    {
        /*
         * 確かめ切れていないので代表も無い。ここで途中まで返すと、
         * **確かめていない代表から行動を積む**ことになる。
         */
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS + 1; $i++) {
            $graph->define('news_category', $this->term((string) $i, 't'.$i, 'T'.$i));
        }

        $sealed = $this->sealed($graph);

        $this->assertSame([], $sealed->representatives());
        $this->assertSame([], $sealed->aliases());
    }

    public function test_受け皿ごとに分かれて出る(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', 'お知らせ'));
        $graph->define('works_category', $this->term('2', 'oshirase', '実績'));

        $this->assertSame(
            ['news_category', 'works_category'],
            array_column($this->sealed($graph)->representatives(), 'target'),
        );
    }

    // ── 割り当てから代表を引く（27D-5a） ──────────────────────

    public function test_封の前には割り当て先を引けない(): void
    {
        /*
         * 封をする前は、重複を畳んでいないので**代表が決まっていない**。
         * ここで引けると、後から代表が変わったときに
         * **記事だけ別の項目へ結び付いた**状態ができる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', 'お知らせ'));

        $this->expectException(LogicException::class);

        $graph->representativeFor('news_category', 'oshirase');
    }

    public function test_割り当ての綴りから代表のidを引ける(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', 'お知らせ'));

        $this->assertSame('1', $this->sealed($graph)->representativeFor('news_category', 'oshirase'));
    }

    public function test_当てた綴りでも元の綴りで引ける(): void
    {
        /*
         * **item が持っているのは元の綴りだけ**である。
         * こちらが当てた綴り（`term-7`）で引く形にすると、
         * 当てが働いた分類の割り当てが**丸ごと引けなくなる** —
         * 日本語のサイトではそれがほとんどになる。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('7', 'お知らせ', 'お知らせ'));

        $sealed = $this->sealed($graph);

        $this->assertSame('term-7', $sealed->representatives()[0]['storageSlug']);
        $this->assertSame('7', $sealed->representativeFor('news_category', 'お知らせ'));
    }

    public function test_重なった定義でも代表を引ける(): void
    {
        // 割り当ては綴りで来るので、重なった id のどちらでもなく**代表**が返る
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'a', 'A'));
        $graph->define('news_category', $this->term('2', 'a', 'A'));

        $this->assertSame('1', $this->sealed($graph)->representativeFor('news_category', 'a'));
    }

    public function test_定義の無い綴りは引けない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', 'お知らせ'));

        $this->assertNull($this->sealed($graph)->representativeFor('news_category', 'nai'));
    }

    public function test_受け皿が違えば引けない(): void
    {
        // 対応先の分類が違えば、**同じ綴りでも別の項目**である
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', 'お知らせ'));

        $this->assertNull($this->sealed($graph)->representativeFor('works_category', 'oshirase'));
    }

    public function test_数が多すぎたときは引けない(): void
    {
        /*
         * 確かめ切れていないので代表も無い。ここで引けると、
         * **確かめていない代表へ記事を結び付ける**ことになる。
         */
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS + 1; $i++) {
            $graph->define('news_category', $this->term((string) $i, 't'.$i, 'T'.$i));
        }

        $this->assertNull($this->sealed($graph)->representativeFor('news_category', 't1'));
    }

    public function test_名前も元の綴りも返さない_割り当て(): void
    {
        // 返るのは WXR 側の id だけ。名前は入力にも出力にも出ない
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', '山田太郎商店'));

        $this->assertSame('1', $this->sealed($graph)->representativeFor('news_category', 'oshirase'));
    }

    private function sealed(TermGraph $graph): TermGraph
    {
        $graph->seal(new PlanFindings);

        return $graph;
    }

    private function term(string $termId, string $slug, string $name): WxrTermDefinition
    {
        return new WxrTermDefinition($termId, 'category', $slug, $name, null);
    }
}
