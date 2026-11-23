<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\Plan\TermGraph;
use AgentCms\Core\Wxr\WxrTermDefinition;
use LogicException;
use Tests\TestCase;

/**
 * 作る順（Phase 9.14 / STAGE 27D-4・SPEC §C.3）。
 *
 * ## なぜ報告の順と別の口なのか
 *
 * `representatives()` は **WXR に出てきた順**で、`plan` の行動の並びに使う。
 * その並びは畳んで指紋になっているので、**動かすと同じ WXR から違う plan が出る**。
 *
 * `apply` が要るのは**依存の順**である。`CreateTerm` が受け取る親は
 * こちらの ULID なので、**親を先に作って id を覚えていなければ**子を作れない。
 *
 * 目的が別なので、口も別にする。どちらも決定的で、同じ WXR なら同じ順になる。
 */
final class TermGraphCreationOrderTest extends TestCase
{
    public function test_封の前には作る順を読めない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'a', 'A'));

        $this->expectException(LogicException::class);

        $graph->creationOrder();
    }

    public function test_親が先に来る(): void
    {
        // WXR には**子が先**に書かれている
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('2', 'ko', '子', 'oya'));
        $graph->define('news_category', $this->term('1', 'oya', '親'));

        $order = array_column($this->sealed($graph)->creationOrder(), 'termId');

        $this->assertSame(['1', '2'], $order);
    }

    public function test_三代でも上から順に来る(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('3', 'mago', '孫', 'ko'));
        $graph->define('news_category', $this->term('2', 'ko', '子', 'oya'));
        $graph->define('news_category', $this->term('1', 'oya', '親'));

        $this->assertSame(
            ['1', '2', '3'],
            array_column($this->sealed($graph)->creationOrder(), 'termId'),
        );
    }

    public function test_親の対応も返す(): void
    {
        /*
         * `CreateTerm` の `parentId` は**こちらの ULID** である。
         * 誰が親かが分からなければ、作った id を結び付けられない。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oya', '親'));
        $graph->define('news_category', $this->term('2', 'ko', '子', 'oya'));

        $order = $this->sealed($graph)->creationOrder();

        $this->assertNull($order[0]['parentTermId']);
        $this->assertSame('1', $order[1]['parentTermId']);
    }

    public function test_名前も元の綴りも返さない(): void
    {
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('1', 'oshirase', '山田太郎商店'));

        $flat = json_encode($this->sealed($graph)->creationOrder(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($flat);
        $this->assertStringNotContainsString('山田太郎商店', $flat);
    }

    public function test_数が多すぎたときは何も返さない(): void
    {
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS + 1; $i++) {
            $graph->define('news_category', $this->term((string) $i, 't'.$i, 'T'.$i));
        }

        $this->assertSame([], $this->sealed($graph)->creationOrder());
    }

    public function test_報告の並びは動かさない(): void
    {
        /*
         * **`representatives()` は WXR の順のまま。**
         * ここが動くと plan の指紋が変わり、同じ WXR から違う plan が出る。
         */
        $graph = new TermGraph;
        $graph->define('news_category', $this->term('2', 'ko', '子', 'oya'));
        $graph->define('news_category', $this->term('1', 'oya', '親'));

        $sealed = $this->sealed($graph);

        $this->assertSame(['2', '1'], array_column($sealed->representatives(), 'termId'));
        $this->assertSame(['1', '2'], array_column($sealed->creationOrder(), 'termId'));
    }

    public function test_十万件の一本鎖でも順を作れる(): void
    {
        // 親を再帰で辿ると、鎖が長いだけで呼び出しが積み上がって落ちる
        $graph = new TermGraph;

        for ($i = 1; $i <= TermGraph::MAX_DEFINITIONS; $i++) {
            $graph->define('news_category', $this->term(
                (string) $i, 't'.$i, 'T'.$i, $i > 1 ? 't'.($i - 1) : null,
            ));
        }

        $order = $this->sealed($graph)->creationOrder();

        $this->assertCount(TermGraph::MAX_DEFINITIONS, $order);
        $this->assertSame('1', $order[0]['termId']);
        $this->assertSame((string) TermGraph::MAX_DEFINITIONS, $order[TermGraph::MAX_DEFINITIONS - 1]['termId']);
    }

    private function sealed(TermGraph $graph): TermGraph
    {
        $graph->seal(new PlanFindings);

        return $graph;
    }

    private function term(string $termId, string $slug, string $name, ?string $parentSlug = null): WxrTermDefinition
    {
        return new WxrTermDefinition($termId, 'category', $slug, $name, $parentSlug);
    }
}
