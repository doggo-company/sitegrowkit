<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 記事のプレビュー（Phase 9.15 / STAGE 28A・SPEC §B）。
 *
 * **preview が描くのは「保存済みの下書き」**である（OG-B1）。
 * ブラウザ内の未送信内容は 1 バイトも受け取らない。
 *
 * ここで固定するのは 2 つ:
 *
 * 1. **下書きが preview では見え、公開側では見えない** — 片方だけでは足りない。
 *    「見える」だけを見ると、公開側の穴を開けても緑のままになる
 * 2. **公開と同じ経路で描く** — sanitize を preview だけ緩めない
 */
final class EntryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_下書きはpreviewで見えて公開側では見えない(): void
    {
        $slug = 'draft-'.uniqid();
        $id = $this->news('まだ公開していない記事', $slug);

        // preview では見える
        $this->actingAs($this->owner)
            ->get("/admin/news/{$id}/preview")
            ->assertOk()
            ->assertSee('まだ公開していない記事', false);

        // **公開側は 404 のまま。** preview を足したことで公開面が開いてはいけない
        $this->get("/news/{$slug}")->assertNotFound();
    }

    public function test_previewは検索にもキャッシュにも残さない(): void
    {
        $id = $this->news('下書き', 'draft-'.uniqid());

        $response = $this->actingAs($this->owner)->get("/admin/news/{$id}/preview")->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // 並びは framework が正規化するので、**指示そのもの**が在ることを見る
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString(
                $directive,
                (string) $response->headers->get('Cache-Control'),
                "Cache-Control に {$directive} が無い",
            );
        }
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    /**
     * **preview だけ sanitize を緩めない。**
     *
     * 緩めると「preview では出たのに公開で消えた」が起き、
     * 編集する人にとって **preview が嘘をついた**ことになる。
     */
    public function test_公開で消えるものはpreviewでも消える(): void
    {
        $id = $this->news('危ないの', 'draft-'.uniqid(), '<p>本文</p><script>alert(1)</script>');

        $this->actingAs($this->owner)
            ->get("/admin/news/{$id}/preview")
            ->assertOk()
            ->assertSee('本文', false)
            ->assertDontSee('<script>', false);
    }

    /**
     * 公開の口を持たない型は、**404 ではなく理由の分かる応答**（SPEC §B.2）。
     *
     * 404 だと「壊れている」に見える。**無い理由が違えば、返すものも違う。**
     */
    public function test_汎用投稿も下書きを描き公開側には出さない(): void
    {
        $id = DB::table('post')->insertGetId([
            'title' => '公開の口が無い型', 'slug' => 'p-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->get("/admin/post/{$id}/preview")
            ->assertOk()
            ->assertSee('公開の口が無い型', false)
            ->assertDontSee('公開ページがありません', false)
            ->assertSee('<p>本文</p>', false);

        $slug = (string) DB::table('post')->where('id', $id)->value('slug');
        $this->get('/post/'.$slug)->assertNotFound();
    }

    public function test_ログインしていなければpreviewも開けない(): void
    {
        $id = $this->news('下書き', 'draft-'.uniqid());

        $this->get("/admin/news/{$id}/preview")->assertRedirect('/login');
    }

    /**
     * **「権限で断る」を、実際に断らせて確かめる**（F-23）。
     *
     * この試験が無かったとき、`abortFor()` の Forbidden を 403 から 200 へ変えても
     * 6 本すべてが緑のままだった。**「権限で弾く」を丸ごと無効にしても気づかない**状態である。
     *
     * ## なぜ利用者を作って確かめないのか
     *
     * 実測すると、**いま HTTP 越しにこの枝へ到達する方法が無い**:
     *
     * - `Role` は 4 つとも `ContentRead` を持つ（`app/Support/Role.php`）
     * - 無効化された利用者は `EnsureUserIsActive` が**先に**弾く（redirect であって 403 ではない）
     * - 未ログインも middleware が弾く（policy まで来ない）
     *
     * **枝が塞がっているのではなく、そこへ行く道がまだ無い。**
     * だから policy そのものを断る側へ差し替えて、**対応づけ（Forbidden → 403）**を確かめる。
     * 役割が増えて「読めない人」が実在するようになったら、そのときは利用者で確かめられる。
     */
    public function test_policyが断ればpreviewは403になる(): void
    {
        $id = $this->news('下書き', 'draft-'.uniqid());

        $this->app->instance(OperationPolicy::class, new class implements OperationPolicy
        {
            public function allows(string $operationId, OperationClassification $classification, OperationContext $context): bool
            {
                return false;
            }

            public function denialReason(string $operationId, OperationContext $context): string
            {
                return '試験のため断りました';
            }
        });

        $this->actingAs($this->owner)->get("/admin/news/{$id}/preview")->assertForbidden();
    }

    /**
     * **押しても「ありません」しか出ないボタンを出さない。**
     *
     * 口だけ作って入口が無いと、機能として届かない。逆に、描く相手がいない型で
     * 入口だけ出すと、押した人に「壊れている」と思わせる。
     */
    public function test_入口は描ける型にだけ出す(): void
    {
        $news = $this->news('下書き', 'draft-'.uniqid());

        $this->actingAs($this->owner)->get("/admin/news/{$news}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('previewUrl', "/admin/news/{$news}/preview"));

        $post = DB::table('post')->insertGetId([
            'title' => '公開の口が無い型', 'slug' => 'p-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)->get("/admin/post/{$post}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('previewUrl', "/admin/post/{$post}/preview"));
    }

    private function news(string $title, string $slug, string $body = '<p>本文</p>'): int
    {
        return DB::table('news')->insertGetId([
            'title' => $title, 'slug' => $slug, 'body' => $body, 'status' => 'draft',
            'published_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
