<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Menus\Menu;
use AgentCms\Core\Menus\MenuLinkType;
use AgentCms\Core\Pages\FrontPage;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;
use App\Support\Industry\IndustrySetup;
use Illuminate\Support\Facades\Route;

/**
 * 「最初の 6 歩」（WP 一周 2026-09-03）。
 *
 * ## なぜ要るのか
 *
 * オーナーの原文 —「**一番困るのははじめインストールした後に何をすればいいか
 * わからないこと**」（2026-09-03 13:06）。
 *
 * 「はじめに」パネルは前から在ったが、4 項目が**ずっと同じ顔で並んでいる**だけで、
 * どれが済んでいてどれが残っているかは自分で覚えておくしかなかった。
 *
 * ## Phase 8.5-E の意図をここで覆している
 *
 * `GettingStartedPanel.vue` には「**進捗の自動判定・パーセンテージ・
 * チェックボックスの保存は持たない**」と書いてあった。B-39 のような
 * オーナー決定の印は無いが、明示された意図なので黙って消さない。
 *
 * 覆したのは**自動判定**の部分だけで、**手動の済んだ印の保存は今も持たない**。
 * 最初の 5 歩はチェックした印を保存せず、**いまのサイトの実際の状態から導く**。
 * 6 歩目だけは下記の実行記録を読む。
 * だから「済んだことにして先へ進む」ができず、印と現実がずれない。
 *
 * ## 6 歩目は実際に実行した最後の結果を使う
 *
 * 公開前チェック（`PublicationReadiness::check()`）は**公開している URL を
 * 全部 HTTP で取りに行く**。ダッシュボードを開くたびにサイト全体を叩くことになる。
 *
 * 設定だけで済みにすると、回していないのに緑になる。この約束は守る。
 * ただし公開前チェック画面で実際に実行した結果は DB に残し、最後が 0 件なら済みにする。
 * 現在も安全だという保証にはしない。日時を併記し、公開直前には再確認してもらう。
 */
