<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Backups\BackupRunStatus;
use AgentCms\Core\Application\Backups\DeleteBackup;
use AgentCms\Core\Application\Backups\ListBackups;
use AgentCms\Core\Application\Backups\RestoreBackup;
use AgentCms\Core\Application\Backups\StartBackup;
use AgentCms\Core\Application\Backups\VerifyBackup;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Backups\BackupCatalog;
use AgentCms\Core\Backups\BackupFailed;
use AgentCms\Core\Backups\BackupPaths;
use AgentCms\Core\Backups\BackupRunLauncher;
use AgentCms\Core\Backups\BackupStage;
use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * backup の管理画面（Phase 9.11 / STAGE 24D・SPEC §H）。
 *
 * ## 取得は画面から始められる。ただし HTTP の中では取らない（OG3 改訂・2026-09-09）
 *
 * 大きなサイトでは web の要求時間に収まらず、途中で切れると中途半端な物が残る —
 * この理由は今も正しい。だから `store` は**別プロセスを起動して記録を残すだけ**で返り、
 * 進み・完了・失敗は `runStatus` が状態ファイルから答える（`StartBackup` / `BackupRunStatus`）。
 *
 * 別プロセスを起動できない環境（`proc_open` が塞がれた共有ホスティング等）では
 * 理由と**取得コマンド**を出す。コマンド表示は無くさない — 画面から始められない
 * 場所でも「どうやって取るのか分からない」を残さないためである。
 *
 * ## 取得・復元・持ち出しは Owner だけ
 *
 * 判定は operation 側の `OperationPolicy` が行う。ここで決めるのは
 * **導線を出すかどうか**だけである（画面で隠しても経路は塞がない）。
 */
final class BackupAdminController
{
    /**
     * 取得コマンド。**一覧と、戻せなかったときの案内が同じ文字列を出す。**
     *
     * 2 か所に書くと、片方だけ直したときに「画面が言うとおりにやったのに動かない」が生まれる。
     */
    private const BACKUP_COMMAND = 'php artisan agentcms:backup';

    public function index(
        Request $request,
        ListBackups $list,
        BackupRunStatus $status,
        BackupRunLauncher $launcher,
    ): Response {
        $result = $list->execute($this->context($request));

        $this->abortUnlessSuccess($result);

        $canCreate = $this->can($request, Capability::BackupRestore);

        /*
         * 進み（いちばん新しい run）は Owner にだけ渡す。画面を開き直しても
         * 「さっき始めた取得がどうなったか」が消えないように、props で最初から持たせる。
         */
        $run = $canCreate ? $status->execute(null, $this->context($request)) : null;

        return Inertia::render('admin/backups/Index', [
            'backups' => $result->data['backups'],
            // 画面からコピーできるようにする（画面から始められない環境の代替）
            'command' => self::BACKUP_COMMAND,
            'canCreate' => $canCreate,
            // 別プロセスを起動できない理由（できるなら null）。ボタンを押す前に分かるようにする
            'createUnavailableReason' => $canCreate ? $launcher->unavailableReason() : null,
            'run' => $run?->isSuccess() === true ? $run->data['run'] : null,
            // 段階の並びと言葉は core が決める（画面に別の表を持たない）
            'stages' => array_map(
                static fn (BackupStage $stage): array => ['key' => $stage->value, 'label' => $stage->label()],
                BackupStage::ordered(),
            ),
            'canRestore' => $this->can($request, Capability::BackupRestore),
            'canDelete' => $this->can($request, Capability::SettingsManage),
        ]);
    }

