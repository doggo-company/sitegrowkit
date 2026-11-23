<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPageDocument;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use App\Support\OperationCatalog;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\News\Models\News;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.1D: PageDocument foundation の受け入れ（owner 指定の 20 項目）。
 *
 * 「Visual Editor を作る」ためではなく、
 * **Visual Editor / AI / MCP / API / import が同じ document を操作できる**土台を固定する。
 */
final class PageDocumentTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'test-agent');
    }

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    private function catalog(): OperationCatalog
    {
        return app(OperationCatalog::class);
    }

    /** @param array<string, mixed> $command */
    private function command(int $pageId, array $command, ?OperationContext $context = null): Result
    {
        return app(PatchPageDocument::class)->execute($pageId, $command, $context ?? $this->admin());
    }

    private function createPage(string $slug = 'company', string $title = '会社案内'): Page
    {
        $result = app(CreatePage::class)->execute(
            ['title' => $title, 'slug' => $slug],
            $this->admin(),
        );

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    private function rootId(Page $page): string
    {
        $version = PageVersion::query()->findOrFail($page->draft_version_id);

        return (string) $version->document['root']['id'];
    }

    /** ページに 1 つブロックを足して、その id を返す */
    private function insert(Page $page, array $component, ?string $parent = null): string
    {
        $result = $this->command($page->id, [
            'operation' => 'component.insert',
            'parent' => $parent ?? $this->rootId($page->refresh()),
            'component' => $component,
        ]);

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        $children = $result->data['document']['root']['children'];
        $node = $parent === null ? end($children) : null;

        if ($node === null) {
            // 入れ子の場合は木を辿って最後に足したものを探す
            $node = DocumentTree::find($result->data['document'], $parent);
            $node = end($node['children']);
        }

        return (string) $node['id'];
    }

    // 1. document-managed page を作れる
    public function test_a_document_managed_page_can_be_created(): void
    {
        $page = $this->createPage();

        $this->assertTrue($page->isDocumentManaged());
        $this->assertNotNull($page->draft_version_id);
        $this->assertNull($page->published_version_id);
    }

    // 2 + 3. 主要 component が並び、サーバー側で HTML になる
    public function test_foundation_components_render_to_server_side_html(): void
    {
        $page = $this->createPage('landing', 'ランディング');
        $root = $this->rootId($page);

        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $this->insert($page, ['type' => 'heading', 'props' => ['text' => '選ばれる理由', 'level' => 2]], $section);
        $this->insert($page, ['type' => 'text', 'props' => ['text' => '説明文です。']], $section);
        $this->insert($page, ['type' => 'button', 'props' => ['label' => 'お問い合わせ', 'href' => '/contact']], $section);

        $container = $this->insert($page, ['type' => 'container', 'props' => ['layout' => 'grid', 'columns' => 3], 'children' => []], $section);
        $this->insert($page, ['type' => 'heading', 'props' => ['text' => '実績', 'level' => 3]], $container);

        $this->assertNotSame($root, $section);

        app(PublishPage::class)->execute($page->id, $this->admin());

        $response = $this->get('/landing');

        $response->assertOk()
            ->assertSee('選ばれる理由')
            ->assertSee('説明文です。')
            ->assertSee('お問い合わせ')
            ->assertSee('doc-container--grid', false);
    }

    // 4. 公開ページに Vue runtime を要求しない
    public function test_the_public_page_does_not_require_a_vue_runtime(): void
    {
        $page = $this->createPage('simple', 'シンプル');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $this->insert($page, ['type' => 'heading', 'props' => ['text' => '見出し', 'level' => 1]], $section);
        app(PublishPage::class)->execute($page->id, $this->admin());

        $html = $this->get('/simple')->assertOk()->getContent();

        $this->assertStringNotContainsString('resources/js/app.ts', $html);
        $this->assertStringNotContainsString('data-page=', $html);
        $this->assertStringNotContainsString('id="app"', $html);
    }

    // 5. content binding が type 名で解決される（News / Works を hardcode しない）
    public function test_content_binding_resolves_by_type_without_hardcoded_models(): void
    {
        $page = $this->createPage('news-page', 'お知らせ一覧');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $this->insert($page, [
            'type' => 'collection',
            'props' => ['layout' => 'list', 'limit' => 3],
            'binding' => ['source' => 'collection', 'type' => 'news',
                'sort' => [['field' => 'published_at', 'direction' => 'desc']]],
        ], $section);

        app(PublishPage::class)->execute($page->id, $this->admin());

        $html = $this->get('/news-page')->assertOk()->getContent();
        $latest = News::query()->where('status', 'published')->first();

        $this->assertNotNull($latest);
        $this->assertStringContainsString($latest->title, $html);

        // core 側の binding / renderer がモジュールのモデルへ依存していないこと
        foreach (['Render/BindingResolver', 'Render/DocumentRenderer', 'Application/Pages/PatchPageDocument'] as $file) {
            $source = (string) file_get_contents(base_path("core/src/{$file}.php"));
            $this->assertStringNotContainsString('Modules\\', $source, "{$file} が module へ依存しています");
        }
    }

    // 6. draft を変えても published は変わらない
    public function test_editing_the_draft_does_not_change_the_published_page(): void
    {
        $page = $this->createPage('about-us', '私たちについて');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $heading = $this->insert($page, ['type' => 'heading', 'props' => ['text' => '公開時の見出し', 'level' => 2]], $section);
        app(PublishPage::class)->execute($page->id, $this->admin());

        $this->command($page->id, [
            'operation' => 'component.update',
            'target' => $heading,
            'changes' => ['props' => ['text' => '編集中の見出し']],
        ]);

        $html = $this->get('/about-us')->assertOk()->getContent();

        $this->assertStringContainsString('公開時の見出し', $html);
        $this->assertStringNotContainsString('編集中の見出し', $html);
    }

    // 7. publish で published version が切り替わる
    public function test_publish_switches_the_published_version(): void
    {
        $page = $this->createPage('switch', '切替');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $heading = $this->insert($page, ['type' => 'heading', 'props' => ['text' => '第1版', 'level' => 2]], $section);
        app(PublishPage::class)->execute($page->id, $this->admin());
        $first = $page->refresh()->published_version_id;

        $this->command($page->id, [
            'operation' => 'component.update',
            'target' => $heading,
            'changes' => ['props' => ['text' => '第2版']],
        ]);
        app(PublishPage::class)->execute($page->id, $this->admin());

        $this->assertNotSame($first, $page->refresh()->published_version_id);
        $this->get('/switch')->assertOk()->assertSee('第2版');
    }

    // 8. 古い revision での更新は CONFLICT
    public function test_a_stale_revision_update_returns_conflict(): void
    {
        $page = $this->createPage('conflict', '競合');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $heading = $this->insert($page, ['type' => 'heading', 'props' => ['text' => 'A', 'level' => 2]], $section);

        $current = PageVersion::query()->findOrFail($page->refresh()->draft_version_id)->revision;

        // 人間が先に保存した
        $this->command($page->id, [
            'operation' => 'component.update', 'target' => $heading,
            'changes' => ['props' => ['text' => '人間の編集']], 'expectedVersion' => $current,
        ]);

        // AI が古い version のまま上書きしようとする
        $stale = $this->command($page->id, [
            'operation' => 'component.update', 'target' => $heading,
            'changes' => ['props' => ['text' => 'AI の上書き']], 'expectedVersion' => $current,
        ], $this->agent());

        $this->assertSame(OperationStatus::Conflict, $stale->status);

        $document = app(GetPageDocument::class)->execute($page->id, $this->admin())->data['document'];
        $node = DocumentTree::find($document, $heading);

        $this->assertSame('人間の編集', $node['props']['text']);
    }

    // 9. component 更新に確認は不要
    public function test_updating_a_component_does_not_require_confirmation(): void
    {
        $page = $this->createPage('quick', '通常編集');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $heading = $this->insert($page, ['type' => 'heading', 'props' => ['text' => '前', 'level' => 2]], $section);

        $result = $this->command($page->id, [
            'operation' => 'component.update', 'target' => $heading,
            'changes' => ['props' => ['text' => '後']],
        ], $this->agent());

        $this->assertSame(OperationStatus::Success, $result->status);
    }

    // 10. component 削除は MCP で確認必須
    public function test_deleting_a_component_requires_confirmation_from_an_agent(): void
    {
        $page = $this->createPage('delete-block', 'ブロック削除');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $this->insert($page, ['type' => 'heading', 'props' => ['text' => '消される見出し', 'level' => 2]], $section);

        $first = $this->command($page->id, ['operation' => 'component.delete', 'target' => $section], $this->agent());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertStringContainsString('1 個のブロックも一緒に削除されます', implode(' ', $first->confirmation->impact));

        $document = app(GetPageDocument::class)->execute($page->id, $this->admin())->data['document'];
        $this->assertNotNull(DocumentTree::find($document, $section));

        $second = $this->command($page->id, ['operation' => 'component.delete', 'target' => $section],
            $this->agent()->withConfirmationToken($first->confirmation->token));

        $this->assertSame(OperationStatus::Success, $second->status);
        $this->assertNull(DocumentTree::find($second->data['document'], $section));
    }

    // 11. page trash は MCP で確認必須
    public function test_trashing_a_page_requires_confirmation_from_an_agent(): void
    {
        $page = $this->createPage('trash-me', '削除対象');

        $first = app(TrashPage::class)->execute($page->id, $this->agent());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->assertSame('draft', $page->refresh()->status->value);

        $second = app(TrashPage::class)->execute(
            $page->id,
            $this->agent()->withConfirmationToken($first->confirmation->token),
        );

        $this->assertSame(OperationStatus::Success, $second->status);
        $this->assertSame('trashed', $page->refresh()->status->value);
    }

    // closeout: AI が作るページは必ず下書き（公開は page.publish のみ）
    public function test_a_page_created_by_an_agent_is_always_a_draft(): void
    {
        $result = $this->catalog()->invoke('page.create', [
            'title' => 'AI が作ったページ', 'slug' => 'agent-made',
        ], $this->agent());

        $this->assertSame(OperationStatus::Success, $result->status);

        $page = Page::query()->findOrFail($result->data['page']['id']);

        $this->assertSame('draft', $page->status->value);
        $this->assertNull($page->published_version_id);
        $this->assertTrue($page->isDocumentManaged());
        $this->get('/agent-made')->assertNotFound();

        $this->assertDatabaseHas('audit_logs', [
            'interface' => 'mcp', 'operation' => 'page.create',
            'classification' => 'write', 'result' => 'success', 'resource_type' => 'page',
        ]);
    }

    // closeout: 作成 → 編集 → 公開（公開だけ確認が要る）が MCP 経路で成立する
    public function test_an_agent_can_create_edit_and_publish_a_page(): void
    {
        $created = $this->catalog()->invoke('page.create', [
            'title' => 'AI のランディング', 'slug' => 'agent-landing',
        ], $this->agent());
        $pageId = (int) $created->data['page']['id'];

        $document = $this->catalog()->invoke('page.document.get', ['pageId' => $pageId], $this->agent());
        $root = (string) $document->data['document']['root']['id'];

        $patched = $this->catalog()->invoke('page.document.patch', [
            'pageId' => $pageId,
            'command' => ['operation' => 'component.insert', 'parent' => $root,
                'component' => ['type' => 'section', 'children' => [
                    ['type' => 'heading', 'props' => ['text' => 'AI が書いた見出し', 'level' => 1]],
                ]]],
        ], $this->agent());
        $this->assertSame(OperationStatus::Success, $patched->status, json_encode($patched->errors));

        // 公開は確認必須
        $first = $this->catalog()->invoke('page.publish', ['pageId' => $pageId], $this->agent());
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $this->get('/agent-landing')->assertNotFound();

        $second = app(PublishPage::class)->execute(
            $pageId,
            $this->agent()->withConfirmationToken($first->confirmation->token),
        );
        $this->assertSame(OperationStatus::Success, $second->status);

        $this->get('/agent-landing')->assertOk()->assertSee('AI が書いた見出し');
    }

    // closeout: 同じ操作を Admin から行っても同じ Application Service を通る
    public function test_admin_and_agent_page_creation_share_validation_and_audit(): void
    {
        $invalid = ['title' => '不正な slug', 'slug' => 'Invalid Slug'];

        $fromAdmin = app(CreatePage::class)->execute($invalid, $this->admin());
        $fromAgent = $this->catalog()->invoke('page.create', $invalid, $this->agent());

        $this->assertSame(OperationStatus::ValidationError, $fromAdmin->status);
        $this->assertSame(OperationStatus::ValidationError, $fromAgent->status);
        $this->assertArrayHasKey('slug', $fromAgent->errors);

        app(CreatePage::class)->execute(['title' => '管理画面から', 'slug' => 'from-admin'], $this->admin());
        $this->catalog()->invoke('page.create', ['title' => 'AI から', 'slug' => 'from-agent'], $this->agent());

        // STAGE #18A: 監査は **結末ごと**に残る。ここで見たいのは成功した作成の経路
        $interfaces = DB::table('audit_logs')->where('operation', 'page.create')->where('result', 'success')
            ->orderBy('id')->pluck('interface')->all();

        $this->assertSame(['admin', 'mcp'], $interfaces);
    }

    // ─────────────────────────────────────────────────────────────
    // F-P3 是正（Phase 9.3B / #18・SPEC §V.2-(7) PB-1〜PB-8）
    // ─────────────────────────────────────────────────────────────

    /** PB-3: 空の document は **draft では正しい**（作成できなくなってはいけない） */
    public function test_an_empty_document_is_valid_for_drafts_but_not_for_publishing(): void
    {
        $validator = app(DocumentValidator::class);
        $empty = DocumentTree::empty();

        $this->assertSame([], $validator->validate($empty), '空 draft を保存できなくなっている');

        $errors = $validator->validateForPublish($empty);

        $this->assertArrayHasKey('root', $errors);
        $this->assertStringContainsString('少なくとも 1 つブロック', $errors['root'][0]);
    }

    /** PB-7: root の子孫に登録済み component が 1 つあればよい（section だけでも renderable） */
    public function test_a_single_registered_component_makes_a_document_publishable(): void
    {
        $validator = app(DocumentValidator::class);
        $page = $this->createPage('renderable', '描画できるページ');

        // section だけ（中身は空）でも 1 node として数える
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);
        $this->assertSame([], $validator->validateForPublish($this->draftDocument($page)));

        // 入れ子だけに component がある場合も renderable
        $nested = $this->createPage('nested', '入れ子のページ');
        $wrapper = $this->insert($nested, ['type' => 'section', 'children' => []]);
        $this->insert($nested, ['type' => 'heading', 'props' => ['text' => '見出し', 'level' => 2]], $wrapper);

        $this->assertSame([], $validator->validateForPublish($this->draftDocument($nested)));
        $this->assertNotSame('', $section);
    }

    /** 未登録 node は「何かある」ではなく**構造エラー**として返る（renderable と数えない） */
    public function test_unknown_components_still_fail_structural_validation_on_publish(): void
    {
        $validator = app(DocumentValidator::class);
        $document = DocumentTree::empty();
        $document['root']['children'] = [[
            'id' => NodeId::generate(),
            'type' => 'not-a-registered-component',
            'props' => [],
            'children' => [],
        ]];

        $errors = $validator->validateForPublish($document);

        $this->assertNotSame([], $errors);
        $this->assertSame(
            [],
            array_filter(
                $errors,
                static fn (array $messages): bool => str_contains(implode('', $messages), '少なくとも 1 つブロック'),
            ),
            '構造エラーではなく空エラーを返している',
        );
    }

    /** PB-4 / PB-5: 空 document の publish は Admin / API / MCP すべてで validation error */
    public function test_publishing_an_empty_page_fails_on_every_interface(): void
    {
        $page = $this->createPage('empty-page', '空のページ');
        $before = $this->publishState($page->id);

        $contexts = [
            'admin' => $this->admin(),
            'api' => OperationContext::api($this->operatorId(), 'tester'),
            'mcp' => $this->agent(),
        ];

        foreach ($contexts as $label => $context) {
            $result = app(PublishPage::class)->execute($page->id, $context);

            // 確認ダイアログではなく **入力の誤り**として返る（確認より先に検証する）
            $this->assertSame(OperationStatus::ValidationError, $result->status, "{$label} が公開できてしまった");
            $this->assertArrayHasKey('root', $result->errors);
            $this->assertNull($result->confirmation, "{$label} が確認要求になっている");
        }

        // 失敗しても公開状態・draft・監査は 1 つも変わらない
        $this->assertSame($before, $this->publishState($page->id));
    }

    /** 空へ戻した document も draft としては保存でき、公開だけが止まる */
    public function test_a_page_can_be_emptied_again_as_a_draft(): void
    {
        $page = $this->createPage('emptied', '空に戻すページ');
        $section = $this->insert($page, ['type' => 'section', 'children' => []]);

        // 削除は破壊的操作なので確認を経る（既存 semantics を変えない）
        $first = $this->command($page->id, ['operation' => 'component.delete', 'target' => $section]);
        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);

        $removed = $this->command(
            $page->id,
            ['operation' => 'component.delete', 'target' => $section],
            $this->admin()->withConfirmationToken($first->confirmation?->token),
        );

        $this->assertSame(OperationStatus::Success, $removed->status, json_encode($removed->errors));
        $this->assertSame([], app(DocumentValidator::class)->validate($this->draftDocument($page)));

        $publish = app(PublishPage::class)->execute($page->id, $this->admin());
        $this->assertSame(OperationStatus::ValidationError, $publish->status);
    }

    /** publish 専用検証を使うのは PublishPage だけ（PB-2） */
    public function test_only_publish_page_uses_the_publish_validation(): void
    {
        $consumers = [];

        foreach ([
            'core/src/Application/Pages/CreatePage.php',
            'core/src/Application/Pages/PatchPageDocument.php',
            'core/src/Application/Pages/PublishPage.php',
            'core/src/Application/Pages/TrashPage.php',
            'core/src/Document/DocumentTree.php',
        ] as $file) {
            if (str_contains((string) file_get_contents(base_path($file)), 'validateForPublish')) {
                $consumers[] = $file;
            }
        }

        $this->assertSame(['core/src/Application/Pages/PublishPage.php'], $consumers);
    }

    /** @return array<string, mixed> 公開状態と履歴のスナップショット */
    private function publishState(int $pageId): array
    {
        $page = Page::query()->findOrFail($pageId);

        return [
            'status' => $page->status->value,
            'published_version_id' => $page->published_version_id,
            'published_at' => $page->published_at?->toIso8601String(),
            'draft_version_id' => $page->draft_version_id,
            'versions' => PageVersion::query()->where('page_id', $pageId)->count(),
            'document' => json_encode($this->draftDocument($page)),
            'publish_audits' => DB::table('audit_logs')
                ->where('operation', 'page.publish')->where('result', 'success')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function draftDocument(Page $page): array
    {
        $version = PageVersion::query()->findOrFail($page->refresh()->draft_version_id);

        /** @var array<string, mixed> $document */
        $document = $version->document;

        return $document;
    }

    // ─────────────────────────────────────────────────────────────
    // F-P1 是正（Phase 9.3B / #17・SPEC §V.2-(6) MB-1〜MB-7）
    // ─────────────────────────────────────────────────────────────

    /** MB-2 / MB-3 / MB-5 / MB-6: どの経路でも `managedBy` は受け付けない */
    public function test_managed_by_is_rejected_on_every_interface(): void
    {
        $contexts = [
            'admin' => $this->admin(),
            'api' => OperationContext::api($this->operatorId(), 'tester'),
            'mcp' => $this->agent(),
            'cli' => OperationContext::cli('artisan'),
        ];

        foreach ($contexts as $label => $context) {
            foreach ([Page::MANAGED_BY_BLADE, Page::MANAGED_BY_DOCUMENT] as $value) {
                $result = app(CreatePage::class)->execute(
                    ['title' => 'ページ', 'slug' => "p-{$label}", 'managedBy' => $value],
                    $context,
                );

                $this->assertSame(
                    OperationStatus::ValidationError,
                    $result->status,
                    "{$label} が managedBy={$value} を受理した",
                );
                $this->assertArrayHasKey('managedBy', $result->errors);
            }
        }

        // MB-6: どの interface でも 1 件も作られない
        $this->assertSame(0, Page::query()->where('slug', 'like', 'p-%')->count());
    }

    /** 別名や未知キーでも黙って無視せず拒否する（正しい title/slug と混ぜても全体を拒否） */
    public function test_aliases_and_unknown_keys_are_rejected_without_creating_a_page(): void
    {
        $before = $this->pageCounts();

        foreach ([
            'managed_by' => ['title' => 'ページ', 'slug' => 'alias-snake', 'managed_by' => Page::MANAGED_BY_BLADE],
            'managedby' => ['title' => 'ページ', 'slug' => 'alias-lower', 'managedby' => Page::MANAGED_BY_BLADE],
            'status' => ['title' => 'ページ', 'slug' => 'unknown-status', 'status' => 'published'],
            'whatever' => ['title' => 'ページ', 'slug' => 'unknown-key', 'whatever' => 1],
        ] as $key => $input) {
            $result = app(CreatePage::class)->execute($input, $this->admin());

            $this->assertSame(OperationStatus::ValidationError, $result->status, "{$key} を受理した");
            $this->assertArrayHasKey($key, $result->errors, "{$key} が送信されたキー名で返らない");
            // 入力値をそのまま返さない
            $this->assertStringNotContainsString(
                Page::MANAGED_BY_BLADE,
                json_encode($result->errors, JSON_UNESCAPED_UNICODE) ?: '',
            );
        }

        // 検証失敗では pages / page_versions / audit_logs が 1 行も変わらない
        $this->assertSame($before, $this->pageCounts());
    }

    /** MB-1 / MB-4: 正常な contract ならどの経路でも document + draft */
    public function test_pages_are_always_document_managed_drafts(): void
    {
        $created = [
            'admin' => app(CreatePage::class)
                ->execute(['title' => '管理画面', 'slug' => 'ok-admin'], $this->admin()),
            'api' => app(CreatePage::class)
                ->execute(['title' => 'API', 'slug' => 'ok-api'], OperationContext::api($this->operatorId(), 'tester')),
            'cli' => app(CreatePage::class)
                ->execute(['title' => 'CLI', 'slug' => 'ok-cli'], OperationContext::cli('artisan')),
            'mcp' => $this->catalog()
                ->invoke('page.create', ['title' => 'MCP', 'slug' => 'ok-mcp'], $this->agent()),
        ];

        foreach ($created as $label => $result) {
            $this->assertSame(OperationStatus::Success, $result->status, "{$label} が失敗した");
        }

        foreach (['ok-admin', 'ok-api', 'ok-cli', 'ok-mcp'] as $slug) {
            $page = Page::query()->where('slug', $slug)->sole();

            $this->assertSame(Page::MANAGED_BY_DOCUMENT, $page->managed_by);
            $this->assertSame(PageStatus::Draft->value, $page->status->value);
            // 作成直後は空の document version が 1 件
            $this->assertSame(1, PageVersion::query()->where('page_id', $page->id)->count());
        }
    }

    /** OperationCatalog が入力を組み直して禁止キーを捨てない（黙って無視しない） */
    public function test_catalog_passes_input_through_to_the_application_service(): void
    {
        $before = $this->pageCounts();

        $rejected = $this->catalog()->invoke(
            'page.create',
            ['title' => 'AI から', 'slug' => 'agent-managed', 'managedBy' => Page::MANAGED_BY_BLADE],
            $this->agent(),
        );

        $this->assertSame(OperationStatus::ValidationError, $rejected->status);
        $this->assertArrayHasKey('managedBy', $rejected->errors);

        $unknown = $this->catalog()->invoke(
            'page.create',
            ['title' => 'AI から', 'slug' => 'agent-unknown', 'nope' => true],
            $this->agent(),
        );

        $this->assertSame(OperationStatus::ValidationError, $unknown->status);
        $this->assertArrayHasKey('nope', $unknown->errors);

        $this->assertSame($before, $this->pageCounts());
    }

    /** MB-7: blade 管理 Page は内部コード経路では引き続き作れる（読み取り・fallback も維持） */
    public function test_blade_pages_remain_creatable_through_internal_code_paths(): void
    {
        $page = Page::query()->create([
            'title' => 'Blade 管理',
            'slug' => 'blade-managed',
            'status' => PageStatus::Draft->value,
            'managed_by' => Page::MANAGED_BY_BLADE,
        ]);

        $this->assertSame(Page::MANAGED_BY_BLADE, $page->refresh()->managed_by);
        $this->assertNotNull(Page::query()->where('managed_by', Page::MANAGED_BY_BLADE)->first());
    }

    /** @return array<string, int> */
    private function pageCounts(): array
    {
        return [
            'pages' => Page::query()->count(),
            'page_versions' => PageVersion::query()->count(),
            // 成功した作成が 1 件も無いことを見る（拒否の記録は別の結末として残る）
            'page_create_audits' => DB::table('audit_logs')
                ->where('operation', 'page.create')->where('result', 'success')->count(),
        ];
    }

    // 12. 物理削除は AI へ公開しない
    public function test_page_purge_is_not_exposed_to_agents(): void
    {
        foreach (['page.purge', 'page.delete', 'page.forceDelete'] as $forbidden) {
            $this->assertFalse(OperationCatalog::allows($forbidden));
        }

        $this->assertTrue(OperationCatalog::allows('page.trash'));
    }
}
