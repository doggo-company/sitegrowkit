<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Ledger\ExistingTermLookup;
use AgentCms\Core\Wxr\Ledger\TermSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * その綴りの項目が、こちらの分類に既にあるか（Phase 9.14 / STAGE 27D-1・SPEC §C.3）。
 *
 * ## ここが `reuse` の出どころ
 *
 * §C.3 は「既存の分類へ**項目だけ**足す」である。既にある項目に足すことはできないので、
 * **作らずに使う**。作っていないものを取り消しで消してはならないので、
 * 所有権も持たない（`import_records.created_by_import = false`）。
 *
 * ## 見つからないことと、確かめられないことを混ぜない
 *
 * 分類そのものが同期されていない場合は、ここでは答えを出さない。
 * その状態は `TargetStateResolver` が既に止めている（§C.3）。
 */
final class ExistingTermLookupTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
    }

    public function test_同じ綴りの項目があればその_idを返す(): void
    {
        $id = $this->term('news_category', 'oshirase');

        $this->assertSame(
            [['id' => $id, 'archived' => false]],
            $this->find([new TermSlot('news_category', 'oshirase')]),
        );
    }

    public function test_無ければnullを返す(): void
    {
        $this->assertSame([null], $this->find([new TermSlot('news_category', 'nowhere')]));
    }

    public function test_受け皿が違えば別の項目として扱う(): void
    {
        /*
         * 同じ綴りでも分類が違えば別物である。ここを跨いで拾うと、
         * **お知らせの項目に実績の記事がぶら下がる**。
         */
        $this->term('news_category', 'oshirase');
        $this->term('another_category', 'betsumono');

        $this->assertSame(
            [null, null],
            $this->find([
                new TermSlot('another_category', 'oshirase'),
                new TermSlot('news_category', 'betsumono'),
            ]),
        );
    }

    public function test_保管された項目も在るものとして扱う(): void
    {
        /*
         * 保管（archive）は綴りを解放しない。**在るのに無いと読むと、
         * 同じ綴りをもう 1 つ作ろうとして一意制約に弾かれる。**
         *
         * 保管された分類へ項目を足してよいかは別の話で、
         * それは `TargetStateResolver` が `taxonomy_archived` として既に止めている。
         */
        $id = $this->term('news_category', 'oshirase', archived: true);

        /*
         * **保管かどうかも一緒に返す。** 別々に引くと、在るかどうかと保管かどうかで
         * 別の瞬間の DB を見ることになり、その間に保管されれば
         * 「在るが保管ではない」という存在しない状態から plan を作る。
         */
        $this->assertSame(
            [['id' => $id, 'archived' => true]],
            $this->find([new TermSlot('news_category', 'oshirase')]),
        );
    }

    public function test_同期されていない分類はnullを返す(): void
    {
        // 確かめられないだけで、ここでは判断しない（§C.3 は resolver が止める）
        $this->assertSame([null], $this->find([new TermSlot('does_not_exist', 'oshirase')]));
    }

    // ── まとめて引く ────────────────────────────────────────

    public function test_渡した並びのまま返す(): void
    {
        $a = $this->term('news_category', 'aaa');
        $b = $this->term('another_category', 'bbb');

        $this->assertSame(
            [null, ['id' => $a, 'archived' => false], ['id' => $b, 'archived' => false]],
            $this->find([
                new TermSlot('news_category', 'zzz'),
                new TermSlot('news_category', 'aaa'),
                new TermSlot('another_category', 'bbb'),
            ]),
        );
    }

    public function test_分類ごとに1回しか問い合わせない(): void
    {
        $slots = [];

        for ($i = 1; $i <= 200; $i++) {
            $slots[] = new TermSlot('news_category', 'a'.$i);
            $slots[] = new TermSlot('another_category', 'b'.$i);
        }

        $seen = [];
        DB::listen(static function ($query) use (&$seen): void {
            $seen[] = (string) $query->sql;
        });

        $this->find($slots);

        // 分類 2 つ ＋ その id を引く分（分類ごとに 1 回）
        $this->assertLessThanOrEqual(4, count($seen), implode("\n", $seen));
    }

    public function test_一度に渡せる数を超えたら断る(): void
    {
        $slots = [];

        for ($i = 1; $i <= ExistingTermLookup::MAX_WINDOW + 1; $i++) {
            $slots[] = new TermSlot('news_category', 'a'.$i);
        }

        $this->expectException(InvalidArgumentException::class);

        $this->find($slots);
    }

    public function test_同じ枠を二度渡したら断る(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->find([
            new TermSlot('news_category', 'oshirase'),
            new TermSlot('news_category', 'oshirase'),
        ]);
    }

    // ── 枠に本文を入れられない ──────────────────────────────

    public function test_識別子の形でない綴りは指せない(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TermSlot('news_category', 'お知らせ');
    }

    public function test_読むだけで1行も書かない(): void
    {
        $this->term('news_category', 'oshirase');

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->find([
            new TermSlot('news_category', 'oshirase'),
            new TermSlot('news_category', 'nowhere'),
        ]);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    /**
     * @param  list<TermSlot>  $slots
     * @return list<array{id: string, archived: bool}|null>
     */
    private function find(array $slots): array
    {
        return app(ExistingTermLookup::class)->find($slots);
    }

    private function term(string $taxonomy, string $slug, bool $archived = false): string
    {
        $taxonomyId = (string) DB::table('taxonomies')->where('key', $taxonomy)->value('id');

        if ($taxonomyId === '') {
            // 定義が 1 つしか無いので、2 つ目の分類はここで直接作る
            $taxonomyId = Str::ulid()->toString();

            DB::table('taxonomies')->insert([
                'id' => $taxonomyId,
                'key' => $taxonomy,
                'label' => '別の分類',
                'hierarchical' => false,
                'status' => 'active',
                'managed_by' => 'code',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $id = Str::ulid()->toString();

        DB::table('taxonomy_terms')->insert([
            'id' => $id,
            'taxonomy_id' => $taxonomyId,
            'name' => '項目',
            'slug' => $slug,
            'archived_at' => $archived ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
