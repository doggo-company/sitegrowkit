<?php

declare(strict_types=1);

namespace Tests\Feature\Forms;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Forms\Form;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Render\RenderContext;
use AgentCms\Core\Settings\SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/** ページ内に置いても既存フォームの保存・公開状態・安全策が変わらない。 */
final class EmbeddedFormTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        app(SettingsStore::class)->put(['contact_email' => '']);
        config(['agentcms.turnstile.site_key' => '', 'agentcms.turnstile.secret_key' => '']);
        $group = (string) Str::ulid();
        DB::table('field_groups')->insert([
            'id' => $group, 'key' => 'embedded-fields', 'label' => 'お問い合わせ項目',
            'sort_order' => 0, 'status' => 'published',
            'fields' => json_encode([['id' => (string) Str::ulid(), 'key' => 'name', 'type' => 'text', 'label' => 'お名前', 'required' => true]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('field_group_locations')->insert(['field_group_id' => $group, 'kind' => 'form', 'value' => 'embedded-contact']);
        DB::table('forms')->insert([
            'key' => 'embedded-contact', 'name' => 'お問い合わせ', 'status' => 'published',
            'field_group_id' => $group, 'confirm_step' => false, 'submit_label' => '送信する', 'lock_version' => 0,
        ]);

        // 通常の web middleware を通し、CSRF と Blade のエラー情報も実画面と同じにする。
        Route::middleware('web')->get('/embedded-check', function () {
            $html = app(DocumentRenderer::class)->render($this->document(), new RenderContext(new Page, [], preview: request()->boolean('preview')));

            return view('public.document', ['document' => $html, 'themeCss' => '', 'title' => '配置確認']);
        });
    }

    public function test_two_instances_have_unique_labels_and_use_existing_post_route(): void
    {
        $html = (string) $this->get('/embedded-check')->assertOk()->getContent();
        $this->assertStringContainsString('action="'.url('/forms/embedded-contact').'"', $html);
        foreach (['first-form', 'second-form'] as $id) {
            $this->assertStringContainsString('for="embed-'.$id.'-f-name"', $html);
        }
        preg_match_all('/\bid="([^"]+)"/', $html, $matches);
        $this->assertSame(count($matches[1]), count(array_unique($matches[1])));

        // 通知の実際の失敗を起こし、表示だけでなく受信本文が残ることを見る。
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('test smtp unavailable'));
        $this->post('/forms/embedded-contact', ['name' => '受付テスト'])->assertRedirect('/forms/embedded-contact/complete');
        $this->assertSame('受付テスト', FormSubmission::query()->firstOrFail()->values()['name']);
        $this->assertNotNull(FormSubmission::query()->firstOrFail()->admin_notification_error);
    }

    public function test_書き出しではweb専用のフォームを描画しない(): void
    {
        // HTTP middlewareなしで公開フォームを含むdocumentを描画しても、CSRFやerrorsを要求しない。
        $html = app(DocumentRenderer::class)->render($this->document(), new RenderContext(new Page, [], export: true))->toHtml();
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('turnstile', $html);
    }

    public function test_editor_offers_form_names_in_both_palette_and_inspector(): void
    {
        $result = app(CreatePage::class)->execute(
            ['title' => 'フォーム配置', 'slug' => 'embed-editor'],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );
        $this->assertTrue($result->isSuccess());
        $url = '/admin/pages/'.$result->data['page']['id'].'/editor';
        $response = $this->actingAs($this->operator())->get($url)->assertOk();
        foreach (['components', 'specs'] as $prop) {
            $specs = collect($response->inertiaProps($prop));
            $form = $specs->firstWhere('type', 'form');
            $this->assertSame([
                ['value' => 'embedded-contact', 'label' => 'お問い合わせ（受付中）'],
            ], $form['propsSchema']['formKey']['options']);
        }
        Form::query()->update(['status' => 'draft']);
        $form = collect($this->get($url)->inertiaProps('specs'))->firstWhere('type', 'form');
        $this->assertSame([], $form['propsSchema']['formKey']['options']);
    }

    public function test_preview_disables_submission_and_does_not_load_turnstile(): void
    {
        config(['agentcms.turnstile.site_key' => 'test-public-site-key']);
        $html = (string) $this->get('/embedded-check?preview=1')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, '<fieldset disabled'));
        $this->assertStringNotContainsString('turnstile/v0/api.js', $html);
    }

    public function test_turnstile_script_is_loaded_once_for_multiple_forms(): void
    {
        config(['agentcms.turnstile.site_key' => 'test-public-site-key']);
        $html = (string) $this->get('/embedded-check')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'turnstile/v0/api.js'));
        $this->assertSame(2, substr_count($html, 'class="cf-turnstile"'));
    }

    public function test_drafts_and_invalid_fields_are_not_exposed_and_closed_forms_are_not_submittable(): void
    {
        Form::query()->update(['status' => 'draft']);
        $this->get('/embedded-check')->assertOk()->assertDontSee('name="name"', false)->assertDontSee('<h2>お問い合わせ</h2>', false);
        Form::query()->update(['status' => 'closed']);
        $this->get('/embedded-check')->assertOk()->assertSee('受付を終了しました')->assertDontSee('<form ', false);
        Form::query()->update(['status' => 'published', 'field_group_id' => null]);
        $this->get('/embedded-check')->assertOk()->assertDontSee('<form ', false);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return ['root' => ['type' => 'page', 'id' => 'root', 'children' => [[
            'type' => 'section', 'id' => 'section', 'children' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'form', 'props' => ['formKey' => 'embedded-contact']],
                ['first-form', 'second-form'],
            ),
        ]]]];
    }
}
