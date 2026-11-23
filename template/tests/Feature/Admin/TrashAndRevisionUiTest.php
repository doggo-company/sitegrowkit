<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use AgentCms\Core\Application\Content\TrashContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Trash\TrashColumns;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ゴミ箱と履歴の画面が **裏側の契約どおりに動くか**（Phase 9.8 / STAGE 21E）。
 *
 * 画面そのものの見え方は Playwright が見る。ここで固定するのは
 * 「どの状態のときに、何が渡り、何が渡らないか」である。
 */
final class TrashAndRevisionUiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_通常一覧はactiveだけを出す(): void
    {
        $kept = $this->entry('のこる');
        $this->trash($this->entry('すてた'));

        $this->actingAs($this->owner)->get('/admin/post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/resource/Index')
                ->where('state', 'active')
                ->has('items.data', 1)
                ->where('items.data.0.id', $kept),
            );
    }

    public function test_ゴミ箱一覧はtrashedだけを出す(): void
    {
        $this->entry('のこる');
        $trashed = $this->trash($this->entry('すてた'));

        $this->actingAs($this->owner)->get('/admin/post?state=trashed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/resource/Index')
                ->where('state', 'trashed')
                ->has('items.data', 1)
                ->where('items.data.0.id', $trashed),
            );
    }

    public function test_ゴミ箱の中も検索できて件数が混ざらない(): void
    {
        $this->entry('りんご');
        $this->trash($this->entry('みかん'));
        $this->trash($this->entry('ぶどう'));

        // 検索語は browser と同じく percent-encode して渡す（生のまま送るのは HTTP ではない）
        $this->actingAs($this->owner)->get('/admin/post?state=trashed&q='.rawurlencode('みかん'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('q', 'みかん')
                ->where('state', 'trashed')
                ->has('items.data', 1)
                ->where('items.total', 1),
            );

        // 通常一覧の件数はゴミ箱を数えない
        $this->actingAs($this->owner)->get('/admin/post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 1));
    }

    /**
     * ページを送っても**見ている状態が変わらない**（STAGE 21E 修正）。
     *
     * 2 ページ目のリンクから `state` が落ちると、ゴミ箱を見ていたつもりが
     * 通常の一覧へ戻る。利用者から見れば「消したものが勝手に復活した」に等しい。
     */
    public function test_ゴミ箱の2ページ目リンクが状態を保つ(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->trash($this->entry("すてた {$i}"));
        }

        $this->actingAs($this->owner)->get('/admin/post?state=trashed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.total', 21)
                ->where('items.last_page', 2)
                ->where('items.next_page_url', fn (?string $url): bool => is_string($url)
                    && str_contains($url, 'state=trashed')
                    && str_contains($url, 'page=2'),
                ),
            );
    }

    /** 検索語も一緒に運ぶ。片方だけ残ると 2 ページ目で結果が変わる */
    public function test_ゴミ箱の検索結果もページを送って絞り込みが残る(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->trash($this->entry("りんご {$i}"));
        }

        $this->trash($this->entry('みかん'));
        $this->entry('りんご（ゴミ箱ではない）');

        $links = null;

        $this->actingAs($this->owner)->get('/admin/post?state=trashed&q='.rawurlencode('りんご'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$links): void {
                $page->where('items.total', 21)->where('items.last_page', 2);
                $links = $page->toArray()['props']['items']['next_page_url'] ?? null;
            });

        $this->assertIsString($links);
        $this->assertStringContainsString('state=trashed', $links);
        $this->assertStringContainsString('q='.rawurlencode('りんご'), $links);

        // 2 ページ目も **ゴミ箱の・検索に合うものだけ**
        $this->actingAs($this->owner)->get($links)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'trashed')
                ->where('q', 'りんご')
                ->where('items.current_page', 2)
                ->where('items.total', 21)
                ->has('items.data', 1),
            );
    }

    /** 通常の一覧では `state` を混ぜない（付けたつもりのない絞り込みを増やさない） */
    public function test_通常一覧のページ送りは検索語だけを運ぶ(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->entry("りんご {$i}");
        }

        $this->actingAs($this->owner)->get('/admin/post?q='.rawurlencode('りんご'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.last_page', 2)
                ->where('items.next_page_url', fn (?string $url): bool => is_string($url)
                    && str_contains($url, 'q='.rawurlencode('りんご'))
                    && ! str_contains($url, 'state='),
                ),
            );
    }

    public function test_一覧に版が渡る(): void
    {
        $id = $this->entry('版つき');

        $this->actingAs($this->owner)->get('/admin/post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.lock_version', 0)
                ->where('items.data.0.id', $id),
            );
    }

    public function test_ゴミ箱の投稿の編集urlは読み取り専用になる(): void
    {
        $id = $this->trash($this->entry('すてた'));

        $this->actingAs($this->owner)->get("/admin/post/{$id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // 編集フォームを出さない
                ->component('admin/resource/Trashed')
                ->where('item.id', $id)
                ->has('routeBase'),
            );
    }

    public function test_無効な投稿タイプでは書き込み導線を出さない(): void
    {
        $this->entry('無効な型');

        DB::table('content_types')->insert([
            'content_type_id' => 'post', 'status' => 'archived', 'lifecycle' => 'ready',
            'menu_order' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->forgetScopedInstances();

        $this->actingAs($this->owner)->get('/admin/post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canWrite', false));
    }

    public function test_ゴミ箱へ移すのは2段階で版を送る(): void
    {
        $id = $this->entry('2 段階');

        // 1 回目は確認だけ。**何も変わらない**
        $this->actingAs($this->owner)
            ->post("/admin/post/{$id}/trash", ['lock_version' => 0])
            ->assertRedirect();

        $this->assertNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));

        $token = (string) app(TrashContent::class)
            ->execute('post', $id, $this->context(), 0)->confirmation?->token;

        $this->actingAs($this->owner)
            ->post("/admin/post/{$id}/trash", ['confirmation_token' => $token, 'lock_version' => 0])
            ->assertRedirect();

        $this->assertNotNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_復元は確認なしで下書きへ戻ったことを伝える(): void
    {
        $id = $this->entry('公開中', 'published');
        $this->trash($id);

        $response = $this->actingAs($this->owner)
            ->post("/admin/post/{$id}/restore", ['lock_version' => 1]);

        $response->assertRedirect();
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, '下書き'));

        $this->assertSame('draft', DB::table('post')->where('id', $id)->value('status'));
    }

    public function test_古い版での復元は何も変えない(): void
    {
        $id = $this->trash($this->entry('競合'));

        $this->actingAs($this->owner)->post("/admin/post/{$id}/restore", ['lock_version' => 0]);

        // 版が食い違うので戻らない
        $this->assertNotNull(DB::table('post')->where('id', $id)->value(TrashColumns::DELETED_AT));
    }

    public function test_履歴はactiveでもゴミ箱でも読める(): void
    {
        $id = $this->entry('履歴');
        $this->artisan('agentcms:content-baseline', ['--type' => 'post'])->assertSuccessful();

        $this->actingAs($this->owner)->getJson("/admin/post/{$id}/revisions")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('revisions.0.readable', true);

        $this->trash($id);

        $this->actingAs($this->owner)->getJson("/admin/post/{$id}/revisions")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_履歴が0件でも落ちない(): void
    {
        $id = $this->entry('履歴なし');

        $this->actingAs($this->owner)->getJson("/admin/post/{$id}/revisions")
            ->assertOk()
            ->assertJsonPath('revisions', []);
    }

    public function test_壊れた版は読めない印がつく(): void
    {
        $id = $this->entry('壊れた');
        $this->artisan('agentcms:content-baseline', ['--type' => 'post'])->assertSuccessful();

        DB::table('content_entry_revisions')->where('content_id', $id)
            ->update([
                /*
             * **不正なバイト列では壊さない。**
             *
             * SQLite は受けるが、**MySQL は `3140 Invalid JSON text` で拒む** —
             * そして拒むのが正しい。本番でその行は作れない。
             *
             * 読む側は「**JSON として読めて、かつ配列であるか**」で判断している
             * （`RevisionWriter::hydrate()`）。だから**妥当な JSON だが配列でない**もので壊す。
             */
                'snapshot' => '"読めない中身"',
            ]);

        $this->actingAs($this->owner)->getJson("/admin/post/{$id}/revisions")
            ->assertOk()
            ->assertJsonPath('revisions.0.readable', false);
    }

    public function test_画面に完全削除の導線が無い(): void
    {
        foreach ([
            'js/pages/admin/resource/Index.vue',
            'js/pages/admin/resource/Trashed.vue',
            'js/components/RevisionHistoryDialog.vue',
        ] as $rel) {
            // **動く側だけを見る。** なぜ使わないかを書いた説明文は対象にしない
            $source = self::withoutComments((string) file_get_contents(resource_path($rel)));

            foreach (['purge', '完全に削除', '物理削除', 'force-delete', 'forceDelete'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$rel} に {$forbidden} がある");
            }

            // ブラウザの confirm も v-html も使わない
            $this->assertDoesNotMatchRegularExpression('/(^|[^A-Za-z])confirm\s*\(/m', $source, $rel);
            $this->assertStringNotContainsString('window.confirm', $source, $rel);
            $this->assertStringNotContainsString('v-html', $source, $rel);
        }
    }

    /**
     * 履歴は **受け取った記録を出し切る**（STAGE 21E 修正）。
     *
     * presenter が返しているのに画面が読み飛ばしていると、
     * 「誰がいつ何をしたのか」を追う手段が無くなる。
     */
    public function test_履歴に種別と実行者を出す(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/RevisionHistoryDialog.vue'));

        // presenter の値をそのまま描く（別 API を足して名前やメールを引かない）
        $this->assertStringContainsString('kindLabel(row.kind)', $source);
        $this->assertStringContainsString('actorLabel(row.created_by)', $source);

        // 既知の種別は日本語、知らない種別は捨てない
        $this->assertStringContainsString("manual: '手動'", $source);
        $this->assertStringContainsString("autosave: '自動保存'", $source);
        $this->assertStringContainsString('[kind] ?? kind', $source);

        // 実行者は識別子だけ。記録が無いときに「システム」と決めつけない
        $this->assertStringContainsString('利用者 #', $source);
        $this->assertStringContainsString('実行者の記録なし', $source);

        // **動く側だけを見る。** なぜ引かないかを書いた説明文は対象にしない
        $code = self::withoutComments($source);

        foreach (['email', 'メール', 'created_by_name', 'actorName'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "{$forbidden} を引いている");
        }
    }

    /** presenter は既に種別と実行者を返している（API を増やす必要が無かった根拠） */
    public function test_履歴apiが種別と実行者を返す(): void
    {
        $id = $this->entry('メタ情報');
        $this->artisan('agentcms:content-baseline', ['--type' => 'post'])->assertSuccessful();

        $this->actingAs($this->owner)->getJson("/admin/post/{$id}/revisions")
            ->assertOk()
            ->assertJsonPath('revisions.0.kind', 'manual')
            // baseline はコマンドが書くので実行者の記録は無い
            ->assertJsonPath('revisions.0.created_by', null)
            ->assertJsonPath('revisions.0.created_interface', 'cli');
    }

    public function test_削除ではなくゴミ箱へ移すと表示する(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/admin/resource/Index.vue'));

        $this->assertStringContainsString('ゴミ箱へ移す', $source);
        // 「削除」という表記を残さない（戻せるのに戻せないと読ませない）
        $this->assertStringNotContainsString('>削除<', $source);
    }

    public function test_scriptを仕込んだ題名でjsonブロックを抜け出せない(): void
    {
        $this->entry('<script>window.__xss = 1;</script>');

        $html = (string) $this->actingAs($this->owner)->get('/admin/post')->getContent();

        /*
         * Inertia は props を `<script type="application/json">` の中へ入れる。
         * この文脈で危ないのは **閉じタグだけ**で、そこを抜けられなければ
         * 中身が何であれ実行されない。JSON 符号化が `</script>` を `<\/script>` に
         * するので抜けられない。ここを確かめる（開きタグの有無は関係がない）。
         */
        $this->assertStringNotContainsString('</script>window', $html);
        $this->assertStringContainsString('<\\/script>', $html);

        // 画面側も `v-html` を使わず、Vue の既定のエスケープで文字として出す
        $this->assertStringNotContainsString(
            'v-html',
            self::withoutComments((string) file_get_contents(resource_path('js/pages/admin/resource/Index.vue'))),
        );
    }

    /** コメントを落とす（JS の `//` と `/* *\/`、テンプレートの `<!-- -->`） */
    private static function withoutComments(string $source): string
    {
        return (string) preg_replace(
            ['#/\*[\s\S]*?\*/#', '#(^|[^:])//[^\n]*#m', '#<!--[\s\S]*?-->#'],
            ['', '$1', ''],
            $source,
        );
    }

    private function context(): OperationContext
    {
        return OperationContext::admin((int) $this->owner->getKey(), (string) $this->owner->name);
    }

    private function entry(string $title, string $status = 'draft'): int
    {
        return DB::table('post')->insertGetId([
            'title' => $title, 'slug' => 'p-'.uniqid(), 'body' => '<p>本文</p>', 'status' => $status,
            'published_at' => $status === 'published' ? now()->subDay() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function trash(int $id): int
    {
        $token = (string) app(TrashContent::class)->execute('post', $id, $this->context())->confirmation?->token;
        $result = app(TrashContent::class)->execute('post', $id, $this->context()->withConfirmationToken($token));

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        return $id;
    }
}
