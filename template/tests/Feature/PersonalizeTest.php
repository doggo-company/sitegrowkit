<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Application\Users\UserGuard;
use App\Models\User;
use App\Support\Capability;
use App\Support\Industry\IndustryProfiles;
use App\Support\Role;
use Database\Seeders\ContactFormSeeder;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 「究極のパーソナライズ CMS」の 3 点（2026-09-28・メンテナの方針）。
 *
 * 1. ページも、サイトの枠（見本・取り込んだテーマ）に載る
 * 2. 最初に業種を聞き、お問い合わせの項目・見本の文章をその業種にする
 * 3. 「投稿」の呼び名を業種に合わせ、作りの画面は制作者のメニューにだけ出す
 */
final class PersonalizeTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]);
    }

    private function publishedPage(string $slug): void
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => '料金', 'slug' => $slug], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'heading', 'props' => ['text' => '料金のご案内', 'level' => 1]]]],
        ], $context);
        app(PublishPage::class)->execute($page->id, $context);
    }

    // ── 1. 枠 ──

    public function test_ページはサイトの枠に載り見本の断り書きは出さない(): void
    {
        $this->publishedPage('price');

        $this->get('/price')->assertOk()
            ->assertSee('imported/css/style.css', false)
            ->assertSee('class="page-title doc-band"', false)
            ->assertSee('料金のご案内')
            ->assertDontSee('同梱のサンプルサイト');
    }

    // ── 2. 業種の入口 ──

    public function test_業種を選ぶとお問い合わせの項目と説明がその業種になる(): void
    {
        $this->seed(ContactFormSeeder::class);

        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'trimming'])->assertRedirect();

        $form = Form::query()->where('key', 'contact')->firstOrFail();
        $fields = json_decode((string) DB::table('field_groups')->where('id', $form->field_group_id)->value('fields'), true);

        $this->assertSame(IndustryProfiles::get('trimming')['contactDescription'], $form->description);
        $this->assertContains('pet_name', array_column($fields, 'key'));
        $this->assertNotContains('company', array_column($fields, 'key'));
        $this->app?->forgetScopedInstances();
        $this->get('/contact')->assertOk()->assertSee('犬種・猫種');
    }

    public function test_手を入れたフォームは業種を選んでも変えない(): void
    {
        $this->seed(ContactFormSeeder::class);
        Form::query()->where('key', 'contact')->update(['description' => '当院へのご予約はこちら']);

        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'cafe'])->assertRedirect();

        $this->assertSame('当院へのご予約はこちら', Form::query()->where('key', 'contact')->value('description'));
    }

    public function test_選び直すと業種で入れた形は次の業種に替わる(): void
    {
        $this->seed(ContactFormSeeder::class);
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'trimming']);
        $this->actingAs($owner)->post('/admin/industry', ['industry' => 'koumuten']);

        $this->assertSame(IndustryProfiles::get('koumuten')['contactDescription'], Form::query()->where('key', 'contact')->value('description'));
    }

    public function test_業種の見本の文章は事実を言い切らない(): void
    {
        // 見本は「何を書く場所か」を案内する文と、〇〇の空欄だけで作る（ファクトチェックのルール）
        foreach (IndustryProfiles::all() as $key => $profile) {
            foreach ($profile['serviceRows'] as [, $price]) {
                $this->assertDoesNotMatchRegularExpression('/\d/', $price, "{$key} の見本に具体的な数字が入っている");
            }
        }

        app(SettingsStore::class)->put([IndustryProfiles::SETTING_KEY => 'koumuten']);
        $this->seed(TemplateContentSeeder::class);
        $this->get('/about')->assertOk()->assertSee('工務店・リフォーム')->assertDontSee('デザインスタジオ');
    }

    /** 見本トップのボタンは業種の言葉にする。工務店で「料金プランを見る」と出ていた（UX 一周 2026-09-28） */
    public function test_見本トップのボタンの文言が業種に合う(): void
    {
        $this->seed(TemplateContentSeeder::class);

        // 業種を選ぶ前は、これまでどおり
        $this->get('/')->assertOk()->assertSee('>相談する</a>', false)->assertSee('>料金プランを見る</a>', false);

        app(SettingsStore::class)->put([IndustryProfiles::SETTING_KEY => 'koumuten']);
        $this->get('/')->assertOk()
            ->assertSee('>家づくりを相談する</a>', false)
            // 2 つ目は /service の見出し（業種の呼び名）と同じ言葉
            ->assertSee('>家づくりの流れを見る</a>', false)
            ->assertDontSee('料金プランを見る');

        foreach (IndustryProfiles::all() as $key => $profile) {
            $this->assertNotSame('', $profile['ctaLabel'], "{$key} のボタンの文言が空");
            // 店のことを言い切らない（数字や「無料」などを入れない）
            $this->assertDoesNotMatchRegularExpression('/\d|無料|即日|No\.?1|一番/u', $profile['ctaLabel'], "{$key} のボタンが店のことを言い切っている");
        }
    }

    /** 記事の呼び名に「メニュー」を使わない。管理画面では「メニュー」はナビの画面の名前（UX 一周 第12周） */
    public function test_記事の呼び名が管理画面のメニューとぶつからない(): void
    {
        foreach (IndustryProfiles::all() as $key => $profile) {
            $this->assertStringNotContainsString('メニュー', $profile['postLabel'], "{$key} の記事の呼び名が「メニュー」（ナビの画面）とぶつかる");
        }
    }

    /**
     * 生成直後（キャッチコピーも説明も空）の見本トップが、緑の帯にボタンだけになっていた。
     * 業種の見出し（〇〇の空欄）・どこに書けば出るかの案内・写真を入れる場所を出す（UX 一周 2026-09-28）
     */
    public function test_見本トップは設定が空でも見出しと案内と写真の場所を出す(): void
    {
        $this->seed(TemplateContentSeeder::class);
        app(SettingsStore::class)->put(['tagline' => '', 'seo_description' => '', 'hero_text' => '', IndustryProfiles::SETTING_KEY => 'koumuten']);

        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<h1>〇〇（地域）の工務店</h1>', $html);
        $this->assertStringContainsString('class="hero-guide"', $html);
        $this->assertStringContainsString('class="hero-photo-slot"', $html);

        // 入れたら、入れたものが出る（案内は消える）
        app(SettingsStore::class)->put(['tagline' => '地元の木で、長く住める家を', 'seo_description' => '木造の注文住宅の工務店です。']);
        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<h1>地元の木で、長く住める家を</h1>', $html);
        $this->assertStringContainsString('木造の注文住宅の工務店です。', $html);
        $this->assertStringNotContainsString('class="hero-guide"', $html);

        foreach (IndustryProfiles::all() as $key => $profile) {
            $this->assertStringContainsString('〇〇', $profile['heroTitle'], "{$key} の見出しに空欄が無い（店のことを言い切っている）");
            $this->assertDoesNotMatchRegularExpression('/\d/', $profile['heroTitle']);
        }
    }

    public function test_知らない業種は受け付けない(): void
    {
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'casino'])->assertSessionHasErrors('industry');
    }

    public function test_設定を触れない人には業種の入口を出さない(): void
    {
        $editor = User::factory()->create(['role' => Role::Editor->value, 'email_verified_at' => now()]);

        $this->actingAs($editor)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('industry', null));
        $this->actingAs($editor)->post('/admin/industry', ['industry' => 'cafe'])->assertForbidden();
    }

    // ── 3. 呼び名とサイドバー ──

    public function test_業種で投稿の呼び名が変わる(): void
    {
        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'koumuten']);
        $this->app?->forgetScopedInstances();

        $this->assertSame('施工事例', app(ContentTypeSettingsResolver::class)->for('post')->displayOverrides['label'] ?? null);
    }

    public function test_自分で付けた呼び名は業種を選んでも変えない(): void
    {
        app(ContentTypeSettingsWriter::class)->save(
            'post', ['display_overrides' => json_encode(['label' => 'ブログ'], JSON_UNESCAPED_UNICODE)], 0,
        );

        $this->actingAs($this->owner())->post('/admin/industry', ['industry' => 'koumuten']);
        $this->app?->forgetScopedInstances();

        $this->assertSame('ブログ', app(ContentTypeSettingsResolver::class)->for('post')->displayOverrides['label'] ?? null);
    }

    public function test_作りの画面は制作者のメニューにだけ出る(): void
    {
        $this->assertNotContains(Capability::SiteBuild, Role::Owner->capabilities());
        $this->assertContains(Capability::SiteBuild, Role::Developer->capabilities());

        $developer = User::factory()->create(['role' => Role::Developer->value, 'email_verified_at' => now()]);
        $this->actingAs($developer)->get('/dashboard')->assertInertia(
            fn (Assert $p) => $p->where('auth.capabilities', fn ($caps) => in_array('site.build', (array) collect($caps)->all(), true)),
        );
    }

    public function test_制作者がいれば最後のオーナーを制作者に替えられる(): void
    {
        // 全権限の人が 1 人は残ること（制作者もオーナーと同じ重みで数える）
        $guard = app(UserGuard::class);
        $owner = $this->owner();

        $this->assertTrue($guard->isLastActiveOwner($owner));

        User::factory()->create(['role' => Role::Developer->value]);

        $this->assertFalse($guard->isLastActiveOwner($owner));
    }

    public function test_記事の一覧と詳細もサイトの枠に載る(): void
    {
        $this->actingAs($this->owner())->post('/admin/content-types/post/quick-start');
        $this->actingAs($this->owner())->post('/admin/post', ['title' => 'Spring Campaign', 'body' => '本文', 'status' => 'published']);
        $this->app?->forgetScopedInstances();

        $this->get('/blog')->assertOk()
            ->assertSee('imported/css/style.css', false)
            ->assertSee('<title>投稿 | ', false);
        $this->get('/blog/spring-campaign')->assertOk()->assertSee('imported/css/style.css', false);
    }

    public function test_本文の最初の見出しがページの題と同じなら帯を出さない(): void
    {
        $context = OperationContext::admin($this->operatorId(), 'fixture');
        $result = app(CreatePage::class)->execute(['title' => 'メニュー', 'slug' => 'menu-page'], $context);
        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => (string) $version->document['root']['id'],
            'component' => ['type' => 'section', 'children' => [['type' => 'heading', 'props' => ['text' => 'メニュー', 'level' => 1]]]],
        ], $context);
        app(PublishPage::class)->execute($page->id, $context);

        $html = (string) $this->get('/menu-page')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="page-title doc-band"', $html);
        $this->assertSame(1, substr_count($html, 'class="doc-heading'));
    }

    public function test_サイトの枠ではボタンの色を枠の色に合わせる(): void
    {
        $this->publishedPage('price');

        $this->get('/price')->assertOk()->assertSee('--color-primary: var(--green,', false);
    }
}
