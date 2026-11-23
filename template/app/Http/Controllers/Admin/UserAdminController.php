<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use App\Application\Activity\ActivityFilter;
use App\Application\Activity\ActivityLabels;
use App\Application\Activity\ListActivity;
use App\Application\Users\ChangeUserRole;
use App\Application\Users\DeactivateUser;
use App\Application\Users\GetUser;
use App\Application\Users\InviteUser;
use App\Application\Users\IssueSetupLink;
use App\Application\Users\ListUsers;
use App\Application\Users\PasswordSetupLink;
use App\Application\Users\ReactivateUser;
use App\Application\Users\ResendInvitation;
use App\Application\Users\UpdateUser;
use App\Application\Users\UserPresenter;
use App\Support\SafeFailureLog;
use App\Support\SetupLinkVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * 利用者の管理画面（PHASE WP-BASELINE / STAGE #18A・SPEC §1A.10）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**Controller から DB を直接更新しない。**
 * 認可・検証・確認・session 失効・監査はすべて Application Service 側にある。
 *
 * ## 読み取りにも権限を効かせる
 *
 * 一覧も詳細も Application Service を通すので、URL 直打ち（IDOR）でも
 * 同じ拒否になる。画面で項目を隠すのは補助でしかない。
 */
final class UserAdminController
{
    public function __construct(
        private readonly SetupLinkVault $setupLinks,
        private readonly PasswordSetupLink $setupLinkIssuer,
    ) {}

