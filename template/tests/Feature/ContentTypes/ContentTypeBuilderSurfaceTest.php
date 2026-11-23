<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\AuditRecorder;
use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\ContentTypes\CreateContentType;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\BuilderFieldType;
use App\Support\OperationCatalog;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Phase 9.4 / STAGE #17A — 作成した投稿タイプが「全部の面」に出る（SPEC §2-8・9・§1D）。
 *
 * 管理画面 / API / MCP / Application Service が **別々の型の一覧**を持たないことを固定する。
 */
final class ContentTypeBuilderSurfaceTest extends BuilderTestCase
{
    /** SPEC §2-8 — API の content-types 一覧・schema 取得・CRUD へ反映される */
    public function test_api_v1へ反映される(): void
    {
        $user = $this->admin();
        $this->createContentType($user);
        $this->rebootApplication();

        // 一覧
        $index = $this->actingAs($user)->getJson('/api/v1/content-types');
        $index->assertOk();
        $ids = array_column((array) $index->json('data'), 'id');
        $this->assertContains($this->typeId, $ids);
        $this->assertContains('news', $ids, '既存の型が消えている');

        // schema 取得
        $schema = $this->actingAs($user)->getJson("/api/v1/content-types/{$this->typeId}");
        $schema->assertOk();
        $this->assertSame($this->typeId, $schema->json('data.id'));
        $this->assertSame('cms', $schema->json('data.managedBy'));
        $this->assertContains('title', array_column((array) $schema->json('data.fields'), 'key'));

        // CRUD（読み取り + 更新は api/v1 の既存契約どおり）
        $this->actingAs($user)->post("/admin/{$this->typeId}", [
            'title' => 'API から見える？', 'slug' => 'api', 'status' => 'draft',
        ])->assertRedirect();

        $list = $this->actingAs($user)->getJson("/api/v1/content/{$this->typeId}");
        $list->assertOk();
        $this->assertSame(1, $list->json('meta.total'));

        $id = (int) $list->json('data.0.id');

        $this->actingAs($user)->getJson("/api/v1/content/{$this->typeId}/{$id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'API から見える？');

        $this->actingAs($user)->patchJson("/api/v1/content/{$this->typeId}/{$id}", [
            'data' => ['title' => 'API から更新'],
        ])->assertOk();

        $this->actingAs($user)->getJson("/api/v1/content/{$this->typeId}/{$id}")
            ->assertJsonPath('data.title', 'API から更新');
    }

    /** SPEC §2-9 — MCP（OperationCatalog）から使える */
    public function test_operation_catalogから使える(): void
    {
        $this->createContentType($this->admin());
        $this->rebootApplication();

        $catalog = app(OperationCatalog::class);
        $context = OperationContext::agent(OperationInterface::Mcp);

        // content.types.list に出る
        $types = $catalog->invoke('content.types.list', [], $context);
        $this->assertTrue($types->isSuccess());
        $this->assertContains($this->typeId, array_column($types->data['types'], 'id'));

        // content.schema.get で定義が取れる
        $schema = $catalog->invoke('content.schema.get', ['type' => $this->typeId], $context);
        $this->assertTrue($schema->isSuccess());
        $this->assertSame($this->typeId, $schema->data['schema']['id']);

        // content.create → content.list → content.get が通る
        $created = $catalog->invoke('content.create', [
            'type' => $this->typeId,
            'data' => ['title' => 'MCP から作成', 'slug' => 'mcp', 'status' => 'draft'],
        ], $context);
        $this->assertTrue($created->isSuccess(), (string) $created->message);

        $id = (int) $created->data['item']['id'];

        $this->assertTrue($catalog->invoke('content.list', ['type' => $this->typeId], $context)->isSuccess());
        $got = $catalog->invoke('content.get', ['type' => $this->typeId, 'id' => $id], $context);
        $this->assertSame('MCP から作成', $got->data['item']['title']);

        $updated = $catalog->invoke('content.update', [
            'type' => $this->typeId, 'id' => $id, 'data' => ['title' => 'MCP から更新'],
        ], $context);
        $this->assertTrue($updated->isSuccess());

        // 削除は確認を要求する（作った型でも既存と同じ扱い）
        $deleted = $catalog->invoke('content.delete', ['type' => $this->typeId, 'id' => $id], $context);
        $this->assertSame(OperationStatus::ConfirmationRequired, $deleted->status);
    }

    /**
     * Content Type の**作成そのもの**は MCP へ公開しない（SPEC §1・§4）。
     *
     * AI から新しいテーブルとファイルを作らせないという設計判断を、
     * allowlist と Application Service の両方で固定する。
     */
    public function test_content_typeの作成はmcpから実行できない(): void
    {
        $this->assertFalse(
            OperationCatalog::allows('content.type.create'),
            'Content Type 作成が MCP allowlist に載っています',
        );

        $result = app(OperationCatalog::class)->invoke(
            'content.type.create',
            $this->payload(),
            OperationContext::agent(OperationInterface::Mcp),
        );

        $this->assertSame(OperationStatus::Forbidden, $result->status);

        // Application Service を直接叩いても経路で拒否される（二重に閉じる）
        $direct = app(CreateContentType::class)->execute(
            $this->payload(),
            OperationContext::agent(OperationInterface::Mcp),
        );

        $this->assertSame(OperationStatus::Forbidden, $direct->status);
        $this->assertFileDoesNotExist($this->schemaPath.'/'.$this->typeId.'.json');
    }

    /**
     * Builder が選ばせる **すべての** field type が、生成した投稿タイプの
     * 管理フォームから実際に入力でき、保存できる（cms-director 指摘）。
     */
    public function test_全てのfield_typeが投稿フォームから入力できる(): void
    {
        $user = $this->admin();

        $fields = [];
        foreach (BuilderFieldType::cases() as $type) {
            $fields[] = [
                'key' => $type === BuilderFieldType::Text ? 'title' : 'f_'.$type->value,
                'type' => $type->value,
                'label' => $type->label(),
                'choices' => $type->needsChoices() ? ['alpha', 'beta'] : [],
            ];
        }

        $this->createContentType($user, ['fields' => $fields, 'list' => ['title'], 'search' => ['title']]);
        $this->rebootApplication();

        // 画面が描ける型かどうかは Vue 側の実装に依存する。
        // schema が返す型が **すべて SchemaFieldInput の対応表に載っている**ことを確かめる
        $rendered = $this->renderableFieldTypes();

        foreach (BuilderFieldType::cases() as $type) {
            $this->assertContains(
                $type->value,
                $rendered,
                "field type [{$type->value}] を SchemaFieldInput が描画できません",
            );
        }

        // 実際に保存できる
        $this->actingAs($user)->post("/admin/{$this->typeId}", [
            'title' => 'すべての型',
            'f_textarea' => '複数行',
            'f_richtext' => '<p>本文</p>',
            'f_slug' => 'all-types',
            'f_number' => '12.5',
            'f_boolean' => true,
            'f_select' => 'alpha',
            'f_radio' => 'beta',
            'f_checkbox' => ['alpha', 'beta'],
            'f_date' => '2026-08-14',
            'f_datetime' => '2026-08-14 10:00:00',
            'f_email' => 'test@example.test',
            'f_url' => 'https://example.test',
            'f_tel' => '03-1234-5678',
            'f_status' => 'draft',
        ])->assertRedirect("/admin/{$this->typeId}");

        $item = app(ContentTypeResolver::class)
            ->newModel($this->typeId)->newQuery()->firstOrFail();

        $this->assertSame('すべての型', $item->getAttribute('title'));
        $this->assertSame('alpha', $item->getAttribute('f_select'));
        $this->assertSame(['alpha', 'beta'], $item->getAttribute('f_checkbox'));
        $this->assertSame(12.5, $item->getAttribute('f_number'));
        $this->assertTrue((bool) $item->getAttribute('f_boolean'));
    }

    /**
     * 監査ログが書けなくても、作成済みの成果物は巻き戻さず、
     * **黙って 500 にもしない**（成功 + 監査欠落の警告）。
     */
    public function test_監査失敗でも作成は成功し警告が返る(): void
    {
        $this->app->bind(AuditRecorder::class, fn (): AuditRecorder => new class implements AuditRecorder
        {
            public function record(
                string $operationId,
                OperationClassification $classification,
                OperationContext $context,
                Result $result,
                ?string $resourceType = null,
                ?string $resourceId = null,
                array $metadata = [],
            ): void {
                throw new RuntimeException('監査ログを書けません（テスト用の故障注入）');
            }
        });

        $context = OperationContext::admin($this->admin()->getKey(), 'tester');

        // 1 回目は確認要求（この時点では何も変わらない）
        $preview = app(CreateContentType::class)->execute($this->payload(), $context);
        $this->assertSame(OperationStatus::ConfirmationRequired, $preview->status);

        $result = app(CreateContentType::class)->execute(
            $this->payload(),
            $context->withConfirmationToken((string) $preview->confirmation?->token),
        );

        $this->assertTrue($result->isSuccess(), '監査失敗で作成が失敗扱いになっています');
        $this->assertFalse($result->meta['auditRecorded'] ?? true, '監査欠落が伝わっていません');

        // 成果物は残る（巻き戻さない）
        $this->assertFileExists($this->schemaPath.'/'.$this->typeId.'.json');
        $this->assertTrue(Schema::hasTable($this->typeId));
    }

    /**
     * `SchemaFieldInput.vue` が描画できる field type。
     *
     * **画面のソースから読み取る。** ここで一覧を手書きすると、
     * Vue 側で型を落としてもテストが気づけない。
     *
     * @return list<string>
     */
    private function renderableFieldTypes(): array
    {
        $source = (string) file_get_contents(
            base_path('resources/js/components/schema/SchemaFieldInput.vue'),
        );

        $types = [];

        // `inlineInputTypes` の対応表
        if (preg_match('/const inlineInputTypes[^=]*=\s*\{(.*?)\};/s', $source, $m) === 1) {
            preg_match_all('/^\s*([a-z]+):/m', $m[1], $keys);
            $types = [...$types, ...$keys[1]];
        }

        // `field.type === 'xxx'` / `includes` で分岐している型
        preg_match_all("/field\.type === '([a-z]+)'/", $source, $matches);

        return array_values(array_unique([...$types, ...$matches[1]]));
    }
}
