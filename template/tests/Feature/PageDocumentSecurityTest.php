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
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Document\DocumentTree;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Html\RestrictedHtml;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * Phase 9.1D: 検証・安全性・共存の受け入れ（owner 指定 13〜20）。
 */
final class PageDocumentSecurityTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoContentSeeder::class);
    }

    private function admin(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'tester');
    }

    private function agent(): OperationContext
    {
        return OperationContext::agent(OperationInterface::Mcp, 'test-agent');
    }

    private function page(string $slug = 'sec'): Page
    {
        $result = app(CreatePage::class)->execute(['title' => '検証', 'slug' => $slug], $this->admin());

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** validator を直接叩くための document 組み立て */
    private function document(array ...$children): array
    {
        return [
            'schemaVersion' => 1,
            'root' => ['id' => NodeId::generate(), 'type' => 'page', 'props' => [], 'children' => $children],
        ];
    }

    private function node(string $type, array $extra = []): array
    {
        return array_merge(['id' => NodeId::generate(), 'type' => $type, 'props' => [], 'children' => []], $extra);
    }

    // 13. Restricted HTML sanitizer
    public function test_restricted_html_removes_scripts_handlers_and_dangerous_urls(): void
    {
        $sanitizer = app(RestrictedHtml::class);

        $payloads = [
            '<script>alert(1)</script>' => 'script',
            '<div onclick="alert(1)">x</div>' => 'onclick',
            '<a href="javascript:alert(1)">x</a>' => 'javascript:',
            '<a href="data:text/html,<script>alert(1)</script>">x</a>' => 'data:text/html',
            '<iframe src="https://evil.example"></iframe>' => 'iframe',
            '<div style="position:fixed;top:0">x</div>' => 'style=',
            '<img src=x onerror="alert(1)">' => 'onerror',
            '<style>body{display:none}</style>' => '<style',
            '<object data="x.swf"></object>' => 'object',
            '<form action="/x"><input name="a"></form>' => '<form',
        ];

        foreach ($payloads as $input => $mustNotAppear) {
            $clean = $sanitizer->clean($input);
            $this->assertStringNotContainsStringIgnoringCase($mustNotAppear, $clean, "危険な内容が残りました: {$input}");
        }

        // 通常の版面は残る
        $this->assertStringContainsString('<strong>', $sanitizer->clean('<p><strong>大事</strong></p>'));
        $this->assertStringContainsString('<td>', $sanitizer->clean('<table><tr><td>1</td></tr></table>'));

        // ページの骨組みは HTML ではなく component で作る（構造要素は落とす）
        $this->assertStringNotContainsString('<section', $sanitizer->clean('<section><p>x</p></section>'));
    }

    public function test_html_component_output_is_sanitized_on_the_public_page(): void
    {
        $page = $this->page('html-block');
        $section = $this->insertNode($page, ['type' => 'section', 'children' => []], null);
        $this->insertNode($page, [
            'type' => 'html',
            'props' => ['html' => '<p>安全な内容</p><script>alert(1)</script>'],
        ], $section);

        app(PublishPage::class)->execute($page->id, $this->admin());
        $html = $this->get('/html-block')->assertOk()->getContent();

        $this->assertStringContainsString('安全な内容', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    // 14. 未登録の component type は拒否
    public function test_an_unregistered_component_type_is_rejected(): void
    {
        $errors = app(DocumentValidator::class)->validate(
            $this->document($this->node('section', ['children' => [$this->node('evil-widget')]])),
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('登録されていないブロック種別', implode(' ', array_merge(...array_values($errors))));
    }

    public function test_the_registry_never_resolves_an_arbitrary_view_path(): void
    {
        $registry = app(ComponentRegistry::class);

        // 描画先は registry に登録されたものだけ。type から任意のパスにならない
        foreach ($registry->all() as $definition) {
            $this->assertStringStartsWith('components.document.', $definition->view);
        }

        $this->assertFalse($registry->has('../../etc/passwd'));
        $this->assertFalse($registry->has('AgentCms\\Core\\Pages\\Page'));
    }

    // 15. node id の重複を拒否
    public function test_a_duplicate_node_id_is_rejected(): void
    {
        $id = NodeId::generate();
        $document = $this->document(
            $this->node('section', ['id' => $id, 'children' => [
                $this->node('heading', ['id' => $id, 'props' => ['text' => 'x', 'level' => 2]]),
            ]]),
        );

        $errors = app(DocumentValidator::class)->validate($document);

        $this->assertStringContainsString('重複', implode(' ', array_merge(...array_values($errors))));
    }

    public function test_a_duplicate_key_is_rejected_but_key_is_not_identity(): void
    {
        $document = $this->document(
            $this->node('section', ['key' => 'dup', 'children' => [
                $this->node('heading', ['key' => 'dup', 'props' => ['text' => 'x', 'level' => 2]]),
            ]]),
        );

        $this->assertNotEmpty(app(DocumentValidator::class)->validate($document));

        // key が無くても document は妥当（identity は id）
        $ok = $this->document($this->node('section', ['children' => [
            $this->node('heading', ['props' => ['text' => 'x', 'level' => 2]]),
        ]]));

        $this->assertSame([], app(DocumentValidator::class)->validate($ok));
    }

    // 16. 不正な binding を拒否
    public function test_an_invalid_binding_is_rejected(): void
    {
        $validator = app(DocumentValidator::class);

        $unknownType = $this->document($this->node('section', ['children' => [
            $this->node('collection', [
                'props' => ['layout' => 'list'],
                'binding' => ['source' => 'collection', 'type' => 'not-a-type'],
            ]),
        ]]));
        $this->assertStringContainsString('コンテンツタイプ', implode(' ', array_merge(...array_values($validator->validate($unknownType)))));

        $unknownField = $this->document($this->node('section', ['children' => [
            $this->node('collection', [
                'props' => ['layout' => 'list'],
                'binding' => ['source' => 'collection', 'type' => 'news',
                    'sort' => [['field' => 'nope', 'direction' => 'desc']]],
            ]),
        ]]));
        $this->assertNotEmpty($validator->validate($unknownField));
    }

    public function test_dangerous_urls_and_unknown_props_are_rejected(): void
    {
        $validator = app(DocumentValidator::class);

        $badUrl = $this->document($this->node('section', ['children' => [
            $this->node('button', ['props' => ['label' => 'x', 'href' => 'javascript:alert(1)']]),
        ]]));
        $this->assertNotEmpty($validator->validate($badUrl));

        $unknownProp = $this->document($this->node('section', ['children' => [
            $this->node('heading', ['props' => ['text' => 'x', 'level' => 2, 'onclick' => 'alert(1)']]),
        ]]));
        $this->assertNotEmpty($validator->validate($unknownProp));

        $badStyle = $this->document($this->node('section', ['children' => [
            $this->node('heading', ['props' => ['text' => 'x', 'level' => 2], 'style' => ['position' => 'fixed']]),
        ]]));
        $this->assertNotEmpty($validator->validate($badStyle));
    }

    // 17. subtree 取得（AI の token 消費を抑える）
    public function test_an_agent_can_fetch_only_a_subtree(): void
    {
        $page = $this->page('subtree');
        $section = $this->insertNode($page, ['type' => 'section', 'key' => 'hero', 'children' => []], null);
        $this->insertNode($page, ['type' => 'heading', 'props' => ['text' => '見出し', 'level' => 2]], $section);
        $other = $this->insertNode($page, ['type' => 'section', 'children' => []], null);
        $this->insertNode($page, ['type' => 'text', 'props' => ['text' => '別セクション']], $other);

        $whole = app(GetPageDocument::class)->execute($page->id, $this->agent());
        $subtree = app(GetPageDocument::class)->execute($page->id, $this->agent(), from: $section);
        $shallow = app(GetPageDocument::class)->execute($page->id, $this->agent(), depth: 0);

        $wholeSize = strlen((string) json_encode($whole->data['document']));
        $subtreeSize = strlen((string) json_encode($subtree->data['document']));

        $this->assertLessThan($wholeSize, $subtreeSize);
        $subtreeJson = (string) json_encode($subtree->data['document'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('見出し', $subtreeJson);
        $this->assertStringNotContainsString('別セクション', $subtreeJson);

        // depth=0 は子を返さず件数だけ返す
        $this->assertSame([], $shallow->data['document']['root']['children']);
        $this->assertSame(2, $shallow->data['document']['root']['childCount']);

        // key でも引ける（identity ではないが指せる）
        $byKey = app(GetPageDocument::class)->execute($page->id, $this->agent(), from: 'hero');
        $this->assertSame($section, $byKey->data['document']['root']['id']);
    }

    // 18. Admin と MCP が同じ Application Service を通る
    public function test_admin_and_mcp_go_through_the_same_application_service(): void
    {
        $page = $this->page('parity');
        $section = $this->insertNode($page, ['type' => 'section', 'children' => []], null);

        // 同じ不正入力に、経路が違っても同じ検証結果
        $command = ['operation' => 'component.insert', 'parent' => $section,
            'component' => ['type' => 'heading', 'props' => ['level' => 9]]];

        $fromAdmin = app(PatchPageDocument::class)->execute($page->id, $command, $this->admin());
        $fromAgent = app(PatchPageDocument::class)->execute($page->id, $command, $this->agent());

        $this->assertSame(OperationStatus::ValidationError, $fromAdmin->status);
        $this->assertSame(OperationStatus::ValidationError, $fromAgent->status);
    }

    // 19. 監査に本文全文を残さない
    public function test_the_audit_log_does_not_store_page_content(): void
    {
        $page = $this->page('audit');
        $section = $this->insertNode($page, ['type' => 'section', 'children' => []], null);
        $bodyText = 'これは監査に残ってはいけない本文です';

        $this->insertNode($page, ['type' => 'text', 'props' => ['text' => $bodyText]], $section);

        $rows = DB::table('audit_logs')->where('operation', 'page.document.patch')->pluck('metadata');

        $this->assertNotEmpty($rows);

        foreach ($rows as $metadata) {
            $this->assertStringNotContainsString($bodyText, (string) $metadata);
        }

        // 変更の要約は残っている
        $this->assertStringContainsString('component.insert', (string) $rows->last());
    }

    // 20. 既存の Blade 管理ページは無変更
    public function test_existing_blade_managed_pages_are_untouched(): void
    {
        // 既存の公開ページはそのまま動く
        $this->get('/')->assertOk();
        $this->get('/news')->assertOk();
        $this->get('/works')->assertOk();
        $this->get('/contact')->assertOk();

        // Blade 管理として登録したページは document 編集を受け付けない
        $bladePage = Page::query()->create([
            'title' => '会社概要（Blade）', 'slug' => 'legacy',
            'status' => 'published', 'managed_by' => Page::MANAGED_BY_BLADE,
        ]);

        $result = app(PatchPageDocument::class)->execute($bladePage->id, [
            'operation' => 'component.insert', 'parent' => 'page',
            'component' => ['type' => 'heading', 'props' => ['text' => 'x', 'level' => 2]],
        ], $this->admin());

        $this->assertSame(OperationStatus::Conflict, $result->status);

        // 公開 route も document 側に奪われない
        $this->get('/legacy')->assertNotFound();
    }

    /** @param array<string, mixed> $component */
    private function insertNode(Page $page, array $component, ?string $parent): string
    {
        $version = PageVersion::query()->findOrFail($page->refresh()->draft_version_id);
        $root = (string) $version->document['root']['id'];

        $result = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $parent ?? $root,
            'component' => $component,
        ], $this->admin());

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));

        $node = DocumentTree::find($result->data['document'], $parent ?? $root);
        $children = $node['children'];

        return (string) end($children)['id'];
    }
}
