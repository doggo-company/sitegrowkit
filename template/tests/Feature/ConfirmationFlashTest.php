<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\CreatePage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 確認ダイアログの flash が **リダイレクトのあとまで届く**ことを、経路ごとに固定する。
 *
 * ブラウザで «確認ダイアログが出ない» と分かったとき、
 * 原因がサーバー（flash が消えている）なのか画面側（受け取っていない）なのかを
 * 切り分けられないと、当てずっぽうの修正になる。
 * ここは **サーバーだけ**を見る: リダイレクトを追って、返ってきた Inertia payload に
 * `flash.confirmation` が載っているかを確かめる。
 */
final class ConfirmationFlashTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** HTML に埋め込まれた Inertia payload から flash を取り出す */
    private function inertiaFlash(string $html): array
    {
        if (preg_match('/data-page="app" type="application\\/json">(.*?)<\\/script>/s', $html, $m) !== 1) {
            return [];
        }

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return is_array($page['flash'] ?? null) ? $page['flash'] : [];
    }

    public function test_page_trash_の確認がリダイレクト後に届く(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $result = app(CreatePage::class)->execute(
            ['title' => 'p', 'slug' => 'p'],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );
        $page = Page::query()->findOrFail($result->data['page']['id']);

        $this->actingAs($admin);

        $follow = $this->followingRedirects()
            ->from("/admin/pages/{$page->id}/edit")
            ->post("/admin/pages/{$page->id}/trash");

        $follow->assertOk();

        $this->assertNotNull($this->inertiaFlash($follow->getContent() ?: '')['confirmation'] ?? null);
    }

    public function test_term_delete_の確認がリダイレクト後に届く(): void
    {
        app(TaxonomySynchronizer::class)->sync();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $taxonomy = Taxonomy::query()->where('key', 'news_category')->firstOrFail();
        $term = new TaxonomyTerm;
        $term->forceFill([
            'id' => TaxonomyId::generate(), 'taxonomy_id' => $taxonomy->id,
            'name' => 'x', 'slug' => 'x', 'sort_order' => 0,
        ])->save();

        $this->actingAs($admin);

        $follow = $this->followingRedirects()
            ->from('/admin/taxonomies/news_category')
            ->post("/admin/terms/{$term->id}/delete");

        $follow->assertOk();

        $this->assertNotNull($this->inertiaFlash($follow->getContent() ?: '')['confirmation'] ?? null);
    }

    /**
     * PWA の 3 route は **セッションを開始しない**。
     *
     * Service Worker はこれらを裏で取りに来る。セッションが始まると、その取得が
     * flash を読んで消してしまい、直後に利用者が開いた画面から
     * 確認ダイアログや toast が消える（実際に「削除の確認が出ない」として表面化した）。
     */
    public function test_pwa_の3routeはセッションを発行しない(): void
    {
        foreach (['/admin/manifest.webmanifest', '/admin/sw.js', '/admin/offline'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();

            $cookies = array_map(
                static fn ($cookie): string => $cookie->getName(),
                $response->headers->getCookies(),
            );

            $this->assertNotContains(
                config('session.cookie'),
                $cookies,
                "{$uri} がセッション cookie を発行しています",
            );
        }
    }

    /** PWA の取得が、直前に置かれた flash を消さない */
    public function test_pwa_の取得はflashを消さない(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($admin);

        $result = app(CreatePage::class)->execute(
            ['title' => 'q', 'slug' => 'q'],
            OperationContext::admin($this->operatorId(), 'fixture'),
        );
        $page = Page::query()->findOrFail($result->data['page']['id']);

        // 確認 flash を置く
        $this->from("/admin/pages/{$page->id}/edit")
            ->post("/admin/pages/{$page->id}/trash")
            ->assertRedirect();

        // Service Worker が裏で取りに来る想定
        $this->get('/admin/sw.js')->assertOk();
        $this->get('/admin/manifest.webmanifest')->assertOk();

        // そのあとで利用者が画面を開いても、確認はまだ残っている
        $follow = $this->get("/admin/pages/{$page->id}/edit");

        $this->assertNotNull(
            $this->inertiaFlash($follow->getContent() ?: '')['confirmation'] ?? null,
            'PWA の取得で確認 flash が消えている',
        );
    }
}
