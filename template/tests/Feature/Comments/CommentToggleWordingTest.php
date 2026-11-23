<?php

declare(strict_types=1);

namespace Tests\Feature\Comments;

use AgentCms\Core\Application\ContentTypes\SetContentTypeComments;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * コメントを ON にするときの説明が、**実際に起きること**と一致している
 * （WP 一周 6 周目 2026-09-03 / 効くもの ③）。
 *
 * ## 何が起きていたか
 *
 * 投稿タイプの設定と、その確認ダイアログが、2 か所とも同じことを言っていた:
 *
 * > **まだ誰もコメントを書けません。** 受け付ける仕組みは後の工程で作ります
 *
 * **実物は違う。** ON にした瞬間、記事ページにコメント欄が出て、
 * 実際に送信でき、管理画面に「承認待ち 1 件」として並ぶ（6 周目で実測）。
 *
 * ## なぜ危ないか
 *
 * 実物より**控えめ**に書くのは、大きく書くのと同じくらい危ない。
 * 片方は失望で済むが、こちらは**事故**である —
 * 「まだ書けないなら、先に入れておこう」と思って ON にした案件のサイトが、
 * その瞬間から公開のコメント受付になる。スパムも、個人情報も入ってくる。
 *
 * ## ここで固定すること
 *
 * 1. 説明が「書けません」と言わない
 * 2. **受け付けが始まる**ことを言う
 * 3. 言ったとおりに、公開ページへ本当に投稿欄が出る（**文言と実物を同じ試験で見る**）
 */
final class CommentToggleWordingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        config([
            'agentcms.turnstile.secret_key' => '',
            'agentcms.turnstile.site_key' => '',
        ]);
    }

    public function test_onにする確認が_受け付けが始まることを言う(): void
    {
        $this->publishType(comments: false);

        $result = app(SetContentTypeComments::class)
            ->execute('post', true, 0, $this->context());

        $this->assertSame(OperationStatus::ConfirmationRequired, $result->status);

        $impact = implode(' / ', $result->confirmation?->impact ?? []);

        /*
         * 🔴 **「受け付け」を含むかだけでは足りない。** 直す前の文言も
         * 「受け付ける仕組みは後の工程で作ります」で通ってしまった。
         * **始まる**ことと、**どこで**始まるかまで見る。
         */
        $this->assertStringContainsString('公開ページ', $impact);
        $this->assertStringContainsString('受け付けが始まります', $impact);
    }

    /** 🔴 実物と逆のことを言わない。 */
    public function test_onにする確認が_書けないとは言わない(): void
    {
        $this->publishType(comments: false);

        $result = app(SetContentTypeComments::class)
            ->execute('post', true, 0, $this->context());

        $impact = implode(' / ', $result->confirmation?->impact ?? []);

        $this->assertStringNotContainsString('まだ誰もコメントを書けません', $impact);
        $this->assertStringNotContainsString('後の工程で作ります', $impact);
    }

    /**
     * 🔴 **文言と実物を、同じ試験の中で見る。**
     *
     * 別々に見ていると、片方だけ直したときに気づけない。
     * ここは「言ったとおりのことが起きる」を 1 本で押さえる。
     */
    public function test_言ったとおりに公開ページへ投稿欄が出る(): void
    {
        $this->publishType(comments: false);

        $impact = implode(' / ', app(SetContentTypeComments::class)
            ->execute('post', true, 0, $this->context())
            ->confirmation?->impact ?? []);

        $this->assertStringContainsString('公開ページ', $impact);
        $this->assertStringContainsString('受け付けが始まります', $impact);

        // 確認どおりに ON にする
        $this->publishType(comments: true);
        $slug = $this->publishedPost();

        $this->get("/blog/{$slug}")
            ->assertOk()
            ->assertSee('コメントを書く')
            ->assertSee('コメントを送信');
    }

    /** 既定は OFF。**入れただけでコメントが開くことはない** */
    public function test_既定はoffで_記事ページに投稿欄が出ない(): void
    {
        $this->publishType(comments: false);
        $slug = $this->publishedPost();

        $this->get("/blog/{$slug}")
            ->assertOk()
            ->assertDontSee('コメントを書く');
    }

    /**
     * 確認の文言に、強調記法（`**`）を素のまま出さない。
     *
     * 画面はこの文字列をそのまま描くので、読む人には `**` が見えていた。
     */
    public function test_確認の文言にmarkdownの記号が残っていない(): void
    {
        $this->publishType(comments: false);

        $on = app(SetContentTypeComments::class)->execute('post', true, 0, $this->context());

        $this->publishType(comments: true);

        $off = app(SetContentTypeComments::class)->execute('post', false, 0, $this->context());

        foreach ([$on, $off] as $result) {
            foreach ($result->confirmation?->impact ?? [] as $line) {
                $this->assertStringNotContainsString('**', $line, "強調記法が残っている: {$line}");
            }
        }
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function publishType(bool $comments): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'comments_enabled' => $comments,
                'status' => 'active',
                'lifecycle' => 'ready',
                'public_slug' => 'blog',
                'registration' => json_encode(['public' => true], JSON_UNESCAPED_UNICODE),
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    private function publishedPost(string $slug = 'oshirase'): string
    {
        DB::table('post')->insert([
            'title' => 'お知らせ',
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $slug;
    }
}
