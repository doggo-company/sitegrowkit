<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\CreateContent;
use AgentCms\Core\Application\Content\UpdateContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\TaxonomyPayload;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 3B — assignment の排他制御（SPEC §G.6）。
 *
 * ## driver ごとの保証差（**縮退を隠さない**）
 *
 * | driver | 保証 |
 * | --- | --- |
 * | **MySQL** | Content Entry 行ロックが**実際に待機・直列化**する。加えて事後検証 |
 * | **SQLite** | `lockForUpdate()` は **no-op**。transaction + unique 制約 + **事後検証だけ**が防御線 |
 *
 * MySQL では **2 本の接続**を使い、実際のロック待ちと並行 commit を発生させる。
 * トランザクションを跨いだ検証が必要なため、`RefreshDatabase`（テストを 1 つの
 * トランザクションで包む）は使わず、`migrate:fresh` で毎回作り直す。
 *
 * **実測で分かったこと（MySQL）**: `taxonomy_term_assignments` を
 * `where(content_type, content_id) … for update` で読むと、対象行が 0 件でも
 * REPEATABLE READ の **gap lock** が `idx_assignment_entry` の隙間に掛かる。
 * その結果、同じ Content Entry への並行 INSERT は Content Entry 行ロックより
 * 手前で既に直列化される。**データが壊れる方向ではない**が、
 * 待ち時間切れが `conflict` として返り得ることを contract として記録する。
 */
final class ContentTaxonomyConcurrencyTest extends TestCase
{
    use HasOperator;

    private const PROBE = 'agentcms_probe';

    private string $schemaPath = '';

    private OperationContext $admin;

    /** 競合書き込みが行ロック / gap ロックに阻まれたか */
    private bool $competingBlocked = false;

    /** 競合書き込みが**ロック以外の理由**で失敗した場合の中身（握りつぶさない） */
    private ?QueryException $competingError = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSchemasWithTaxonomies();

        Artisan::call('migrate:fresh');

        // 操作者は **migrate:fresh のあと**に作る（このクラスはテーブルを作り直す）
        $this->admin = OperationContext::admin($this->operatorId(), 'admin');

        app(TaxonomySynchronizer::class)->sync();
        $this->makeTaxonomy('news_single');
        $this->makeTaxonomy('news_multi');

