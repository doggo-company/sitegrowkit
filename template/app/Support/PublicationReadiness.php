<?php

declare(strict_types=1);

namespace App\Support;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Inertia\ResponseFactory;

/**
 * 公開してよい状態か。
 *
 * 🔴 **残っている納品ブロッカーは、どれもエラーを出さない。**
 * 電話番号が `0766-00-0000` でも、問い合わせの宛先が開発用でも、
 * 「※ 記事は仮のものです」が出ていても、**サイトは 200 で開く。**
 * 気づけるのは、覚えている人が見に行ったときだけ。
 *
 * ▷ **見るのは「訪問者に見えているもの」。**view に焼き付いた文も、
 *   DB に入っている文も、描いてから探せば同じように見つかる。
 *
 * ▷ **印は言葉で持つ。**「仮」の 1 文字では `仮植え`（造園の正しい用語）に当たる。
 *   広すぎる検査は、緑にするために本文を歪めることになる。
 */
final class PublicationReadiness
{
    /**
     * 原稿待ちの印。**どれも「原稿が来たら消える言葉」だけ**を選んでいる。
     *
     * @var list<string>
     */
    private const MARKERS = [
        '準備中',
        '仮のもの',
        '仮のテキスト',
        '仮の項目',
        '仮の内容',
        '素材回収',
        '差し替え',
        'ダミー',
        'example.test',
        'admin@example',
        'lorem ipsum',
        // 業種の見本やメニュー表・営業時間のブロックが置く空欄（UX 一周 2026-09-28）
        '〇〇',
        // 〇 1 つの曜日（定休日を「〇曜日」のまま残すと、嘘の定休日が出る）
        '〇曜日',
    ];

    /** 桁がすべて 0 の番号は、実在しない（仮の番号として置かれたもの）。 */
    private const FAKE_TEL = '/\b0\d{1,4}-0{2,4}-0{4}\b/';

    /**
     * **同梱の見本が、見本のまま公開されている**印（WP 一周 2026-09-03）。
     *
     * 会社名と通知先だけ変えて見本の文章が丸ごと残っていても、原稿待ちの印は
     * 1 つも当たらない（見本の文章に「準備中」は入っていない）。
     * **一番ありがちな事故がこれ**で、納品したサイトに
     * 「本サイトは SiteGrowKit に同梱のサンプルサイトです」が残る。
     *
     * ここで見るのは**見本だと名乗っている文**だけである。
     * 文章がその案件にふさわしいかは相変わらず見ない（機械には決められない）。
     *
     * ▷ ファイルの指紋で照合していないのは、**同梱時のハッシュ一覧を配っていない**ため。
     *   足すと「見本の正本」が 2 か所（ファイルと一覧）になり、片方だけ古くなる。
     *   描いた HTML に出ている文なら、**訪問者が見ているもの**をそのまま見られる。
     *
     * @var list<string>
     */
    private const SAMPLE_MARKERS = [
        '同梱のサンプルサイト',
        'コンテンツはデモ用',
    ];

    /**
     * **見本の説明文**（UX 一周 2026-09-28）。
     *
     * 同梱のお問い合わせフォームの説明は、制作会社向けの文で入っている
     * （`ContactFormSeeder`）。トリミングサロンでも工務店でも、そのまま公開される。
     * 見本だと名乗っていない文なので `SAMPLE_MARKERS` には当たらず、
     * 公開前チェックが黙って通していた。
     *
     * 🔴 **1 文まるごとで持つ。**「ご相談」「お見積もり」だけでは、
     * 案件が自分で書いた正しい文にも当たる。
     *
     * @var list<string>
     */
    public const SAMPLE_LEADS = [
        '制作のご相談・お見積もりは下記フォームからお送りください',
    ];

    /**
     * 描いた HTML に残っている見本の説明文。
     *
     * @return list<string>
     */
    public static function sampleLeadsIn(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $found = [];

        foreach (self::SAMPLE_LEADS as $lead) {
            if (mb_strpos($text, $lead) !== false) {
                $found[] = $lead;
            }
        }

        return $found;
    }