    public function index(Request $request, ListUsers $list): Response
    {
        $result = $list->execute(
            $this->context($request),
            trim((string) $request->query('q', '')),
            (string) $request->query('role', ''),
            (string) $request->query('status', ''),
            (int) $request->query('page', 1),
        );

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/users/Index', [
            'users' => $result->data['users'] ?? [],
            'meta' => $result->meta,
            'filters' => [
                'q' => trim((string) $request->query('q', '')),
                'role' => (string) $request->query('role', ''),
                'status' => (string) $request->query('status', ''),
            ],
            'options' => UserPresenter::filterOptions(),
        ]);
    }

    /**
     * 招待フォーム。
     *
     * **画面を出すだけでも Application Service を通す**（Correction 1）。
     * ここを素通りさせると、権限の無い利用者が URL 直打ちで
     * 招待フォームへ到達できてしまう（送信は拒否されるが、
     * 「見えるのに使えない」入口を残さない）。
     */
    public function create(Request $request, ListUsers $list): Response
    {
        $result = $list->execute($this->context($request), perPage: 1);

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/users/Create', [
            'options' => UserPresenter::filterOptions(),
        ]);
    }

    public function store(Request $request, InviteUser $invite): SymfonyResponse
    {
        $result = $invite->execute($this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        // オーナーを増やす招待は確認を通す（Application Service が決める）
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Forbidden) {
            $this->abortFor($result);
        }

        if (! $result->isSuccess()) {
            return back()->withErrors(['email' => (string) $result->message]);
        }

        /*
         * 受け渡し方法で結末の見せ方が変わる（STAGE #18B）。
         *
         * 手動なら **URL をこの 1 回だけ**画面へ渡す。
         * flash なので次のリクエストには残らない（保存もしない）。
         */
        if (($result->meta['deliveryMethod'] ?? null) === InviteUser::DELIVERY_MANUAL) {
            return $this->handOverSetupLink(
                $result,
                '利用者は作成されました。ただし',
                redirect()->route('admin.users.index'),
            );
        }

        $delivered = (bool) ($result->meta['delivered'] ?? true);

        return redirect()
            ->route('admin.users.index')
            ->with('success', $delivered
                ? '利用者を招待しました。パスワード設定の案内メールを送信しました。'
                : '利用者を作成しましたが、招待メールを送信できませんでした。一覧から招待を再送してください（重複して作成しないでください）。');
    }

    /**
     * パスワード設定 URL を発行し直す（STAGE #18B）。
     *
     * 発行のたびに前の URL は無効になる。表示は 1 回だけ。
     */
    public function issueSetupLink(Request $request, IssueSetupLink $issue, int $id): SymfonyResponse
    {
        $result = $issue->execute($id, $this->context($request));

        if ($result->status === OperationStatus::Forbidden) {
            $this->abortFor($result);
        }

        if (! $result->isSuccess()) {
            /*
             * `withErrors` は Edit 画面に表示先が無く、**利用者には見えない**。
             * 失敗は必ず目に入る形（error トースト）で返す。
             * 文言は Application Service が決めたもので、URL も token も含まない。
             */
            return $this->toast(back(), 'error', (string) $result->message);
        }

        return $this->handOverSetupLink($result, '', back());
    }

    /**
     * 発行した URL の受け渡し画面（STAGE #18B）。
     *
     * ## secret を URL へ載せない
     *
     * ここへは **秘密を含まない固定の URL** で来る。
     * リダイレクト先の path や query に token を置くと、
     * ブラウザ履歴・アクセスログ・Referer へ複製されてしまう。
     * 実体は暗号化した封筒として session に預けてあり、
     * この画面が取り出すと同時に消える。
     *
     * 封筒が無い（直接開いた・再読み込み・戻る）ときは
     * 何も見せずに一覧へ返す。
     */
    public function showSetupLink(Request $request, ListUsers $list, SetupLinkVault $vault): SymfonyResponse
    {
        // 画面を出すだけでも権限を通す（Correction 1 と同じ理由）
        $allowed = $list->execute($this->context($request), perPage: 1);

        if (! $allowed->isSuccess()) {
            $this->abortFor($allowed);
        }

        $envelope = $vault->pull();

        if ($envelope === null) {
            return redirect()->route('admin.users.index');
        }

        /*
         * この 1 画面だけ **厳格な CSP** を張る（管理画面全体には CSP が無い）。
         * 表示しているのが資格情報そのものなので、万一の混入で
         * 外部へ送られる経路（img / connect / form）をすべて閉じる。
         * style と script は nonce を持つものだけ通す。
         */
        $nonce = base64_encode(random_bytes(16));

        $response = $this->withoutTrace(
            response()->view('admin.setup-link', [...$envelope, 'nonce' => $nonce]),
        );

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'none'",
            "style-src 'nonce-{$nonce}'",
            "script-src 'nonce-{$nonce}'",
            "img-src 'none'",
            "connect-src 'none'",
            "font-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'none'",
        ]));

        return $response;
    }

    /**
     * 発行した URL を **1 回だけ**渡すための受け渡し。
     *
     * 暗号化した封筒を session へ預け、秘密を含まない固定 URL へ送る。
     * URL そのものは DB にも監査にもログにも履歴にも書かない。
     */
    private function handOverSetupLink(
        Result $result,
        string $whatWasDone,
        RedirectResponse $onFailure,
    ): SymfonyResponse {
        $userId = (int) ($result->data['user']['id'] ?? 0);

        try {
            $this->setupLinks->stash([
                'url' => (string) ($result->data['setupUrl'] ?? ''),
                'expiresInMinutes' => (int) ($result->data['expiresInMinutes'] ?? 0),
                'userName' => (string) ($result->data['user']['name'] ?? ''),
                'userId' => $userId,
            ]);
        } catch (Throwable $e) {
            /*
             * 発行は済んでいるのに、渡す途中で落ちた。
             *
             * ここで 500 にすると **誰も知らない有効な鍵**が期限まで残る。
             * だから (1) 例外の中身を出さずに記録し、(2) その鍵をその場で失効させ、
             * (3) 何が起きて次に何をすればよいかを日本語で返す。
             */
            SafeFailureLog::record('パスワード設定 URL を表示できませんでした', $e);

            /*
             * 案内は **実際に起きたことだけ**を書く。
             * 失効に失敗したのに「使えなくしました」と言うと、
             * 「もう安全だ」と思わせたぶんだけ危険になる。
             */
            $revoked = $this->setupLinkIssuer->revokeFor($userId);

            /*
             * **成功として見せない。**
             * `with('success')` は共有されておらず画面に出ないうえ、
             * 失敗を成功の色で出すことにもなる。error トーストで返す。
             */
            return $this->toast($onFailure, 'error', $whatWasDone
                .'パスワード設定 URL を表示できませんでした。'
                .($revoked
                    ? 'この URL は使えなくしました。詳細画面から発行し直してください。'
                    : '詳細画面から発行し直してください（発行し直すと、以前の URL は使えなくなります）。'));
        }

        /*
         * **ハード遷移で送る**（`Inertia::location`）。
         *
         * 受け取り画面は Inertia ではなく素の Blade なので、
         * 普通のリダイレクトを返すと Inertia の client が
         * 「Inertia ではない応答」としてモーダルへ押し込んでしまう
         * （URL は変わらず、離脱・戻るの扱いも壊れる）。
         * 409 + `X-Inertia-Location` を返してブラウザに本物のページ遷移をさせる。
         * Inertia 以外からの呼び出しでは通常の 302 になる。
         */
        return Inertia::location(route('admin.users.setup-link.show'));
    }

    /**
     * 画面に必ず見える形で結末を伝える。
     *
     * `redirect()->with('success')` は `HandleInertiaRequests` が共有しておらず、
     * 画面の toast も Inertia の flash しか見ていない。
     * つまり session へ入れても **利用者には届かない**ので、
     * 届く経路（`Inertia::flash('toast')`）で返す。
     *
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    private function toast(RedirectResponse $redirect, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return $redirect;
    }

    /**
     * この応答を **どこにも残さない**ためのヘッダー。
     *
     * キャッシュ・履歴からの復元・Referer 経由の流出を止める。
     * 「戻る」で復元された場合の対処は画面側の `pageshow` が受け持つ。
     */
    private function withoutTrace(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function edit(Request $request, GetUser $get, int $id): Response
    {
        $result = $get->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/users/Edit', [
            'user' => $result->data['user'] ?? [],
            'options' => UserPresenter::filterOptions(),
        ]);
    }

    public function update(Request $request, UpdateUser $update, int $id): RedirectResponse
    {
        $result = $update->execute($id, $this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        return $this->redirectFor($result, $this->updateMessage($result), $id);
    }

    /**
     * 更新後の案内文（Correction 2-8 / 3 / 4）。
     *
     * **更新自体は成功している**ので、送信に失敗しても失敗として見せない。
     * ただし案内は **実際に効く手順だけ**を、相手の状態に合わせて書く。
     *
     * 以前ここには「同じ内容で保存し直すと再送されます」と出していたが、
     * 2 回目の保存はメールアドレスが変わらないため `UpdateUser` は
     * 送信処理へ入らない。**絶対に再送されない手順を案内していた**。
     * 案内が嘘だと、利用者は効かない操作を繰り返して詰まる。
     *
     * 復帰手順は相手の状態でまるごと変わるので、混ぜずに分ける。
     *
     * | 送ったもの | 送信 | 案内 |
     * | --- | --- | --- |
     * | 招待（まだ使い始めていない人） | 成功 | 新しいアドレスへ招待を送ったこと |
     * | 招待 | 失敗 | 一覧から招待を再送（本人はログインできない） |
     * | 確認メール（使い始めている人） | 成功 | 通常の完了 |
     * | 確認メール | 失敗 | 本人が新アドレスでログイン → 確認画面から再送 |
     */
    private function updateMessage(Result $result): string
    {
        $kind = (string) ($result->meta['deliveryKind'] ?? UpdateUser::DELIVERY_NONE);
        $delivered = (bool) ($result->meta['delivered'] ?? true);

        if ($kind === UpdateUser::DELIVERY_INVITATION) {
            return $delivered
                ? '利用者の情報を更新し、新しいメールアドレスへ招待メールを送信しました。'
                    .'以前のメールアドレスへ送った招待リンクは使えなくなります。'
                : '利用者の情報を更新しましたが、招待メールを送信できませんでした。'
                    .'この利用者はまだパスワードを設定していないため、'
                    .'このままでは利用を開始できません。一覧から招待を再送してください。';
        }

        if ($kind === UpdateUser::DELIVERY_VERIFICATION && ! $delivered) {
            return '利用者の情報を更新しましたが、確認メールを送信できませんでした。'
                .'メールアドレスは新しいものに変わっており、本人は確認が済むまで管理画面を使えません。'
                .'本人が新しいメールアドレスでログインすると確認の案内画面が出るので、'
                .'そこから確認メールを再送してください。';
        }

        return '利用者の情報を更新しました';
    }

    public function changeRole(Request $request, ChangeUserRole $change, int $id): RedirectResponse
    {
        $result = $change->execute($id, (string) $request->input('role', ''), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        return $this->redirectFor($result, '権限を変更しました', $id);
    }

    public function deactivate(Request $request, DeactivateUser $deactivate, int $id): RedirectResponse
    {
        $result = $deactivate->execute($id, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        return $this->redirectFor($result, '利用者を無効化しました', $id);
    }

    public function reactivate(Request $request, ReactivateUser $reactivate, int $id): RedirectResponse
    {
        return $this->redirectFor(
            $reactivate->execute($id, $this->context($request)),
            '利用者を再び有効にしました',
            $id,
        );
    }

    public function resendInvitation(Request $request, ResendInvitation $resend, int $id): RedirectResponse
    {
        return $this->redirectFor(
            $resend->execute($id, $this->context($request)),
            '招待メールを再送しました',
            $id,
        );
    }

    /** ユーザー別の行動履歴（全体履歴と同じ操作を actorId 固定で呼ぶ） */
    public function activity(Request $request, GetUser $get, ListActivity $list, int $id): Response
    {
        $context = $this->context($request);
        $user = $get->execute($id, $context);

        if (! $user->isSuccess()) {
            $this->abortFor($user);
        }

        $filter = ActivityFilter::fromInput($request->query(), actorId: $id);
        $result = $list->execute($filter, $context, (int) $request->query('page', 1));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/users/Activity', [
            'user' => $user->data['user'] ?? [],
            'entries' => $result->data['entries'] ?? [],
            'meta' => $result->meta,
            'filter' => $result->data['filter'] ?? [],
            'options' => [
                'operations' => ActivityLabels::operationOptions(),
                'results' => ActivityLabels::resultOptions(),
                'interfaces' => ActivityLabels::interfaceOptions(),
            ],
        ]);
    }

    /**
     * Application Service へ渡す入力。
     *
     * 制御用のキー（CSRF token / method / 確認トークン）だけを外す。
     * 受理キーの判定と拒否は Application Service の責務。
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_diff_key($request->all(), [
            '_token' => true, '_method' => true, 'confirmation_token' => true,
        ]);
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    private function redirectFor(Result $result, string $success, int $id): RedirectResponse
    {
        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['user' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()->route('admin.users.edit', ['id' => $id])->with('success', $success);
    }

    private function abortFor(Result $result): never
    {
        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, $result->message ?? '');
    }
}
