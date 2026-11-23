<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerEntry;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Ledger\LedgerState;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 台帳と現実を突き合わせて、決められないものを言う（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 理由を 1 つにまとめない
 *
 * 「別の型に入っている」と「対象が消えている」は、**次にやることが違う**。
 * 前者は `--type` の指定を戻すか前の batch を取り消す、後者は台帳を片付ける。
 * まとめると、片方の手順でもう片方を直そうとすることになる。
 *
 * ## ここでは決めない
 *
 * 何を作り、何を飛ばし、何を使い回すかは、この先の仕事である（§D.2 の `PlanAction`）。
 * ここは**止める理由を集めて、台帳が言っていることをそのまま返す**。
 */
final class LedgerReconcilerTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    private PlanFindings $findings;

    public function test_記録が無ければ何も言わない(): void
    {
        $entries = $this->reconcile([new LedgerKey(ImportKind::Content, 'news', '101')]);

        $this->assertSame(LedgerState::Absent, $entries[0]->entry->state);
        $this->assertSame([], $this->findings->toArray());
    }

    public function test_記録があって対象も在れば何も言わない(): void
    {
        $id = $this->news();
        $this->record('content', 'news', '101', $id);

        $entries = $this->reconcile([new LedgerKey(ImportKind::Content, 'news', '101')]);

        $this->assertSame(LedgerState::Exact, $entries[0]->entry->state);
        $this->assertSame($id, $entries[0]->entry->targetId);
        $this->assertSame([], $this->findings->toArray());
    }

    public function test_別の型に入っていれば食い違いとして止める(): void
    {
        $this->record('content', 'post', '101', $this->news());

        $entries = $this->reconcile([new LedgerKey(ImportKind::Content, 'news', '101')]);

        $this->assertSame(LedgerState::Conflict, $entries[0]->entry->state);

        $found = $this->findings->toArray();

        $this->assertSame(1, $found['ledger_conflict']['total']);
        $this->assertSame(
            [['external_id' => '101', 'source_type' => 'content']],
            $found['ledger_conflict']['shown'],
        );
        $this->assertArrayNotHasKey('ledger_target_missing', $found);
        $this->assertTrue($this->findings->blocksPlan());
    }

    public function test_台帳にあるのに対象が消えていれば別の理由で止める(): void
    {
        /*
         * **「別の型に入っている」と混ぜない。** こちらは台帳を片付ける話で、
         * `--type` の指定を戻しても直らない。
         */
        $this->record('content', 'news', '101', '999999');

        $entries = $this->reconcile([new LedgerKey(ImportKind::Content, 'news', '101')]);

        $found = $this->findings->toArray();

        $this->assertSame(1, $found['ledger_target_missing']['total']);
        $this->assertArrayNotHasKey('ledger_conflict', $found);
        $this->assertTrue($this->findings->blocksPlan());

        /*
         * 台帳が言っていることは**そのまま返す**。
         * ここで conflict へ落とすと、返り値が台帳と食い違う。
         * 止めるのは findings の仕事で、`blocksPlan()` が既に真である。
         */
        $this->assertSame(LedgerState::Exact, $entries[0]->entry->state);
    }

    public function test_対象がゴミ箱にあれば人へ返す(): void
    {
        /*
         * **自動でどちらへも倒さない。**
         *
         * 取り消しの跡かもしれないし、人がゴミ箱へ入れたのかもしれない。
         * 台帳からは見分けられない（§D.3 / OG8 のとおり取り消しても投稿は残る）。
         *
         * 飛ばせば十年分がゴミ箱に入ったまま「成功」と報告され、
         * 作り直せば消したはずのものが戻る。どちらの自動判断も誤る。
         */
        $id = $this->news(trashed: true);
        $this->record('content', 'news', '101', $id);

        $this->reconcile([new LedgerKey(ImportKind::Content, 'news', '101')]);

        $found = $this->findings->toArray();

        $this->assertSame(1, $found['ledger_target_trashed']['total']);
        $this->assertArrayNotHasKey('ledger_target_missing', $found);
        $this->assertTrue($this->findings->blocksPlan());
    }

    public function test_ゴミ箱と消滅を混ぜない(): void
    {
        // 直す先が違う。ゴミ箱は人へ返す、消滅は台帳を片付ける
        $this->record('content', 'news', '101', $this->news(trashed: true));
        $this->record('content', 'news', '102', '999999');

        $this->reconcile([
            new LedgerKey(ImportKind::Content, 'news', '101'),
            new LedgerKey(ImportKind::Content, 'news', '102'),
        ]);

        $found = $this->findings->toArray();

        $this->assertSame(1, $found['ledger_target_trashed']['total']);
        $this->assertSame(1, $found['ledger_target_missing']['total']);
    }

    public function test_型が消えていても対象なしとして止める(): void
    {
        // 探しに行く先が無い。**推測で別の型を探さない**
        $this->record('content', 'gone_away', '101', '1');

        $this->reconcile([new LedgerKey(ImportKind::Content, 'gone_away', '101')]);

        $this->assertSame(1, $this->findings->toArray()['ledger_target_missing']['total']);
    }

    public function test_理由が複数あればすべて出す(): void
    {
        // 1 件目で止めると、直しては実行しを繰り返させる（§A.3）
        $this->record('content', 'post', '101', $this->news());
        $this->record('content', 'news', '102', '999999');

        $this->reconcile([
            new LedgerKey(ImportKind::Content, 'news', '101'),
            new LedgerKey(ImportKind::Content, 'news', '102'),
            new LedgerKey(ImportKind::Content, 'news', '103'),
        ]);

        $this->assertSame(
            ['ledger_conflict', 'ledger_target_missing'],
            array_keys($this->findings->toArray()),
        );
    }

    public function test_種類ごとに理由の出どころが分かる(): void
    {
        $this->record('term', 'news_category', '5', '01JZZZZZZZZZZZZZZZZZZZZZZZ');

        $this->reconcile([new LedgerKey(ImportKind::Term, 'news_category', '5')]);

        $this->assertSame(
            [['external_id' => '5', 'source_type' => 'term']],
            $this->findings->toArray()['ledger_target_missing']['shown'],
        );
    }

    public function test_渡した並びのまま返す(): void
    {
        $id = $this->news();
        $this->record('content', 'news', '102', $id);

        $entries = $this->reconcile([
            new LedgerKey(ImportKind::Content, 'news', '101'),
            new LedgerKey(ImportKind::Content, 'news', '102'),
        ]);

        $this->assertSame([LedgerState::Absent, LedgerState::Exact], array_map(static fn ($v) => $v->entry->state, $entries));
    }

    public function test_読むだけで1行も書かない(): void
    {
        $this->record('content', 'news', '101', $this->news());

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        $this->reconcile([new LedgerKey(ImportKind::Content, 'news', '101')]);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  list<LedgerKey>  $keys
     * @return list<LedgerEntry>
     */
    private function reconcile(array $keys): array
    {
        $this->findings = new PlanFindings;

        return app(LedgerReconciler::class)->reconcile($keys, $this->findings);
    }

    private function record(string $kind, string $targetType, string $externalId, string $targetId): void
    {
        $batch = strtoupper(bin2hex(random_bytes(13)));

        DB::table('import_batches')->insert([
            'id' => $batch,
            'source' => 'export.xml',
            'file_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'options' => '{}',
            'status' => 'completed',
            'counts' => '{}',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        DB::table('import_records')->insert([
            'batch_id' => $batch,
            'external_id' => $externalId,
            'kind' => $kind,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by_import' => true,
            'created_at' => now(),
        ]);
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
