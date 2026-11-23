<?php

use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Http\AdminCspMiddleware;
use AgentCms\Core\Http\AssignRequestId;
use AgentCms\Core\Http\RestorePendingMiddleware;
use AgentCms\Core\Http\SecurityHeadersMiddleware;
use AgentCms\Core\Redirects\NotFoundHandler;
use AgentCms\Core\Trash\TrashReadinessUnavailable;
use App\Http\Controllers\PublicFormController;
use App\Http\Middleware\AdminNoStore;
use App\Http\Middleware\BlockConcurrentSessionWrites;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireSiteBuild;
use App\Http\Middleware\StagingNoindex;
use App\Http\Responses\AdminForbiddenPage;
use App\Http\Responses\NotFoundPage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // 公開環境だけで毎日確認する。Cron停止時は画面の48時間鮮度判定で未確認へ戻る。
        $schedule->command('agentcms:maintenance')->dailyAt('03:20')->withoutOverlapping(15)->environments(['production', 'staging']);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        /*
         * 監査の相関 ID を **すべてのリクエストの先頭で** 採番する
         * （STAGE #18A / Correction 2-3）。web も api も同じ 1 本を通すため
         * global stack の先頭へ置く。受信ヘッダーは読まない。
         */
        $middleware->prepend(AssignRequestId::class);

        /*
         * 途中で止まった backup の復元が残っているあいだは **全部の要求を 503** で止める
         * （release R09）。web も api も health も。整えるのは `agentcms:backup-recover`。
         */
        $middleware->append(RestorePendingMiddleware::class);

        // 無効化された利用者を締め出す（STAGE #18A）。
        // auth を要求する route グループへ明示的に付ける（`active`）
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            // サイトの作りの画面は制作者だけ（UX 一周 2026-09-28）
            'site.build' => RequireSiteBuild::class,
        ]);

        /*
         * 管理画面の書き込みと描き直しは、同じ session の中で順に処理する（session blocking）。
         * 同時に走ると、遅れて終わった方が session を古い中身で書き戻し、知らせ（flash）を消していた
         * （UX 一周 23 周目 #1 の根）。StartSession より前に置く。
         */
        $middleware->web(prepend: [BlockConcurrentSessionWrites::class]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AdminCspMiddleware::class,
            SecurityHeadersMiddleware::class,
            StagingNoindex::class,
            // 管理画面をブラウザに残させない（UX 一周 13 周目 R13-1・R13-2: 「戻る」で古い画面）
            AdminNoStore::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * ゴミ箱の対応状況を確かめられなかった（Phase 9.8 / STAGE 21B・SPEC §C.4.1.2）。
         *
         * **空の 200 を返さない。** 0 件の一覧を 200 で返すと、公開サイトは
         * 「記事が 1 件も無いページ」を配信する。それは CDN にもブラウザにも残り、
         * 利用者には「サイトの中身が全部消えた」と見える。読めないなら 503 で言う。
         *
         * 変換はここ 1 か所だけ。面ごとに書かない。**原因は外へ出さない** —
         * 出すのは「今は読めない。少ししてからもう一度」だけである。
         */
        /*
         * 404 になるときだけ転送を考える（Phase 9.10 / P0-5・OG1）。
         *
         * **全リクエストの前段に置かない。** 置くと普通のページ表示に DB を 1 回足す。
         * 転送したい URL はほぼ必ず「もう無い URL」なので、404 が確定してから引けば
         * 通常のアクセスへの費用はゼロになる。
         *
         * 公開面の 404 は fallback の中の 2 つの controller と非 GET の fallback から
         * 出ているが、最後はすべてここを通る。**面ごとに書かない。**
         *
         * 転送しないときは 404 を記録する。null を返せば、これまでどおりの 404 になる。
         */
        // 送信制限でも書いた内容を残し、同じ入力画面からやり直せるようにする。
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 429 || ! $request->isMethod('POST') || $request->expectsJson()) {
                return null;
            }

            if ($request->routeIs('forms.store', 'forms.confirm')) {
                return app(PublicFormController::class)->rateLimited(
                    $request, (string) $request->route('key'), app(FormFields::class), $e->getHeaders(),
                );
            }

            if ($request->routeIs('contact.store')) {
                $request->session()->flashInput($request->only(['name', 'email', 'subject', 'message', 'privacy']));

                return response()->view('contact::form', [
                    'rateLimitMessage' => '少し待ってからもう一度送ってください。入力した内容は残っています。',
                ], 429, $e->getHeaders() + ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
            }

            return null;
        });

        // 権限の無い管理画面は、管理画面の枠の中で言う（白地の 403 にしない・UX 一周 23 周目 #6）
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return app(AdminForbiddenPage::class)->render($e, $request);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $redirect = app(NotFoundHandler::class)->handle($request);
            if ($redirect !== null || $request->is('api/*') || $request->expectsJson()) {
                return $redirect;
            }

            return app(NotFoundPage::class)->render($request);
        });

        $exceptions->render(function (TrashReadinessUnavailable $e, Request $request) {
            $retryAfter = 60;

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(
                    ['message' => $e->safeMessage()],
                    503,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            return response($e->safeMessage(), 503, [
                'Retry-After' => (string) $retryAfter,
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        });
    })->create();
