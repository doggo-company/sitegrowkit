<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Application\Taxonomy\GetTaxonomy;
use AgentCms\Core\Application\Taxonomy\ListTaxonomies;
use AgentCms\Core\Application\Taxonomy\ListTerms;
use AgentCms\Core\Application\Taxonomy\ReorderTerms;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 9.3B / #14・#15 — **OperationCatalog / API route / MCP bridge の 3 者整合**。
 *
 * SPEC §P.3（MCP tool 7 件）と §Q.1（API route 7 本）は **1:1 に一致**する契約。
 * ここが 3 者一致の正本で、他のテストはこれを参照する。
 *
 * MCP tool の名前と inputSchema は TypeScript 側（`packages/cli/tests/mcp.test.ts`）が
 * 固定する。**利用者の生成プロジェクトに `packages/cli/` は無い**ので、
 * ここからは読まない。PHP 側が担うのは
 * 「bridge（`OperationCatalog::invoke`）が Application Service まで到達するか」。
 */
final class TaxonomySurfaceParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 分類の確定契約（§P.3 / §Q.1）。**この表が 7 操作の正本**。
     *
     * @var array<string, array{service: class-string, classification: OperationClassification, route: string, method: string}>
     */
    private const TAXONOMY_OPERATIONS = [
        'taxonomy.list' => [
            'service' => ListTaxonomies::class,
            'classification' => OperationClassification::Read,
            'route' => 'api.v1.taxonomies.index',
            'method' => 'GET',
        ],
        'taxonomy.get' => [
            'service' => GetTaxonomy::class,
            'classification' => OperationClassification::Read,
            'route' => 'api.v1.taxonomies.show',
            'method' => 'GET',
        ],
        'term.list' => [
            'service' => ListTerms::class,
            'classification' => OperationClassification::Read,
            'route' => 'api.v1.taxonomies.terms.index',
            'method' => 'GET',
        ],
        'term.create' => [
            'service' => CreateTerm::class,
            'classification' => OperationClassification::Write,
            'route' => 'api.v1.taxonomies.terms.store',
            'method' => 'POST',
        ],
        'term.update' => [
            'service' => UpdateTerm::class,
            'classification' => OperationClassification::Write,
            'route' => 'api.v1.terms.update',
            'method' => 'PATCH',
        ],
        'term.reorder' => [
            'service' => ReorderTerms::class,
            'classification' => OperationClassification::Write,
            'route' => 'api.v1.taxonomies.terms.reorder',
            'method' => 'POST',
        ],
        'term.delete' => [
            'service' => DeleteTerm::class,
            'classification' => OperationClassification::Destructive,
            'route' => 'api.v1.terms.delete',
            'method' => 'POST',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomySynchronizer::class)->sync();
    }

    // ─────────────────────────────────────────────────────────────
    // 3 者整合
    // ─────────────────────────────────────────────────────────────

    public function test_7操作がcatalogとrouteの両方に存在する(): void
    {
        foreach (self::TAXONOMY_OPERATIONS as $operationId => $spec) {
            // catalog
            $this->assertTrue(OperationCatalog::allows($operationId), "{$operationId} が catalog に無い");
            $this->assertSame(
                $spec['classification'],
                OperationCatalog::classificationOf($operationId),
                "{$operationId} の分類が違う",
            );

            // Application Service 側の宣言と一致する（catalog が勝手に分類を決めない）
            $service = app($spec['service']);
            $this->assertSame($operationId, $service->operationId());
            $this->assertSame($spec['classification'], $service->classification());

            // route
            $route = app('router')->getRoutes()->getByName($spec['route']);
            $this->assertNotNull($route, "{$spec['route']} が無い");
            $this->assertContains($spec['method'], $route->methods(), "{$spec['route']} の method が違う");
        }
    }

    public function test_catalogとrouteに欠落も余分もない(): void
    {
        $catalog = array_values(array_filter(
            OperationCatalog::ids(),
            static fn (string $id): bool => str_starts_with($id, 'taxonomy.') || str_starts_with($id, 'term.'),
        ));
        sort($catalog);

        $expected = array_keys(self::TAXONOMY_OPERATIONS);
        sort($expected);

        // catalog だけに存在する / 表だけに存在する、のどちらも許さない
        $this->assertSame($expected, $catalog);

        // route 側も同じ 7 本だけ（分類 route が勝手に増えていない）
        $routes = array_values(array_filter(
            array_map(
                static fn ($r): string => (string) $r->getName(),
                app('router')->getRoutes()->getRoutes(),
            ),
            static fn (string $name): bool => str_starts_with($name, 'api.v1.taxonomies')
                || str_starts_with($name, 'api.v1.terms'),
        ));
        sort($routes);

        $expectedRoutes = array_column(self::TAXONOMY_OPERATIONS, 'route');
        sort($expectedRoutes);

        $this->assertSame($expectedRoutes, $routes);
    }

    public function test_既存の操作が失われていない(): void
    {
        $existing = [
            'content.types.list', 'content.schema.get', 'content.list', 'content.get',
            'content.create', 'content.update', 'content.delete',
            'media.list', 'media.get', 'media.update', 'media.replace',
            'settings.get', 'settings.update',
            // `contact.list` / `contact.get` はここから外した（B-43）。
            // **失われたのではなく、9.12 の「受信は出さない」に旧側が揃った**
            'page.list', 'page.create', 'page.get', 'page.document.get',
            'page.document.patch', 'page.publish', 'page.trash',
        ];

        foreach ($existing as $id) {
            $this->assertTrue(OperationCatalog::allows($id), "既存操作 {$id} が消えた");
        }

        /*
         * **数は書かない。**
         *
         * ここには「22（既存）+ 7（分類）+ 1 = 30」と書いてあり、隣に
         * `assertCount(39, …)` が並んでいた。**足し算の方が先に嘘になっていた**
         * のに、誰も落ちなかった — 数は名前の代わりにならない。
         *
         * 公開範囲そのものは `McpBoundaryTest` が**名前で**固定している。
         * ここが見るのは「**この一覧の操作が消えていないか**」だけである。
         *
         * すぐ上の 2 つ（`$existing` の件数・`TAXONOMY_OPERATIONS` の件数）も
         * 落とした。どちらもこのファイルの中の配列を数え直しているだけで、
         * **書いた本人以外には何も約束していなかった**。
         */
        $this->assertTrue(OperationCatalog::allows('content.trash'), 'ゴミ箱への移動が公開されていない');
    }

    public function test_operation_i_dに重複がない(): void
    {
        $ids = OperationCatalog::ids();

        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    /** catalog にあるのに実行できない（未解決 handler）操作が無い */
    public function test_catalogのすべての操作が実行経路を持つ(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'parity');

        foreach (self::TAXONOMY_OPERATIONS as $operationId => $spec) {
            $result = $catalog->invoke($operationId, [], $context);

            // 引数が空なので成功しないものもあるが、**「AI から実行できません」にはならない**
            $this->assertNotSame(
                "操作 [{$operationId}] は AI から実行できません",
                $result->message,
                "{$operationId} の handler が未解決",
            );
        }
    }

    public function test_taxonomy自体のcrudはどこにも公開されていない(): void
    {
        foreach ([
            'taxonomy.create', 'taxonomy.update', 'taxonomy.delete', 'taxonomy.sync',
            'term.assign', 'term.unassign', 'term.archive', 'term.unarchive',
        ] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden), "{$forbidden} が公開されている");
        }

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/taxonomies')) {
                continue;
            }

            // taxonomy そのものを書き換える route が無い
            $writesTaxonomy = $route->methods() !== ['GET', 'HEAD']
                && ! str_contains($uri, '/terms');

            $this->assertFalse($writesTaxonomy, "{$uri} が taxonomy を書き換える");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // MCP bridge（OperationCatalog::invoke）が Application Service へ到達する
    // ─────────────────────────────────────────────────────────────

    public function test_mcp経由で7操作すべてが_application_serviceへ到達する(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'agent');

        // taxonomy.list
        $list = $catalog->invoke('taxonomy.list', [], $context);
        $this->assertTrue($list->isSuccess());
        $this->assertSame('news_category', $list->data['taxonomies'][0]['key']);

        // taxonomy.get
        $get = $catalog->invoke('taxonomy.get', ['key' => 'news_category'], $context);
        $this->assertTrue($get->isSuccess());
        $this->assertSame('news_category', $get->data['taxonomy']['key']);

        // term.create
        $created = $catalog->invoke('term.create', [
            'taxonomyKey' => 'news_category', 'name' => 'プレスリリース', 'slug' => 'press',
        ], $context);
        $this->assertTrue($created->isSuccess(), (string) $created->message);
        $termId = (string) $created->data['term']['id'];

        // term.list
        $terms = $catalog->invoke('term.list', ['taxonomyKey' => 'news_category'], $context);
        $this->assertTrue($terms->isSuccess());
        $this->assertCount(1, $terms->data['terms']);
        $this->assertFalse($terms->meta['includeArchived']);

        // term.update（archive も同じ tool）
        $updated = $catalog->invoke('term.update', [
            'termId' => $termId, 'name' => '報道発表',
        ], $context);
        $this->assertTrue($updated->isSuccess(), (string) $updated->message);
        $this->assertSame('報道発表', $updated->data['term']['name']);

        $archived = $catalog->invoke('term.update', ['termId' => $termId, 'archived' => true], $context);
        $this->assertTrue($archived->isSuccess());
        $this->assertTrue($archived->data['term']['archived']);
        $catalog->invoke('term.update', ['termId' => $termId, 'archived' => false], $context);

        // term.reorder
        $second = $catalog->invoke('term.create', [
            'taxonomyKey' => 'news_category', 'name' => 'お知らせ', 'slug' => 'topics',
        ], $context);
        $secondId = (string) $second->data['term']['id'];

        $reordered = $catalog->invoke('term.reorder', [
            'taxonomyKey' => 'news_category',
            'orderedTermIds' => [$secondId, $termId],
        ], $context);
        $this->assertTrue($reordered->isSuccess(), (string) $reordered->message);
        $this->assertSame([$secondId, $termId], $reordered->data['order']);

        // term.delete（確認 → 実行）
        $confirmation = $catalog->invoke('term.delete', ['termId' => $secondId], $context);
        $this->assertSame(OperationStatus::ConfirmationRequired, $confirmation->status);
        $this->assertNotNull(TaxonomyTerm::query()->find($secondId), '確認前に削除した');

        $deleted = $catalog->invoke(
            'term.delete',
            ['termId' => $secondId],
            $context->withConfirmationToken($confirmation->confirmation?->token),
        );
        $this->assertTrue($deleted->isSuccess(), (string) $deleted->message);
        $this->assertNull(TaxonomyTerm::query()->find($secondId));
    }

    public function test_mcp経由のvalidation_errorが正規化される(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'agent');

        // 未知キーは黙って無視せず validation error
        $unknown = $catalog->invoke('term.create', [
            'taxonomyKey' => 'news_category', 'name' => 'x', 'sortOrder' => 1,
        ], $context);
        $this->assertSame(OperationStatus::ValidationError, $unknown->status);
        $this->assertArrayHasKey('sortOrder', $unknown->errors);

        // termId は位置引数。属性として渡らないので「未知のキー」にならない
        $term = $catalog->invoke('term.create', [
            'taxonomyKey' => 'news_category', 'name' => 'y',
        ], $context);
        $updated = $catalog->invoke('term.update', [
            'termId' => (string) $term->data['term']['id'], 'name' => 'z',
        ], $context);
        $this->assertTrue($updated->isSuccess(), (string) $updated->message);

        // 並べ替えの要素型
        $reorder = $catalog->invoke('term.reorder', [
            'taxonomyKey' => 'news_category', 'orderedTermIds' => [123],
        ], $context);
        $this->assertSame(OperationStatus::ValidationError, $reorder->status);
        $this->assertArrayHasKey('orderedTermIds', $reorder->errors);
    }

    public function test_mcp経由のnot_foundとconflictが正規化される(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'agent');

        // not found
        $this->assertSame(
            OperationStatus::NotFound,
            $catalog->invoke('taxonomy.get', ['key' => 'nope'], $context)->status,
        );
        $this->assertSame(
            OperationStatus::NotFound,
            $catalog->invoke('term.update', ['termId' => TaxonomyId::generate(), 'name' => 'x'], $context)->status,
        );

        // conflict: 参照が残っている term は削除できない（confirmation も出さない）
        $created = $catalog->invoke('term.create', [
            'taxonomyKey' => 'news_category', 'name' => '使用中',
        ], $context);
        $termId = (string) $created->data['term']['id'];

        TaxonomyTermAssignment::query()->create([
            'term_id' => $termId, 'content_type' => 'news', 'content_id' => 1,
        ]);

        $conflict = $catalog->invoke('term.delete', ['termId' => $termId], $context);

        $this->assertSame(OperationStatus::Conflict, $conflict->status);
        $this->assertNull($conflict->confirmation);
        $this->assertNotNull(TaxonomyTerm::query()->find($termId));
    }

    public function test_mcp経由でも認可契約が維持される(): void
    {
        $catalog = app(OperationCatalog::class);

        // 名無しの Admin context は既存 policy が拒否する
        $denied = $catalog->invoke('term.list', ['taxonomyKey' => 'news_category'], OperationContext::admin(null, ''));

        $this->assertSame(OperationStatus::Forbidden, $denied->status);
    }

    /** エラー本文に内部情報を出さない */
    public function test_mcpのエラーに内部情報が漏れない(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'agent');

        $messages = [];

        foreach ([
            ['taxonomy.get', ['key' => 'nope']],
            ['term.list', ['taxonomyKey' => 'nope']],
            ['term.create', ['taxonomyKey' => 'news_category', 'name' => '']],
            ['term.update', ['termId' => 'not-a-ulid', 'name' => 'x']],
            ['term.reorder', ['taxonomyKey' => 'news_category', 'orderedTermIds' => [null]]],
            ['term.delete', ['termId' => 'not-a-ulid']],
        ] as [$operationId, $input]) {
            $result = $catalog->invoke($operationId, $input, $context);

            $messages[] = (string) $result->message;
            $messages[] = (string) json_encode($result->errors, JSON_UNESCAPED_UNICODE);
        }

        $body = implode("\n", $messages);

        foreach ([
            'SQLSTATE', 'PDO', 'select ', 'insert into', 'password',
            base_path(), 'AgentCms\\', 'Stack trace', '#0 ',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "[{$leak}] が漏れている");
        }
    }

    /** read 系の操作は DB を書き換えない */
    public function test_read系の3操作は_d_bを書き換えない(): void
    {
        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp, 'agent');

        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            $sql = strtolower(trim($query->sql));

            foreach (['insert ', 'update ', 'delete '] as $write) {
                if (str_starts_with($sql, $write)) {
                    $writes[] = $sql;
                }
            }
        });

        $catalog->invoke('taxonomy.list', [], $context);
        $catalog->invoke('taxonomy.get', ['key' => 'news_category'], $context);
        $catalog->invoke('term.list', ['taxonomyKey' => 'news_category'], $context);

        $this->assertSame([], $writes, '読み取りで書き込みが発生した: '.implode(' / ', $writes));
    }
}
