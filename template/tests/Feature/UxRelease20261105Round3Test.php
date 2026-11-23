<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Document\AccessibilityCheck;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Pages\SlugFromTitle;
use App\Models\User;
use App\Support\Industry\IndustryProfiles;
use App\Support\Industry\IndustrySetup;
use App\Support\Role;
use Database\Seeders\ContactFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開前の UX 一周 3 周目（2026-11-05・整骨院の受付の人の目・rc7）で迷ったところの回帰。
 *
 * - M2: 業種に教室・医院/整骨院/サロンが無く、「その他」で「会社名・屋号」と出ていた
 * - M3: 決まった言葉の無い日本語の題（施術のご案内）の URL が `/page` になっていた
 * - N3: 置いたばかりの見出し・本文に「見出し」「リッチテキスト」が**値として**入り、打つとくっついた
 *
 * M11・N4（保存の知らせが画面外）と N5（「画像を選択」が画面外）は画面の位置の話なので
 * `tests/e2e/ux-round3.spec.ts` が見る。
 */
final class UxRelease20261105Round3Test extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    /** @return array<string, string> key => label */
    private function contactLabels(): array
    {
        $form = Form::query()->where('key', 'contact')->sole();
        $fields = json_decode((string) DB::table('field_groups')->where('id', $form->field_group_id)->value('fields'), true);

        return array_column($fields, 'label', 'key');
    }

    // ── M2 ──

    public function test_業種に教室と医院整骨院サロンがあり選べる(): void
    {
        $labels = array_column(IndustryProfiles::options(), 'label', 'key');

        $this->assertSame('教室・スクール', $labels['school'] ?? null);
        $this->assertSame('医院・整骨院・サロン', $labels['clinic'] ?? null);
        // 「その他」は最後のまま
        $this->assertSame('other', array_key_last($labels));

        $this->seed(ContactFormSeeder::class);
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'clinic'])->assertRedirect();

        $fields = $this->contactLabels();
        $this->assertArrayNotHasKey('company', $fields, '整骨院のお問い合わせに会社名の欄が残っている');
        $this->assertSame('ご希望の日時', $fields['preferred_date'] ?? null);
        $this->assertSame(
            ['初めての方のご予約', '2回目以降のご予約', 'ご相談', 'その他'],
            collect(json_decode((string) DB::table('field_groups')->value('fields'), true))->firstWhere('key', 'subject')['choices'],
        );
    }

    public function test_教室を選ぶと習う方の年齢の欄が出て会社名は出ない(): void
    {
        $this->seed(ContactFormSeeder::class);
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'school'])->assertRedirect();

        $fields = $this->contactLabels();
        $this->assertSame('習う方の年齢・学年', $fields['student_age'] ?? null);
        $this->assertArrayNotHasKey('company', $fields);
    }

    /** 「その他」は会社を前提にしない。9 周目からは会社・屋号の欄そのものを置かない（UX 一周 9 周目 M2） */
    public function test_その他は会社名を前提にしない(): void
    {
        $this->seed(ContactFormSeeder::class);
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'other'])->assertRedirect();

        $this->assertArrayNotHasKey('company', $this->contactLabels());
        // 税理士・会計事務所は会社名のまま（お客さんが会社のことが多い）
        $this->assertSame('会社名・屋号', collect(IndustrySetup::contactFields(IndustryProfiles::get('zeirishi')))->firstWhere('key', 'company')['label']);
    }

    /** 前の版で「その他」を選んだ店（会社名・屋号のまま）も、選び直せば業種の形に替わる */
    public function test_前の版のその他の形も手を入れていない形として選び直せる(): void
    {
        $this->seed(ContactFormSeeder::class);
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'other'])->assertRedirect();

        // rc7 までの「その他」の形に戻す（会社名・屋号の欄があり、ご用件は「ご相談／その他」）
        foreach (IndustryProfiles::legacyOtherShapes() as $legacy) {
            $form = Form::query()->where('key', 'contact')->sole();
            $legacyProfile = [...IndustryProfiles::get('other'), ...$legacy, 'companyLabel' => '会社名・屋号'];
            DB::table('field_groups')->where('id', $form->field_group_id)->update(['fields' => json_encode(IndustrySetup::contactFields($legacyProfile), JSON_UNESCAPED_UNICODE)]);
            $form->forceFill(['description' => $legacy['contactDescription']])->save();
        }

        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'clinic'])->assertRedirect();

        $this->assertArrayHasKey('preferred_date', $this->contactLabels(), '前の版の形を「手を入れた」とみなして替えなかった');
    }

    // ── M3 ──

    public function test_決まった言葉の無い日本語の題もローマ字のurlになる(): void
    {
        // 漢字を含む題もサーバーが形態素解析で読む（19 周目）
        $this->assertSame('shijutsu-no-go-annai', SlugFromTitle::draft('施術のご案内'));
        $this->assertSame('pan-no-go', SlugFromTitle::draft('パンのご')); // setup の読み（reading-slug.mjs）と同じ決まり
        // カタカナ語は英語の表記（20 周目 R2）
        $this->assertSame('taiken-lesson', SlugFromTitle::draft('体験 レッスン'));
        // 題の頭のひらがなは助詞として切らない
        $this->assertSame('hajimeteno-lesson', SlugFromTitle::draft('はじめてのレッスン'));
        // 定番の名前は今までどおり英語
        $this->assertSame('company', SlugFromTitle::draft('会社概要'));
    }

    public function test_urlを空のまま作るとpageに落ちずに重なれば番号が付く(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)->post('/admin/pages', ['title' => '施術のご案内'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/pages', ['title' => '施術のご案内'])->assertRedirect();
        // 自分で URL を書いたら、それを使う
        $this->actingAs($admin)->post('/admin/pages', ['title' => '施術のご案内', 'slug' => 'treatment'])->assertRedirect();

        $this->assertSame(
            ['shijutsu-no-go-annai', 'shijutsu-no-go-annai-2', 'treatment'],
            Page::query()->orderBy('id')->pluck('slug')->all(),
        );
    }

    // ── N3 ──

    public function test_置いたばかりの見出しテキスト本文は値が空でパレットにも仮の文字を入れない(): void
    {
        $page = app(CreatePage::class)->execute(['title' => '仮の文字', 'slug' => 'placeholder'], OperationContext::admin($this->operatorId(), 'fixture'));
        $components = (array) $this->actingAs(User::query()->findOrFail($this->operatorId()))
            ->get("/admin/pages/{$page->data['page']['id']}/editor")
            ->inertiaProps('components');
        $byType = array_column($components, null, 'type');

        $this->assertArrayNotHasKey('text', $byType['heading']['defaults']['props'], '見出しに「見出し」を値として入れている');
        $this->assertArrayNotHasKey('text', $byType['text']['defaults']['props']);
        $this->assertArrayNotHasKey('html', $byType['richtext']['defaults']['props'], '本文に「リッチテキスト」を値として入れている');
        // ボタンの文言は値のまま（空だと公開面に名前の無いリンクができる）
        $this->assertSame('ボタン', $byType['button']['defaults']['props']['label'] ?? null);
    }

    public function test_空の見出しと本文は編集画面だけに仮の文字が出て公開面には出ない(): void
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => '仮の文字', 'slug' => 'placeholder'], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);

        $patched = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [
                ['type' => 'heading', 'props' => ['level' => 1]],
                ['type' => 'text', 'props' => []],
                ['type' => 'richtext', 'props' => []],
            ]],
        ], $context);
        $this->assertTrue($patched->isSuccess(), json_encode($patched->errors, JSON_UNESCAPED_UNICODE));

        $admin = User::query()->findOrFail($this->operatorId());
        $canvas = (string) $this->actingAs($admin)->get("/admin/pages/{$page->id}/preview?canvas=1")->assertOk()->getContent();

        // blade の文言は resources/js/lib/prop-placeholder.ts（右の欄の placeholder）と同じ
        $this->assertStringContainsString('見出しを入力', $canvas);
        $this->assertStringContainsString('テキストを入力', $canvas);
        $this->assertStringContainsString('本文を入力', $canvas);
        $this->assertStringContainsString('doc-placeholder', $canvas);

        // 置いた直後に注意を出さない（空の見出しは公開面に描かないので、読み上げの問題にならない）
        $rules = array_column(app(AccessibilityCheck::class)->inspect($patched->data['document']), 'rule');
        $this->assertNotContains('heading-empty', $rules);

        $this->assertTrue(app(PublishPage::class)->execute($page->id, $context)->isSuccess());
        $public = (string) $this->get('/placeholder')->assertOk()->getContent();

        $this->assertStringNotContainsString('見出しを入力', $public, '仮の文字が公開面に出ている');
        $this->assertStringNotContainsString('本文を入力', $public);
        $this->assertStringNotContainsString('doc-placeholder', $public);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*class="doc-heading[^"]*"[^>]*>\s*<\/h1>/', $public, '空の見出しを公開面に描いている');
    }
}
