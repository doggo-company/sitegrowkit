<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 管理画面から並べ替える口（P1 ②）。
 *
 * ## 画面は「どれを、どれの直後へ」しか送らない
 *
 * 一覧が持っているのは**その頁の行**だけである。全件を送らせる形にすると、
 * **頁の外の行を上書き**する。だから口も `{afterId}` だけを受ける。
 */
final class ContentReorderApiTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function news(string $title, string $publishedAt): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => 'a-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => $publishedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> 手で決めた順に並べた見出し */
    private function order(): array
    {
        return DB::table('news')
            ->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id')
            ->pluck('title')->all();
    }

    public function test_直後へ動かせる(): void
    {
        $a = $this->news('A', '2026-03-01 00:00:00');
        $b = $this->news('B', '2026-02-01 00:00:00');

        $this->actingAs($this->operator())
            ->postJson("/api/v1/content/news/{$a}/reorder", ['afterId' => $b])
            ->assertOk();

        $this->assertSame(['B', 'A'], $this->order());
    }

    /** **`afterId` を書かなければ先頭へ**（一番よくやる操作を既定にする） */
    public function test_after_idが無ければ先頭へ(): void
    {
        $this->news('A', '2026-03-01 00:00:00');
        $b = $this->news('B', '2026-02-01 00:00:00');

        $this->actingAs($this->operator())
            ->postJson("/api/v1/content/news/{$b}/reorder", [])
            ->assertOk();

        $this->assertSame(['B', 'A'], $this->order());
    }

    /**
     * **入っていない人には触らせない。**
     *
     * 掲載順は公開サイトの見え方そのものなので、読むだけの人が変えられてはいけない。
     */
    public function test_ログインしていなければ触れない(): void
    {
        $a = $this->news('A', '2026-03-01 00:00:00');
        $this->news('B', '2026-02-01 00:00:00');

        $this->postJson("/api/v1/content/news/{$a}/reorder", ['afterId' => null])
            ->assertStatus(401);

        $this->assertSame(['A', 'B'], $this->order());
    }

    /**
     * **一覧の画面に並べ替えの導線が出る。**
     *
     * 口が在っても、画面が知らなければ誰も押せない。
     */
    public function test_一覧に並べ替えの導線が出る(): void
    {
        $this->news('A', '2026-03-01 00:00:00');

        $this->actingAs($this->operator())
            ->get('/admin/news')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/resource/Index')
                ->where('canReorder', true));
    }

    /**
     * **ゴミ箱では出さない。**
     *
     * 捨てたものの掲載順に意味は無い。**出しても押せる先が無い。**
     */
    public function test_ゴミ箱では並べ替えの導線を出さない(): void
    {
        $this->news('A', '2026-03-01 00:00:00');

        $this->actingAs($this->operator())
            ->get('/admin/news?state=trashed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canReorder', false));
    }

    /**
     * **読めない `afterId` は断る**（F-39）。
     *
     * `(int)` で潰すと **`true` が 1 になり「id 1 の直後へ」に化ける** — 頼んでいない
     * 場所へ動く。`"3.9"` の切り詰めも、3 と 4 のどちらのつもりか分からないので断る。
     */
    public function test_整数として読めないafter_idは断る(): void
    {
        $a = $this->news('A', '2026-03-01 00:00:00');
        $this->news('B', '2026-02-01 00:00:00');

        foreach ([['afterId' => 'abc'], ['afterId' => true], ['afterId' => [1]], ['afterId' => '3.9']] as $payload) {
            $this->actingAs($this->operator())
                ->postJson("/api/v1/content/news/{$a}/reorder", $payload)
                ->assertStatus(422);
        }

        // **1 件も動いていない**
        $this->assertSame(['A', 'B'], $this->order());
    }

    /** **知らない id は 404**（別の行を巻き込まない） */
    public function test_知らないidは404(): void
    {
        $this->news('A', '2026-03-01 00:00:00');

        $this->actingAs($this->operator())
            ->postJson('/api/v1/content/news/999999/reorder', [])
            ->assertNotFound();
    }
}