final readonly class FirstSteps
{
    /**
     * **kit が配ったままの名前**（`siteNamed()` の docblock）。
     *
     * 製品名は `.env.example` の `APP_NAME` が正で、見本の名前は
     * `DemoContentSeeder` / `TemplateContentSeeder` が入れる。ここはその写しなので、
     * **食い違ったら試験が落ちる**ようにしてある（`FirstStepsTest`）。
     * 写しを置いているのは、`config('app.name')` が**利用者の名前で上書きされる**
     * 値だからである（ウィザードが `.env` を書き換える）。
     *
     * @var list<string>
     */
    private const SHIPPED_NAMES = [
        // 製品名（`.env.example` の APP_NAME）と、その旧名
        'SiteGrowKit',
        'Agent CMS Kit',
        // Laravel の既定（`.env` を書かずに動かした場合）
        'Laravel',
        // 同梱の見本が入れる名前
        'Studio Sample',
        'Green Leaf Design',
    ];

    public function __construct(
        private SettingsStore $settings,
        private SchemaRegistry $schemas,
        private ContentTypeResolver $types,
    ) {}

    /**
     * @return list<array{
     *     key: string, title: string, description: string,
     *     href: string, linkLabel: string, done: bool, checkable: bool
     * }>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'site',
                'title' => 'サイトの名前を決める',
                'description' => 'いまは製品名のままです。お店や会社の名前に変えると、画面にもメールにも反映されます。',
                'href' => '/admin/site',
                'linkLabel' => 'サイト設定を開く',
                'done' => $this->siteNamed(),
                'checkable' => true,
            ],
            [
                'key' => 'content',
                'title' => '最初のページか記事を出す',
                'description' => '1 本公開すると、サイトが動いていることを目で確かめられます。',
                'href' => '/admin/pages/create',
                'linkLabel' => 'ページを作る',
                'done' => $this->somethingPublished(),
                'checkable' => true,
            ],
            [
                'key' => 'front',
                'title' => 'トップページを自分の言葉にする',
                'description' => 'トップ用のページを作って公開し、「このページをトップページにする」を押します。',
                'href' => '/admin/pages/create?'.http_build_query(['title' => 'トップページ', 'slug' => 'home']),
                'linkLabel' => 'トップ用のページを作る',
                'done' => app(FrontPage::class)->resolve() !== null,
                'checkable' => true,
            ],
            [
                'key' => 'menu',
                'title' => 'メニューをこのサイトに合わせる',
                'description' => 'いまは見本のままです。作ったページを並べて、要らないものを外します。',
                'href' => '/admin/menus/header',
                'linkLabel' => 'メニューを開く',
                'done' => $this->menuTouched(),
                'checkable' => true,
            ],
            [
                'key' => 'inbox',
                'title' => '問い合わせが自分に届くようにする',
                'description' => $this->inboxDescription(),
                // まだ見本の宛先が残っている**その場所**へ連れて行く（UX 一周 5 周目 R5-2: サイト設定を直しても、
                // フォームの通知先が見本のままで済みにならず、どこを直せばよいか画面に無かった）
                'href' => $this->inboxProblem()['href'] ?? '/admin/site#contact_email',
                'linkLabel' => '通知先を設定する',
                'done' => $this->inboxReachable(),
                'checkable' => true,
            ],
            [
                /*
                 * ダッシュボードでは再実行せず、実際の最後の結果を読む（上の docblock）。
                 */
                'key' => 'readiness',
                'title' => '公開の前にひととおり見る',
                'description' => '開けないページや、仮のままの言葉が残っていないかを確かめます。公開の直前にどうぞ。',
                'href' => '/admin/readiness',
                'linkLabel' => '公開前チェックを開く',
                'done' => (app(ReadinessResult::class)->last()['count'] ?? null) === 0,
                'checkable' => false,
            ],
        ];
    }

    /** 判定できる 5 歩が済んだか。6 歩目は数に入れない */
    public function allDone(): bool
    {
        foreach ($this->all() as $step) {
            if ($step['checkable'] && ! $step['done']) {
                return false;
            }
        }

        return true;
    }

    /**
     * サイトの名前が**誰かの決めた名前**になっているか（WP 一周 2026-09-06 / F-19）。
     *
     * ## `config('app.name')` と比べてはいけない
     *
     * 前はそう比べていた。**セットアップウィザードは、入力された名前を
     * `.env` の `APP_NAME` と `site_name` の両方へ書く**（`wizard/runner.ts`）。
     * つまりウィザードを通った人は、名前を決めた瞬間から 2 つが必ず一致し、
     * **1 歩目が永久に未完了**になる。ダッシュボードは名前が入っているのに
     * 「いまは製品名のままです」と言い続けていた。
     *
     * README_FIRST §6 が「ここが一番迷うところです」と書いている、その 1 歩目である。
     *
     * ## 代わりに、配ったままの名前かを見る
     *
     * `menuTouched()` と同じ考え方 — **kit が配った既定と見比べる**。
     * ここに無い名前は、誰かが決めた名前である。
     */
    private function siteNamed(): bool
    {
        $name = trim((string) $this->settings->get('site_name', ''));

        return $name !== '' && ! in_array($name, self::SHIPPED_NAMES, true);
    }

    /**
     * ページでも投稿でもよい。**公開サイトに実際に出ていること**。
     *
     * 🔴 **`status = published` だけを見てはいけない**（2 周目 2026-09-03 で踏んだ）。
     *
     * 投稿は `status` に加えて `published_at` が要る（`HasContentSchema::scopePublished`）。
     * 状態を「公開」にしても日時が空だと**公開サイトには出ない**。
     * status だけを見ると、**サイトに何も出ていないのに「出した」と緑になる**。
     * ページも同じで、`published_version_id` が無ければ出ない（`isPublicallyVisible`）。
     *
     * **公開面と同じ条件で数える。** 別の条件で数えると、緑が何も保証しなくなる。
     */
    private function somethingPublished(): bool
    {
        // 公開予約中はまだ出ていない — 公開面と同じ scope で数える
        $page = Page::query()->publiclyVisible()->exists();

        if ($page) {
            return true;
        }

        foreach ($this->types->availableTypes() as $id) {
            if (! EditorialCollections::isEditorial($this->schemas->get($id))) {
                continue;
            }

            /*
             * 🔴 **公開サイトに出さない型の記事は数えない**（UX 一周 2026-09-28）。
             * 「投稿」は公開サイトに出さない設定で始まる。そこで記事を「公開」にしても
             * サイトには出ないのに、ここが「済み」と言っていた。
             */
            if (! app(ContentTypeLifecyclePolicy::class)->isPublic($id)) {
                continue;
            }

            /*
             * `published()` は `HasContentSchema` の scope なので、`Model` の
             * 汎用 Builder からは静的に見えない（型だけの話で、実行はできる）。
             * **公開面と同じ条件で数える**という上の約束をここで崩さないため、
             * 別の書き方に逃げず、見えないことだけを断っておく。
             */
            // @phpstan-ignore-next-line method.notFound
            if ($this->types->newModel($id)->newQuery()->published()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * ヘッダーメニューが**見本のまま**でないか。
     *
     * `MenuSeeder` が既定の項目を入れるので「1 件以上あるか」では最初から済みになる。
     * 見たいのは「触ったか」なので、**既定の宣言と見比べる**（B-28 の 1 か所から来る）。
     */
    private function menuTouched(): bool
    {
        $menu = Menu::query()->where('key', 'header')->first();

        if ($menu === null) {
            return false;
        }

        // 画面で保存したことがあれば済み
        if ((int) $menu->lock_version > 0) {
            return true;
        }

        /*
         * 見本のままかは**リンク先の並び**で見る（UX 一周 9 周目 R9-1）。
         * 表示名で比べていたので、業種を選ぶ（/service や記事の呼び名が替わる）・見本を非公開にする
         * （見本の行が既定の一覧から外れる）だけで「直した」と数え、メニューに触る前に済みになっていた。
         */
        $current = $menu->items()->whereNull('parent_id')->orderBy('sort_order')->get()
            // 業種選択で kit が足した記事一覧は、利用者の編集に数えない。
            ->reject(fn ($item): bool => $item->id === $this->settings->get(IndustrySetup::AUTO_MENU_ITEM_KEY)
                && $item->link_type === MenuLinkType::ContentIndex->value && $item->content_type === 'post')
            ->values()
            ->map(static fn ($item): string => $item->link_type.'|'.($item->link_route ?? $item->content_type ?? ''))
            ->all();
        // 入れたときの既定: 宣言のうち、行き先が在るもの（見本を非公開にしても行き先は在る）
        $seeded = array_values(array_map(
            static fn (array $item): string => $item['type']->value.'|'.$item['ref'],
            array_filter(
                DefaultNavigation::declared(),
                static fn (array $item): bool => $item['type'] !== MenuLinkType::Route
                    ? app(SchemaRegistry::class)->has($item['ref'])
                    : Route::has($item['ref']),
            ),
        ));
        $seededAvailable = array_map(
            static fn (array $item): string => $item['link_type'].'|'.($item['link_route'] ?? $item['content_type'] ?? ''),
            DefaultNavigation::seedItems(),
        );

        return $current !== $seeded && $current !== $seededAvailable;
    }

    /**
     * 送った問い合わせが誰かに届くか。
     *
     * **`PublicationReadiness` と同じ順・同じ条件で見る。** 別の場所を見ていると、
     * 片方は済みなのにもう片方が落ち続ける。
     */
    /** @return array{where: string, to: string, href: string, fallback: bool}|null 直す必要のある最初の通知先 */
    private function inboxProblem(): ?array
    {
        return app(InboxRecipients::class)->problems()[0] ?? null;
    }

    private function inboxDescription(): string
    {
        $base = '通知先が見本のままだと、送った人には「送信完了」と出るのに誰にも届きません。';
        $problem = $this->inboxProblem();

        if ($problem === null) {
            return $base;
        }

        $now = $problem['to'] === '' ? '未設定' : $problem['to'];

        // フォームの欄が空のときは、空のときに届く先を言う（UX 一周 15 周目 G2: フォームの画面は空なのに、ここは宛先を言っていた）
        if ($problem['fallback'] && $problem['to'] !== '') {
            $now = "通知先の欄は空なので、{$problem['to']} に届きます";
        }

        return $base."まだ見本のままなのは「{$problem['where']}」です（いま: {$now}）。";
    }

    private function inboxReachable(): bool
    {
        if (app(InboxRecipients::class)->problems() !== []) {
            return false;
        }

        return ! in_array((string) config('mail.default', ''), ['log', 'array'], true);
    }
}
