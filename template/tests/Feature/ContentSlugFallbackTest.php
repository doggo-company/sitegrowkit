<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 日本語のタイトルでも、読める URL にする（WP 一周 2 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * `Str::slug('秋の庭しごとのご案内')` は**空文字**を返す。空のときは
 * `Str::lower(Str::random(8))` へ落ちていたので、URL が `mf55bgrs` になっていた。
 *
 * 画面には「**空のままにすると見出しから自動で作られます**」と書いてある。
 * 日本語で書く人にとっては、**案内文と結果が食い違っていた**。
 *
 * ## なぜ日付にしたか
 *
 * ローマ字にするには、かな漢字を読みへ変える辞書が要る。辞書を積んでも
 * 「生」が「なま」か「せい」かは文脈で変わるので、**必ず外す**。
 * 外した読みが URL になって配られると、あとから直しても 404 を撒く。
 *
 * 日付なら **意味を持たないが、並びが分かり、口で言える**。
 * `2026-09-03-1` は「その日の 1 本目」と読めて、あとから直せる。
 */
final class ContentSlugFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function create(string $title, ?string $slug = null): void
    {
        $payload = ['title' => $title, 'body' => '本文', 'status' => 'draft'];

        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        $this->actingAs($this->admin())
            ->post('/admin/news', $payload)
            ->assertSessionHasNoErrors();
    }

    public function test_日本語だけの見出しは日付から作る(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');

        $this->create('秋の庭しごとのご案内');

        $this->assertSame('2026-09-03-1', DB::table('news')->value('slug'));
    }

    public function test_同じ日の2本目は連番になる(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');

        $this->create('ひとつめ');
        $this->create('ふたつめ');

        $this->assertSame(
            ['2026-09-03-1', '2026-09-03-2'],
            DB::table('news')->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_英数字が入っていれば今までどおり見出しから作る(): void
    {
        $this->create('Autumn Garden Work');

        $this->assertSame('autumn-garden-work', DB::table('news')->value('slug'));
    }

    public function test_自分で入れた_url_は変えない(): void
    {
        $this->create('秋の庭しごとのご案内', 'aki-no-niwa');

        $this->assertSame('aki-no-niwa', DB::table('news')->value('slug'));
    }

    /** **意味を持たない乱数は、もう出さない** */
    public function test_乱数のような_url_にはしない(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');

        $this->create('日本語だけ');

        $this->assertDoesNotMatchRegularExpression(
            '/^[a-z0-9]{8}$/',
            (string) DB::table('news')->value('slug'),
            '乱数の URL に落ちている',
        );
    }

    /** 画面の案内文が、実際の動きと合っていること（食い違いを残さない） */
    public function test_案内文が実際の動きと合っている(): void
    {
        $help = '';

        foreach (app(SchemaRegistry::class)->get('news')->fields as $field) {
            if ($field->key === 'slug') {
                $help = (string) $field->help;
            }
        }

        $this->assertNotSame('', $help, 'slug の案内文が無い');
        $this->assertStringContainsString('日付', $help, '日付から作ることが案内文に書かれていない');
    }
}
