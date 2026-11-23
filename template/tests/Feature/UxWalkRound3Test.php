<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\FieldGroups\UpdateFieldGroup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use Database\Seeders\ContactFormSeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * UX 一周 第 3 周（税理士事務所の所長の目・2026-09-28）で見つけたものの回帰。
 */
final class UxWalkRound3Test extends TestCase
{
    use RefreshDatabase;

    private ?User $actor = null;

    private function context(): OperationContext
    {
        app()->forgetScopedInstances();
        $this->actor ??= User::factory()->create();

        return OperationContext::admin((int) $this->actor->getKey(), 'fixture');
    }

    /**
     * 画面が送るのと同じ形（ID の無い行は ID を送らない）で、件名の選択肢だけを変える。
     *
     * @return array<string, mixed>
     */
    private function payloadWithSubjectChoices(array $choices): array
    {
        $group = DB::table('field_groups')->where('key', 'contact_form')->first();
        $fields = json_decode((string) $group->fields, true);

        return [
            'key' => 'contact_form',
            'label' => 'お問い合わせ',
            'locations' => [['kind' => 'form', 'value' => 'contact']],
            'fields' => array_map(fn (array $f): array => [
                'key' => $f['key'],
                'type' => $f['type'],
                'label' => $f['label'],
                'required' => (bool) ($f['required'] ?? false),
                'help' => '',
                'choices' => $f['key'] === 'subject' ? $choices : ($f['choices'] ?? []),
            ], $fields),
        ];
    }

    /** 🔴 これが本体。選択肢を変えただけで「全項目が消える」とは言わない */
    public function test_同梱フォームの選択肢を変えても項目が消えるとは言わない(): void
    {
        $this->seed(ContactFormSeeder::class);
        $id = (string) DB::table('field_groups')->where('key', 'contact_form')->value('id');

        $context = $this->context();
        $first = app(UpdateFieldGroup::class)->execute(
            $id,
            $this->payloadWithSubjectChoices(['お見積もり', 'ご相談', 'その他', '記帳代行']),
            $context,
        );

        // 選択肢を足すだけなら、確認も要らない
        $this->assertSame(OperationStatus::Success, $first->status, implode(' / ', $first->confirmation->impact ?? []));
    }

    public function test_選択肢を消すときは消える選択肢だけを言う(): void
    {
        $this->seed(ContactFormSeeder::class);
        $id = (string) DB::table('field_groups')->where('key', 'contact_form')->value('id');

        $context = $this->context();
        $first = app(UpdateFieldGroup::class)->execute(
            $id,
            $this->payloadWithSubjectChoices(['記帳代行', '確定申告', 'その他']),
            $context,
        );

        $this->assertSame(OperationStatus::ConfirmationRequired, $first->status);
        $impact = implode("\n", $first->confirmation->impact);
        $this->assertStringNotContainsString('画面に出なくなります', $impact);
        $this->assertStringNotContainsString('埋め戻し', $impact);
        $this->assertStringContainsString('お見積もり', $impact);
    }

    private function publishedFront(): int
    {
        $context = OperationContext::admin((int) ($this->actor ??= User::factory()->create())->getKey(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => 'トップページ', 'slug' => 'home'], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'heading', 'props' => ['text' => 'やまびこ', 'level' => 1]]]],
        ], $context);
        app(PublishPage::class)->execute($page->id, $context);
        app(SettingsStore::class)->put([
            'site_name' => 'やまびこ税理士事務所',
            'tagline' => '身近な相談相手',
            'tel' => '042-600-1234',
            FrontPage::SETTING_KEY => (int) $page->getKey(),
        ]);

        return (int) $page->getKey();
    }

    public function test_トップに選んだページはタブと検索結果でページの題を名乗らない(): void
    {
        $this->publishedFront();

        $this->get('/')->assertOk()->assertSee('<title>やまびこ税理士事務所 | 身近な相談相手</title>', false);
    }

    public function test_標準の枠のフッターにも電話番号が出る(): void
    {
        $this->publishedFront();

        $this->get('/')->assertOk()->assertSee('href="tel:0426001234"', false)->assertSee('042-600-1234');
    }

    public function test_メニューに足す項目の初期値にトップのページを使わない(): void
    {
        $this->seed(MenuSeeder::class);
        $id = $this->publishedFront();

        $this->actingAs($this->actor)
            ->get('/admin/menus/header')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('choices.pages', fn ($pages) => collect($pages)->firstWhere('id', $id)['isFront'] === true));
    }
}
