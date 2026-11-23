<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\ContactEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * kit の標準の画面（サイト内検索・フォームの入力画面）も、店の枠に載せる（UX 一周 2026-09-28）。
 *
 * 見本の意匠をやめたフォームとサイト内検索だけが kit 既定の素朴な枠（`layouts.public`）で描かれ、
 * ロゴ・ナビ・ボタンの色が店の枠と違って、そこだけ別のサイトに飛んだように見えていた。
 */
final class SiteFrameStandardViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_サイト内検索は店の枠に載る(): void
    {
        $html = (string) $this->get('/_search?q=test')->assertOk()->getContent();

        // 店の枠（見本）のヘッダーとフッター
        $this->assertStringContainsString('id="site-header"', $html);
        $this->assertStringContainsString('id="site-footer"', $html);
        // kit 既定の枠のヘッダーではない
        $this->assertStringNotContainsString('class="site-header"', $html);
        // 題の帯と、kit の部品の見た目・枠の主な色
        $this->assertStringContainsString('class="page-title"', $html);
        $this->assertStringContainsString('/forms.css', $html);
        $this->assertStringContainsString('--color-primary: var(--green,', $html);
        // 検索結果は検索エンジンに載せない（枠が替わっても落とさない）
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        // 題にサイト名が 1 回だけ付く
        $this->assertMatchesRegularExpression('#<title>サイト内検索 \| [^|<]+</title>#u', $html);
    }

    public function test_見本の意匠をやめたフォームも店の枠に載る(): void
    {
        $form = $this->form('yoyaku');
        app(SettingsStore::class)->put([ContactEntry::SAMPLE_VIEW_OFF_SETTING => true]);

        $html = (string) $this->get("/forms/{$form->key}")->assertOk()->getContent();

        $this->assertStringContainsString('id="site-header"', $html);
        $this->assertStringNotContainsString('class="site-header"', $html);
        $this->assertStringContainsString('class="page-title"', $html);
        $this->assertStringContainsString('/forms.css', $html);
        $this->assertStringContainsString('class="form-page__submit"', $html);
        // 店の人のフォームには、見本の断り書きを出さない
        $this->assertStringNotContainsString('同梱のサンプルサイトです', $html);
        // フォームの題はこれまでどおり「サイト名 | フォーム名」（PublicFormLayout）
        $this->assertMatchesRegularExpression('#<title>[^|<]+ \| ご予約</title>#u', $html);
    }

    public function test_フォームの部品の見た目は店の枠でも当たる(): void
    {
        $css = (string) file_get_contents(public_path('forms.css'));

        foreach (['.form-field__label', '.form-field__required', '.form-page__fields .form-page__submit', '.site-search button'] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} が forms.css に無い");
        }

        // 確認画面の送信（枠の .btn に任せる）を上書きしない
        $this->assertDoesNotMatchRegularExpression('/^\.form-page__submit\s*\{[^}]*background/m', $css);
    }

    public function test_店の枠のフッターにsnsとサイト内検索が出る(): void
    {
        app(SettingsStore::class)->put(['instagram_url' => '@tsuchinowa_pottery']);

        $html = (string) $this->get('/about')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://www.instagram.com/tsuchinowa_pottery"', $html);
        $this->assertStringContainsString('href="'.route('site.search').'"', $html);
    }

    /** 送ったあと（完了画面）も、入力画面と同じ題の帯を出す（UX 一周 第10周） */
    public function test_完了画面も店の枠では題の帯を出す(): void
    {
        $form = $this->form('yoyaku');
        $render = fn (string $layout): string => view('forms.complete', [
            'layout' => $layout, 'title' => 'ご予約', 'form' => $form, 'message' => '送信しました。',
        ])->render();

        $onSite = $render('layouts.imported');
        $this->assertStringContainsString('<section class="page-title">', $onSite);
        $this->assertSame(1, substr_count($onSite, '<h1>ご予約</h1>'), '見出しが 2 回出ている');

        // kit 既定の枠では帯を出さず、これまでどおり本文の見出し
        $plain = $render('layouts.public');
        $this->assertStringNotContainsString('<section class="page-title">', $plain);
        $this->assertSame(1, substr_count($plain, '<h1>ご予約</h1>'));
    }

    private function form(string $key): Form
    {
        $groupId = (string) Str::ulid();

        DB::table('field_groups')->insert([
            'id' => $groupId,
            'key' => 'form_'.strtolower(substr($groupId, -6)),
            'label' => 'フォームの項目',
            'sort_order' => 0,
            'status' => 'published',
            'fields' => json_encode([
                ['id' => (string) Str::ulid(), 'key' => 'field_name', 'type' => 'text', 'label' => 'お名前', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_group_locations')->insert([
            'field_group_id' => $groupId,
            'kind' => 'form',
            'value' => $key,
        ]);

        $id = DB::table('forms')->insertGetId([
            'key' => $key,
            'name' => 'ご予約',
            'field_group_id' => $groupId,
            'status' => 'published',
            'confirm_step' => false,
            'submit_label' => '送信',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Form::query()->findOrFail($id);
    }
}
