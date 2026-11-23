<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\FieldGroups\ArchiveFieldGroup;
use AgentCms\Core\Application\FieldGroups\CreateFieldGroup;
use AgentCms\Core\Application\FieldGroups\FieldGroupPresenter;
use AgentCms\Core\Application\FieldGroups\GetFieldGroup;
use AgentCms\Core\Application\FieldGroups\ListFieldGroups;
use AgentCms\Core\Application\FieldGroups\ReorderFieldGroups;
use AgentCms\Core\Application\FieldGroups\RestoreFieldGroup;
use AgentCms\Core\Application\FieldGroups\UpdateFieldGroup;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * カスタムフィールド（Field Group）の管理画面 — Phase 9.5-A / 9.5-B。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・衝突検査・危険度分類・確認・監査はすべて Application Service 側にある。
 *
 * ## できること
 *
 * 一覧・作成・編集・並べ替え・アーカイブ・復元。
 * **物理削除は無い**（定義も投稿の値も消さない）。
 */
final class FieldGroupAdminController
{
    public function index(Request $request, ListFieldGroups $list): Response
    {
        $result = $list->execute($this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/field-groups/Index', [
            'groups' => $result->data['groups'] ?? [],
            // 「0 件」と「まだ導入されていない」を画面で混同させない
            'installed' => $result->data['installed'] ?? false,
        ]);
    }

    /**
     * 作成フォーム。
     *
     * **ここだけ Application Service を呼ばない**ので、認可を素通りしないよう
     * 明示的に確かめる。フォームは `field_group.create` の入口そのものなので、
     * 同じ operation id で判定する（画面で隠すだけにしない）。
     */
    public function create(Request $request, FieldGroupPresenter $presenter, OperationPolicy $policy): Response
    {
        if (! $policy->allows('field_group.create', OperationClassification::Write, $this->context($request))) {
            abort(403, $policy->denialReason('field_group.create', $this->context($request)));
        }

        return Inertia::render('admin/field-groups/Create', [
            'options' => $this->formOptions($presenter),
        ]);
    }

    public function store(Request $request, CreateFieldGroup $create): RedirectResponse
    {
        $result = $create->execute($this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['key' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()
            ->route('admin.field-groups.index')
            ->with('success', "カスタムフィールド「{$request->string('label')}」を作成しました");
    }

    public function edit(Request $request, string $id, GetFieldGroup $get, FieldGroupPresenter $presenter): Response
    {
        $result = $get->execute($id, $this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/field-groups/Edit', [
            'group' => $result->data['group'] ?? [],
            'options' => $this->formOptions($presenter),
        ]);
    }

    public function update(Request $request, string $id, UpdateFieldGroup $update): RedirectResponse
    {
        $result = $update->execute($id, $this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        // 1 回目は「何が起きるか」を返して止まる。管理画面も AI も同じ ConfirmationGate を通る
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());
            Inertia::flash('plan', $result->data['plan'] ?? null);

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['key' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        /*
         * **呼んだ画面へ帰す**（R-8A ② 監査 指摘 18）。
         *
         * フォームの画面から項目を保存すると、ここが常に
         * カスタムフィールドの一覧へ飛ばしていた — **見せないと決めた画面へ**である。
         * 往復 0 が最後の 1 押しで崩れ、flash の文言も禁止語を含んでいた。
         *
         * 戻り先は **Referer ではなく明示**で受ける。偽装に強く、試験もしやすい。
         */
        $back = self::callerPath($request->input('redirect_to'));

        if ($back !== null) {
            // **呼び出し元の語彙で言う。** ただし文言は受け取らない（受け取ると何でも出せる）
            return redirect()->to($back)->with('success', '入力項目を保存しました');
        }

        return redirect()
            ->route('admin.field-groups.index')
            ->with('success', "カスタムフィールド「{$request->string('label')}」を保存しました");
    }

    public function archive(Request $request, string $id, ArchiveFieldGroup $archive): RedirectResponse
    {
        return $this->statusChange($archive->execute($id, $this->context($request)), 'アーカイブしました');
    }

    public function restore(Request $request, string $id, RestoreFieldGroup $restore): RedirectResponse
    {
        return $this->statusChange($restore->execute($id, $this->context($request)), '復元しました');
    }

    public function reorder(Request $request, ReorderFieldGroups $reorder): RedirectResponse
    {
        $result = $reorder->execute($this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return back()->with('success', '並び順を保存しました');
    }

    private function statusChange(Result $result, string $done): RedirectResponse
    {
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['status' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        $label = $result->data['fieldGroup']['label'] ?? '';

        return redirect()
            ->route('admin.field-groups.index')
            ->with('success', "カスタムフィールド「{$label}」を{$done}");
    }

    /**
     * 画面が組み立てに使う選択肢。**サーバーが authoritative**にする。
     *
     * @return array<string, mixed>
     */
    /**
     * 選択肢と上限は core の presenter が正本（R-8A ②・フォーム画面と共有する）。
     *
     * @return array<string, mixed>
     */
    private function formOptions(FieldGroupPresenter $presenter): array
    {
        return $presenter->editorOptions();
    }

    /**
     * Application Service へ渡す入力。
     *
     * **キーを拾い直して組み立て直さない。** 受理キーの判定・型の検証・拒否は
     * `FieldGroupDraft` が一元的に行う契約なので、ここで先回りして落とすと
     * 「送ったのに黙って捨てられた」状態になる。
     *
     * 除くのは確認トークンだけ（値ではなく制御情報のため）。
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_diff_key($request->all(), [
            'confirmation_token' => true,
            '_token' => true,
            '_method' => true,
            // 戻り先も**値ではなく制御情報**。渡すと Draft が「指定できません」で断る
            'redirect_to' => true,
        ]);
    }

    /**
     * 保存後に帰る先。**管理画面の中の相対パスだけ**を通す。
     *
     * 受け取った文字列をそのまま `redirect()->to()` へ渡すと、
     * **どこへでも飛ばせる口**（open redirect）になる。ここで通すのは
     * `/admin/` で始まる相対パスだけで、それ以外は**黙って既定へ戻す**
     * （呼び出し元の作りの問題なので、利用者に見せる理由が無い）。
     */
    private static function callerPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > 255) {
            return null;
        }

        /*
         * `//evil` は scheme 相対 URL、`\` は一部のブラウザが `/` として扱う。
         * `..` は**親ディレクトリ参照**で、`/admin/../../evil` のように
         * **`/admin/` で始まっていても外へ出られる**（`..%2f` も `..` を含む）。
         */
        if (str_contains($value, '\\') || str_starts_with($value, '//') || str_contains($value, '..')) {
            return null;
        }

        return str_starts_with($value, '/admin/') ? $value : null;
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
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
