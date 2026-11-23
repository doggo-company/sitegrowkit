<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\TargetRef;
use AgentCms\Core\Wxr\Ledger\TargetRowLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 台帳が指している行が、いま実際にあるか（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 意味は決めない。**在るかどうかだけ**を見る
 *
 * ゴミ箱の投稿を「無い」と読むか「在る」と読むかは、取り込みの方針そのもので、
 * Owner Gate（status mapping）の決定を待っている。
 *
 * ここで Eloquent 経由に引くと、`SoftDeletes` の既定の絞り込みが `deleted_at` を
 * 黙って見る。**待っているはずの決定が、読み方の副作用で片側に決まる。**
 * だから物理的な行の存在だけを読み、`deleted_at` は 1 度も見ない。
 *
 * 下の「ゴミ箱の行も在ると読む」は、**取り込みの意味を決めたものではなく、
 * この道具が何を返すかの契約**である。意味を決めるのは Owner Gate の後の段。
 */
final class TargetRowLookupTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    public function test_行があれば在ると読む(): void
    {
        $id = $this->news();

        $this->assertSame([true], $this->exists([new TargetRef(ImportKind::Content, 'news', $id)]));
    }

    public function test_行が無ければ無いと読む(): void
    {
        $this->assertSame(
            [false],
            $this->exists([new TargetRef(ImportKind::Content, 'news', '999999')]),
        );
    }

    public function test_ゴミ箱にある行も在ると読む(): void
    {
        /*
         * **これは取り込みの意味を決めたものではない。**
         *
         * ゴミ箱の投稿を「入っている」と読むか「消えた」と読むかは Owner Gate の
         * 決定待ちである。ここで固定するのは、この道具が `deleted_at` を
         * **1 度も見ない**という契約の方で、Eloquent 経由に変えた途端に
         * 既定の絞り込みが働き、**待っているはずの決定が黙って片側に倒れる**。
         */
        $id = $this->news();

        DB::table('news')->where('id', $id)->update(['deleted_at' => now()]);

        $this->assertSame([true], $this->exists([new TargetRef(ImportKind::Content, 'news', $id)]));
    }

    public function test_分類項目とコメントとメディアも読む(): void
    {
        $refs = [
            new TargetRef(ImportKind::Term, 'news_category', $this->term()),
            new TargetRef(ImportKind::Comment, 'comment', $this->comment()),
            new TargetRef(ImportKind::Media, 'media', $this->media()),
        ];

        $this->assertSame([true, true, true], $this->exists($refs));
    }

    public function test_型そのものが無くなっていれば無いと読む(): void
    {
        /*
         * 台帳の `target_type` は**過去の**取り込み先である。型が消えていれば、
         * その行を探しに行く先が無い。**推測で別の型を探さない。**
         */
        $this->assertFalse(app(ContentTypeResolver::class)->knows('gone_away'));

        $this->assertSame(
            [false],
            $this->exists([new TargetRef(ImportKind::Content, 'gone_away', '999999')]),
        );
    }

    // ── まとめて引く ────────────────────────────────────────

    public function test_渡した並びのまま返す(): void
    {
        $present = $this->news();

        $result = $this->exists([
            new TargetRef(ImportKind::Content, 'news', '999999'),
            new TargetRef(ImportKind::Content, 'news', $present),
            new TargetRef(ImportKind::Term, 'news_category', '01JZZZZZZZZZZZZZZZZZZZZZZZ'),
        ]);

        $this->assertSame([false, true, false], $result);
    }

    public function test_取り込み先ごとに1回しか問い合わせない(): void
    {
        /*
         * 1 件ずつ引くと、十年分では数万回になる。**読むだけの操作が本番を遅くする。**
         * 引く先は種類と取り込み先の組で決まるので、その組ごとに 1 回でよい。
         *
         * 型の定義を読む問い合わせは**件数に比例しない**（一度きりで memo される）。
         * 数える前に温めておかないと、そちらを N+1 と読み違える。
         */
        app(ContentTypeResolver::class)->newModel('news');

        $refs = [];

        for ($i = 1; $i <= 200; $i++) {
            $refs[] = new TargetRef(ImportKind::Content, 'news', (string) $i);
            $refs[] = new TargetRef(ImportKind::Term, 'news_category', Str::ulid()->toString());
        }

        $seen = $this->countQueries(fn () => $this->exists($refs));

        $this->assertSame(2, count($seen), implode("\n", $seen));
    }

    public function test_件数を増やしても問い合わせは増えない(): void
    {
        // **これが N+1 の本体である。**組の数ではなく件数に比例していないか
        app(ContentTypeResolver::class)->newModel('news');

        $few = $this->countQueries(fn () => $this->exists([
            new TargetRef(ImportKind::Content, 'news', '1'),
            new TargetRef(ImportKind::Content, 'news', '2'),
        ]));

        $many = [];

        for ($i = 1; $i <= 400; $i++) {
            $many[] = new TargetRef(ImportKind::Content, 'news', (string) $i);
        }

        $this->assertSame(count($few), count($this->countQueries(fn () => $this->exists($many))));
    }

    public function test_一度に渡せる数を超えたら断る(): void
    {
        $refs = [];

        for ($i = 1; $i <= TargetRowLookup::MAX_WINDOW + 1; $i++) {
            $refs[] = new TargetRef(ImportKind::Content, 'news', (string) $i);
        }

        $this->expectException(InvalidArgumentException::class);

        $this->exists($refs);
    }

    public function test_同じ行を二度渡したら断る(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->exists([
            new TargetRef(ImportKind::Content, 'news', '999999'),
            new TargetRef(ImportKind::Content, 'news', '999999'),
        ]);
    }

    // ── 指す先に本文を入れられない ──────────────────────────

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function unusableRefs(): array
    {
        return [
            '空の取り込み先' => ['', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
            '空の id' => ['news', ''],
            '桁を超える id' => ['news', str_repeat('1', 65)],
            '改行を含む id' => ['news', "1\n2"],
            '空白を含む取り込み先' => ['news items', '1'],
            'メールアドレス' => ['news', 'yamada@example.com'],
            '日本語' => ['news', '山田太郎'],
        ];
    }

    #[DataProvider('unusableRefs')]
    public function test_識別子の形でないものは指せない(string $targetType, string $targetId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TargetRef(ImportKind::Content, $targetType, $targetId);
    }

    // ── 1 行も書かない ──────────────────────────────────────

    public function test_読むだけで1行も書かない(): void
    {
        $id = $this->news();

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->exists([
            new TargetRef(ImportKind::Content, 'news', $id),
            new TargetRef(ImportKind::Term, 'news_category', '01JZZZZZZZZZZZZZZZZZZZZZZZ'),
        ]);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  \Closure(): mixed  $run
     * @return list<string> その間に飛んだ問い合わせ
     */
    private function countQueries(\Closure $run): array
    {
        $seen = [];

        DB::listen(static function ($query) use (&$seen): void {
            $seen[] = (string) $query->sql;
        });

        $run();

        return $seen;
    }

    /**
     * @param  list<TargetRef>  $refs
     * @return list<bool>
     */
    private function exists(array $refs): array
    {
        return app(TargetRowLookup::class)->exists($refs);
    }

    private function news(): string
    {
        return (string) DB::table('news')->insertGetId([
            'title' => '記事',
            'slug' => 'kiji-'.Str::lower(Str::random(8)),
            'body' => '本文',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function term(): string
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $taxonomyId = (string) DB::table('taxonomies')->where('key', 'news_category')->value('id');
        $id = Str::ulid()->toString();

        DB::table('taxonomy_terms')->insert([
            'id' => $id,
            'taxonomy_id' => $taxonomyId,
            'name' => 'お知らせ',
            'slug' => 'oshirase',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function comment(): string
    {
        return (string) DB::table('content_comments')->insertGetId([
            'content_type' => 'news',
            /*
             * **記事の id は整数である**（`content_comments.content_id` は
             * `unsignedBigInteger`）。ULID を入れると sqlite は黙って受け取るが、
             * MySQL は `1265 Data truncated` で止まる — 27F の全掃引で出た。
             * ここが確かめるのは「コメントの行が在ると読めるか」だけで、
             * 指す先の記事は要らないので、**列の型に合う値**を置く。
             */
            'content_id' => 1,
            'status' => 'approved',
            'author_name' => '読者',
            'body' => 'こんにちは',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function media(): string
    {
        return (string) DB::table('media')->insertGetId([
            'name' => '写真',
            'path' => 'uploads/a.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
            'width' => 1,
            'height' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
