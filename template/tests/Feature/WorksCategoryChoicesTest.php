<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * Phase 9.3B / #24 — works.category の選択肢（SPEC §U.4 / V-1〜V-6・F-T1）。
 *
 * ## この Stage で決めたこと
 *
 * 同梱 schema から **`Branding` と `Web` だけ**を外した。
 * `branding` / `Branding`、`web` / `Web` は Admin の選択肢として **区別がつかず**、
 * どちらを選んでも同じ意味に見えるのに別の値として保存される。
 *
 * **既存の値は 1 つも書き換えない。** 開発機に残っている `Branding` / `Web` の行は、
 * 追跡対象外の project override（`content/schemas/overrides/works.json`）で
 * 有効なまま保つ。値の小文字化や canonical への寄せは **9.3C の判断**であり、
 * ここでは決めない。
 */
final class WorksCategoryChoicesTest extends TestCase
{
    use RefreshDatabase;

    /** 配布する同梱 schema の選択肢（標準 3 値 + import 由来 5 値） */
    private const DISTRIBUTED = [
        'web', 'graphic', 'branding',
        'Corporate', 'EC', 'LP', 'Package', 'Service',
    ];

    public function test_同梱schemaの選択肢はちょうど8値(): void
    {
        $raw = $this->baseChoices();

        $this->assertSame(self::DISTRIBUTED, $raw);
    }

    public function test_同梱schemaにcasefold重複が無い(): void
    {
        $raw = $this->baseChoices();

        $this->assertSame(count($raw), count(array_unique($raw)));
        $this->assertSame(
            count($raw),
            count(array_unique(array_map('mb_strtolower', $raw))),
            '大文字小文字だけが違う選択肢が残っている',
        );
    }

    public function test_categoryはselect_requiredのままで分類宣言も無い(): void
    {
        $works = app(SchemaRegistry::class)->get('works');
        $category = $works->field('category');

        $this->assertSame('select', $category->type);
        $this->assertTrue($category->required);
        $this->assertSame([], $works->taxonomies, 'この Stage で taxonomy 化しない');
    }

    /**
     * 開発機の override が **足し算として**効く。
     *
     * base から外した `Branding` / `Web` を、既存の行を書き換えずに有効なまま保つ仕組み。
     * override が無い環境（＝利用者の新規 project）では 8 値のままになる。
     */
    public function test_project_overrideは選択肢を足すだけで削らない(): void
    {
        $category = app(SchemaRegistry::class)->get('works')->field('category');
        $base = $this->baseChoices();

        // 先頭は同梱 schema のまま（削除も並べ替えもされない）
        $this->assertSame($base, array_slice($category->choices, 0, count($base)));

        if (! file_exists(base_path('content/schemas/overrides/works.json'))) {
            // override が無い環境（利用者 project と同じ形）では base がそのまま効く
            $this->assertSame($base, $category->choices);

            return;
        }

        // 開発機では外した 2 値が override 経由で有効
        $this->assertContains('Branding', $category->choices);
        $this->assertContains('Web', $category->choices);
    }

    /**
     * base から外した表記の行を **1 つも書き換えない**。
     *
     * schema を変えたからといって DB を正規化してよいことにはならない。
     * 表示文字列が黙って変わるのは、利用者から見れば別の壊れ方になる。
     * ここでは実際に `Branding` / `Web` の行を作り、schema を読んだあとも
     * **値も件数もそのまま**であることを見る。
     */
    public function test_baseから外した表記の行を書き換えない(): void
    {
        foreach (['Branding', 'Web'] as $i => $category) {
            Work::query()->create([
                'title' => "作品 {$category}",
                'slug' => 'work-'.mb_strtolower($category),
                'category' => $category,
                'body' => '<p>本文</p>',
                'status' => 'published',
                'published_at' => now()->subDays($i),
            ]);
        }

        $before = Work::query()->orderBy('id')->pluck('category')->all();
        $this->assertSame(['Branding', 'Web'], $before);

        // schema を読み直しても DB は変わらない（正規化も除去も起きない）
        app(SchemaRegistry::class)->get('works')->field('category');

        $this->assertSame(['Branding', 'Web'], Work::query()->orderBy('id')->pluck('category')->all());
        $this->assertSame(2, Work::query()->count());
    }

    /** @return list<string> */
    private function baseChoices(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = json_decode(
            (string) file_get_contents(base_path('content/schemas/works.json')),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );

        foreach ($raw['fields'] as $field) {
            if ($field['key'] === 'category') {
                /** @var list<string> $choices */
                $choices = $field['choices'];

                return $choices;
            }
        }

        $this->fail('works.json に category がありません');
    }
}
