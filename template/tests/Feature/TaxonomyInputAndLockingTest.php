<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Application\Taxonomy\ReorderTerms;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 2B CORRECTION の受け入れ。
 *
 * 1. Application Service 境界の**厳密な入力検証**（A / B / C）
 * 2. term mutation の**検証・lock・保存を同一 transaction へ統合**（D〜G）
 */
final class TaxonomyInputAndLockingTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private OperationContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = OperationContext::admin($this->operatorId(), 'admin');
        app(TaxonomySynchronizer::class)->sync();
    }

    // ─────────────────────────────────────────────────────────────
    // A. CreateTerm の入力
    // ─────────────────────────────────────────────────────────────

    /** A-1 */
    public function test_a1_slug未指定なら自動生成する(): void
    {
        $result = $this->create(['taxonomyKey' => 'news_category', 'name' => 'お知らせ']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['term']['generatedSlug']);
    }

    /** A-2 / A-3 / A-4: 明示的な空 slug を「未指定」とみなさない */
    public function test_a2_不正なslugを拒否しtermを作らない(): void
    {
        foreach ([['slug' => ''], ['slug' => null], ['slug' => 123], ['slug' => ['a']],
            ['slug' => true], ['slug' => '   ']] as $patch) {
            $result = $this->create(array_merge(
                ['taxonomyKey' => 'news_category', 'name' => 'x'], $patch,
            ));

            $this->assertSame(
                OperationStatus::ValidationError,
                $result->status,
                'slug='.json_encode($patch['slug']).' を受理した',
            );
            $this->assertArrayHasKey('slug', $result->errors);
        }

        $this->assertSame(0, TaxonomyTerm::query()->count());
    }

    /** A-5 */
    public function test_a5_parent_id_nullはroot作成(): void
    {
        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'root', 'parentId' => null,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($result->data['term']['parentId']);
    }

    /** A-6 / A-7: 型不正を null（root）へ変換しない */
    public function test_a6_不正なparent_idはrootを作らずvalidation_error(): void
    {
        foreach (['', '  ', 123, ['a'], true, 0] as $bad) {
            $result = $this->create([
                'taxonomyKey' => 'news_category', 'name' => 'x', 'parentId' => $bad,
            ]);

            $this->assertSame(
                OperationStatus::ValidationError,
                $result->status,
                'parentId='.json_encode($bad).' を受理した',
            );
            $this->assertArrayHasKey('parentId', $result->errors);
        }

        $this->assertSame(0, TaxonomyTerm::query()->count(), 'root として作成してしまった');
    }

    /** A-8 */
    public function test_a8_非文字列のdescriptionを拒否する(): void
    {
        foreach ([123, ['a'], true] as $bad) {
            $result = $this->create([
                'taxonomyKey' => 'news_category', 'name' => 'x', 'description' => $bad,
            ]);

            $this->assertSame(OperationStatus::ValidationError, $result->status);
            $this->assertArrayHasKey('description', $result->errors);
        }

        // null と文字列は許可。空文字は null へ正規化する（contract）
        $ok = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'a', 'description' => '',
        ]);
        $this->assertTrue($ok->isSuccess());
        $this->assertNull($ok->data['term']['description']);
    }

    /** A-9 */
    public function test_a9_不変値のキーを拒否する(): void
    {
        foreach (['id', 'taxonomyId', 'taxonomy_id', 'managedBy', 'managed_by',
            'archived', 'archivedAt', 'archived_at', 'sortOrder', 'sort_order'] as $key) {
            $result = $this->create([
                'taxonomyKey' => 'news_category', 'name' => 'x', $key => 'v',
            ]);

            $this->assertSame(OperationStatus::ValidationError, $result->status, "{$key} を受理した");
            $this->assertArrayHasKey($key, $result->errors);
        }

        $this->assertSame(0, TaxonomyTerm::query()->count());
    }

    /** A-10: 正常キー + 未知キーでも全体を拒否する */
    public function test_a10_未知キーが混ざれば全体を拒否する(): void
    {
        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => '正しい名前', 'unexpected' => true,
        ]);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('unexpected', $result->errors);
        $this->assertSame(0, TaxonomyTerm::query()->count());
    }

    /** A-11 */
    public function test_a11_validation失敗で_d_bと_auditが変化しない(): void
    {
        // STAGE #18A: 拒否も記録されるので、**成功した記録**の数で比べる
        $before = [TaxonomyTerm::query()->count(), DB::table('audit_logs')->where('result', 'success')->count()];

        $this->create(['taxonomyKey' => 'news_category', 'name' => '', 'unknown' => 1]);

        $this->assertSame($before, [
            TaxonomyTerm::query()->count(), DB::table('audit_logs')->where('result', 'success')->count(),
        ]);
    }

    public function test_taxonomy_keyの型も厳密に見る(): void
    {
        foreach (['', 123, null, ['a']] as $bad) {
            $result = $this->create(['taxonomyKey' => $bad, 'name' => 'x']);

            $this->assertSame(OperationStatus::ValidationError, $result->status);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // B. UpdateTerm の入力
    // ─────────────────────────────────────────────────────────────

    /** B-1 / B-2 / B-3: 型不正で root へ移動しない */
    public function test_b1_不正なparent_idで既存parentを維持する(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $child = $this->makeTerm($taxonomy, 'child', parentId: $parent->id);

        foreach ([['a'], 123, '', '  ', true, 0] as $bad) {
            $result = app(UpdateTerm::class)->execute($child->id, ['parentId' => $bad], $this->admin);

            $this->assertSame(
                OperationStatus::ValidationError,
                $result->status,
                'parentId='.json_encode($bad).' を受理した',
            );
            $this->assertSame($parent->id, $child->fresh()?->parent_id, 'root へ移動した');
        }
    }

    /** B-4 */
    public function test_b4_parent_id_nullは明示的なroot移動(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');
        $child = $this->makeTerm($taxonomy, 'child', parentId: $parent->id);

        $result = app(UpdateTerm::class)->execute($child->id, ['parentId' => null], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($child->fresh()?->parent_id);
    }

    /** B-5 */
    public function test_b5_未知キーのみは拒否する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $result = app(UpdateTerm::class)->execute($term->id, ['unexpected' => true], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    /** B-6: 正常キー + 未知キー → 全体を拒否し name を更新しない */
    public function test_b6_未知キーが混ざればnameも更新しない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'original');

        $result = app(UpdateTerm::class)
            ->execute($term->id, ['name' => '新名称', 'unexpected' => true], $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertArrayHasKey('unexpected', $result->errors);
        $this->assertSame('original', $term->fresh()?->name, '部分更新してしまった');
    }

    /** B-7 */
    public function test_b7_不正なslugを拒否する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'original');

        foreach ([null, '', '  ', 123, ['a'], true] as $bad) {
            $result = app(UpdateTerm::class)->execute($term->id, ['slug' => $bad], $this->admin);

            $this->assertSame(OperationStatus::ValidationError, $result->status);
            $this->assertSame('original', $term->fresh()?->slug);
        }
    }

    /** B-8: "true" / 0 / 1 を boolean へ暗黙変換しない */
    public function test_b8_archivedはbooleanのみ受理する(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        foreach (['true', 'false', 0, 1, null, ['a']] as $bad) {
            $result = app(UpdateTerm::class)->execute($term->id, ['archived' => $bad], $this->admin);

            $this->assertSame(
                OperationStatus::ValidationError,
                $result->status,
                'archived='.json_encode($bad).' を受理した',
            );
            $this->assertNull($term->fresh()?->archived_at);
        }
    }

    /** B-9 */
    public function test_b9_validation失敗で部分更新も_auditも残さない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'original');
        $auditBefore = DB::table('audit_logs')->where('result', 'success')->count();

        app(UpdateTerm::class)->execute($term->id, [
            'name' => '新名称', 'archived' => 'true',
        ], $this->admin);

        $this->assertSame('original', $term->fresh()?->name);
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('result', 'success')->count());
    }

    // ─────────────────────────────────────────────────────────────
    // C. ReorderTerms の要素型
    // ─────────────────────────────────────────────────────────────

    public function test_c_ordered_term_idsの要素型を厳密に見る(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $b = $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        $cases = [
            'list ではない' => ['x' => $a->id, 'y' => $b->id],
            '配列要素' => [$a->id, ['nested']],
            '数値要素' => [$a->id, 123],
            'boolean 要素' => [$a->id, true],
            'null 要素' => [$a->id, null],
            '空文字要素' => [$a->id, ''],
            'ULID でない' => [$a->id, 'not-a-ulid'],
        ];

        foreach ($cases as $label => $ids) {
            $result = app(ReorderTerms::class)
                ->execute('news_category', null, $ids, $this->admin);

            $this->assertSame(
                OperationStatus::ValidationError,
                $result->status,
                "{$label} を受理した",
            );
        }

        // 並び順は変わっていない
        $this->assertSame(0, $a->fresh()?->sort_order);
        $this->assertSame(1, $b->fresh()?->sort_order);
    }

    // ─────────────────────────────────────────────────────────────
    // D. 4 mutation 共通の lock 順序
    // ─────────────────────────────────────────────────────────────

    public function test_d_4つのmutationが_taxonomy行を先にlockする(): void
    {
        $taxonomy = $this->taxonomy();
        $a = $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $this->makeTerm($taxonomy, 'b', sortOrder: 1);
        $deletable = $this->makeTerm($taxonomy, 'deletable', sortOrder: 2);

        $token = app(DeleteTerm::class)->execute($deletable->id, $this->admin)->confirmation?->token;

        $cases = [
            'term.create' => [
                fn () => $this->create(['taxonomyKey' => 'news_category', 'name' => 'new']),
                'insert into taxonomy_terms',
            ],
            'term.update' => [
                fn () => app(UpdateTerm::class)->execute($a->id, ['name' => 'renamed'], $this->admin),
                'update taxonomy_terms',
            ],
            'term.reorder' => [
                fn () => app(ReorderTerms::class)
                    ->execute('news_category', null, array_reverse($this->siblingIds()), $this->admin),
                'update taxonomy_terms',
            ],
            'term.delete' => [
                fn () => app(DeleteTerm::class)
                    ->execute($deletable->id, $this->admin->withConfirmationToken($token)),
                'delete from taxonomy_terms',
            ],
        ];

        foreach ($cases as $operation => [$run, $writePrefix]) {
            $sqls = $this->captureSql($run);

            // どの driver でも成立する事実: taxonomy 行の取得が書き込みより前にある
            $taxonomyIndex = $this->firstIndexMatching($sqls, $this->lockedSelect('taxonomies'));
            $writeIndex = $this->firstIndexMatching(
                $sqls,
                static fn (string $sql): bool => str_starts_with($sql, $writePrefix),
            );

            $this->assertNotNull($taxonomyIndex, "{$operation} が taxonomy 行を取得していない");
            $this->assertNotNull($writeIndex, "{$operation} の書き込みが見つからない");
            $this->assertLessThan(
                $writeIndex,
                $taxonomyIndex,
                "{$operation} が taxonomy を lock する前に書き込んだ",
            );

            $locks = $this->realLocks($sqls);

            if (DB::connection()->getDriverName() === 'mysql') {
                // MySQL では実際に FOR UPDATE が出る。**最初の lock は必ず taxonomies**
                $this->assertNotSame([], $locks, "{$operation} が行 lock を発行していない");
                $this->assertStringContainsString(
                    'taxonomies',
                    $locks[0],
                    "{$operation} の最初の lock が taxonomies ではない: {$locks[0]}",
                );
            } else {
                // SQLite は lockForUpdate を無視する（Laravel の SQLite grammar が no-op）。
                // 「lock が出ない」ことを明示して記録し、順序は上の assert で担保する
                $this->assertSame([], $locks, 'SQLite で FOR UPDATE が出ている（前提が変わった）');
            }
        }
    }

    public function test_d_lockのあとに検証と保存が行われる(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');

        $sqls = $this->captureSql(fn () => $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'child', 'parentId' => $parent->id,
        ]));

        $lockTaxonomy = $this->firstIndexMatching($sqls, $this->lockedSelect('taxonomies'));
        $lockParent = $this->firstIndexMatching($sqls, $this->lockedSelect('taxonomy_terms'));
        $insert = $this->firstIndexMatching($sqls, static fn (string $s): bool => str_starts_with($s, 'insert into taxonomy_terms'));

        $this->assertNotNull($lockTaxonomy, 'taxonomy 行を lock していない');
        $this->assertNotNull($lockParent, 'parent / 兄弟行を lock していない');
        $this->assertNotNull($insert);
        $this->assertLessThan($lockParent, $lockTaxonomy, 'taxonomy より先に term を lock した');
        $this->assertLessThan($insert, $lockParent, '検証より先に insert した');
    }

    // ─────────────────────────────────────────────────────────────
    // E / F. transaction 内の再検証
    // ─────────────────────────────────────────────────────────────

    /** 検証が lock 後の fresh state で行われる: archive を transaction 内で挟む */
    public function test_e_parentがarchiveされた状態は再検証で捕まる(): void
    {
        $taxonomy = $this->taxonomy();
        $parent = $this->makeTerm($taxonomy, 'parent');

        // transaction 内の再検証直前に親を archive する（同一接続で割り込む）
        DB::listen(function ($query) use ($parent): void {
            if (str_contains(self::normalize($query->sql), 'from taxonomies')) {
                TaxonomyTerm::query()->whereKey($parent->id)->update(['archived_at' => now()]);
            }
        });

        $result = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'child', 'parentId' => $parent->id,
        ]);

        $this->assertSame(
            OperationStatus::ValidationError,
            $result->status,
            'transaction 内で再検証していない（archive 済みの親の下に作成した）',
        );
        $this->assertSame(1, TaxonomyTerm::query()->count());
    }

    /** UpdateTerm は stale model ではなく locked term の状態から差分を決める */
    public function test_f_updateはlocked_termの現在状態から差分を決める(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        // execute 前に別経路で archive される（stale model は archived=false のまま）
        $stale = $term->fresh();
        TaxonomyTerm::query()->whereKey($term->id)->update(['archived_at' => now()]);

        // archived=true を要求 → locked state では既に archived なので同値 = 変更なし
        $result = app(UpdateTerm::class)->execute($stale->id, ['archived' => true], $this->admin);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($result->meta['action'], 'stale state から archive と誤判定した');
        $this->assertTrue($result->data['term']['archived']);
    }

    public function test_f_updateはconcurrentな変更を古い値で上書きしない(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');
        $stale = $term->fresh();

        // 別経路で description が設定される
        TaxonomyTerm::query()->whereKey($term->id)->update(['description' => '別経路の説明']);

        // name だけを更新する。description は指定していないので維持されるべき
        app(UpdateTerm::class)->execute($stale->id, ['name' => '新名称'], $this->admin);

        $fresh = $term->fresh();
        $this->assertSame('新名称', $fresh?->name);
        $this->assertSame('別経路の説明', $fresh?->description, '古い model の値で上書きした');
    }

    // ─────────────────────────────────────────────────────────────
    // G. reorder / delete の lock 統一
    // ─────────────────────────────────────────────────────────────

    public function test_g_reorderはtaxonomyのあとに兄弟をlockする(): void
    {
        $taxonomy = $this->taxonomy();
        $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        $sqls = $this->captureSql(fn () => app(ReorderTerms::class)
            ->execute('news_category', null, array_reverse($this->siblingIds()), $this->admin));

        $lockTaxonomy = $this->firstIndexMatching($sqls, $this->lockedSelect('taxonomies'));
        $update = $this->firstIndexMatching($sqls, static fn (string $s): bool => str_starts_with($s, 'update taxonomy_terms'));
        $siblings = $this->lastIndexMatching(
            $sqls,
            static fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, 'from taxonomy_terms'),
            (int) $update,
        );

        $this->assertNotNull($lockTaxonomy, 'taxonomy 行を取得していない');
        $this->assertNotNull($update);
        // taxonomy → 兄弟 → 更新 の順
        $this->assertLessThan($siblings, $lockTaxonomy);
        $this->assertLessThan($update, $siblings);
    }

    public function test_g_deleteは確認と再検査の順序を保つ(): void
    {
        $term = $this->makeTerm($this->taxonomy(), 'x');

        $first = app(DeleteTerm::class)->execute($term->id, $this->admin);
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertNotNull($term->fresh(), '確認前に削除した');

        $sqls = $this->captureSql(fn () => app(DeleteTerm::class)
            ->execute($term->id, $this->admin->withConfirmationToken($first->confirmation?->token)));

        $lockTaxonomy = $this->firstIndexMatching($sqls, $this->lockedSelect('taxonomies'));
        $delete = $this->firstIndexMatching($sqls, static fn (string $s): bool => str_starts_with($s, 'delete from taxonomy_terms'));
        $lockTerm = $this->lastIndexMatching(
            $sqls,
            static fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, 'from taxonomy_terms'),
            (int) $delete,
        );

        $this->assertNotNull($lockTaxonomy, 'delete で taxonomy 行を取得していない');
        $this->assertNotNull($delete);
        // taxonomy → term → 参照再検査 → delete の順
        $this->assertLessThan($lockTerm, $lockTaxonomy);
        $this->assertLessThan($delete, $lockTerm);
        $this->assertNull($term->fresh());
    }

    /** reorder と create の同時実行で full sibling-set が壊れない */
    public function test_g_createで増えた兄弟は古いreorder入力を拒否する(): void
    {
        $taxonomy = $this->taxonomy();
        $this->makeTerm($taxonomy, 'a', sortOrder: 0);
        $this->makeTerm($taxonomy, 'b', sortOrder: 1);

        // 並べ替え UI が取得した時点の兄弟集合
        $snapshot = $this->siblingIds();

        // その後に別経路で兄弟が増える
        $this->create(['taxonomyKey' => 'news_category', 'name' => 'c']);

        $result = app(ReorderTerms::class)
            ->execute('news_category', null, array_reverse($snapshot), $this->admin);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('すべて含めて', $result->errors['orderedTermIds'][0]);
    }

    /** concurrent slug: 同じ slug は片方だけ成功する */
    public function test_concurrent_slugは片方だけ成功する(): void
    {
        $first = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'A', 'slug' => 'press',
        ]);
        $second = $this->create([
            'taxonomyKey' => 'news_category', 'name' => 'B', 'slug' => 'press',
        ]);

        $this->assertTrue($first->isSuccess());
        $this->assertSame(OperationStatus::ValidationError, $second->status);
        $this->assertSame(1, TaxonomyTerm::query()->where('slug', 'press')->count());
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function create(array $input, ?OperationContext $context = null)
    {
        return app(CreateTerm::class)->execute($input, $context ?? $this->admin);
    }

    /** @return list<string> */
    private function siblingIds(): array
    {
        return TaxonomyTerm::query()
            ->whereNull('parent_id')
            ->orderBy('sort_order')->orderBy('id')
            ->pluck('id')->all();
    }

    /**
     * 実行中に発行された SQL を小文字で集める。
     *
     * @return list<string>
     */
    private function captureSql(callable $run): array
    {
        /** @var list<string> $sqls */
        $sqls = [];
        DB::listen(static function ($query) use (&$sqls): void {
            $sqls[] = self::normalize($query->sql);
        });

        $run();

        return $sqls;
    }

    /**
     * `for update` を含む SQL だけを順番に集める。
     *
     * @return list<string>
     */
    private function captureLockOrder(callable $run): array
    {
        $marker = $this->lockMarker();

        return array_values(array_filter(
            $this->captureSql($run),
            static fn (string $sql): bool => $marker === ''
                ? str_starts_with($sql, 'select')
                : str_contains($sql, $marker),
        ));
    }

    /**
     * SQLite は `FOR UPDATE` を無視する（Laravel の SQLite grammar が no-op）。
     * そのため SQLite では **クエリ順序**で lock 順序を検証し、
     * MySQL では実際に発行される `for update` で検証する。
     */
    private function lockMarker(): string
    {
        return DB::connection()->getDriverName() === 'mysql' ? 'for update' : '';
    }

    /**
     * 実際に発行された行 lock（MySQL のみ）。
     *
     * @param  list<string>  $sqls
     * @return list<string>
     */
    private function realLocks(array $sqls): array
    {
        return array_values(array_filter(
            $sqls,
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));
    }

    /**
     * `$before` より前で最後に一致した位置。
     *
     * @param  list<string>  $sqls
     * @param  callable(string): bool  $matches
     */
    private function lastIndexMatching(array $sqls, callable $matches, int $before): ?int
    {
        $found = null;

        foreach ($sqls as $index => $sql) {
            if ($index >= $before) {
                break;
            }

            if ($matches($sql)) {
                $found = $index;
            }
        }

        return $found;
    }

    private function lockedSelect(string $table): callable
    {
        $marker = $this->lockMarker();

        return static fn (string $sql): bool => str_contains($sql, "from {$table}")
            && ($marker === '' ? str_starts_with($sql, 'select') : str_contains($sql, $marker));
    }

    /** 引用符（バッククォート / ダブルクォート）を外して driver 差を吸収する */
    private static function normalize(string $sql): string
    {
        return strtolower(trim(str_replace(['`', '"'], '', $sql)));
    }

    /**
     * @param  list<string>  $sqls
     * @param  callable(string): bool  $matches
     */
    private function firstIndexMatching(array $sqls, callable $matches): ?int
    {
        foreach ($sqls as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }

    private function taxonomy(): Taxonomy
    {
        return Taxonomy::query()->where('key', 'news_category')->firstOrFail();
    }

    private function makeTerm(
        Taxonomy $taxonomy,
        string $slug,
        ?string $parentId = null,
        int $sortOrder = 0,
    ): TaxonomyTerm {
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(), 'taxonomy_id' => $taxonomy->id,
            'name' => $slug, 'slug' => $slug, 'parent_id' => $parentId,
            'sort_order' => $sortOrder,
        ])->save();

        return $term;
    }
}