    /**
     * 描いた HTML に残っている「見本のまま」の印。
     *
     * **原稿待ちの印とは別に数える。** 直す場所が違う（片方は原稿、
     * もう片方はページごと差し替えるか消すか）ので、混ぜると指摘が読めなくなる。
     *
     * @return list<string>
     */
    public static function sampleMarkersIn(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $found = [];

        foreach (self::SAMPLE_MARKERS as $marker) {
            if (mb_stripos($text, $marker) !== false) {
                $found[] = $marker;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * 描いた HTML の中に残っている、原稿待ちの印。
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $found = [];

        foreach (self::MARKERS as $marker) {
            if (mb_stripos($text, $marker) !== false) {
                $found[] = $marker;
            }
        }

        if (preg_match(self::FAKE_TEL, $text, $m) === 1) {
            $found[] = $m[0];
        }

        return array_values(array_unique($found));
    }

    /**
     * 公開の手前で止めるべきものの一覧。**空なら出してよい。**
     *
     * @return list<array{level: string, where: string, what: string, fix?: array{href: string, label: string}}>
     */
    public function check(): array
    {
        $findings = [];
        $paths = $this->publicPaths();
        /** @var array<string, string> $links リンク先 => そのリンクがあったページ */
        $links = [];
        /** @var array<string, list<string>> $linkedFrom リンク先 => そのリンクがあったページ全部 */
        $linkedFrom = [];

        foreach ($paths as $path) {
            [$status, $html] = $this->fetch($path);

            /*
             * 🔴 **sitemap に載っているのに開けない。**
             *
             * D-9 が言っていた事故がこれ — `news` / `works` は module が
             * 受けているだけなので、**module を外した日に 404 になる**。
             * sitemap には載り続けるし、メニューにも並ぶ。
             * 名指しで守るのではなく、**載っているのに開けない**を拾う。
             */
            if ($status >= 400) {
                $findings[] = [
                    'level' => 'blocker',
                    'where' => $path,
                    'what' => "sitemap に載っているのに開けない（{$status}）",
                ];

                // 開けないページの本文を印で数えても意味がない
                continue;
            }
            foreach (self::placeholdersIn($html) as $marker) {
                $findings[] = [
                    'level' => 'blocker',
                    'where' => $path,
                    'what' => "原稿待ちの言葉が公開面に出ている: 「{$marker}」",
                ];
            }

            foreach (self::sampleLeadsIn($html) as $lead) {
                $findings[] = [
                    'level' => 'blocker',
                    'where' => $path,
                    'what' => "見本の説明文のまま公開されています: 「{$lead}」（お店に合わせた文に書き換えてください）",
                ];
            }

            foreach (self::internalLinksIn($html) as $href) {
                $links[$href] ??= $path;
                $linkedFrom[$href][] = $path;
            }

            if (self::sampleMarkersIn($html) !== []) {
                $findings[] = [
                    'level' => 'blocker',
                    'where' => $path,
                    'what' => '同梱の見本のまま公開されています（このサイトの内容に差し替えるか、'
                        .'非公開にしてください）',
                ];
            }
        }

        foreach ($this->brokenLinkFindings($links, $paths) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->settingFindings() as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->hiddenPublishedFindings() as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->provisionalUrlFindings() as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->unreachableListFindings($linkedFrom) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * 記事を公開したのに、その一覧へサイトのどこからも行けない（UX 一周 第10周・2026-09-28）。
     *
     * 工務店で施工事例を 1 件公開した。一覧（/blog）は開けるし sitemap にも載るので
     * ここまでの検査は全部通ったが、ヘッダーのメニューにもトップにもリンクが無く、
     * **訪問者には施工事例がどこにも見えなかった**。
     *
     * 一覧そのもの・その型の記事（一覧からしか辿れない）以外のページから 1 本でもリンクがあれば通す。
     * メニューに置くか、トップのボタンに置くかは店が決める。
     *
     * @param  array<string, list<string>>  $linkedFrom  リンク先 => そのリンクがあったページ
     * @return list<array{level: string, where: string, what: string, fix: array{href: string, label: string}}>
     */
    private function unreachableListFindings(array $linkedFrom): array
    {
        $types = app(ContentTypeResolver::class);
        $schemas = app(SchemaRegistry::class);
        $lifecycle = app(ContentTypeLifecyclePolicy::class);
        $permalinks = app(ContentPermalink::class);
        $findings = [];

        foreach ($types->availableTypes() as $id) {
            $schema = $schemas->get($id);

            if (! EditorialCollections::isEditorial($schema) || ! $lifecycle->isPublic($id)) {
                continue;
            }

            $index = $permalinks->index($id);
            $list = is_string($index) ? (string) (parse_url($index, PHP_URL_PATH) ?? '') : '';

            if ($list === '' || $list === '/') {
                continue;
            }

            // @phpstan-ignore-next-line method.notFound（`published()` は HasContentSchema の scope）
            if ((int) $types->newModel($id)->newQuery()->published()->count() === 0) {
                continue;
            }

            // 店が付けた呼び名（工務店なら「施工事例」）で言う。型の名前（投稿）では通じない
            $label = app(ContentTypeSettingsResolver::class)->for($id)->labelOr($schema->label);

            $from = array_filter(
                $linkedFrom[$list] ?? [],
                static fn (string $page): bool => $page !== $list && ! str_starts_with($page, rtrim($list, '/').'/'),
            );

            if ($from === []) {
                $findings[] = [
                    'level' => 'blocker',
                    'where' => "「{$label}」の一覧（{$list}）",
                    'what' => "「{$label}」の記事を公開していますが、一覧へサイトのどこからも行けません"
                        .'（メニューかトップのボタンに一覧を置いてください）',
                    'fix' => [
                        'href' => '/admin/menus/header',
                        'label' => 'メニューに一覧を足す',
                    ],
                ];
            }
        }

        return $findings;
    }

    /** 一度に確かめるリンク先の数。多いサイトでも公開前チェックの画面を待たせすぎない */
    private const MAX_LINKS = 60;

    /**
     * 描いた HTML の中の、サイト内へのリンク先（パスだけ・重複なし）。
     *
     * 見るのは `<a href>` だけ。画像や CSS、管理画面、ログインは対象にしない。
     *
     * @return list<string>
     */
    public static function internalLinksIn(string $html): array
    {
        preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $html, $m);

        $root = rtrim((string) url('/'), '/');
        $found = [];

        foreach ($m[1] as $raw) {
            $href = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (str_starts_with($href, $root)) {
                $href = substr($href, strlen($root));
            }

            if ($href === '' || ! str_starts_with($href, '/') || str_starts_with($href, '//')) {
                continue;
            }

            $path = (string) (parse_url($href, PHP_URL_PATH) ?? '');

            if ($path === '' || preg_match('#^/(admin|login|logout|storage|build|imported|_)#', $path) === 1
                || preg_match('/\.[a-z0-9]{2,5}$/i', $path) === 1) {
                continue;
            }

            $found[$path] = true;
        }

        return array_keys($found);
    }

    /**
     * ページの中のリンクで、押すと開けないもの（UX 一周 2026-09-28）。
     *
     * ボタンのリンク先に `/works` と書いたが、ページの URL は `/gallery` だった。
     * sitemap のページは全部開けるので、上の検査は何も言わず、**押すと 404 のボタン**が公開された。
     *
     * @param  array<string, string>  $links  リンク先 => そのリンクがあったページ
     * @param  list<string>  $checked  既に開けることを確かめたページ
     * @return list<array{level: string, where: string, what: string}>
     */
    private function brokenLinkFindings(array $links, array $checked): array
    {
        $findings = [];

        foreach (array_slice($links, 0, self::MAX_LINKS, true) as $href => $where) {
            if (in_array($href, $checked, true)) {
                continue;
            }

            [$status] = $this->fetch($href);

            if ($status >= 400) {
                $findings[] = [
                    'level' => 'blocker',
                    'where' => $where,
                    'what' => "ページの中のリンク先が開けません: {$href}（{$status}）。リンク先を直すか、そのページを作って公開してください",
                ];
            }
        }

        return $findings;
    }

    /**
     * 「公開」にしたのに、サイトに出ていない記事（UX 一周 2026-09-28）。
     *
     * 新しいプロジェクトの「投稿」は公開サイトに出さない設定で始まる。
     * そこで記事を「公開」にすると、一覧には「公開」と出るのに、サイトのどこにも無い。
     * sitemap にも載らないので、上の検査（sitemap を見る）では**見えない**。
     *
     * @return list<array{level: string, where: string, what: string, fix: array{href: string, label: string}}>
     */
    private function hiddenPublishedFindings(): array
    {
        $types = app(ContentTypeResolver::class);
        $schemas = app(SchemaRegistry::class);
        $lifecycle = app(ContentTypeLifecyclePolicy::class);
        $findings = [];

        foreach ($types->availableTypes() as $id) {
            $schema = $schemas->get($id);

            if (! EditorialCollections::isEditorial($schema) || $lifecycle->isPublic($id)) {
                continue;
            }

            // @phpstan-ignore-next-line method.notFound（`published()` は HasContentSchema の scope）
            $count = (int) $types->newModel($id)->newQuery()->published()->count();

            if ($count > 0) {
                // 店が付けた呼び名で言う（サイドバーと同じ言葉。「投稿」では別のものに見える）
                $label = app(ContentTypeSettingsResolver::class)->for($id)->labelOr($schema->label);
                $findings[] = [
                    'level' => 'blocker',
                    'where' => "「{$label}」の記事",
                    'what' => "「公開」にした記事が {$count} 件ありますが、「{$label}」を公開サイトに出さない設定のため、サイトには表示されていません",
                    'fix' => [
                        // 記事の一覧に「公開サイトに出せるようにする」ボタンがある
                        'href' => Route::has("admin.{$id}.index") ? route("admin.{$id}.index", absolute: false) : "/admin/content-types/{$id}/edit",
                        'label' => '公開サイトに出せるようにする',
                    ],
                ];
            }
        }

        return $findings;
    }

    /**
     * 公開中のページの URL が、仮の形（page・page-2・page-20261106 など）のまま（UX 一周 19 周目 U1）。
     * 訪問者に見える URL なので、お店の人に分かる名前にしてもらう。
     *
     * @return list<array{level: string, where: string, what: string, fix: array{href: string, label: string}}>
     */
    private function provisionalUrlFindings(): array
    {
        $findings = [];

        foreach (Page::query()->where('status', PageStatus::Published->value)->orderBy('id')->get() as $page) {
            $slug = (string) $page->slug;

            if (preg_match('/^page(?:-\d{8})?(?:-\d+)?$/', $slug) !== 1) {
                continue;
            }

            $findings[] = [
                'level' => 'warning',
                'where' => '/'.$slug,
                'what' => "「{$page->title}」の URL が仮の形（/{$slug}）のままです。お店の人と訪問者に分かる URL にしてください",
                'fix' => ['href' => "/admin/pages/{$page->getKey()}/edit#page-slug", 'label' => 'URL を直す'],
            ];
        }

        return $findings;
    }

    /**
     * 設定側で埋まっていないもの。**画面に出ていなくても、届かない原因になる。**
     *
     * @return list<array{level: string, where: string, what: string}>
     */
    private function settingFindings(): array
    {
        $findings = [];

        /*
         * 🔴 **送る側と同じ順で、受け口ごとに見る**（`InboxRecipients`）。
         *
         * ここがサイト設定の宛先だけを見ていたので、フォームの画面で通知先を入れても
         * 「開発用の宛先のまま」と言い続けた（UX 一周 2026-09-28）。
         * 検査が送る側と別の場所を見ていると、**埋めたのに落ち続ける**か、
         * **埋まっていないのに通る**。どちらも害になる。
         */
        foreach (app(InboxRecipients::class)->problems() as $problem) {
            $findings[] = [
                'level' => 'blocker',
                'where' => $problem['where'],
                'what' => $problem['to'] === ''
                    ? '通知先がどこにも入っていない（送った人には「送信完了」と出るので、双方が気づけない）'
                    : "開発用の宛先のままになっている（{$problem['to']}）",
            ];
        }

        $mailer = (string) config('mail.default', '');

        if (in_array($mailer, ['log', 'array'], true)) {
            $findings[] = [
                'level' => 'blocker',
                'where' => 'メール設定',
                'what' => "メールが実際には送られない設定になっている（mail.default = {$mailer}）",
            ];
        }

        return $findings;
    }

    /**
     * 公開している URL。**sitemap と同じものを見る** —
     * 二重に持つと、片方だけ増えたときに検査がすり抜ける。
     *
     * @return list<string>
     */
    private function publicPaths(): array
    {
        preg_match_all('|<loc>([^<]+)</loc>|', $this->render('/sitemap.xml'), $m);

        $paths = [];

        foreach ($m[1] as $loc) {
            $path = parse_url(html_entity_decode($loc), PHP_URL_PATH);

            /*
             * 🔴 **トップだけが黙って抜けていた**（2026-08-28）。
             * sitemap の 1 本目は `http://.../` で、`parse_url` が
             * **空文字を返す**（`null` のこともある）。
             * 「空なら飛ばす」と書くと、**一番見られるページが検査から消える**。
             */
            $paths[] = is_string($path) && $path !== '' ? $path : '/';
        }

        return $paths === [] ? ['/'] : array_values(array_unique($paths));
    }

    /** 訪問者が見るのと同じものを描く。 */
    private function render(string $path): string
    {
        return $this->fetch($path)[1];
    }

    /**
     * 訪問者が受け取るものを、**状態コードごと**返す。
     *
     * 🔴 **本文だけ見ていると、404 が緑で通る。**404 の本文に原稿待ちの言葉は
     * 無いので、印を数える検査は何も言わない（2026-08-29 実測）。
     *
     * @return array{0: int, 1: string}
     */
    private function fetch(string $path): array
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        /*
         * 🔴 **元の request へ必ず戻す**（2026-08-30 実測）。
         *
         * `$kernel->handle()` は container の `request` を**差し替える**。
         * console から呼ぶぶんには誰も困らなかったが、
         * **管理画面から呼んだ瞬間に外側の応答が壊れた** —
         * `Request::create('/about')` は `http://localhost`（ポート無し）なので、
         * 戻さないと以降の `asset()` が `http://localhost/build/...` を吐き、
         * **CSP の `'self'`（`localhost:8181`）に弾かれて画面が真っ白になる**。
         * 実際に 46 件の CSP 違反が出た。
         *
         * 「検査したせいで検査した画面が壊れる」ので、**例外が出ても戻す**。
         */
        $original = app('request');

        /*
         * 🔴 **資材の台帳も戻す**（WP 一周 6 周目 2026-09-03）。
         *
         * request を戻しただけでは足りなかった。公開ページを描くと
         * **Vite の preload 台帳**へ公開側の資材が積まれ、
         * `AddLinkHeadersForPreloadedAssets` がそれを**外側の応答**へ付ける:
         *
         * ```
         * Link: <http://localhost/build/assets/public-….css>; rel=preload
         * ```
         *
         * ポートが無いのは、`Request::create('/about')` が `http://localhost` だからである。
         * ブラウザはこれを先読みしようとし、CSP に弾かれる（実測で 4 件）。
         * **画面は動くので気づけない** — console を見た人だけが「壊れている」と読む。
         *
         * 台帳を空にする（`flush()`）のではなく、**使い捨てへ差し替えて戻す**。
         * 空にすると、この検査より前に積まれた**外側の資材まで落とす**ことになる。
         */
        $originalVite = app(Vite::class);

        /*
         * 🔴 **ファサードが掴んでいる実体も一緒に外す**（WP 一周 7 周目 2026-09-04）。
         *
         * `app()->instance()` は container にだけ効く。ファサードは一度解決した実体を
         * 静的に持ち続けるので、**差し替えたつもりでも `Vite::` は外側を掴んだまま**になる。
         *
         * 内側のリクエストでも `AdminCspMiddleware` は走る。そこで呼ばれる
         * `Vite::useCspNonce()` が**外側の nonce を書き換えてしまい**、
         * middleware が既にヘッダへ書いた値と割れる。結果、`/admin/readiness` を
         * 直接開くと **head の inline 3 つが全部弾かれる**（dark mode・背景色・font）。
         *
         * 見た目はほとんど変わらないので、console を開いた人しか気づけない。
         */
        Facade::clearResolvedInstance(Vite::class);

        app()->instance(Vite::class, new Vite);

        // 内部検査は匿名の独立セッションで実行する。外側の Store を再利用すると、
        // StartSession が ID と属性を差し替え、画面の再読み込みでログアウトしてしまう。
        $savedBindings = [];
        foreach (['session', 'session.store', 'auth', 'auth.driver', 'cookie', StartSession::class, ResponseFactory::class] as $binding) {
            $savedBindings[$binding] = app($binding);
        }
        $sessionManager = new SessionManager(app());
        $sessionManager->extend((string) config('session.driver'), fn () => new ArraySessionHandler(
            (int) config('session.lifetime'),
        ));
        app()->instance('session', $sessionManager);
        app()->instance('session.store', $sessionManager->driver());
        app()->instance('auth', new AuthManager(app()));
        app()->forgetInstance('auth.driver');
        app()->forgetInstance(StartSession::class);
        $cookies = clone $savedBindings['cookie'];
        foreach ($cookies->getQueuedCookies() as $cookie) {
            $cookies->unqueue($cookie->getName(), $cookie->getPath());
        }
        app()->instance('cookie', $cookies);
        // 内部の匿名リクエストが共有propsを上書きしても、外側のユーザー名・権限を保持する。
        app()->instance(ResponseFactory::class, clone $savedBindings[ResponseFactory::class]);
        foreach (['session', 'auth', 'cookie', ResponseFactory::class] as $facade) {
            Facade::clearResolvedInstance($facade);
        }

        try {
            $response = $kernel->handle(Request::create($path, 'GET'));
        } finally {
            foreach ($savedBindings as $binding => $instance) {
                app()->instance($binding, $instance);
            }
            foreach (['session', 'auth', 'cookie', ResponseFactory::class] as $facade) {
                Facade::clearResolvedInstance($facade);
            }
            app()->instance('request', $original);
            app()->instance(Vite::class, $originalVite);
            // 戻すときも同じ。外さないと、内側の使い捨てを掴んだままになる
            Facade::clearResolvedInstance(Vite::class);
            app(UrlGenerator::class)->setRequest($original);
        }

        return [$response->getStatusCode(), $response->getContent() ?: ''];
    }
}
