<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Pages\Page;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UxRound2IndustrySlugTest extends TestCase
{
    use RefreshDatabase;

    private function choose(string $industry): void
    {
        $this->app?->forgetScopedInstances();
        $this->post('/admin/industry', ['industry' => $industry])->assertSessionHasNoErrors();
        $this->app?->forgetScopedInstances();
    }

    public function test_自動で採番したurlは選び直した業種に合わせて再び採番する(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]));
        // 同梱 news / works の経路と衝突する世界。次の候補もページに使われている。
        Page::query()->create(['title' => '別のページ', 'slug' => 'works-2', 'status' => 'draft']);
        $this->choose('trimming');
        $this->assertSame('news-2', app(ContentTypeSettingsResolver::class)->for('post')->publicSlug);
        $this->choose('koumuten');
        $this->assertSame('works-3', app(ContentTypeSettingsResolver::class)->for('post')->publicSlug);
        $this->choose('trimming');
        $this->assertSame('news-2', app(ContentTypeSettingsResolver::class)->for('post')->publicSlug);
    }

    public function test_利用者が付けた採番風のurlは選び直しても変えない(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]));
        $this->assertTrue(app(ContentTypeSettingsWriter::class)->save('post', ['public_slug' => 'news-2'], 0));
        $this->choose('trimming');
        $this->choose('koumuten');
        $this->assertSame('news-2', app(ContentTypeSettingsResolver::class)->for('post')->publicSlug);
    }

    /** 選び直して元の業種へ戻しても、途中の URL は今の一覧へ転送される（転送が輪にならない） */
    public function test_元の業種へ戻しても途中の_ur_lは今の一覧へ転送される(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Owner->value, 'email_verified_at' => now()]));
        $this->choose('trimming');
        $this->choose('koumuten');
        $this->assertSame('works-2', app(ContentTypeSettingsResolver::class)->for('post')->publicSlug);
        $this->choose('trimming');
        $this->assertSame('news-2', app(ContentTypeSettingsResolver::class)->for('post')->publicSlug);

        $this->get('/news-2')->assertOk();
        $this->get('/works-2')->assertStatus(301)->assertRedirect('/news-2');
    }
}
