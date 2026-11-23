<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerLookup;
use AgentCms\Core\Wxr\Ledger\LedgerState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 台帳に既に何があるかを、まとめて 1 度だけ読む（Phase 9.14 / STAGE 27C・SPEC §D.2）。
 *
 * ## 1 件ずつ引かない
 *
 * 十年分は数万件になる。1 件ずつ引くと、`plan` を出すだけで数万回の問い合わせが飛ぶ。
 * まとめて引き、**種類ごとに 1 回**で済ませる。
 *
 * ## ここでは決めない
 *
 * 返すのは「台帳から見て今どうなっているか」だけである。
 * それを取り込みの理由へ落とすのも、対象の行が本当に在るかを確かめるのも、
 * この先の仕事である。**読むことと決めることを混ぜない。**
 *
 * ## 本文を持てない形にする
 *
 * 鍵に入るのは種類・取り込み先・外部 id だけで、いずれも識別子の形しか通さない。
 * 本文も名前も綴りもメールアドレスも、**入れようとした時点で断る**。
 */
final class LedgerLookupTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    private const TABLE = 'import_records';

    // ── 台帳から見た状態 ────────────────────────────────────

    public function test_記録が無ければ何も無いと読む(): void
    {
        $entry = $this->inspectOne(ImportKind::Content, 'post', '101');

        $this->assertSame(LedgerState::Absent, $entry->state);
        $this->assertNull($entry->targetId);
    }

    public function test_同じ取り込み先に1行だけあれば確かだと読む(): void
    {
        $batch = $this->batch();
        $this->record($batch, 'content', 'post', '101', targetId: 'T1', owned: true);

        $entry = $this->inspectOne(ImportKind::Content, 'post', '101');

        $this->assertSame(LedgerState::Exact, $entry->state);
        $this->assertSame('T1', $entry->targetId);
        $this->assertSame($batch, $entry->batchId);
        $this->assertTrue($entry->createdByImport);
    }

    public function test_所有していない記録もそのまま読む(): void
    {
        // 使い回した対象は `target_id` を持つが所有はしない
        $this->record($this->batch(), 'content', 'post', '101', targetId: 'T1', owned: false);

        $this->assertFalse($this->inspectOne(ImportKind::Content, 'post', '101')->createdByImport);
    }

    public function test_別の取り込み先に入っていれば食い違いと読む(): void
    {
        /*
         * `--type=post:news` で一度入れた後に `--type=post:blog` で入れ直すと、
         * 一意制約は `target_type` を含むので**通ってしまう**。
         * 同じ記事が 2 つの型に並ぶ前に気づけるようにする。
         */
        $this->record($this->batch(), 'content', 'news', '101');

        $entry = $this->inspectOne(ImportKind::Content, 'blog', '101');

        $this->assertSame(LedgerState::Conflict, $entry->state);
        // 決めるのはこの先。ここでは**値を渡さない**
        $this->assertNull($entry->targetId);
    }

    public function test_同じ取り込み先にも別の取り込み先にもあれば食い違いと読む(): void
    {
        $batch = $this->batch();

        $this->record($batch, 'content', 'post', '101');
        $this->record($batch, 'content', 'news', '101');

        $this->assertSame(
            LedgerState::Conflict,
            $this->inspectOne(ImportKind::Content, 'post', '101')->state,
        );
    }

    public function test_同じ鍵の行が2つあれば食い違いと読む(): void
    {
        /*
         * 一意制約があるので通常は起きない。**制約の無い DB へ復元された台帳**や、
         * 手で足された行はあり得る。そこで「1 行目を採る」と、
         * どちらが採られたか分からないまま十年分がぶら下がる。
         */
        $batch = $this->batch();

        /*
         * 一意制約があるので、**この形はここでしか作れない**。
         * 制約を外した台帳（別 DB からの復元・手で足した行）を再現する。
         */
        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropUnique('uq_import_external');
        });

        DB::table(self::TABLE)->insert([
            ['batch_id' => $batch, 'external_id' => '101', 'kind' => 'content',
                'target_type' => 'post', 'target_id' => 'T1', 'created_by_import' => true, 'created_at' => now()],
            ['batch_id' => $batch, 'external_id' => '101', 'kind' => 'content',
                'target_type' => 'post', 'target_id' => 'T2', 'created_by_import' => true, 'created_at' => now()],
        ]);

        $this->assertSame(
            LedgerState::Conflict,
            $this->inspectOne(ImportKind::Content, 'post', '101')->state,
        );
    }

    public function test_種類が違えば食い違いにしない(): void
    {
        /*
         * WordPress の id は種類ごとに振られる。記事の 101 と分類項目の 101 は
         * **別物**なので、跨いで衝突させない。
         */
        $this->record($this->batch(), 'term', 'news_category', '101');

        $this->assertSame(
            LedgerState::Absent,
            $this->inspectOne(ImportKind::Content, 'post', '101')->state,
        );
    }

    // ── まとめて引く ────────────────────────────────────────

    public function test_渡した並びのまま返す(): void
    {
        /*
         * 並びが変わると、呼ぶ側は**どの記事の結果かを id で引き直す**ことになる。
         * そのために id を配列で抱えると、まとめて引いた意味が無くなる。
         */
        $this->record($this->batch(), 'content', 'post', '102', targetId: 'T2');

        $entries = app(LedgerLookup::class)->inspect([
            new LedgerKey(ImportKind::Content, 'post', '101'),
            new LedgerKey(ImportKind::Content, 'post', '102'),
            new LedgerKey(ImportKind::Content, 'post', '103'),
        ]);

        $this->assertSame(
            [LedgerState::Absent, LedgerState::Exact, LedgerState::Absent],
            array_column($entries, 'state'),
        );
        $this->assertSame('T2', $entries[1]->targetId);
    }

    public function test_千件でも種類の数だけしか問い合わせない(): void
    {
        /*
         * 1 件ずつ引くと、`plan` を出すだけで千回の問い合わせが飛ぶ。
         * 十年分では数万回になり、**読むだけの操作が本番を遅くする**。
         *
         * **取り込み先を散らして数える。** 全部同じ取り込み先で数えると、
         * 取り込み先ごとに引く実装でも同じ数になり、
         * 「取り込み先では絞らない」を数の側から固定できない。
         */
        /*
         * 種類と取り込み先は**同じ剰余で回さない**。同じにすると片方から
         * もう片方が決まってしまい、取り込み先ごとに引く実装でも数が変わらない。
         * 3 と 4 で回して、12 通りの組が出るようにする。
         */
        $targetTypes = ['post', 'news', 'blog'];
        $kinds = ImportKind::cases();

        $keys = [];

        for ($i = 1; $i <= LedgerLookup::MAX_WINDOW; $i++) {
            $keys[] = new LedgerKey(
                $kinds[$i % count($kinds)],
                $targetTypes[$i % count($targetTypes)],
                (string) $i,
            );
        }

        $seen = [];
        DB::listen(static function ($query) use (&$seen): void {
            $seen[] = (string) $query->sql;
        });

        $entries = app(LedgerLookup::class)->inspect($keys);

        $this->assertCount(LedgerLookup::MAX_WINDOW, $entries);
        // **等しいこと**を求める。以下では取り込み先ごとに引く実装も通ってしまう
        $this->assertSame(count($kinds), count($seen), implode("\n", $seen));
    }

    public function test_一度に渡せる数を超えたら断る(): void
    {
        /*
         * 上限が無いと、十年分の id を 1 つの `IN` へ並べることになる。
         * どこかで DB 側が黙って切るか、落ちる。**呼ぶ側で窓を切らせる。**
         */
        $keys = [];

        for ($i = 1; $i <= LedgerLookup::MAX_WINDOW + 1; $i++) {
            $keys[] = new LedgerKey(ImportKind::Content, 'post', (string) $i);
        }

        $this->expectException(InvalidArgumentException::class);

        app(LedgerLookup::class)->inspect($keys);
    }

    public function test_同じ鍵を二度渡したら断る(): void
    {
        /*
         * 同じ鍵が 2 つあると、同じ対象へ 2 回書く plan ができる。
         * WXR の中身の問題ではなく組み立ての誤りなので、数えずに断る。
         */
        $this->expectException(InvalidArgumentException::class);

        app(LedgerLookup::class)->inspect([
            new LedgerKey(ImportKind::Content, 'post', '101'),
            new LedgerKey(ImportKind::Content, 'post', '101'),
        ]);
    }

    // ── 鍵に本文を入れられない ──────────────────────────────

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function unusableKeys(): array
    {
        return [
            '空の取り込み先' => ['', '101'],
            '空の外部 id' => ['post', ''],
            '桁を超える取り込み先' => [str_repeat('a', 65), '101'],
            '桁を超える外部 id' => ['post', str_repeat('1', 65)],
            '改行を含む外部 id' => ['post', "101\n102"],
            '空白を含む取り込み先' => ['post type', '101'],
            'メールアドレス' => ['post', 'yamada@example.com'],
            '日本語の名前' => ['post', '山田太郎'],
            '本文らしきもの' => ['post', 'こんにちは、今日は良い天気です。'],
        ];
    }

    #[DataProvider('unusableKeys')]
    public function test_識別子の形でないものは鍵にできない(string $targetType, string $externalId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LedgerKey(ImportKind::Content, $targetType, $externalId);
    }

    public function test_返す値にも識別子の形しか入らない(): void
    {
        /*
         * 台帳の行は他から書かれ得る。読んだものをそのまま外へ渡すと、
         * **台帳が本文を運ぶ経路**になる。返すところでも形を確かめる。
         */
        DB::table(self::TABLE)->insert([
            'batch_id' => $this->batch(),
            'external_id' => '101',
            'kind' => 'content',
            'target_type' => 'post',
            'target_id' => "壊れた 値\n",
            'created_by_import' => true,
            'created_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->inspectOne(ImportKind::Content, 'post', '101');
    }

    // ── 1 行も書かない ──────────────────────────────────────

    public function test_読むだけで1行も書かない(): void
    {
        $this->record($this->batch(), 'content', 'post', '101');

        $before = $this->snapshot();
        $writes = $this->watchWrites();

        app(LedgerLookup::class)->inspect([
            new LedgerKey(ImportKind::Content, 'post', '101'),
            new LedgerKey(ImportKind::Term, 'news_category', '5'),
        ]);

        $this->assertSame([], $writes(), '書き込みの問い合わせが出ています');
        $this->assertSame($before, $this->snapshot(), '表の中身が変わっています');
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function inspectOne(ImportKind $kind, string $targetType, string $externalId): object
    {
        return app(LedgerLookup::class)->inspect([new LedgerKey($kind, $targetType, $externalId)])[0];
    }

    private function batch(): string
    {
        $id = strtoupper(bin2hex(random_bytes(13)));

        DB::table('import_batches')->insert([
            'id' => $id,
            'source' => 'export.xml',
            'file_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'options' => '{}',
            'status' => 'completed',
            'counts' => '{}',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return $id;
    }

    private function record(
        string $batch,
        string $kind,
        string $targetType,
        string $externalId,
        string $targetId = 'T1',
        bool $owned = true,
    ): void {
        DB::table(self::TABLE)->insert([
            'batch_id' => $batch,
            'external_id' => $externalId,
            'kind' => $kind,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_by_import' => $owned,
            'created_at' => now(),
        ]);
    }
}
