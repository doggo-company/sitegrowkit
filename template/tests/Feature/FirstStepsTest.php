<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Forms\Form;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\FirstSteps;
use Database\Seeders\ContactFormSeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「最初の 5 歩」（WP 一周 2026-09-03 / オーナー 13:06）。
 *
 * 最初の 5 歩は **手動の完了印を保存しないこと**を固定する。
 * 6 歩目は実際に回した結果だけを保存する（Round3ReadinessTest）。
 * 済んだ印はサイトの実際の状態から導く。だから「済んだことにして先へ進む」ができず、
 * 印と現実がずれない（`GettingStartedPanel.vue` の「チェックボックスの保存は持たない」は守る）。
 */
final class FirstStepsTest extends TestCase
{
    use RefreshDatabase;

    private function steps(): array
    {
        $this->app?->forgetScopedInstances();

        return app(FirstSteps::class)->all();
    }

    /** 公開面に出る形にする（版が無いページは出ない） */
    private function publishVersion(Page $page): void
    {
        $id = \DB::table('page_versions')->insertGetId([
            'page_id' => $page->getKey(),
            'revision' => 1,
            'document' => json_encode(['type' => 'root', 'children' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page->forceFill(['published_version_id' => $id])->save();
    }

    private function step(string $key): array
    {
        foreach ($this->steps() as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        $this->fail("{$key} の歩が無い");
    }

    public function test_六歩ある(): void
    {
        $this->assertSame(
            ['site', 'content', 'front', 'menu', 'inbox', 'readiness'],
            array_column($this->steps(), 'key'),
        );
    }

    public function test_生成直後は何も済んでいない(): void
    {
        foreach ($this->steps() as $step) {
            $this->assertFalse($step['done'], "{$step['key']} が最初から済みになっている");
        }

        $this->assertFalse(app(FirstSteps::class)->allDone());
    }

    public function test_サイト名を製品名から変えると一歩目が済む(): void
    {
        $this->assertFalse($this->step('site')['done']);

        app(SettingsStore::class)->put(['site_name' => 'みどり園']);

        $this->assertTrue($this->step('site')['done']);
    }

    public function test_製品名のままなら一歩目は済まない(): void
    {
        app(SettingsStore::class)->put(['site_name' => 'SiteGrowKit']);

        $this->assertFalse($this->step('site')['done'], '製品名のままなのに済みになっている');
    }

    /** 同梱の見本が入れる名前も「まだ決めていない」 */
    public function test_見本の名前のままなら一歩目は済まない(): void
    {
        app(SettingsStore::class)->put(['site_name' => 'Studio Sample']);

        $this->assertFalse($this->step('site')['done'], '見本の名前のままなのに済みになっている');
    }

    /**
     * 🔴 **ウィザードで名前を付けた人が、永久に未完了だった**（F-19 / 2026-09-06）。
     *
     * ウィザードは入力された名前を `.env` の `APP_NAME` と `site_name` の
     * **両方**へ書く（`wizard/runner.ts`）。前の判定は「2 つが違うこと」を
     * 条件にしていたので、名前を決めた瞬間から必ず一致し、1 歩目が済まなかった。
     * ダッシュボードは名前が入っているのに「いまは製品名のままです」と言い続けていた。
     */
    public function test_ウィザードのようにapp_nameも同じ名前でも一歩目は済む(): void
    {
        config(['app.name' => 'みどり園']);
        app(SettingsStore::class)->put(['site_name' => 'みどり園']);

        $this->assertTrue(
            $this->step('site')['done'],
            'ウィザードで名前を付けたのに 1 歩目が済んでいない',
        );
    }

    /**
     * 配ったままの名前の一覧が、**正から外れていないこと**。
     *
     * `FirstSteps::SHIPPED_NAMES` は `.env.example` と見本の seeder の写しである。
     * 写しなので、**片方だけ変わったらここで落ちる**ようにしておく。
     */
    public function test_配ったままの名前が正と揃っている(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertSame(
            1,
            preg_match('/^APP_NAME="?([^"\n]+)"?$/m', $env, $matches),
            '.env.example の APP_NAME を読めない',
        );

        $reflection = new \ReflectionClass(FirstSteps::class);
        /** @var list<string> $shipped */
        $shipped = $reflection->getConstant('SHIPPED_NAMES');

        $this->assertContains(trim($matches[1]), $shipped, '.env.example の製品名が一覧に無い');
        $this->assertContains('Studio Sample', $shipped, '見本の名前が一覧に無い');
    }

    public function test_公開したページがあると二歩目が済む(): void
    {
        $this->assertFalse($this->step('content')['done']);

        $page = Page::query()->create([
            'title' => '会社概要',
            'slug' => 'company',
            'status' => PageStatus::Published->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);
        $this->publishVersion($page);

        $this->assertTrue($this->step('content')['done']);
    }

    public function test_下書きのままでは二歩目は済まない(): void
    {
        Page::query()->create([
            'title' => '下書き',
            'slug' => 'shitagaki',
            'status' => PageStatus::Draft->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $this->assertFalse($this->step('content')['done'], '下書きで済みになっている');
    }

    /**
     * 🔴 **公開面に出ていないものを「出した」と言わない**（2 周目 2026-09-03 で踏んだ）。
     *
     * 版が無いページは `status = published` でも公開サイトに出ない
     * （`Page::isPublicallyVisible()`）。status だけを見ると
     * **サイトに何も出ていないのに緑**になる。
     */
    public function test_版の無いページでは二歩目は済まない(): void
    {
        Page::query()->create([
            'title' => '版が無い',
            'slug' => 'nashi',
            'status' => PageStatus::Published->value,
            'published_version_id' => null,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $this->assertFalse(
            $this->step('content')['done'],
            '公開サイトに出ていないのに済みになっている',
        );
    }

    /**
     * 🔴 **同じことが投稿でも起きる。**
     *
     * 状態を「公開」にしても `published_at` が空だと公開サイトには出ない
     * （`HasContentSchema::scopePublished`）。管理画面には「公開」と出るので、
     * ここで緑にすると**画面だけが済んだことになる**。
     */
    public function test_公開日時の無い投稿では二歩目は済まない(): void
    {
        \DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'status' => 'active',
                'display_overrides' => json_encode(['show_in_menu' => true], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        \DB::table('post')->insert([
            'title' => '日時が空',
            'slug' => 'nichiji',
            'body' => '本文',
            'status' => 'published',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(
            $this->step('content')['done'],
            '公開日時が空で公開サイトに出ないのに済みになっている',
        );
    }

    public function test_公開日時の入った投稿なら二歩目が済む(): void
    {
        /*
         * 投稿は既定で「公開サイトに出さない」。日時があっても型が非公開なら
         * サイトには出ないので、二歩目は済まない（FirstSteps::somethingPublished）。
         * 公開面と同じ条件に揃えてから「済む」を確かめる。
         */
        \DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'status' => 'active',
                'lifecycle' => 'ready',
                'public_slug' => 'blog',
                'registration' => json_encode(['public' => true], JSON_UNESCAPED_UNICODE),
                'display_overrides' => json_encode(['show_in_menu' => true], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        app()->forgetScopedInstances();

        \DB::table('post')->insert([
            'title' => '出ている',
            'slug' => 'deteiru',
            'body' => '本文',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($this->step('content')['done']);
    }

    /**
     * `MenuSeeder` が既定の項目を入れるので、「1 件以上あるか」では最初から済みになる。
     * 見たいのは**触ったか**である。
     */
    public function test_メニューが見本のままなら三歩目は済まない(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertFalse($this->step('menu')['done'], '見本のままなのに済みになっている');
    }

    public function test_メニューを変えると三歩目が済む(): void
    {
        $this->seed(MenuSeeder::class);

        Menu::query()->where('key', 'header')->first()?->items()->first()?->delete();

        $this->assertTrue($this->step('menu')['done']);
    }

    public function test_通知先が見本のままなら四歩目は済まない(): void
    {
        app(SettingsStore::class)->put(['contact_email' => 'admin@example.test']);
        config()->set('mail.default', 'smtp');

        $this->assertFalse($this->step('inbox')['done'], 'example のままなのに済みになっている');
    }

    public function test_メールが送られない設定なら四歩目は済まない(): void
    {
        app(SettingsStore::class)->put(['contact_email' => 'info@midorien.test']);
        config()->set('mail.default', 'log');

        $this->assertFalse($this->step('inbox')['done'], '送られない設定なのに済みになっている');
    }

    /**
     * サイト設定を直しても、フォームの通知先が見本のままなら、その場所へ連れて行く（UX 一周 5 周目 R5-2）。
     * 以前はいつもサイト設定へ行き、直しても済みにならず、どこが残っているか画面に無かった。
     */
    public function test_四歩目はまだ見本の宛先が残っている場所へ連れて行く(): void
    {
        $this->seed(ContactFormSeeder::class);
        app(SettingsStore::class)->put(['contact_email' => 'info@midorien.test']);
        config()->set('mail.default', 'smtp');
        Form::query()->where('key', 'contact')->update(['notify_to' => 'owner@example.test']);

        $step = $this->step('inbox');

        $this->assertFalse($step['done']);
        $this->assertSame('/admin/forms/contact#notify_to', $step['href']);
        $this->assertStringContainsString('owner@example.test', $step['description']);
    }

    public function test_通知先を自分のものにすると四歩目が済む(): void
    {
        app(SettingsStore::class)->put(['contact_email' => 'info@midorien.test']);
        config()->set('mail.default', 'smtp');

        $this->assertTrue($this->step('inbox')['done']);
    }

    /**
     * **実行記録が無ければ 6 歩目を済みにしない。**
     *
     * 公開前チェックは公開している URL を全部 HTTP で取りに行くので、
     * ダッシュボードを開くたびには回せない。安い部分だけで緑にすると、
     * 重い検査を回していないのに緑になる。
     */
    public function test_六歩目は未実施なら済みにしない(): void
    {
        $step = $this->step('readiness');

        $this->assertFalse($step['checkable']);
        $this->assertFalse($step['done']);
    }

    public function test_判定できる五歩が済めば_alldone(): void
    {
        $this->seed(MenuSeeder::class);

        app(SettingsStore::class)->put([
            'site_name' => 'みどり園',
            'contact_email' => 'info@midorien.test',
        ]);
        config()->set('mail.default', 'smtp');

        $this->publishVersion(Page::query()->create([
            'title' => '会社概要',
            'slug' => 'company',
            'status' => PageStatus::Published->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]));

        app(SettingsStore::class)->put(['front_page_id' => Page::query()->value('id')]);
        Menu::query()->where('key', 'header')->first()?->items()->first()?->delete();

        $this->app?->forgetScopedInstances();

        $this->assertTrue(app(FirstSteps::class)->allDone(), '5 歩とも済んでいるのに allDone にならない');
    }

    /**
     * 🔴 **押すと状態が変わる行き先を、link のまま出さない**（2 周目 2026-09-03 で踏んだ）。
     *
     * 2 歩目の行き先は、書ける入れ物が無いとき `admin.content-types.quick-start`
     * （**POST 専用**）に変わる。`method` を渡し忘れると GET で叩いて 404 になり、
     * 押した人には「壊れている」としか見えない。
     * **型検査も build も通ってしまう**ので、ここで留める。
     */
    public function test_1クリックの行き先を_post_で叩く(): void
    {
        $panel = (string) file_get_contents(
            base_path('resources/js/components/GettingStartedPanel.vue'),
        );

        $this->assertMatchesRegularExpression(
            '/<Link\b[^>]*:method=/su',
            $panel,
            '状態が変わる行き先に method を渡していない（GET で叩いて 404 になる）',
        );

        // route 側が POST 専用のままであること（片方だけ変わると同じ事故に戻る）
        $route = app('router')->getRoutes()->getByName('admin.content-types.quick-start');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
    }

    /**
     * **5 歩が済んだあと、次にどこへ行けばよいかを出す**（4 周目 2026-09-03 の 🟡）。
     *
     * 縮んだパネルには公開前チェックのボタンしか無く、「準備はできています」の先が
     * 空いていた。件数のカードは下に並んでいるが、**次の一手は名前で言うほうがよい**。
     */
    public function test_済んだあとの行き先を渡す(): void
    {
        $panel = (string) file_get_contents(
            base_path('resources/js/components/GettingStartedPanel.vue'),
        );

        foreach (['記事を書く', 'ページ', 'デザイン'] as $word) {
            $this->assertStringContainsString($word, $panel, "次の行き先に「{$word}」が無い");
        }
    }

    public function test_ダッシュボードが六歩を渡す(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $steps = $this->actingAs($admin)->get('/dashboard')->inertiaProps('firstSteps');

        $this->assertIsArray($steps);
        $this->assertSame(
            ['site', 'content', 'front', 'menu', 'inbox', 'readiness'],
            array_column($steps['steps'], 'key'),
        );
        $this->assertFalse($steps['allDone']);
    }

    public function test_通知先の一歩は通知先の欄へ直接飛ぶ(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $steps = $this->actingAs($admin)->get('/dashboard')->inertiaProps('firstSteps');
        $inbox = collect($steps['steps'])->firstWhere('key', 'inbox');

        // サイト設定の上に着くと、通知先の欄はロゴ・画像の下で画面の外にある（UX 一周 2026-11-05 M9）
        $this->assertSame('/admin/site#contact_email', $inbox['href']);
    }
}
