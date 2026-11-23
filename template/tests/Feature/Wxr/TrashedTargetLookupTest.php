<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\TargetRef;
use AgentCms\Core\Wxr\Ledger\TrashedTargetLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 対象がいまゴミ箱に入っているか（Phase 9.14 / STAGE 27C・SPEC §D.3）。
 *
 * ## `TargetRowLookup` と分ける
 *
 * あちらは**物理的に行があるか**しか答えない。ゴミ箱の解釈を混ぜると、
 * 「在るか」と「入っているか」が 1 つの答えになり、
 * **どちらの意味で使われているのか呼ぶ側から見えなくなる**。
 *
 * ここも判断はしない。**入っているかどうかを返すだけ**で、
 * それを取り込みの理由へ落とすのは決定側の仕事である。
 */
final class TrashedTargetLookupTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    public function test_ゴミ箱に入っていれば入っていると読む(): void
    {
        $id = $this->news(trashed: true);

        $this->assertSame([true], $this->trashed([new TargetRef(ImportKind::Content, 'news', $id)]));
    }

    public function test_ゴミ箱に入っていなければ入っていないと読む(): void
    {
        $this->assertSame(
            [false],
            $this->trashed([new TargetRef(ImportKind::Content, 'news', $this->news())]),
        );
    }

    public function test_行が無ければ入っていないと読む(): void
    {
        /*
         * **「無い」と「ゴミ箱にある」を混ぜない。** 行が無いことは
         * `TargetRowLookup` の担当で、ここは「入っているか」しか答えない。
         * ここで true を返すと、消えた対象がゴミ箱にあると誤って報告される。
         */
        $this->assertSame(
            [false],
            $this->trashed([new TargetRef(ImportKind::Content, 'news', '999999')]),
        );
    }

    public function test_ゴミ箱を持たない種類は入っていないと読む(): void
    {
        /*
         * 分類項目・コメント・メディアはゴミ箱の対象ではない（9.8 SPEC §A）。
         * 列が無いので、**問い合わせに行かない**。
         */
        $this->assertSame(
            [false, false, false],
            $this->trashed([
                new TargetRef(ImportKind::Term, 'news_category', '01JZZZZZZZZZZZZZZZZZZZZZZZ'),
                new TargetRef(ImportKind::Comment, 'comment', '1'),
                new TargetRef(ImportKind::Media, 'media', '1'),
            ]),
        );
    }

    public function test_渡した並びのまま返す(): void
    {
        $trashed = $this->news(trashed: true);
        $alive = $this->news();

        $this->assertSame(
            [false, true, false],
            $this->trashed([
                new TargetRef(ImportKind::Content, 'news', $alive),
                new TargetRef(ImportKind::Content, 'news', $trashed),
                new TargetRef(ImportKind::Content, 'news', '999999'),
            ]),
        );
    }

    public function test_取り込み先ごとに1回しか問い合わせない(): void
    {
        app(ContentTypeResolver::class)->newModel('news');

        $refs = [];

        for ($i = 1; $i <= 200; $i++) {
            $refs[] = new TargetRef(ImportKind::Content, 'news', (string) $i);
            $refs[] = new TargetRef(ImportKind::Term, 'news_category', Str::ulid()->toString());
        }

        $seen = [];
        DB::listen(static function ($query) use (&$seen): void {
            $seen[] = (string) $query->sql;
        });

        $this->trashed($refs);

        // 分類はゴミ箱を持たないので、問い合わせは投稿の 1 回だけ
        $this->assertSame(1, count($seen), implode("\n", $seen));
    }

    public function test_読むだけで1行も書かない(): void
    {
        $id = $this->news(trashed: true);

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->trashed([new TargetRef(ImportKind::Content, 'news', $id)]);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    /**
     * @param  list<TargetRef>  $refs
     * @return list<bool>
     */
    private function trashed(array $refs): array
    {
        return app(TrashedTargetLookup::class)->trashed($refs);
    }

    private function news(bool $trashed = false): string
    {
        $id = (string) DB::table('news')->insertGetId([
            'title' => '記事',
            'slug' => 'kiji-'.Str::lower(Str::random(8)),
            'body' => '本文',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($trashed) {
            DB::table('news')->where('id', $id)->update(['deleted_at' => now()]);
        }

        return $id;
    }
}
