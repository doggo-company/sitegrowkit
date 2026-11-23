<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Document\NodeId;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use App\Models\User;
use App\Support\Document\PairRows;
use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * カフェのメニュー表と営業時間のブロック（UX 一周 2026-09-28）。
 *
 * 第 4 周（カフェ）で、メニューと営業時間を載せるのに、見出しと文章を 1 行ずつ積むしかなかった。
 * 置けば表の形で出るブロックにする。**見本の値は「何を書く場所か」と〇〇の空欄だけ**
 * （店のことを言い切らない — ファクトチェックのルール）。
 */
final class MenuAndHoursBlocksTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    public function test_2つのブロックが登録されている(): void
    {
        $registry = app(ComponentRegistry::class);

        $this->assertSame('メニュー表', $registry->get('menutable')->label);
        $this->assertSame('営業時間', $registry->get('hours')->label);
    }

    public function test_見本の値は空欄だけで店のことを言い切らない(): void
    {
        foreach (['menutable', 'hours'] as $type) {
            foreach (app(ComponentRegistry::class)->get($type)->propsSchema as $key => $spec) {
                $default = (string) ($spec['default'] ?? '');

                // 具体的な数字（値段・時刻）を入れない。書き換える場所は〇〇で示す
                $this->assertDoesNotMatchRegularExpression('/[0-9０-９]/u', $default, "{$type}.{$key} の見本に数字が入っている");
            }
        }

        $this->assertStringContainsString('〇〇', (string) app(ComponentRegistry::class)->get('menutable')->propsSchema['items']['default']);
        $this->assertStringContainsString('〇〇', (string) app(ComponentRegistry::class)->get('hours')->propsSchema['rows']['default']);
    }

    /** メニュー表の題は既定で空。ページの見出し「メニュー」と 2 回並んでいた（UX 一周 第12周） */
    public function test_メニュー表の題は既定で出さない(): void
    {
        $this->assertSame('', app(ComponentRegistry::class)->get('menutable')->propsSchema['caption']['default']);

        $html = $this->render($this->node('menutable', ['caption' => '', 'items' => 'ブレンド｜480円']));
        $this->assertStringNotContainsString('<caption', $html);
    }

    /** 定休日だけ見本のまま公開しても、公開前チェックが止める（「〇曜日」は拾えていなかった） */
    public function test_定休日の見本が残っていれば公開前チェックが止める(): void
    {
        $closed = (string) app(ComponentRegistry::class)->get('hours')->propsSchema['closed']['default'];
        $this->assertNotSame([], PublicationReadiness::placeholdersIn('<td>'.$closed.'</td>'));
        $this->assertContains('〇曜日', PublicationReadiness::placeholdersIn('<td>〇曜日</td>'));
    }

    public function test_〇〇が残っていれば公開前チェックが止める(): void
    {
        $this->assertContains('〇〇', PublicationReadiness::placeholdersIn('<td>〇〇円</td>'));
        $this->assertSame([], PublicationReadiness::placeholdersIn('<td>480円</td>'));
    }

    public function test_行は区切りで分けて区切りの無い行も捨てない(): void
    {
        $this->assertSame(
            [['ブレンド', '480円'], ['カフェラテ', '520円'], ['季節のケーキ', ''], ['紅茶', '450円']],
            PairRows::parse("ブレンド｜480円\n\nカフェラテ|520円\n季節のケーキ\n紅茶\t450円\n"),
        );
    }

    public function test_メニュー表は表として描き中身はエスケープする(): void
    {
        $html = $this->render($this->node('menutable', [
            'caption' => 'ドリンク',
            'items' => "ブレンド｜480円\n<script>alert(1)</script>｜0円",
            'note' => '',
        ]));

        $this->assertStringContainsString('<caption class="doc-pairs__caption">ドリンク</caption>', $html);
        $this->assertStringContainsString('<tr><th scope="row">ブレンド</th><td>480円</td></tr>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // 注意書きが空なら段落ごと出さない
        $this->assertStringNotContainsString('doc-pairs__note', $html);
    }

    public function test_営業時間は定休日の行を足し空なら出さない(): void
    {
        $with = $this->render($this->node('hours', ['caption' => '営業時間', 'rows' => '月〜金｜9:00〜17:00', 'closed' => '水曜日', 'note' => 'ラストオーダーは閉店の30分前']));

        $this->assertStringContainsString('<th scope="row">月〜金</th><td>9:00〜17:00</td>', $with);
        $this->assertStringContainsString('<th scope="row">定休日</th><td>水曜日</td>', $with);
        $this->assertStringContainsString('ラストオーダーは閉店の30分前', $with);

        $without = $this->render($this->node('hours', ['caption' => '営業時間', 'rows' => '毎日｜9:00〜17:00', 'closed' => '']));
        $this->assertStringNotContainsString('定休日', $without);
    }

    public function test_見本のままの値で置いても文書として通る(): void
    {
        foreach (['menutable', 'hours'] as $type) {
            $props = [];
            foreach (app(ComponentRegistry::class)->get($type)->propsSchema as $key => $spec) {
                $props[$key] = $spec['default'] ?? '';
            }

            $errors = app(DocumentValidator::class)->validate($this->document($this->node($type, $props)));
            $this->assertSame([], $errors, "{$type}: ".json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_ページの編集画面のパレットに見本の値つきで出る(): void
    {
        $page = $this->page();

        $components = $this->actingAs(User::factory()->create())
            ->get("/admin/pages/{$page->id}/editor")
            ->assertOk()
            ->viewData('page')['props']['components'];

        $byType = array_column($components, null, 'type');
        $this->assertArrayHasKey('menutable', $byType);
        $this->assertArrayHasKey('hours', $byType);
        $this->assertStringContainsString('〇〇', (string) $byType['menutable']['defaults']['props']['items']);
        // 書き方の案内が画面へ渡る
        $this->assertNotSame('', (string) ($byType['menutable']['propsSchema']['items']['help'] ?? ''));
    }

    private function page(): Page
    {
        $result = app(CreatePage::class)->execute(['title' => 'メニュー', 'slug' => 'menu-'.strtolower(Str::random(6))], OperationContext::admin($this->operatorId(), 'tester'));

        return Page::query()->findOrFail($result->data['page']['id']);
    }

    /** @param array<string, mixed> $props */
    private function node(string $type, array $props): array
    {
        return ['id' => NodeId::generate(), 'type' => $type, 'props' => $props, 'children' => []];
    }

    private function document(array $child): array
    {
        return [
            'schemaVersion' => 1,
            'root' => ['id' => NodeId::generate(), 'type' => 'page', 'props' => [], 'children' => [
                ['id' => NodeId::generate(), 'type' => 'section', 'props' => [], 'children' => [$child]],
            ]],
        ];
    }

    private function render(array $node): string
    {
        return (string) app(DocumentRenderer::class)->render($this->document($node), new RenderContext($this->page(), []));
    }
}
