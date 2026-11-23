<?php

namespace App\Http\Middleware;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Backups\AutoBackupStatus;
use AgentCms\Core\ContentTypes\Registration\ContentTypeLifecyclePolicy;
use AgentCms\Core\ContentTypes\Registration\PublishingQuickStart;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\User;
use App\Support\AdminScreenAccess;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'adminScreen' => [
                'readOnly' => AdminScreenAccess::readOnly($request),
                'readOnlyMessage' => AdminScreenAccess::readOnlyMessage($request),
                'editing' => AdminScreenAccess::editing($request),
                'navigation' => $request->user() === null ? [] : AdminScreenAccess::navigation($request->user()->role()),
            ],
            'auth' => [
                'user' => $request->user(),
                // 画面の出し分けに使う権限。**判定そのものはサーバーが authoritative** で、
                // ここは「見せる / 見せない」を決めるためだけの情報
                'capabilities' => $this->capabilities($request),
            ],
            /*
             * 保存できたことを画面が言えるようにする（R-8A 監査 指摘 24）。
             *
             * `with('success', …)` は 7 か所以上で使われていたのに、**共有も描画も無く、
             * どこにも出ていなかった** — 「保存したのに画面が何も言わない」が
             * 管理画面全体の状態だった。ここで 1 度共有すれば、全部が一斉に効く。
             */
            'flash' => [
                'success' => $request->hasSession() ? $request->session()->get('success') : null,
                /*
                 * **できなかったことも、同じ場所で言う**（WP 一周 8 周目 2026-09-04 / 効くもの ③）。
                 *
                 * 戻せない backup を押したときは `abort(409)` に落ちていた。Inertia は
                 * 非 Inertia の応答を全画面の `about:srcdoc` iframe に流すので、
                 * 買った人の画面に出るのは **Laravel の例外ページ**だった。
                 * 本番は `APP_DEBUG=false` なので、理由すら消えて「Conflict」だけになる。
                 *
                 * 「できない」は異常終了ではなく、**次の手を選べる状態**である。
                 * success と同じ 1 か所で受け取り、同じ 1 か所が描く。
                 */
                'error' => $request->hasSession() ? $request->session()->get('error') : null,
            ],
            /*
             * 画面が日時を読むための時間帯（WP 一周 5 周目 2026-09-03 / 効くもの ①）。
             *
             * 線の上を流れる日時は ISO8601（UTC・末尾 Z）のままにする —
             * API / MCP から読む AI エージェントも同じ値を見るので、契約は変えない。
             * **人が読む面だけ**をここで受け取った時間帯へ直す（`@/lib/admin-datetime`）。
             *
             * 🔴 **ブラウザの時計で読んではいけない。** 出張先や海外の担当者の画面だけ
             * 公開日時がずれ、しかも本人には気づけない。時間帯は 1 つ、サーバーのものである。
             */
            'timezone' => config('app.timezone'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'nav' => [
                // サイドバーの投稿タイプは **実行時の登録簿から作る**（Phase 9.4 / SPEC §1D）。
                // news / works を画面側の配列へ焼き込まない
                'contentTypes' => $this->contentTypes($request),
                /*
                 * 「投稿」が在るはずの場所（WP 一周 2026-09-03 / 効くもの ①）。
                 *
                 * WordPress から来た人はサイドバーの「投稿」を探す。無いと
                 * 「この CMS には記事が無い」と読んでそこで止まる。
                 * **書ける入れ物が 1 つも無いときだけ**、薄い案内を 1 行置く。
                 */
                'quickStart' => $this->quickStart($request),
                /*
                 * **案件で使う機能**（B-39）。権限とは別の軸である。
                 *
                 * 権限は「その人ができるか」、これは「この案件で使うか」。
                 * 混ぜると「権限が在るのに出ない」を権限のせいだと読み違える。
                 *
                 * **route は閉じない**（B-39 裁定）。サイドバーから外すだけで、
                 * URL では開ける — それは設定画面が言う。
                 */
                'features' => $this->features($request),
                /*
                 * **届かなかった通知の数**（WP 一周 10 周目 2026-09-04）。
                 *
                 * 問い合わせ通知メールが落ちても、能動的に知らせる手段は Push しか無く、
                 * VAPID キーは生成 project で空なので買った人は全員その状態で始まる。
                 *
                 * 🔴 **メールが来ないことは「問い合わせが無い」と区別がつかない。**
                 * だから気づく理由すら生まれない。受信一覧を開けば「失敗」とは出るが、
                 * **開く理由が無い**。
                 *
                 * オーナー決定（2026-09-04）: すでに目が慣れている場所 —
                 * サイドバーの「フォーム」に赤い数で出す。
                 */
                'undelivered' => $this->undelivered($request),
                /*
                 * **最後の自動 backup が失敗した・止まっている**（1 か 0）。
                 *
                 * cron の `schedule:run` は中のコマンドが失敗しても exit 0 で終わり、理由はログにしか
                 * 出ない。バックアップ画面は開く理由が無い — 届かなかった通知と同じ形なので、
                 * 同じくサイドバーの「バックアップ」に赤い印で出す。バックアップ画面を開ける人にだけ。
                 */
                'backupAlert' => $this->backupAlert($request),
            ],
        ];
    }

    /**
     * この案件で使う機能。**未設定は「使う」**（入れただけで消えないようにする）。
     *
     * @return array<string, bool>
     */
    /**
     * 通知メールが届かなかった受信の数。
     *
     * **見えないものの数は出さない** — 受信を読めない人には 0 を返す。
     * 旧受信（`contact_submissions`）も同じ条件で数える。移行していない案件では
     * そちらにだけ残っていることがあるためである。
     */
    private function undelivered(Request $request): int
    {
        // 共有 props はログイン画面でも通る（`features()` と同じ理由）
        if ($request->user() === null) {
            return 0;
        }

        if (! in_array(
            Capability::ContactRead->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($request->user()),
            true,
        )) {
            return 0;
        }

        $count = 0;

        foreach (['form_submissions', 'contact_submissions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count += (int) DB::table($table)
                ->whereNull('admin_notified_at')
                ->whereNotNull('admin_notification_error')
                ->count();
        }

        return $count;
    }

    /** 最後の自動 backup を見てほしいか（バックアップ画面を開けない人には 0） */
    private function backupAlert(Request $request): int
    {
        if ($request->user() === null || ! in_array(
            Capability::BackupRestore->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($request->user()),
            true,
        )) {
            return 0;
        }

        return app(AutoBackupStatus::class)->read()['attention'] ? 1 : 0;
    }

    /** @return array<string, bool> */
    private function features(Request $request): array
    {
        /*
         * **ログインしていなければ DB を触らない。**
         *
         * 共有 props は**ログイン画面でも通る**。まだ migrate していない環境では
         * `settings` テーブルが無く、読むと 500 になる（実測: `AuthLocalizationTest` が落ちた）。
         * 権限・投稿タイプの共有も同じ理由で利用者を先に見ている。
         */
        if ($request->user() === null) {
            return [];
        }

        $settings = app(SettingsStore::class);

        return [
            'comments' => $settings->get('show_comments_menu', true) !== false,
        ];
    }

    /**
     * 今ログインしている利用者ができること。
     *
     * @return list<string>
     */
    private function capabilities(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        $policy = app(RoleOperationPolicy::class);

        return $policy->capabilitiesFor($user);
    }

    /**
     * サイドバーへ出す collection 型 Content Type。
     *
     * 母集団は `ContentTypeResolver`（schema とモデルの両方が揃った型）で、
     * `content.types.list` / `/api/v1/content-types` と**同じ authoritative な一覧**である。
     * 管理画面の route を持たない型（公開だけの型など）は出さない。
     *
     * @return list<array<string, string>>
     */
    /**
     * 1 クリックの出口（無ければ null）。判定は `PublishingQuickStart` 1 か所。
     *
     * @return array{typeId: string, label: string, url: string}|null
     */
    private function quickStart(Request $request): ?array
    {
        if ($request->user() === null) {
            return null;
        }

        $schemas = app(SchemaRegistry::class);
        $resolver = app(ContentTypeResolver::class);
        $lifecycle = app(ContentTypeLifecyclePolicy::class);

        $id = PublishingQuickStart::candidateFor($schemas, $resolver, $lifecycle);

        if ($id === null) {
            return null;
        }

        return [
            'typeId' => $id,
            'label' => $lifecycle->settings($id)->labelOr($schemas->get($id)->label),
            'url' => route('admin.content-types.quick-start', ['type' => $id], absolute: false),
        ];
    }

    /** @return list<array{id: string, label: string, href: string}> */
    private function contentTypes(Request $request): array
    {
        if ($request->user() === null) {
            return [];
        }

        $resolver = app(ContentTypeResolver::class);
        $router = app(Router::class);
        $items = [];

        // 状態の判定は 1 か所（SPEC §J.4）。ここで `status` を読みに行かない
        $lifecycle = app(ContentTypeLifecyclePolicy::class);

        foreach ($resolver->availableTypes() as $id) {
            $schema = $resolver->schema($id);

            if ($schema->kind !== 'collection' || ! $router->has("admin.{$id}.index")) {
                continue;
            }

            if (! $lifecycle->showInMenu($id)) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'label' => $lifecycle->settings($id)->labelOr($schema->label),
                'href' => route("admin.{$id}.index", absolute: false),
            ];
        }

        return $items;
    }
}