    /**
     * 取得を**始める**。取得そのものは別プロセスが進める（OG3 改訂）。
     *
     * できない（別の取得が進行中 / 起動できない）は例外ではなく、次の手を選べる状態として
     * flash の `error` で返す（`abort(409)` にしない理由は `cannotRestore` を参照）。
     */
    public function store(Request $request, StartBackup $start): RedirectResponse
    {
        try {
            $result = $start->execute($this->context($request));
        } catch (BackupFailed $e) {
            // 書込不能や状態ロックの失敗でも「始めた」とは言わず、代替手順へ戻す。
            Log::warning('backup.start.failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'バックアップを開始できませんでした。保存先の空き容量と書込権限を確認し、画面下のコマンドで取得する方法もお試しください');
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->with('error', (string) $result->message);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', 'バックアップの取得を始めました。進みはこの画面に出ます');
    }

    /** 進みを答える。**止まっていれば failed に落として答える**（`BackupRunStore::current`） */
    public function runStatus(Request $request, BackupRunStatus $status, string $run): JsonResponse
    {
        $result = $status->execute($run, $this->context($request));

        return response()->json(
            $result->isSuccess() ? $result->data : ['message' => $result->message],
            match ($result->status) {
                OperationStatus::Success => 200,
                OperationStatus::Forbidden => 403,
                default => 404,
            },
        );
    }

    /** **何も変えない。** 戻せるかどうかだけを返す */
    public function verify(Request $request, VerifyBackup $verify, string $id): JsonResponse
    {
        $result = $verify->execute($id, $this->context($request));

        return response()->json(
            $result->isSuccess() ? $result->data : ['message' => $result->message],
            $result->isSuccess() ? 200 : 404,
        );
    }

    public function restore(Request $request, RestoreBackup $restore, string $id): RedirectResponse
    {
        $result = $restore->execute($id, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->with('error', $this->cannotRestore($result));
        }

        $this->abortUnlessSuccess($result);

        /*
         * 復元すると自分の session も切れる。ログイン画面へ戻すのが正直な導線である
         * （そのまま管理画面を出しても、次の操作で弾かれる）。
         */
        return redirect('/login')->with('success', 'backup から復元しました。もう一度ログインしてください');
    }

    public function destroy(Request $request, DeleteBackup $delete, string $id): RedirectResponse
    {
        $result = $delete->execute($id, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', 'backup を削除しました');
    }

    /**
     * 手元へ落とす（SPEC §G.2）。
     *
     * **Owner 限定**で、**監査に残す**。誰がいつ持ち出したかは記録すべき事実である
     * （中には利用者のパスワード hash と問い合わせの本文が入っている）。
     */
    public function download(Request $request, BackupCatalog $catalog, string $id): BinaryFileResponse
    {
        abort_unless($this->can($request, Capability::BackupRestore), 403);
        abort_unless(BackupPaths::isValidId($id) && $catalog->exists($id), 404);

        $user = $request->user();

        Log::channel(config('logging.default'))->info('backup.download', [
            'backup' => $id,
            'user_id' => $user?->getKey(),
        ]);

        return response()->download(BackupPaths::archive($id), $id.'.zip');
    }

    private function can(Request $request, Capability $capability): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return in_array(
            $capability->value,
            app(RoleOperationPolicy::class)->capabilitiesFor($user),
            true,
        );
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    /**
     * 戻せなかったことを、画面の言葉で伝える（WP 一周 8 周目 2026-09-04 / 効くもの ③）。
     *
     * 🔴 **`abort(409)` にしてはいけない。** Inertia は非 Inertia の応答を
     * 全画面の `about:srcdoc` iframe に流し込むので、買った人に出るのは
     * **Laravel の例外ページ**（stack trace と framework のパス）になる。
     * しかも親の CSP が iframe の inline script を止めるので、折りたたみも動かない。
     * 本番（生成 project の `env.production.example` は `APP_DEBUG=false`）では、
     * その理由すら消えて「Conflict」だけが残る。
     *
     * **理由だけでは足りない。** 読んだ人がその場で選べる手を 2 つ添える —
     * ほかの backup を選ぶか、いまの状態から取り直すか。
     */
    private function cannotRestore(Result $result): string
    {
        return trim((string) $result->message)
            .'。ほかの backup を選ぶか、いまの状態から取り直してください（'.self::BACKUP_COMMAND.'）';
    }

    private function abortUnlessSuccess(Result $result): void
    {
        if ($result->isSuccess()) {
            return;
        }

        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, (string) $result->message);
    }
}