        if ($this->isMysql()) {
            config([
                'database.connections.'.self::PROBE => config('database.connections.'.DB::getDefaultConnection()),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->isMysql()) {
            DB::purge(self::PROBE);
        }

        // このクラスは transaction で包まない（実際に commit する）ので、
        // 後続の RefreshDatabase テストへ行を残さないよう自分で掃除する
        Schema::disableForeignKeyConstraints();

        foreach (['taxonomy_term_assignments', 'taxonomy_terms', 'taxonomies', 'news', 'audit_logs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();

        if ($this->schemaPath !== '' && is_dir($this->schemaPath)) {
            foreach (glob($this->schemaPath.'/overrides/*.json') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->schemaPath.'/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->schemaPath.'/overrides');
            @rmdir($this->schemaPath);
        }

        parent::tearDown();
    }

    /**
     * ① Content Entry 行ロックが実際に待機する。
     *
     * 別接続が Content Entry 行を掴んでいる間、assignment 更新は**進めない**。
     * 待ち時間切れは **生の QueryException ではなく conflict** として返す。
     */
    public function test_content_entry行lockが待機し_conflictとして返る(): void
    {
        $term = $this->makeTerm('news_single', 'a');
        $id = $this->createNews();

        if (! $this->isMysql()) {
            // SQLite は lockForUpdate を無視する。`for update` が 1 つも出ないことを事実として固定する
            $locks = [];
            DB::listen(static function ($q) use (&$locks): void {
                if (str_contains(strtolower($q->sql), 'for update')) {
                    $locks[] = $q->sql;
                }
            });

            $this->assertTrue($this->assign($id, [$term->id])->isSuccess());
            $this->assertSame([], $locks, 'SQLite で行ロックが発行された');

            return;
        }

        $probe = DB::connection(self::PROBE);
        $probe->beginTransaction();
        $probe->table('news')->where('id', $id)->lockForUpdate()->get();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $start = microtime(true);
        $result = $this->assign($id, [$term->id]);
        $elapsed = microtime(true) - $start;

        $probe->rollBack();

        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertGreaterThanOrEqual(0.5, $elapsed, '待機せずに即座に失敗した');
        $this->assertSame([], $this->assignedIds($id), 'ロック中に書き込んだ');

        // 生の SQL / SQLSTATE / 接続情報を利用者へ返さない
        foreach (['SQLSTATE', 'select', 'update ', 'insert', 'innodb', 'PDO'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $result->message);
        }

        // ロックが解けたあとは通常どおり 1 件になる（直列化）
        $this->assertTrue($this->assign($id, [$term->id])->isSuccess());
        $this->assertCount(1, $this->assignedIds($id));
    }

    /**
     * ② 同じ Content Entry への並行更新は **Content Entry 行ロックで直列化**され、
     *    single taxonomy が最終的に 2 件にならない。
     *
     * 競合相手も **Application 経路と同じ mutex（Content Entry 行）を握る**書き手として
     * 模倣する。これが確定 architecture の前提であり、
     * mutex を迂回する直接 SQL までは保証しない（§G.6 の注記）。
     *
     * 1. 別接続が Entry の行ロックを保持したまま assignment を 1 件入れる
     * 2. その間、こちらの更新は**進めない**（待って conflict）
     * 3. ロックが解けたら、こちらは相手の結果を現在集合として読み、全置換する
     * 4. 最終的に **1 件**（2 件にならない）
     */
    public function test_single_taxonomyが同時更新で2件にならない(): void
    {
        $mine = $this->makeTerm('news_single', 'mine');
        $theirs = $this->makeTerm('news_single', 'theirs');
        $id = $this->createNews();

        if (! $this->isMysql()) {
            // SQLite は接続を分けられないため、直列実行へ縮退した形で不変条件を確かめる
            $this->competingWrite($id, $theirs->id);

            $result = $this->assign($id, [$mine->id]);

            $this->assertCompetingWriteWasClean();
            $this->assertTrue($result->isSuccess(), (string) $result->message);
            $this->assertSame([$mine->id], $this->assignedIds($id));

            return;
        }

        $probe = DB::connection(self::PROBE);
        $probe->beginTransaction();
        $probe->table('news')->where('id', $id)->lockForUpdate()->get();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        // ① 相手が mutex を握っている間は進めない
        $blocked = $this->assign($id, [$mine->id]);

        $this->assertSame(OperationStatus::Conflict, $blocked->status, 'mutex を無視して進んだ');
        $this->assertSame([], $this->assignedIds($id));

        // ② 相手が assignment を入れて commit（= mutex を解放）
        $probe->table('taxonomy_term_assignments')->insert([
            'term_id' => $theirs->id, 'content_type' => 'news',
            'content_id' => $id, 'sort_order' => 0, 'created_at' => now(),
        ]);
        $probe->commit();

        // ③ 直列化されたので、こちらは相手の結果を現在集合として読み全置換する
        $result = $this->assign($id, [$mine->id]);

        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame([$mine->id], $this->assignedIds($id), 'single taxonomy が 2 件になった');
        $this->assertSame(1, $result->meta['taxonomies']['removed'], '相手の 1 件を置換していない');
    }

    /**
     * ③ **別の Content Entry** は互いにブロックしない（STAGE 3B CORRECTION / F-3B-3）。
     *
     * 以前は assignment を `where(content_type, content_id) … for update` で読んでいたため、
     * 0 件でも `idx_assignment_entry` に **gap lock** が掛かり、
     * index 上の隙間を共有する**無関係な Entry** の INSERT まで待たせていた。
     *
     * ここでは Entry A の更新の最中に Entry B へ assignment を保存し、
     * **lock timeout を待たずに完了できる**ことを実測する。
     */
    public function test_別の_content_entryをブロックしない(): void
    {
        $termA = $this->makeTerm('news_single', 'for-a');
        $termB = $this->makeTerm('news_single', 'for-b');
        $entryA = $this->createNews();
        $entryB = $this->createNews();

        $lockedTables = null;
        $elapsed = 0.0;

        /*
         * **握ったあとに割り込む**（B-4）。
         *
         * `beforeQuery` は INSERT の**手前**で走るので、その時点では主 transaction は
         * まだ assignment のロックを握っていない。**「誰を巻き込むか」を測るには遅すぎる**
         * ——正確には、**早すぎる**。結論（別 Entry はブロックされない）は変わらないが、
         * 測っている位置が主張と合っていなかった。
         */
        $this->afterQuery(
            static fn (string $sql): bool => str_starts_with($sql, 'insert into "taxonomy_term_assignments"'),
            function () use ($entryB, $termB, &$lockedTables, &$elapsed): void {
                // この時点で主 transaction が握っているロックの実測（MySQL 8 のみ）
                $lockedTables = $this->lockedTables();

                $start = microtime(true);
                $this->competingWrite($entryB, $termB->id);
                $elapsed = microtime(true) - $start;
            },
        );

        $result = $this->assign($entryA, [$termA->id]);

        $this->assertCompetingWriteWasClean();
        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertFalse($this->competingBlocked, 'Entry B が Entry A の更新にブロックされた');
        $this->assertSame([$termA->id], $this->assignedIds($entryA));
        $this->assertSame([$termB->id], $this->assignedIds($entryB));

        if (! $this->isMysql()) {
            return;
        }

        $this->assertLessThan(1.0, $elapsed, 'lock timeout を待った（gap lock が残っている）');

        if ($lockedTables === null) {
            // performance_schema を読めない環境。**この主張は測れていない**
            return;
        }

        // MySQL 8: 主 transaction は Content Entry 行と term 行だけを握る
        $this->assertContains('news', $lockedTables, 'ロックを 1 つも握っていない（測る位置が早すぎる）');
        $this->assertNotContains(
            'taxonomy_term_assignments', $lockedTables, 'assignment に範囲ロックが残っている',
        );
    }

    /**
     * ④ 同一 term を共有する別 Entry は **term 行ロック**で短時間直列化し得る。
     *
     * これは archive / rename 競合を防ぐための意図した挙動であり、
     * **assignment の gap lock が原因ではない**。
     * 区別の根拠は「別 term なら待たない（③）」ことと、
     * 主 transaction が assignment 行ロックを持たないことの実測である。
     */
    public function test_同一termを共有する別_entryはterm行lockで直列化する(): void
    {
        $shared = $this->makeTerm('news_single', 'shared');
        $entryA = $this->createNews();
        $entryB = $this->createNews();

        $lockedTables = null;

        $this->beforeQuery(
            static fn (string $sql): bool => str_starts_with($sql, 'insert into "taxonomy_term_assignments"'),
            function () use ($entryB, $shared, &$lockedTables): void {
                $lockedTables = $this->lockedTables();
                $this->competingWrite($entryB, $shared->id);
            },
        );

        $result = $this->assign($entryA, [$shared->id]);

        $this->assertCompetingWriteWasClean();
        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertSame([$shared->id], $this->assignedIds($entryA));

        if ($lockedTables !== null) {
            // 待つ理由があるとすれば term 行。assignment 行ではない
            $this->assertContains('taxonomy_terms', $lockedTables);
            $this->assertNotContains('taxonomy_term_assignments', $lockedTables);
        }

        if ($this->competingBlocked) {
            // 阻まれたなら term 行ロック由来。Entry B には書かれていない
            $this->assertSame([], $this->assignedIds($entryB));

            return;
        }

        $this->assertSame([$shared->id], $this->assignedIds($entryB));
    }

    /**
     * ⑥ **まとめて外す更新**が、無関係な Content Entry をブロックしない。
     *
     * ③ は「足す」筋道しか通っていなかった。**外す筋道には DELETE が居る。**
     *
     * 権限つき MySQL 8.4 での実測:
     *
     * | 外す項目 | 選ばれる index | 隙間ロック | 無関係な entry |
     * | --- | --- | --- | --- |
     * | 1〜2 件 | `uq_assignment_entry` | 無し | 通る |
     * | **3 件以上** | **`idx_assignment_entry`** | **有り** | **1205 で待たされる** |
     *
     * optimiser がどちらを選ぶかは**行数と統計次第**なので、
     * 「いまは uq を選ぶから安全」では守りにならない。**件数に依らず**
     * 隙間を作らないことを、この試験で固定する。
     */
    public function test_まとめて外す更新が別の_content_entryをブロックしない(): void
    {
        $terms = array_map(fn (int $i): TaxonomyTerm => $this->makeTerm('news_multi', "m{$i}"), range(1, 4));
        $forB = $this->makeTerm('news_multi', 'for-b');
        $entryA = $this->createNews();
        $entryB = $this->createNews();

        $ids = array_map(static fn (TaxonomyTerm $t): string => (string) $t->id, $terms);
        $this->assertTrue($this->assign($entryA, $ids, 'news_multi')->isSuccess(), '前提: 4 件付く');

        $elapsed = 0.0;

        // **走ったあと**に割り込む。DELETE がロックを握っている最中でなければ意味がない
        $this->afterQuery(
            static fn (string $sql): bool => str_starts_with($sql, 'delete from "taxonomy_term_assignments"'),
            function () use ($entryB, $forB, &$elapsed): void {
                $start = microtime(true);
                $this->competingWrite($entryB, (string) $forB->id);
                $elapsed = microtime(true) - $start;
            },
        );

        // 4 件のうち 3 件を外す（optimiser が index を乗り換える件数）
        $result = $this->assign($entryA, [$ids[0]], 'news_multi');

        $this->assertCompetingWriteWasClean();
        $this->assertTrue($result->isSuccess(), (string) $result->message);
        $this->assertFalse(
            $this->competingBlocked,
            'まとめて外す DELETE が、無関係な Entry B を巻き込んだ（隙間ロック）',
        );
        $this->assertSame([$ids[0]], $this->assignedIds($entryA));
        $this->assertSame([(string) $forB->id], $this->assignedIds($entryB));

        if ($this->isMysql()) {
            $this->assertLessThan(1.0, $elapsed, 'lock timeout を待った（隙間ロックが残っている）');
        }
    }

    /**
     * ⑦ assignment を消す文の**形**を固定する（両面）。
     *
     * 振る舞いの試験（⑥）は「いまの optimiser が乗り換える件数」に依存する。
     * 統計が変われば、**欠陥が戻っても⑥が緑のまま**ということが起こり得る。
     * だから形そのものを見る:
     *
     * - **在ること**: `where id in (…)` で消している
     * - **無いこと**: `content_type` / `content_id` の条件が**残っていない**
     *
     * 後者が要るのは、**PK で絞りつつ範囲条件も残す形**だと optimiser が
     * 範囲側を選び得るからである。「id で消している」だけでは守りにならない。
     */
    public function test_assignmentを消す文はprimary_keyだけで絞る(): void
    {
        $terms = array_map(fn (int $i): TaxonomyTerm => $this->makeTerm('news_multi', "s{$i}"), range(1, 4));
        $ids = array_map(static fn (TaxonomyTerm $t): string => (string) $t->id, $terms);
        $entry = $this->createNews();

        $this->assertTrue($this->assign($entry, $ids, 'news_multi')->isSuccess(), '前提: 4 件付く');

        $deletes = [];
        DB::listen(function ($query) use (&$deletes): void {
            $sql = strtolower(str_replace('`', '"', $query->sql));

            if (str_starts_with($sql, 'delete from "taxonomy_term_assignments"')) {
                $deletes[] = $sql;
            }
        });

        $this->assertTrue($this->assign($entry, [$ids[0]], 'news_multi')->isSuccess());

        $this->assertNotSame([], $deletes, '外す文が 1 つも出ていない（試験が空振りしている）');

        foreach ($deletes as $sql) {
            $this->assertStringContainsString('"id" in (', $sql, 'primary key で絞っていない');
            $this->assertStringNotContainsString('"content_type"', $sql, '範囲条件が残っている');
            $this->assertStringNotContainsString('"content_id"', $sql, '範囲条件が残っている');
        }
    }

    /**
     * ③ 検証中に term が archive されたら、新規 assignment は成立しない。
     *
     * term 行を lock する**直前**に別接続が archive して commit する。
     * lock 後の fresh state を読むので、archived を新規追加として通さない。
     */
    public function test_同時archiveされたtermへ新規assignmentしない(): void
    {
        $term = $this->makeTerm('news_single', 'soon-archived');
        $id = $this->createNews();

        if ($this->isMysql()) {
            // term 行を lock する直前に、別接続が archive して commit する
            $this->beforeQuery(
                static fn (string $sql): bool => str_contains($sql, 'from "taxonomy_terms"')
                    && str_contains($sql, 'for update'),
                function () use ($term): void {
                    $this->competingArchive($term->id);
                },
            );

            $result = $this->assign($id, [$term->id]);
        } else {
            // SQLite は `for update` を発行しないため、この割り込み点が存在しない。
            // 縮退保証として「archive 済み term は新規追加できない」ことを直接確かめる
            $this->competingArchive($term->id);
            $result = $this->assign($id, [$term->id]);
        }

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame([], $this->assignedIds($id));
        $this->assertNotNull(TaxonomyTerm::query()->findOrFail($term->id)->archived_at);
    }

    /**
     * ④ SQLite の縮退保証 — 事後検証が最後の防御線として働く。
     *
     * 事後検証の読み取り直前に、同じ Content Entry へ余分な行が現れたケース。
     * どの driver でも **conflict で巻き戻す**ことを固定する。
     */
    public function test_事後検証が最終集合の違反を検出する(): void
    {
        $mine = $this->makeTerm('news_single', 'mine');
        $extra = $this->makeTerm('news_single', 'extra');
        $id = $this->createNews();

        $injected = false;

        $this->beforeQuery(
            static fn (string $sql): bool => str_starts_with($sql, 'select * from "taxonomy_term_assignments"'),
            function () use ($id, $extra, &$injected): void {
                if ($injected) {
                    return;
                }

                $injected = true;
                DB::table('taxonomy_term_assignments')->insert([
                    'term_id' => $extra->id, 'content_type' => 'news',
                    'content_id' => $id, 'sort_order' => 0, 'created_at' => now(),
                ]);
            },
            skip: 1,
        );

        $result = $this->assign($id, [$mine->id]);

        $this->assertTrue($injected, '事後検証の読み取りが発行されていない');
        $this->assertSame(OperationStatus::Conflict, $result->status);
        $this->assertNotContains($mine->id, $this->assignedIds($id), '違反したまま commit した');
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * 別接続（MySQL）または同接続（SQLite）からの競合書き込み。
     *
     * MySQL では主 transaction が握る行ロック / gap ロックに阻まれることがある。
     * それも**直列化が働いた証拠**なので、失敗を握りつぶさず記録する。
     */
    /**
     * 競合書き込みが**ロック以外の理由**で失敗していないこと。
     *
     * これを見ないと、無関係な失敗（権限・FK 違反など）が
     * 「阻まれなかった」＝**直列化を測れた**ように見えてしまう。
     */
    private function assertCompetingWriteWasClean(): void
    {
        if ($this->competingError !== null) {
            $this->fail(
                '競合書き込みがロック以外の理由で失敗した（直列化の証拠として数えてはいけない）: '
                .$this->competingError->getMessage(),
            );
        }
    }

    private function competingWrite(int $contentId, string $termId): void
    {
        $writer = $this->writer();

        try {
            if ($this->isMysql()) {
                // 阻まれた場合にテストが 50 秒待たないようにする
                $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
            }

            $writer->table('taxonomy_term_assignments')->insert([
                'term_id' => $termId, 'content_type' => 'news',
                'content_id' => $contentId, 'sort_order' => 0, 'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // **ロックに阻まれた場合だけ**を「直列化が働いた」と数える。
            // どんな失敗でも true にすると、無関係な誤りが「直列化の証拠」に化ける。
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                $this->competingBlocked = true;

                return;
            }

            // ここで投げ返すと**測っている transaction の中**なので conflict に化ける。
            // 記録だけして、tearDown で理由ごと表に出す。
            $this->competingError = $e;
        }
    }

    private function competingArchive(string $termId): void
    {
        $this->writer()->table('taxonomy_terms')
            ->where('id', $termId)
            ->update(['archived_at' => now()]);
    }

    private function writer(): ConnectionInterface
    {
        return $this->isMysql() ? DB::connection(self::PROBE) : DB::connection();
    }

    /**
     * 主 transaction が現在握っている行ロックのテーブル一覧（MySQL 8 のみ）。
     *
     * `performance_schema.data_locks` は 8.0 以降。5.7 では空 list を返し、
     * 呼び出し側は「実測できなかった」として扱う（推測で断定しない）。
     *
     * **読めないときも同じく「実測できなかった」である。**
     * `data_locks` の SELECT には権限が要る。権限の無い DB 利用者だと 1142 になり、
     * この呼び出しは **主 transaction の途中（beforeExecuting の中）** で起きるため、
     * 例外がそのまま `UpdateContent` まで上がって **conflict に化けていた**。
     * 「ロックが強すぎる」ように見えていたが、**測ろうとして失敗しただけ**である。
     * 計測の道具が、測っている対象を壊してはならない。
     *
     * @return list<string>
     */
    private function lockedTables(): ?array
    {
        if (! $this->isMysql() || version_compare((string) DB::selectOne('select version() v')->v, '8.0', '<')) {
            return null;
        }

        try {
            $rows = DB::select(
                "select distinct object_name as name from performance_schema.data_locks
                 where lock_type = 'RECORD' and object_schema = database()",
            );
        } catch (QueryException) {
            /*
             * **「測れなかった」を「ロックが無かった」に丸めない**（B-4）。
             *
             * 権限が無くて読めないときに空配列を返すと、呼ぶ側の
             * 「空なら検査しない」と混ざり、**空振りが緑に見える**。
             */
            return null;
        }

        return array_values(array_map(static fn (object $row): string => (string) $row->name, $rows));
    }

    /** @param  list<string>  $termIds */
    private function assign(int $contentId, array $termIds, string $key = 'news_single'): Result
    {
        return app(UpdateContent::class)->execute(
            'news', $contentId, [], $this->admin, partial: true,
            taxonomies: TaxonomyPayload::of([$key => $termIds]),
        );
    }

    /** @return list<string> */
    private function assignedIds(int $contentId): array
    {
        return TaxonomyTermAssignment::query()
            ->where('content_type', 'news')
            ->where('content_id', $contentId)
            ->orderBy('id')
            ->pluck('term_id')
            ->all();
    }

    private function createNews(): int
    {
        $result = app(CreateContent::class)->execute('news', [
            'title' => '並行更新テスト', 'body' => '<p>本文</p>', 'status' => 'draft',
        ], $this->admin);

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return (int) $result->data['item']['id'];
    }

    private function makeTaxonomy(string $key): void
    {
        $taxonomy = new Taxonomy;
        $taxonomy->forceFill([
            'id' => TaxonomyId::generate(), 'key' => $key, 'label' => $key,
            'hierarchical' => true, 'managed_by' => TaxonomyManagedBy::Code->value,
        ])->save();
    }

    private function makeTerm(string $taxonomyKey, string $slug): TaxonomyTerm
    {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => (string) Taxonomy::query()->where('key', $taxonomyKey)->firstOrFail()->id,
            'name' => $slug, 'slug' => $slug, 'sort_order' => 0,
        ])->save();

        return $term;
    }

    /**
     * その文が**走ったあと**に割り込む。
     *
     * `beforeExecuting` では**まだロックを握っていない**ので、
     * 「握っているロックが誰を巻き込むか」はこちらでしか測れない。
     */
    private function afterQuery(callable $matches, callable $action): void
    {
        $done = false;

        DB::listen(function ($query) use ($matches, $action, &$done): void {
            if ($done || ! $matches(strtolower(str_replace('`', '"', $query->sql)))) {
                return;
            }

            $done = true;
            $action();
        });
    }

    private function beforeQuery(callable $matches, callable $action, int $skip = 0): void
    {
        $seen = 0;
        $done = false;

        DB::beforeExecuting(function (string $query) use ($matches, $action, $skip, &$seen, &$done): void {
            if ($done || ! $matches(strtolower(str_replace('`', '"', $query)))) {
                return;
            }

            if ($seen++ < $skip) {
                return;
            }

            $done = true;
            $action();
        });
    }

    /** 同梱 schema を複製し、`news_single` / `news_multi` 宣言を足した一時 schema へ差し替える */
    private function useSchemasWithTaxonomies(): void
    {
        $source = base_path('content/schemas');
        $this->schemaPath = sys_get_temp_dir().'/agentcms-taxonomy-concurrency-'.uniqid();

        mkdir($this->schemaPath.'/overrides', 0777, true);

        foreach (glob($source.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

            if (($data['id'] ?? '') === 'news') {
                $data['taxonomies'] = [
                    ['key' => 'news_single', 'multiple' => false, 'required' => false],
                    ['key' => 'news_multi', 'multiple' => true, 'required' => false],
                ];
            }

            file_put_contents(
                $this->schemaPath.'/'.basename($file),
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }

        foreach (glob($source.'/overrides/*.json') ?: [] as $file) {
            copy($file, $this->schemaPath.'/overrides/'.basename($file));
        }

        config(['agentcms.schema_path' => $this->schemaPath]);
        app()->forgetInstance(SchemaRegistry::class);
    }
}
