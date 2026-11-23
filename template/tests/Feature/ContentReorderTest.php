<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\ListContent;
use AgentCms\Core\Application\Content\ReorderContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 投稿の手動並べ替え（P1 ②）。
 *
 * ## 全件を送らせない
 *
 * 分類項目の並べ替え（`term.reorder`）は**兄弟全件**を送らせる。兄弟が数個だからである。
 * **投稿は 1000 件になり得る**ので、同じ形にすると頁の中だけを送ることになり、
 * **画面に出ていない行を上書き**する。だから送るのは **「どれを、どれの直後へ」** の 2 つだけ。
 *
 * ## 最初の 1 回だけ、全行に番号が振られる
 *
 * 既存の行は全部 `sort_order = 0` なので、**0 同士では順序が決まらない**。
 * 1 件動かすとき、まず**いま見えている順**をそのまま番号にする（実体化）。
 * **これをしないと、動かした 1 件以外の順序が未定義**になる。
 */
final class ContentReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_二ページ目の上下移動は全体先頭に飛ばず隣だけと入れ替わる(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->news('記事'.$i, '2026-01-01 00:00:00');
            DB::table('news')->where('id', $ids[$i])->update(['sort_order' => $i]);
        }
        $owner = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/v1/content/news/'.$ids[21].'/reorder', ['direction' => 'up'])->assertOk();
        $order = DB::table('news')->orderBy('sort_order')->pluck('id')->all();
        $expected = $ids;
        [$expected[20], $expected[21]] = [$expected[21], $expected[20]];
        $this->assertSame($expected, $order);
        // 二ページ目の先頭も前のページへ一つだけ動く。
        $this->actingAs($owner)->postJson('/api/v1/content/news/'.$ids[21].'/reorder', ['direction' => 'up'])->assertOk();
        [$expected[19], $expected[20]] = [$expected[20], $expected[19]];
        $this->assertSame($expected, DB::table('news')->orderBy('sort_order')->pluck('id')->all());
        $this->actingAs($owner)->postJson('/api/v1/content/news/'.$ids[21].'/reorder', ['direction' => 'down'])->assertOk();
        [$expected[19], $expected[20]] = [$expected[20], $expected[19]];
        $this->assertSame($expected, DB::table('news')->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_上下移動の曖昧な入力は並べ替えない(): void
    {
        $id = $this->news('記事', '2026-01-01 00:00:00');
        foreach ([true, [], 'sideways', 1] as $direction) {
            $result = app(ReorderContent::class)->execute('news', $id, null, OperationContext::cli('test'), $direction);
            $this->assertSame(OperationStatus::ValidationError, $result->status);
        }
        $result = app(ReorderContent::class)->execute('news', $id, 123, OperationContext::cli('test'), 'up');
        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(['記事'], $this->publicOrder());
    }

    /** @return int 記事 id */
    private function news(string $title, string $publishedAt): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => 'o-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => $publishedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reorder(int $id, ?int $afterId): Result
    {
        return app(ReorderContent::class)->execute(
            'news', $id, $afterId, OperationContext::cli('test'),
        );
    }

    /** 公開一覧に出る順（= 並べ替えの対象そのもの） */
    private function publicOrder(): array
    {
        return DB::table('news')
            ->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id')
            ->pluck('title')->all();
    }

    /**
     * **並べ替える前は、いままでと同じ並び。**
     *
     * 既存の行は全部 0 なので、第二キー（公開日の新しい順）がそのまま効く。
     * ここが崩れると、**列を足しただけで全サイトの並びが変わる**。
     */
    public function test_並べ替える前は公開日の新しい順のまま(): void
    {
        $this->news('古い', '2026-01-01 00:00:00');
        $this->news('新しい', '2026-03-01 00:00:00');
        $this->news('まんなか', '2026-02-01 00:00:00');

        $this->assertSame(['新しい', 'まんなか', '古い'], $this->publicOrder());
    }

    /**
     * **最初の 1 回で、いま見えている順が番号になる。**
     *
     * 動かした 1 件だけに番号を振ると、**残りは 0 のまま**で順序が決まらない。
     */
    public function test_最初の移動でいま見えている順が実体化される(): void
    {
        $old = $this->news('古い', '2026-01-01 00:00:00');
        $this->news('新しい', '2026-03-01 00:00:00');
        $this->news('まんなか', '2026-02-01 00:00:00');

        // 「古い」を先頭へ
        $this->assertSame(OperationStatus::Success, $this->reorder($old, null)->status);

        $this->assertSame(['古い', '新しい', 'まんなか'], $this->publicOrder());

        // **全行に番号が付いている**（0 のまま残った行が無い = 順序が決まっている）
        $orders = DB::table('news')->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([0, 1, 2], array_map(intval(...), $orders));
    }

    /** **「直後へ」が効く**（先頭以外へも動かせる） */
    public function test_指定した投稿の直後へ入る(): void
    {
        $this->news('A', '2026-03-01 00:00:00');
        $b = $this->news('B', '2026-02-01 00:00:00');
        $c = $this->news('C', '2026-01-01 00:00:00');

        // C を B の直後へ（もともと A, B, C なので位置は変わらない）
        $this->assertSame(OperationStatus::Success, $this->reorder($c, $b)->status);
        $this->assertSame(['A', 'B', 'C'], $this->publicOrder());

        // B を C の直後へ
        $this->assertSame(OperationStatus::Success, $this->reorder($b, $c)->status);
        $this->assertSame(['A', 'C', 'B'], $this->publicOrder());
    }

    /**
     * **動かしていない型に触れない。**
     *
     * 型をまたいで番号を振ると、**別の型の並びが黙って変わる**。
     */
    public function test_別の型の並びに触れない(): void
    {
        $id = $this->news('お知らせ', '2026-01-01 00:00:00');

        foreach (['実績 1', '実績 2'] as $i => $title) {
            DB::table('works')->insert([
                'title' => $title, 'slug' => 'w-'.uniqid(), 'excerpt' => '概要',
                'body' => '<p>本文</p>', 'category' => 'Web', 'status' => 'published',
                'published_at' => now()->subDays($i + 1),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $before = DB::table('works')->orderBy('id')->pluck('sort_order')->all();

        $this->reorder($id, null);

        $this->assertSame($before, DB::table('works')->orderBy('id')->pluck('sort_order')->all());
    }

    /**
     * **新しい投稿は先頭に入る。**
     *
     * 既定が 0 なので、並べ替え済みの列（0 から連番）の**先頭と同値**になり、
     * 第二キー（公開日の新しい順）で上に来る。**いまの「新しいものが上」と一致する。**
     */
    public function test_新しい投稿は先頭に入る(): void
    {
        $a = $this->news('A', '2026-01-01 00:00:00');
        $this->news('B', '2026-01-02 00:00:00');

        $this->reorder($a, null); // 実体化させる

        $this->news('あとから来た', '2026-06-01 00:00:00');

        $this->assertSame('あとから来た', $this->publicOrder()[0]);
    }

    /**
     * **公開一覧が、手で決めた順で出る。**
     *
     * ここまでの試験は表を直に読んでいる。それだけだと
     * **並びを決める列は正しいのに、一覧がそれを見ていない**を通してしまう。
     */
    public function test_公開一覧が手で決めた順で出る(): void
    {
        $old = $this->news('いちばん古い', '2026-01-01 00:00:00');
        $this->news('いちばん新しい', '2026-03-01 00:00:00');

        // 公開日の順とは**逆**にする（並べ替えが効いていなければ、この順にはならない）
        $this->reorder($old, null);

        $html = (string) $this->get('/news')->assertOk()->getContent();

        $this->assertLessThan(
            (int) mb_strpos($html, 'いちばん新しい'),
            (int) mb_strpos($html, 'いちばん古い'),
            '公開一覧が手で決めた順を見ていない',
        );
    }

    /**
     * **管理一覧も同じ順で出る。**
     *
     * 並べる画面と出る画面の順が違うと、**3 番目に置いたものが 5 番目に出る**。
     */
    public function test_管理一覧も同じ順で出る(): void
    {
        $first = $this->news('あとから作った', '2026-01-01 00:00:00');
        $this->news('先に作った', '2026-03-01 00:00:00');

        // 管理一覧の既定は id の新しい順。**それとは逆**になる並びを作る
        $this->reorder($first, null);

        $result = app(ListContent::class)
            ->execute('news', OperationContext::cli('test'));

        $titles = array_map(
            static fn (array $row): string => (string) ($row['title'] ?? ''),
            $result->data['items'] ?? [],
        );

        $this->assertSame(['あとから作った', '先に作った'], $titles);
    }

    /**
     * **公開日が同じでも、順が決まる**（F-38）。
     *
     * 第二キー（公開日）まででは**同値で止まる**。そこで並びが決まらないと、
     * 実体化のたびに違う番号が付き、**「既存の並びは変わらない」という保証ごと崩れる**。
     *
     * 同値は珍しくない — **WXR の取り込みで一番起きやすい**（元の日付が日単位だったり、
     * 取り込み時刻がそのまま入ったりする）。だから id まで見る。
     */
    public function test_公開日が同値でも順が決まる(): void
    {
        $same = '2026-02-01 00:00:00';

        $first = $this->news('さいしょ', $same);
        $second = $this->news('つぎ', $same);
        $third = $this->news('さいご', $same);

        // 同値なので **id の新しい順**が答えになる
        $this->assertSame(['さいご', 'つぎ', 'さいしょ'], $this->publicOrder());

        // 実体化しても同じ答えになる（**動かしていない行の順が変わらない**）
        $this->reorder($third, null);

        $this->assertSame(['さいご', 'つぎ', 'さいしょ'], $this->publicOrder());
        $this->assertSame(
            [$third => 0, $second => 1, $first => 2],
            array_map(intval(...), DB::table('news')->orderBy('sort_order')
                ->pluck('sort_order', 'id')->all()),
        );
    }

    public function test_トップの見本一覧も手動の掲載順で並ぶ(): void
    {
        $old = $this->news('掲載順先頭', '2026-01-01 00:00:00');
        $this->news('掲載順末尾', '2026-03-01 00:00:00');
        $this->assertSame(OperationStatus::Success, $this->reorder($old, null)->status);
        $this->get('/')->assertOk()->assertSeeInOrder(['掲載順先頭', '掲載順末尾']);
    }

    /** **知らない id は断る**（別の行を巻き込まない） */
    public function test_知らないidは断る(): void
    {
        $this->news('A', '2026-01-01 00:00:00');

        $this->assertSame(OperationStatus::NotFound, $this->reorder(999999, null)->status);
        $this->assertSame(OperationStatus::NotFound, $this->reorder(
            (int) DB::table('news')->value('id'), 999999,
        )->status);
    }

    /** **自分の直後へは動かせない**（意味が無く、番号の計算だけが走る） */
    public function test_自分自身の直後へは動かせない(): void
    {
        $a = $this->news('A', '2026-01-01 00:00:00');

        $this->assertSame(OperationStatus::ValidationError, $this->reorder($a, $a)->status);
    }
}
