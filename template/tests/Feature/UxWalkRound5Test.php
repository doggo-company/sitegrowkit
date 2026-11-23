<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * UX 一周 第 5 周（個人の陶芸作家の目・2026-09-28）で見つけたものの回帰。
 */
final class UxWalkRound5Test extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function pageWithButton(string $slug, string $href): void
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => 'トップ', 'slug' => $slug], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);

        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [
                ['type' => 'heading', 'props' => ['text' => '陶房つちのわ', 'level' => 1]],
                ['type' => 'button', 'props' => ['label' => '作品を見る', 'href' => $href]],
            ]],
        ], $context);
        app(PublishPage::class)->execute($page->id, $context);
    }

    /** 🔴 これが本体。押すと開けないボタンを公開前チェックが言う */
    public function test_ページの中の開けないリンクを公開前チェックが言う(): void
    {
        $this->pageWithButton('tsuchinowa', '/works-typo');

        $broken = array_values(array_filter(
            app(PublicationReadiness::class)->check(),
            fn (array $f): bool => str_contains($f['what'], 'リンク先が開けません'),
        ));

        $this->assertCount(1, $broken);
        $this->assertSame('/tsuchinowa', $broken[0]['where']);
        $this->assertStringContainsString('/works-typo', $broken[0]['what']);
    }

    public function test_開けるリンクは言わない(): void
    {
        $this->pageWithButton('tsuchinowa', '/contact');

        $broken = array_filter(
            app(PublicationReadiness::class)->check(),
            fn (array $f): bool => str_contains($f['what'], 'リンク先が開けません'),
        );

        $this->assertSame([], array_values($broken));
    }

    public function test_サイト内のリンクだけを拾う(): void
    {
        $html = '<a href="/gallery">作品</a><a href="https://example.org/x">外</a><a href="/storage/a.png">画像</a>'
            .'<a href="/admin/pages">管理</a><a href="#top">上へ</a><a href="mailto:a@b.jp">メール</a><a href="/gallery?x=1">同じ</a>';

        $this->assertSame(['/gallery'], PublicationReadiness::internalLinksIn($html));
    }
}
