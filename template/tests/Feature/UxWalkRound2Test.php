<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use App\Models\User;
use App\Support\FirstSteps;
use App\Support\PublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * UX 一周 第 2 周（工務店の社長の目・2026-09-28）で止まった・迷った所の回帰。
 *
 * 新しいプロジェクトの「投稿」は公開サイトに出さない設定で始まる。そこで施工事例を
 * 「公開」にしても、サイトのどこにも出なかった。一覧は「公開」、最初の 5 歩は「済み」と言い、
 * 公開前チェックも黙っていた。
 */
final class UxWalkRound2Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function writePost(string $title, string $status = 'published'): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/post', ['title' => $title, 'body' => '本文', 'status' => $status])
            ->assertSessionHasNoErrors();
    }

    private function postIsPublic(): bool
    {
        $this->app?->forgetScopedInstances();

        return (bool) (app(ContentTypeSettingsResolver::class)->for('post')->registration['public'] ?? false);
    }

    public function test_前提_新しいプロジェクトの投稿は公開サイトに出ない(): void
    {
        $this->assertFalse($this->postIsPublic());
    }

    /** 🔴 これが本体。「公開」にしたのに出ていない記事を、公開前チェックが言う */
    public function test_公開にしたのにサイトに出ない記事を公開前チェックが言う(): void
    {
        $this->writePost('施工事例 1');

        $hidden = array_values(array_filter(
            app(PublicationReadiness::class)->check(),
            fn (array $f): bool => str_contains($f['what'], 'サイトには表示されていません'),
        ));

        $this->assertCount(1, $hidden);
        $this->assertSame('/admin/post', $hidden[0]['fix']['href'] ?? null);
    }

    public function test_サイトに出ない記事では最初の5歩の2歩目を済みにしない(): void
    {
        $this->writePost('施工事例 1');
        $this->app?->forgetScopedInstances();

        $step = collect(app(FirstSteps::class)->all())->firstWhere('key', 'content');

        $this->assertFalse($step['done']);
    }

    public function test_記事の画面から1クリックで公開サイトに出せて同じ画面に戻る(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/post/create')
            ->post('/admin/content-types/post/quick-start', ['stay' => 1])
            ->assertRedirect('/admin/post/create')
            ->assertSessionHas('success');

        $this->assertTrue($this->postIsPublic());
    }

    public function test_記事の一覧でも公開サイトに出ないことを言う(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/post')
            ->assertInertia(fn (Assert $p) => $p->where('publicTypeDisabled', true));
    }

    public function test_日本語まじりの見出しは1文字だけの_url_にしない(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');

        $this->writePost('青梅市 S様邸 新築工事', 'draft');

        $this->assertSame('2026-09-28-1', DB::table('post')->value('slug'));
    }

    public function test_同じ英語の見出しの2本目は番号を付けて保存できる(): void
    {
        $this->writePost('Summer Sale', 'draft');
        $this->writePost('Summer Sale', 'draft');

        $this->assertSame(['summer-sale', 'summer-sale-2'], DB::table('post')->orderBy('id')->pluck('slug')->all());
    }
}
