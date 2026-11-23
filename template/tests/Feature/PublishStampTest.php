<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * 「公開」を選んだら、公開サイトに出る（WP 一周 2 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * 状態を「公開」にしても、公開日時が空だと**公開サイトには出ない**
 * （`HasContentSchema::scopePublished()` が `published_at` を要求する）。
 * 管理画面には「公開」バッジが出て、公開日時の列は「—」。**警告は何も出ない。**
 * 書いた人は出したつもりで、サイトには何も無い。
 *
 * WordPress は「公開」を押した時点で日時が入る。**押した人の意図と結果を揃える。**
 *
 * ## 触るのは空のときだけ
 *
 * 日時を自分で入れた人のものは**絶対に書き換えない**。予約投稿（未来の日時）も
 * そのまま残す。部分更新で日時に触れていないときも、既存の値をそのまま残す。
 */
final class PublishStampTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_公開を選んで日時を空にすると今の時刻が入る(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/news', [
                'title' => '出したつもり',
                'body' => '本文',
                'status' => 'published',
                'published_at' => '',
            ])
            ->assertSessionHasNoErrors();

        $at = DB::table('news')->value('published_at');

        $this->assertNotNull($at, '公開なのに日時が空のまま（サイトに出ない）');
        $this->assertTrue(
            Carbon::parse((string) $at)->diffInMinutes(now()) < 2,
            "入った日時が今ではない: {$at}",
        );
    }

    /** **公開面に実際に出ること**まで見る（日時が入っただけでは足りない） */
    public function test_公開を選んだものは公開面の数に入る(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/news', [
                'title' => '出したつもり',
                'body' => '本文',
                'status' => 'published',
                'published_at' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            News::query()->published()->count(),
            '公開にしたのに公開面へ出ていない',
        );
    }

    public function test_自分で入れた日時は書き換えない(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/news', [
                'title' => '予約',
                'body' => '本文',
                'status' => 'published',
                'published_at' => '2030-01-01 09:00:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith(
            '2030-01-01',
            (string) DB::table('news')->value('published_at'),
            '自分で入れた日時を書き換えている',
        );
    }

    public function test_下書きには日時を入れない(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/news', [
                'title' => '下書き',
                'body' => '本文',
                'status' => 'draft',
                'published_at' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(
            DB::table('news')->value('published_at'),
            '下書きなのに公開日時が入っている',
        );
    }

    /**
     * 既に公開しているものを編集しても、**最初に出した日は動かない**。
     * 動くと一覧の並びが変わり、読者の見え方まで変わる。
     */
    public function test_公開済みを編集しても日時は動かない(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/news', [
            'title' => 'さきに出したもの',
            'body' => '本文',
            'status' => 'published',
            'published_at' => '2026-01-01 09:00:00',
        ])->assertSessionHasNoErrors();

        $id = (int) DB::table('news')->value('id');

        $this->actingAs($admin)->put("/admin/news/{$id}", [
            'title' => '直したもの',
            'body' => '本文',
            'status' => 'published',
            'published_at' => '2026-01-01 09:00:00',
        ])->assertSessionHasNoErrors();

        $this->assertStringStartsWith('2026-01-01', (string) DB::table('news')->value('published_at'));
    }
}
