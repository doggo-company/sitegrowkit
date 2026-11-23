<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy;

use AgentCms\Core\Taxonomy\TermLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 分類の項目を **id から引く**（WP 一周 7 周目 2026-09-03 / 効くもの ③）。
 *
 * メニューが分類の一覧を指せるようにするための読み手である。
 *
 * ## なぜ綴りではなく id で持つか
 *
 * 綴り（`term-1`）は**あとから変えられる**。URL を直接指定で持つと、
 * 変えた瞬間にメニューが黙って 404 になる。
 * `menu_items` は URL を持たない設計なので、ここもそれに従って id で引く。
 *
 * ## 出さないもの
 *
 * 無効にした分類・アーカイブ済みの項目は `null`。
 * 公開面と同じ条件にしておかないと、**メニューにだけ生きているリンクが残る**。
 */
final class MenuTermTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_idから名前と綴りと分類のキーを引く(): void
    {
        $ids = $this->taxonomyWithTerm();

        $found = app(TermLink::class)->find($ids['term']);

        $this->assertNotNull($found);
        $this->assertSame('予防接種', $found['name']);
        $this->assertSame('term-1', $found['slug']);
        $this->assertSame('shinryo_kamoku', $found['taxonomyKey']);
    }

    public function test_無い項目はnull(): void
    {
        $this->assertNull(app(TermLink::class)->find((string) Str::ulid()));
    }

    /** 🔴 公開面と同じ条件にする（メニューにだけ生きたリンクを残さない） */
    public function test_アーカイブした項目はnull(): void
    {
        $ids = $this->taxonomyWithTerm();

        DB::table('taxonomy_terms')->where('id', $ids['term'])->update(['archived_at' => now()]);

        $this->assertNull(app(TermLink::class)->find($ids['term']));
    }

    public function test_無効にした分類はnull(): void
    {
        $ids = $this->taxonomyWithTerm();

        DB::table('taxonomies')->where('id', $ids['taxonomy'])->update(['status' => 'disabled']);

        $this->assertNull(app(TermLink::class)->find($ids['term']));
    }

    /** @return array{taxonomy: string, term: string} */
    private function taxonomyWithTerm(): array
    {
        $taxonomyId = (string) Str::ulid();
        $termId = (string) Str::ulid();

        DB::table('taxonomies')->insert([
            'id' => $taxonomyId,
            'key' => 'shinryo_kamoku',
            'label' => '診療科目',
            'hierarchical' => false,
            'status' => 'active',
            'managed_by' => 'cms',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'id' => $termId,
            'taxonomy_id' => $taxonomyId,
            'name' => '予防接種',
            'slug' => 'term-1',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['taxonomy' => $taxonomyId, 'term' => $termId];
    }
}
