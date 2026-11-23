<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use AgentCms\Core\Application\FieldGroups\ListFieldGroups;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Support\Capability;
use App\Support\OperationCapabilities;
use App\Support\OperationCatalog;
use Illuminate\Support\Facades\Route;

/**
 * Phase 9.5-A / ADR-0015 — 公開面が増えていないこと（必須テスト 11・12）。
 *
 * Field Group は構造を変える操作なので **AI へは公開しない**（G7）。
 * 「うっかり増やしていない」を件数で固定する。
 */
final class FieldGroupSurfaceTest extends FieldGroupTestCase
{
    /** 必須テスト 11 — MCP allowlist に Field Group は入らない */
    public function test_mcpへfield_groupを公開していない(): void
    {
        $ids = OperationCatalog::ids();

        foreach ($ids as $id) {
            $this->assertStringStartsNotWith('field_group', $id);
        }

        $this->assertFalse(OperationCatalog::allows('field_group.list'));
    }

    /** 公開 API も増やさない */
    public function test_api_v1のrouteが増えていない(): void
    {
        $routes = array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'api/v1'),
        );

        /*
         * **本数は書かない。** 増えたときに数だけ直して中身を見ない道になる
         * （api/v1 の write の一覧は `ApiV1Test` が名前で固定している）。
         * ここが言いたいのは「**Field Group の route は 1 本も無い**」だけである。
         */
        foreach ($routes as $route) {
            $this->assertStringNotContainsString('field-group', $route->uri());
        }

        $this->assertNotSame([], $routes, 'api/v1 の route が 1 本も見つかりません');
    }

    /**
     * capability 表に載るのは **この 7 つだけ**（Phase 9.5-B）。
     *
     * すべて投稿タイプと同格（構造を変える権限）。表に無い operation は
     * 利用者 actor に対して default deny になる。
     */
    public function test_capability表のfield_group操作は7つ(): void
    {
        $added = array_values(array_filter(
            OperationCapabilities::operationIds(),
            static fn (string $id): bool => str_starts_with($id, 'field_group'),
        ));

        sort($added);

        $this->assertSame([
            'field_group.archive',
            'field_group.create',
            'field_group.get',
            'field_group.list',
            'field_group.reorder',
            'field_group.restore',
            'field_group.update',
        ], $added);

        foreach ($added as $id) {
            $this->assertSame(
                Capability::ContentTypeManage,
                OperationCapabilities::for($id),
                "{$id} の権限が投稿タイプと同格でない",
            );
        }

        // **削除は存在しない**（アーカイブと復元だけ）
        $this->assertNull(OperationCapabilities::for('field_group.delete'));
    }

    /** Read なので監査しない（`OperationAuditCoverageTest` の対象外である根拠） */
    public function test_一覧はread操作である(): void
    {
        $operation = app(ListFieldGroups::class);

        $this->assertSame('field_group.list', $operation->operationId());
        $this->assertSame(OperationClassification::Read, $operation->classification());
    }

    /** G11 — page_template は enum に存在しない */
    public function test_location_kindはcontent_typeとformだけ(): void
    {
        $this->assertSame(
            ['content_type', 'form'],
            array_map(static fn (FieldGroupLocationKind $k): string => $k->value, FieldGroupLocationKind::cases()),
        );

        $this->assertNull(FieldGroupLocationKind::tryFrom('page_template'));
    }

    /**
     * 管理画面の route は **ちょうど 8 本**で、DELETE は 1 本も無い。
     *
     * 破壊的操作は DELETE 動詞ではなく `POST .../archive` + 確認トークンに揃える
     * （利用者の管理と同じ作法）。
     */
    public function test_field_groupのadmin_routeは8本で削除が無い(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_contains($route->uri(), 'field-group'),
        ));

        $actual = array_map(
            static fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
            $routes,
        );

        sort($actual);

        $this->assertSame([
            'GET admin/field-groups',
            'GET admin/field-groups/create',
            'GET admin/field-groups/{id}',
            'PATCH admin/field-groups/{id}',
            'POST admin/field-groups',
            'POST admin/field-groups/reorder',
            'POST admin/field-groups/{id}/archive',
            'POST admin/field-groups/{id}/restore',
        ], $actual);

        foreach ($routes as $route) {
            $this->assertNotContains('DELETE', $route->methods(), '物理削除の route がある');
        }
    }

    /** 必須テスト 12 — 既存 schema は overlay 導入後も同じものを返す */
    public function test_既存schemaのidとfield数が変わらない(): void
    {
        $expected = [
            'contact' => 5,
            'media' => 2,
            // 標準記事のアイキャッチ・抜粋を含めて固定する。
            'news' => 9,
            // 生成サイトが最初から持つ唯一の投稿タイプ（Phase 9.7 / STAGE 20B）
            'post' => 7,
            'product' => 23,
            // R-5 で「トップページ」を足して 8 → 9、B-39 で「コメントを管理メニューに出す」を
            // 足して 9 → 10。**数を緩めない** — この pin が見ているのは
            // 「overlay で勝手に増減しないこと」で、意図して足したときは足した数へ動かすのが正しい
            // SNS画像に加え、ロゴ・サイトアイコンを追加した13項目を固定する。
            'site' => 13,
            'works' => 11,
        ];

        $actual = [];

        foreach (app(SchemaRegistry::class)->all() as $id => $schema) {
            $actual[$id] = count($schema->fields);
        }

        ksort($actual);

        $this->assertSame($expected, $actual);
    }
}
