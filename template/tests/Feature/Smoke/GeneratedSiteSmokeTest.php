<?php

declare(strict_types=1);

namespace Tests\Feature\Smoke;

use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Models\User;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 生成されたサイトが**そのまま動く**ことの確認（Phase 9.7 / STAGE 20F）。
 *
 * **このディレクトリのテストは利用者のサイトへ配られる。**
 * kit の例（news / works / product）に一切触れない — 触れた瞬間、
 * 利用者の手元で落ちるテストになる。
 *
 * kit ではここに加えて全テストが走る（kit の例はそちらが見る）。
 */
final class GeneratedSiteSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_投稿タイプとして投稿が使える(): void
    {
        $schemas = app(SchemaRegistry::class);

        $this->assertTrue($schemas->has('post'), '「投稿」が見つかりません');
        $this->assertSame('投稿', $schemas->get('post')->label);
        $this->assertTrue(Schema::hasTable('post'), '投稿の保存先がありません');
    }

    public function test_投稿の管理routeが揃っている(): void
    {
        foreach (['index', 'create', 'store', 'edit', 'update', 'destroy'] as $action) {
            $this->assertTrue(Route::has("admin.post.{$action}"), "admin.post.{$action} がありません");
        }
    }

    public function test_管理画面の入口が開く(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/admin')->assertRedirect();
        $this->actingAs($user)->get('/admin/post')->assertOk();
        $this->actingAs($user)->get('/admin/content-types')->assertOk();
        $this->actingAs($user)->get('/admin/taxonomies')->assertOk();
        $this->actingAs($user)->get('/admin/media')->assertOk();
    }

    public function test_公開サイトの基本が開く(): void
    {
        $this->get('/')->assertOk();
        $this->get('/contact')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/robots.txt')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_投稿を作って一覧に出せる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/admin/post', [
            'title' => 'はじめての投稿',
            'slug' => 'first-post',
            'body' => '<p>本文</p>',
            'status' => 'draft',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('post')->where('slug', 'first-post')->count());

        // 一覧は Inertia なので、HTML ではなく渡された props を見る
        $this->actingAs($user)->get('/admin/post')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/resource/Index')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'はじめての投稿'),
            );
    }

    public function test_設定の既定は控えめ(): void
    {
        $policy = app(ContentTypeLifecyclePolicy::class);

        // 設定行を作らずに既定が決まる
        $this->assertSame(0, DB::table('content_types')->count());
        $this->assertTrue($policy->canWrite('post'));
        $this->assertFalse($policy->isPublic('post'), '既定で公開しない');
        $this->assertFalse($policy->commentsEnabled('post'), '既定でコメントを使わない');
    }

    /**
     * **テストが front-end の build 成果物を要求しないこと**（Phase 9.7 / STAGE 20F）。
     *
     * ここが外れると、`public/build` を持たない生成直後のサイトで
     * 画面を描くテストが軒並み `Vite manifest not found` で落ちる。
     * build が一度でも走ると通ってしまうので、「たまに落ちる」に化けて追いにくい。
     */
    public function test_テストはbuild成果物に依存しない(): void
    {
        // manifest を読む経路そのもの。差し替わっていれば空を返し、
        // 実物なら manifest を読みに行く（無ければ例外、在れば script タグ）
        $this->assertSame(
            '',
            (string) app(Vite::class)(['resources/js/app.ts']),
            'Vite が差し替えられていない（build 成果物に依存している）',
        );

        // 画面を描く経路が build の有無に関わらず通ること
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    }

    /**
     * **公開した操作は、どれも実行の分岐を持っている。**
     *
     * ここは「操作の数が変わっていない」を見ていた。数は、増えたときに
     * **数だけ直して中身を見ない**道を作るうえ、利用者のサイトでは
     * 「kit と同じ数か」を確かめても意味がない。
     *
     * 見るべきは**繋がっているか**である。`OperationCatalog::invoke()` は
     * `match` で操作を振り分ける。allowlist に足して分岐を書き忘れると
     * `default` の腕へ落ち、**「AI から実行できません」を返し続ける** —
     * 一覧には出るのに、呼ぶと必ず断られる操作ができる。
     * `default` が受け止めてしまうので、**例外にもならず誰も気づかない**。
     *
     * 実際に呼ぶと副作用が出るので、**分岐が在ることだけ**をソースで見る。
     */
    public function test_公開した操作はどれも実行の分岐を持っている(): void
    {
        $source = (string) file_get_contents(base_path('app/Support/OperationCatalog.php'));

        /*
         * **`match` の中だけを見る。**
         *
         * allowlist の配列も `'<id>' => …` の形なので、ファイル全体を見ると
         * **配列の行が自分自身を証明してしまう**（最初それで、分岐の無い
         * 変異を通した）。右辺で絞る手もあるが、`'a', 'b' => …` と
         * まとめて書かれた腕を見落とす（これも実測した）。
         * だから範囲で切る。
         */
        $start = strpos($source, 'return match ($operationId) {');
        $this->assertNotFalse($start, 'operation の振り分けが見つかりません');

        $end = strpos($source, "\n        };", $start);
        $this->assertNotFalse($end, '振り分けの終わりが見つかりません');

        $arms = substr($source, $start, $end - $start);

        foreach (OperationCatalog::ids() as $id) {
            $this->assertStringContainsString(
                "'{$id}'",
                $arms,
                "[{$id}] は公開されているのに実行の分岐がありません（呼ぶと必ず断られます）",
            );
        }
    }
}
