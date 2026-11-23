<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Application\Pages\GetPage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Application\Pages\RestorePage;
use AgentCms\Core\Application\Pages\TrashPage;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Pages\FrontPageChoices;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * ページの予約公開（release R02）。
 *
 * 守るのは次の 5 つで、どれも **公開面が見えるかどうか**で判定する
 * （`status` 列の値を見て緑にしない — `FirstSteps` が一度それで嘘をついた）。
 *
 * 1. 未来の日時までは route / sitemap に出ず、**その秒**から cron 無しで出る
 * 2. 日時はサイトの timezone で読み、offset 付きはそれに従う
 * 3. 下書きは日時が過ぎても出ない。`published_at` が null の公開ページは今までどおり出る
 * 4. preview は権限つきで予約中も見える
 * 5. 入力は Application 層で検証され、権限・確認を迂回しない
 */
final class PageSchedulingTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────
    // 1. 境界秒・cron 無し
    // ─────────────────────────────────────────────────────────────

    public function test_a_scheduled_page_is_hidden_until_the_exact_second_and_then_appears_without_cron(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('launch');

        $result = $this->publish($page, '2026-09-10T09:00');

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors));
        $this->assertTrue($result->meta['scheduled']);
        $this->assertSame('scheduled', $result->data['page']['publishState']);
        $this->assertSame('公開予約', $result->data['page']['publishStateLabel']);

        // 行は「公開状態」だが、公開面には出ない
        $fresh = $page->fresh();
        $this->assertSame(PageStatus::Published, $fresh?->status);
        $this->assertNotNull($fresh?->published_version_id);
        $this->assertTrue($fresh?->isScheduled());
        $this->assertFalse($fresh?->isPublicallyVisible());

        $this->get('/launch')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/launch');

        // 1 秒前はまだ出ない
        $this->travelTo(Carbon::parse('2026-09-10 08:59:59'));
        $this->get('/launch')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/launch');

        // **ちょうどの秒**で出る。ここまでに artisan / scheduler は何も走っていない
        $this->travelTo(Carbon::parse('2026-09-10 09:00:00'));
        $this->get('/launch')->assertOk()->assertSee('予約した見出し');
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/launch'));

        $fresh = $page->fresh();
        $this->assertSame('published', $fresh?->publishState());
        $this->assertSame('公開中', $fresh?->publishStateLabel());
        // DB の行は時刻到達で書き換わっていない（読み出し側の判定だけで変わる）
        $this->assertSame('2026-09-10 09:00:00', $fresh?->published_at?->format('Y-m-d H:i:s'));
    }

    // ─────────────────────────────────────────────────────────────
    // 2. timezone
    // ─────────────────────────────────────────────────────────────

    public function test_naive_input_is_read_in_the_site_timezone_and_offset_input_is_honoured(): void
    {
        // サイトの timezone を東京にする（boot 時と同じ 2 か所を揃える）
        config()->set('app.timezone', 'Asia/Tokyo');
        date_default_timezone_set('Asia/Tokyo');

        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'Asia/Tokyo'));

        $naive = $this->makePublishablePage('naive');
        $this->assertSame(OperationStatus::Success, $this->publish($naive, '2026-10-01T09:00')->status);

        $withOffset = $this->makePublishablePage('offset');
        // UTC 00:00 = 東京 09:00
        $this->assertSame(OperationStatus::Success, $this->publish($withOffset, '2026-10-01T00:00:00+00:00')->status);

        $offsetWithoutSeconds = $this->makePublishablePage('offset-minutes');
        $this->assertSame(OperationStatus::Success, $this->publish($offsetWithoutSeconds, '2026-10-01T00:00+00:00')->status);

        foreach ([$naive, $withOffset, $offsetWithoutSeconds] as $page) {
            $fresh = $page->fresh();
            $this->assertSame('2026-10-01T09:00:00+09:00', $fresh?->published_at?->toIso8601String(), $page->slug);
            $this->assertTrue($fresh?->isScheduled(), $page->slug);
        }

        // 画面に出す値はサイトの timezone で、timezone 名も一緒に出る
        $presented = app(GetPage::class)
            ->execute($naive->id, $this->context())->data['page'];
        $this->assertSame('2026-10-01 09:00', $presented['publishedAtLocal']);
        $this->assertSame('Asia/Tokyo', $presented['timezone']);

        // 東京 08:59:59 では出ず、東京 09:00:00 で両方出る（UTC で見ると前日 23:59:59 / 00:00:00）
        $this->travelTo(Carbon::parse('2026-10-01 08:59:59', 'Asia/Tokyo'));
        $this->get('/naive')->assertNotFound();
        $this->get('/offset')->assertNotFound();

        $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'Asia/Tokyo'));
        $this->get('/naive')->assertOk();
        $this->get('/offset')->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // 3. 下書き / null
    // ─────────────────────────────────────────────────────────────

    public function test_a_draft_stays_hidden_even_after_its_published_at_has_passed(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('withdrawn');
        $this->publish($page, '2026-09-10T09:00');

        // ゴミ箱 → 復元 で下書きへ戻る。`published_at` は残る（復元は再公開しない）
        $this->confirmAndTrash($page);
        $restored = app(RestorePage::class)->execute($page->id, $this->context());
        $this->assertSame(OperationStatus::Success, $restored->status, (string) $restored->message);

        $fresh = $page->fresh();
        $this->assertSame(PageStatus::Draft, $fresh?->status);
        $this->assertNotNull($fresh?->published_at);
        $this->assertSame('draft', $fresh?->publishState());

        // 日時が過ぎても、下書きは出ない
        $this->travelTo(Carbon::parse('2026-09-11 00:00:00'));
        $this->assertFalse($page->fresh()?->isPublicallyVisible());
        $this->get('/withdrawn')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/withdrawn');
    }

    public function test_an_existing_published_page_without_published_at_stays_visible(): void
    {
        $page = $this->makePublishablePage('legacy');
        $this->publish($page, null);

        // migration 前から在るページの形（公開状態・日時なし）
        DB::table('pages')->where('id', $page->id)->update(['published_at' => null]);

        $fresh = $page->fresh();
        $this->assertNull($fresh?->published_at);
        $this->assertFalse($fresh?->isScheduled());
        $this->assertTrue($fresh?->isPublicallyVisible());
        $this->assertSame('published', $fresh?->publishState());

        $this->get('/legacy')->assertOk();
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/legacy'));
        $this->assertSame(1, Page::query()->publiclyVisible()->where('slug', 'legacy')->count());
    }

    public function test_publishing_without_a_datetime_publishes_now(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('immediate');

        $result = $this->publish($page, null);

        $this->assertSame(OperationStatus::Success, $result->status);
        $this->assertFalse($result->meta['scheduled']);
        $this->assertSame('2026-09-10 08:00:00', $page->fresh()?->published_at?->format('Y-m-d H:i:s'));
        $this->get('/immediate')->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // 4. preview
    // ─────────────────────────────────────────────────────────────

    public function test_preview_of_a_scheduled_page_still_needs_login_and_shows_the_draft(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('sneak');
        $this->publish($page, '2026-09-10T09:00');

        // 未ログインは入れない（予約中でも公開 URL が代わりに開くことはない）
        $this->get("/admin/pages/{$page->id}/preview")->assertRedirect('/login');
        $this->get('/sneak')->assertNotFound();

        $this->actingAs($this->admin())
            ->get("/admin/pages/{$page->id}/preview")
            ->assertOk()
            ->assertSee('予約した見出し')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ─────────────────────────────────────────────────────────────
    // 5. 管理画面
    // ─────────────────────────────────────────────────────────────

    public function test_admin_list_and_edit_screens_say_scheduled_and_when(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('announce');
        $this->publish($page, '2026-09-12T10:30');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/pages')
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('admin/pages/Index')
                ->where('items.0.slug', 'announce')
                ->where('items.0.status', 'published')
                ->where('items.0.publishState', 'scheduled')
                ->where('items.0.statusLabel', '公開予約')
                ->where('items.0.publishedAtLocal', '2026-09-12 10:30'));

        $this->actingAs($admin)->get("/admin/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('admin/pages/Edit')
                ->where('page.scheduled', true)
                ->where('page.publishState', 'scheduled')
                ->where('page.publishedAtLocal', '2026-09-12 10:30')
                ->where('page.timezone', (string) config('app.timezone'))
                // 予約中は「公開ページを見る」を出さない（開くと 404 になる）
                ->where('publicUrl', null));

        // 画面は日時入力と timezone の表示を持つ（Vue の描画は E2E の範囲。ここは存在だけ）
        $edit = (string) File::get(base_path('resources/js/pages/admin/pages/Edit.vue'));
        $this->assertStringContainsString('data-testid="page-publish-at-input"', $edit);
        $this->assertStringContainsString('type="datetime-local"', $edit);
        $this->assertStringContainsString('page.timezone', $edit);
        $this->assertStringContainsString('page-published-at', $edit);
    }

    public function test_admin_publish_accepts_a_datetime_and_rejects_unreadable_input(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $admin = $this->admin();
        $page = $this->makePublishablePage('form');

        // 読めない入力: 状態は動かない
        foreach (['tomorrow', '2026-09', ['2026-09-10T09:00'], '2026-13-45T99:99', '2026-02-30T09:00', '2026-09-10T24:00', '2026-09-10T09:00 tomorrow', '0000-01-01T09:00'] as $garbage) {
            $this->actingAs($admin)
                ->from("/admin/pages/{$page->id}/edit")
                ->post("/admin/pages/{$page->id}/publish", ['publishedAt' => $garbage])
                ->assertRedirect("/admin/pages/{$page->id}/edit")
                ->assertSessionHasErrors('publishedAt');

            $this->assertSame(PageStatus::Draft, $page->fresh()?->status, json_encode($garbage));
            $this->assertNull($page->fresh()?->published_version_id);
        }

        // 読める入力: 予約になり、toast に日時と timezone が入る
        $this->actingAs($admin)
            ->post("/admin/pages/{$page->id}/publish", ['publishedAt' => '2026-09-10T09:00'])
            ->assertRedirect("/admin/pages/{$page->id}/edit")
            ->assertSessionHasNoErrors();

        $this->assertTrue($page->fresh()?->isScheduled());
        $this->get('/form')->assertNotFound();

        // 空欄で押し直すと **いま**公開になる（予約の取り消し = すぐ公開）
        $this->actingAs($admin)
            ->post("/admin/pages/{$page->id}/publish", ['publishedAt' => ''])
            ->assertSessionHasNoErrors();

        $this->assertFalse($page->fresh()?->isScheduled());
        $this->get('/form')->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // 6. API / 権限 / 確認
    // ─────────────────────────────────────────────────────────────

    public function test_api_schedule_needs_confirmation_and_the_token_is_bound_to_the_datetime(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $admin = $this->admin();
        $page = $this->makePublishablePage('via-api');

        $first = $this->actingAs($admin)
            ->postJson("/api/v1/pages/{$page->id}/publish", ['publishedAt' => '2026-09-10T09:00'])
            ->assertStatus(409);

        $token = (string) $first->json('confirmation.token');
        $this->assertNotSame('', $token);
        $this->assertStringContainsString('公開予約', (string) $first->json('confirmation.summary'));
        $this->assertSame(PageStatus::Draft, $page->fresh()?->status);

        // 承認した日時と違う日時では token が効かない（新しい確認を求められる）
        $this->actingAs($admin)
            ->postJson("/api/v1/pages/{$page->id}/publish", ['publishedAt' => '2026-09-10T09:01'],
                ['X-Confirmation' => $token])
            ->assertStatus(409);
        $this->assertSame(PageStatus::Draft, $page->fresh()?->status);

        // 同じ日時で送り直すと通る
        $this->actingAs($admin)
            ->postJson("/api/v1/pages/{$page->id}/publish", ['publishedAt' => '2026-09-10T09:00'],
                ['X-Confirmation' => $token])
            ->assertOk()
            ->assertJsonPath('data.publishState', 'scheduled')
            ->assertJsonPath('meta.scheduled', true);
        $this->assertTrue($page->fresh()?->isScheduled());
    }

    public function test_api_rejects_an_unreadable_datetime_before_asking_for_confirmation(): void
    {
        $page = $this->makePublishablePage('api-garbage');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/pages/{$page->id}/publish", ['publishedAt' => 'next week'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['publishedAt']);

        $this->assertSame(PageStatus::Draft, $page->fresh()?->status);
    }

    public function test_a_viewer_cannot_schedule_a_page(): void
    {
        $page = $this->makePublishablePage('locked');
        $viewer = $this->userWithRole(Role::Viewer);

        $this->actingAs($viewer)
            ->post("/admin/pages/{$page->id}/publish", ['publishedAt' => '2999-01-01T00:00'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson("/api/v1/pages/{$page->id}/publish", ['publishedAt' => '2999-01-01T00:00'])
            ->assertForbidden();

        $this->assertSame(PageStatus::Draft, $page->fresh()?->status);
        $this->assertNull($page->fresh()?->published_at);
    }

    /** 予約中のページは、既存の「公開中ページだけ」の導線（トップ指定・メニュー）に出ない */
    public function test_scheduled_pages_are_not_offered_as_public_targets(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('later');
        $this->publish($page, '2026-09-10T09:00');

        $choices = app(FrontPageChoices::class)->all();
        $this->assertNotContains($page->id, array_map(static fn (array $c): int => (int) $c['id'], $choices));

        $this->travelTo(Carbon::parse('2026-09-10 09:00:00'));
        $choices = app(FrontPageChoices::class)->all();
        $this->assertContains($page->id, array_map(static fn (array $c): int => (int) $c['id'], $choices));
    }

    /** 予約取り消しは即公開せず、時刻を過ぎても下書きの内容を守る。 */
    public function test_cancel_schedule_preserves_content_and_stays_private_after_the_due_time(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:00:00'));
        $page = $this->makePublishablePage('cancel-me');
        $this->publish($page, '2026-09-10T09:00');
        $before = PageVersion::query()->where('page_id', $page->id)->get()->toArray();
        $this->actingAs($this->admin())->post("/admin/pages/{$page->id}/unpublish")
            ->assertRedirect("/admin/pages/{$page->id}/edit")->assertSessionHasNoErrors();
        $this->assertSame(PageStatus::Draft, $page->fresh()?->status);
        $this->assertNull($page->fresh()?->published_at);
        $this->assertSame($before, PageVersion::query()->where('page_id', $page->id)->get()->toArray());
        $this->travelTo(Carbon::parse('2026-09-11 10:00:00'));
        $this->get('/cancel-me')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/cancel-me');
        $this->get("/admin/pages/{$page->id}/preview")->assertOk()->assertSee('予約した見出し');
        $this->assertSame('success', DB::table('audit_logs')->where('operation', 'page.unpublish')->value('result'));
        $this->publish($page->fresh(), null);
        $this->get('/cancel-me')->assertOk()->assertSee('予約した見出し');
    }

    /** API での取り消しは確認を要求し、途中で予約を変えたら古い承認は使えない。 */
    public function test_unpublish_api_confirms_the_current_version_and_schedule(): void
    {
        $page = $this->makePublishablePage('api-withdraw');
        $this->publish($page, null);
        $this->get('/api-withdraw')->assertOk();
        $this->actingAs($this->admin());
        $first = $this->postJson("/api/v1/pages/{$page->id}/unpublish")->assertStatus(409);
        $token = (string) $first->json('confirmation.token');
        $this->assertTrue($page->fresh()?->isPublicallyVisible());
        $this->publish($page, '2999-01-01T09:00');
        $changed = $this->postJson("/api/v1/pages/{$page->id}/unpublish", [], ['X-Confirmation' => $token])->assertStatus(409);
        $this->assertTrue($page->fresh()?->isScheduled());
        $newToken = (string) $changed->json('confirmation.token');
        $this->postJson("/api/v1/pages/{$page->id}/unpublish", [], ['X-Confirmation' => $newToken])
            ->assertOk()->assertJsonPath('data.publishState', 'draft');
        $this->get('/api-withdraw')->assertNotFound();
    }

    public function test_unpublish_rejects_viewers_guests_and_non_document_pages(): void
    {
        $page = $this->makePublishablePage('cannot-withdraw');
        $this->publish($page, null);
        $this->post("/admin/pages/{$page->id}/unpublish")->assertRedirect('/login');
        $this->actingAs($this->userWithRole(Role::Viewer));
        $this->post("/admin/pages/{$page->id}/unpublish")->assertForbidden();
        $this->postJson("/api/v1/pages/{$page->id}/unpublish")->assertForbidden();
        $this->assertTrue($page->fresh()?->isPublicallyVisible());
        $page->forceFill(['managed_by' => Page::MANAGED_BY_BLADE])->save();
        $this->actingAs($this->admin())->post("/admin/pages/{$page->id}/unpublish")->assertSessionHasErrors('conflict');
        $this->assertSame(PageStatus::Published, $page->fresh()?->status);
    }

    public function test_nonexistent_daylight_saving_time_is_not_silently_rescheduled(): void
    {
        config()->set('app.timezone', 'America/New_York');
        $page = $this->makePublishablePage('dst-gap');
        $result = $this->publish($page, '2027-03-14T02:30');
        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(PageStatus::Draft, $page->fresh()?->status);
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    private function context(): OperationContext
    {
        return OperationContext::admin($this->operatorId(), 'fixture');
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function publish(Page $page, ?string $publishedAt): Result
    {
        return app(PublishPage::class)->execute($page->id, $this->context(), $publishedAt);
    }

    /** 公開検証（空ページ不可）を通る中身を持った下書きページ */
    private function makePublishablePage(string $slug): Page
    {
        $result = app(CreatePage::class)->execute(['title' => $slug, 'slug' => $slug], $this->context());
        $this->assertTrue($result->isSuccess(), (string) $result->message);

        $page = Page::query()->findOrFail($result->data['page']['id']);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);
        $root = (string) $version->document['root']['id'];

        $section = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $root,
            'component' => ['type' => 'section', 'children' => []],
        ], $this->context());
        $this->assertSame(OperationStatus::Success, $section->status, json_encode($section->errors));

        $children = $section->data['document']['root']['children'];
        $parent = (string) end($children)['id'];

        $heading = app(PatchPageDocument::class)->execute($page->id, [
            'operation' => 'component.insert',
            'parent' => $parent,
            'component' => ['type' => 'heading', 'props' => ['text' => '予約した見出し', 'level' => 2]],
        ], $this->context());
        $this->assertSame(OperationStatus::Success, $heading->status, json_encode($heading->errors));

        return $page;
    }

    /** ゴミ箱は確認つき。1 回目で token を受け取り、2 回目で通す */
    private function confirmAndTrash(Page $page): void
    {
        $first = app(TrashPage::class)->execute($page->id, $this->context());

        if ($first->status === OperationStatus::ConfirmationRequired) {
            $token = (string) $first->confirmation?->token;
            $first = app(TrashPage::class)->execute($page->id, $this->context()->withConfirmationToken($token));
        }

        $this->assertSame(OperationStatus::Success, $first->status, (string) $first->message);
    }
}
