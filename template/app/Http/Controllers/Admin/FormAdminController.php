<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Content\LockVersionInput;
use AgentCms\Core\Application\FieldGroups\FieldGroupPresenter;
use AgentCms\Core\Application\Forms\DeleteForm;
use AgentCms\Core\Application\Forms\DeleteSubmission;
use AgentCms\Core\Application\Forms\ExportSubmissions;
use AgentCms\Core\Application\Forms\GetForm;
use AgentCms\Core\Application\Forms\GetSubmission;
use AgentCms\Core\Application\Forms\ListForms;
use AgentCms\Core\Application\Forms\ListSubmissions;
use AgentCms\Core\Application\Forms\SaveForm;
use AgentCms\Core\Application\Forms\UpdateSubmissionStatus;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\FieldGroups\FieldGroupLocationKind;
use AgentCms\Core\FieldGroups\FieldGroupRecord;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\Forms\FormStatus;
use AgentCms\Core\Forms\FormSubmission;
use AgentCms\Core\Forms\NotifyRecipients;
use AgentCms\Core\Forms\SubmissionStatus;
use AgentCms\Core\Mail\RecordedMailer;
use AgentCms\Core\Settings\SettingsStore;
use App\Application\Forms\DetachFormFieldGroup;
use App\Application\Forms\FormFieldGroup;
use App\Mail\FormAdminNotification;
use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * フォームと受信の管理画面（Phase 9.12 / STAGE 25D・SPEC §D）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・確認・監査・権限は Application Service 側にある。
 *
 * ## 項目の編集はここでやらない
 *
 * 入力項目は Field Group の画面（9.5-A）が編集する。ここでは**どの束を使うか**を選ぶ。
 * 同じことを 2 か所で覚えさせない（OG1）。
 *
 * ## 検索が弱いことを画面に書く
 *
 * 入力値は JSON なので、検索は**全文一致**しかできない（OG3 の帰結）。
 * できないことを黙っていると、利用者は「壊れている」と受け取る。
 */
final class FormAdminController
{
    public function index(Request $request, ListForms $list): Response
    {
        $result = $list->execute($this->context($request));

        $this->abortUnlessSuccess($result);

        return Inertia::render('admin/forms/Index', [
            'forms' => $result->data['forms'],
            'canManage' => $this->canManage($request),
            'legacyPending' => $this->legacyPending(),
        ]);
    }

    /**
     * まだフォームへ移していない旧お問い合わせの件数（R-12 / 受付の一本化）。
     *
     * ## なぜ画面に出すか
     *
     * 「受付」のメニューは**フォーム 1 つ**になった。旧お問い合わせをメニューから外した
     * だけだと、**過去の受信が画面から辿れなくなる**案件が出る（v0.9.x までに
     * 受信が溜まっている案件）。**消していないものを、読めなくしない。**
     *
     * ここが 0 でない案件だけ、フォーム一覧が入口と移行の手順を出す。
     * 移すと 0 になり、表示も自然に消える。
     *
     * ## 数え方
     *
     * `contact_submission_migrations` は移し終わった旧 ID の記録である
     * （`agentcms:contact-migrate` が二重投入を防ぐために持つ主キー）。
     * **「旧の総数」ではなく「まだ移していない数」**を数えるのは、
     * 移したのに入口が出続けると「移せていない」と読まれるからである。
     */
    private function legacyPending(): int
    {
        if (! Schema::hasTable('contact_submissions')) {
            return 0;
        }

        $query = DB::table('contact_submissions');

        if (Schema::hasTable('contact_submission_migrations')) {
            $query->whereNotIn(
                'id',
                DB::table('contact_submission_migrations')->select('contact_submission_id'),
            );
        }

        return $query->count();
    }

    public function create(Request $request, FieldGroupRepository $groups): Response
    {
        $key = $request->query('key');
        $name = $request->query('name');

        return Inertia::render('admin/forms/Edit', [
            'form' => null,
            /*
             * 見本ページの一覧・公開前チェックから来たときに埋めておく値（UX 一周 2026-09-27）。
             * `/contact` を差し替えるには識別子が `contact` でなければならない。
             * 入れておくだけで、確定と検証は保存（store）が持つ。
             */
            'preset' => is_string($key) && preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $key) === 1
                ? ['key' => $key, 'name' => is_string($name) ? mb_substr($name, 0, 100) : '']
                : null,
            'fields' => [],
            // 新規はまだ key を持たないので絞れない。作った時点で専用の束が付く（R-8A ①）
            'fieldGroups' => $this->formGroups($groups),
            /*
             * **渡す側を揃える。** 新規にはまだ束が無いので中身は null だが、
             * **キーごと渡さないと画面では `undefined` になる**。
             * 「null は守るが undefined は守らない」を画面に作らせない（hotfix）。
             */
            'fieldEditor' => null,
            'statuses' => $this->statuses(),
            // 通知先の欄が空のときに届く先（UX 一周 15 周目 G2。ダッシュボードの 4 歩目と同じ値）
            'fallbackNotifyTo' => implode(', ', NotifyRecipients::forForm(null, (string) app(SettingsStore::class)->get('contact_email'))),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function edit(
        Request $request,
        GetForm $get,
        FieldGroupRepository $groups,
        FieldGroupPresenter $presenter,
        string $key,
    ): Response {
        $result = $get->execute($key, $this->context($request));

        $this->abortUnlessSuccess($result);

        $assignedId = (string) ($result->data['form']['field_group_id'] ?? '');

        return Inertia::render('admin/forms/Edit', [
            'form' => $result->data['form'],
            'fields' => $result->data['fields'],
            'fieldGroups' => $this->formGroups($groups, $key, $assignedId),
            'statuses' => $this->statuses(),
            // 通知先の欄が空のときに届く先（UX 一周 15 周目 G2。ダッシュボードの 4 歩目と同じ値）
            'fallbackNotifyTo' => implode(', ', NotifyRecipients::forForm(null, (string) app(SettingsStore::class)->get('contact_email'))),
            'canManage' => $this->canManage($request),
            /*
             * 入力項目をこの画面の中で編集するための材料（R-8A ②）。
             *
             * **このフォーム専用の束のときだけ編集させる。** 他のフォームや投稿タイプも
             * 指している束をここで書き換えると、**利用者が見ていない画面が壊れる**。
             * 共有の束は読み取りで見せて、どこで編集するかを言う。
             */
            'fieldEditor' => $this->fieldEditor($groups, $presenter, $key, $assignedId),
        ]);
    }

    /**
     * 項目編集の材料。**編集させないときも中身は見せる**（何が出るのか分からないのは困る）。
     *
     * @return array<string, mixed>|null
     */
    private function fieldEditor(
        FieldGroupRepository $groups,
        FieldGroupPresenter $presenter,
        string $formKey,
        string $assignedId,
    ): ?array {
        if ($assignedId === '' || ! $groups->isInstalled()) {
            return null;
        }

        $record = null;

        foreach ($groups->all() as $candidate) {
            if ($candidate->id === $assignedId) {
                $record = $candidate;

                break;
            }
        }

        if ($record === null) {
            return null;
        }

        /*
         * **専用かどうかは「割当先がこのフォーム 1 つだけか」で決める。**
         * 数だけ見て中身を見ないと、投稿タイプにも割り当てられた束を専用だと誤認する。
         *
         * 判定そのものは `FormFieldGroup` が持つ — 切り離し（B-31）も同じ判定を使うので、
         * ここに書き写すと**画面と操作で食い違う**。
         */
        $own = FormFieldGroup::belongsOnlyTo($record, $formKey);

        return [
            'group' => $presenter->detail($record),
            'options' => $presenter->editorOptions(),
            'editable' => $own,
            /*
             * 共有の束を、このフォームだけのものに切り離せるか（B-31）。
             *
             * **押せるものを出して嘘をつかない。** 定義が読めない束は写しても
             * 壊れた束が 2 つになるだけなので、operation 側が断る。断るものは出さない。
             */
            'detachable' => ! $own && $record->isValid(),
        ];
    }

    /**
     * 共有している入力項目を、このフォームだけのものにする（B-31）。
     *
     * **黙って複製しない。** 1 往復目は確認だけを返し、何も変えない。
     * 2 往復目（token 付き）で複製する。元の束と、それを指している他のフォームは
     * 1 バイトも触らない — 判断も理由も `DetachFormFieldGroup` が持つ。
     */
    public function detachFields(Request $request, DetachFormFieldGroup $detach, string $key): RedirectResponse
    {
        $result = $detach->execute($key, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            // 断る理由は Application Service が書いている。ここで言い換えない
            return back()->with('error', (string) $result->message);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '入力項目をこのフォームだけのものにしました');
    }

    public function store(Request $request, SaveForm $save): RedirectResponse
    {
        $result = $save->execute($this->attributes($request) + ['key' => $request->input('key')], $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        $this->abortUnlessSuccess($result);

        return redirect()
            ->route('admin.forms.edit', ['key' => $result->data['form']['key']])
            ->with('success', 'フォームを作成しました');
    }

    public function update(Request $request, SaveForm $save, string $key): RedirectResponse
    {
        $result = $save->execute(
            /*
             * `key` も**そのまま渡す**。画面では読み取り専用だが、直接叩かれたときに
             * 黙って無視すると「変えたつもりで変わっていない」になる。
             * 違うものが来たら operation が理由を付けて断る。
             */
            $this->attributes($request) + array_filter(
                ['key' => $request->input('key')],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ),
            $this->context($request),
            $key,
            LockVersionInput::fromRequest($request->all()),
        );

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', 'フォームを保存しました');
    }

    public function destroy(Request $request, DeleteForm $delete, string $key): RedirectResponse
    {
        $result = $delete->execute($key, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            // 受信が残っている。**何をすればよいか**は Application Service が書いている
            return back()->with('error', (string) $result->message);
        }

        $this->abortUnlessSuccess($result);

        return redirect()->route('admin.forms.index')->with('success', 'フォームを削除しました');
    }

    // --- 受信 ----------------------------------------------------------

    public function submissions(Request $request, ListSubmissions $list, string $key): Response
    {
        $result = $list->execute(
            $key,
            $this->context($request),
            (string) $request->query('q', ''),
            (int) $request->query('page', 1),
            onlyUndelivered: $request->query('delivery') === 'failed',
        );

        $this->abortUnlessSuccess($result);

        $form = GetForm::find($key);

        if ($form === null) {
            abort(404);
        }

        return Inertia::render('admin/forms/Submissions', [
            'formKey' => $key,
            'formName' => $form->name,
            'submissions' => $result->data['submissions'],
            'meta' => $result->meta,
            'statuses' => SubmissionStatus::options(),
            'canWrite' => $this->canWriteContacts($request),
        ]);
    }

    public function submission(Request $request, GetSubmission $get, string $key, string $id): Response
    {
        $result = $get->execute((int) $id, $this->context($request));

        $this->abortUnlessSuccess($result);

        // URL のフォームと中身のフォームが食い違っていたら見せない
        $form = GetForm::find($key);

        if ($form === null || $result->data['submission']['form_id'] !== $form->getKey()) {
            abort(404);
        }

        return Inertia::render('admin/forms/Submission', [
            'formKey' => $key,
            'formName' => $form->name,
            'submission' => $result->data['submission'],
            'statuses' => SubmissionStatus::options(),
            'canWrite' => $this->canWriteContacts($request),
        ]);
    }

    public function updateSubmission(
        Request $request,
        UpdateSubmissionStatus $update,
        string $key,
        string $id,
    ): RedirectResponse {
        $result = $update->execute((int) $id, (string) $request->input('status', ''), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '状態を変更しました');
    }

    /**
     * 通知メールを、もう一度送る（WP 一周 10 周目 2026-09-04）。
     *
     * 🔴 **届かなかったことに気づいても、送り直せなければ意味が無い。**
     * サイドバーの赤い数 → 絞り込み → ここ、で終わりまで行けるようにする。
     *
     * 宛先の決め方は公開フォームの送信と**同じ 1 か所**（`NotifyRecipients::forForm()`）。
     * 別々の順で決めると「送り直したのに違う所へ届いた」が起きる。
     *
     * 送れたら失敗の記録を消す。**消さないとサイドバーの数が減らない** —
     * 減らない印は、そのうち見られなくなる。
     */
    public function resendNotification(
        Request $request,
        RecordedMailer $mailer,
        SettingsStore $settings,
        string $key,
        string $id,
    ): RedirectResponse {
        abort_unless($this->canWriteContacts($request), 403);

        $form = GetForm::find($key);

        abort_if($form === null, 404);

        $submission = FormSubmission::query()
            ->where('id', (int) $id)
            ->where('form_id', $form->getKey())
            ->first();

        abort_if($submission === null, 404);

        $to = NotifyRecipients::forForm($form->notify_to, (string) $settings->get('contact_email'));

        $error = $to === []
            ? '通知先が設定されていません'
            : $mailer->send(new FormAdminNotification($form, $submission), $to);

        $submission->forceFill([
            'admin_notified_at' => $error === null ? now() : null,
            'admin_notification_error' => $error,
        ])->save();

        if ($error !== null) {
            return back()->with('error', '通知メールを送れませんでした: '.$error);
        }

        return back()->with('success', '通知メールを送り直しました（'.implode('、', $to).'）');
    }

    /**
     * 受信を 1 件消す（R-12 / 2026-08-30）。
     *
     * **個人情報なので消せることが要る。** 戻せないので確認を挟む
     * （`destroy` と同じ形。確認トークンは `OperationContext` が運ぶ）。
     */
    public function destroySubmission(
        Request $request,
        DeleteSubmission $delete,
        string $key,
        string $id,
    ): RedirectResponse {
        $result = $delete->execute((int) $id, $this->context($request));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        $this->abortUnlessSuccess($result);

        // 消した先の画面はもう無いので、一覧へ戻す
        return to_route('admin.forms.submissions', ['key' => $key])
            ->with('success', '受信を削除しました');
    }

    /** **持ち出しなので監査に残る**（Application Service 側で記録する — OG7） */
    public function export(Request $request, ExportSubmissions $export, string $key): StreamedResponse
    {
        $result = $export->execute($key, $this->context($request));

        $this->abortUnlessSuccess($result);

        $csv = (string) $result->data['csv'];
        $filename = (string) $result->data['filename'];

        return response()->streamDownload(
            static function () use ($csv): void {
                echo $csv;
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // 中身を解釈させない（一般ファイル配信と同じ作法）
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    // --- 内部 ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function attributes(Request $request): array
    {
        return [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'field_group_id' => $request->input('field_group_id'),
            'status' => $request->input('status', FormStatus::Draft->value),
            'confirm_step' => $request->boolean('confirm_step'),
            'submit_label' => $request->input('submit_label'),
            'complete_message' => $request->input('complete_message'),
            'notify_to' => $request->input('notify_to'),
            'autoreply_field' => $request->input('autoreply_field'),
            'autoreply_subject' => $request->input('autoreply_subject'),
            'autoreply_body' => $request->input('autoreply_body'),
            'autoreply_signature' => $request->input('autoreply_signature'),
            'mail_from_name' => $request->input('mail_from_name'),
            'reply_to' => $request->input('reply_to'),
        ];
    }

    /**
     * このフォームで選べる Field Group。
     *
     * 投稿タイプ用の束を選べるようにすると、`richtext` のような
     * **フォームでは使えない項目**を含む束を選んでしまう。
     *
     * ## 候補 = 割当先が一致する束 ∪ **いま割り当てられている束**（R-8A ①）
     *
     * 以前は「割当先の種類がフォームか」しか見ておらず、**どのフォームを指しているか（value）を
     * 読んでいなかった** — つまり全フォームの束が全フォームの候補に出ていた。
     * 打ち間違えても正しく見え、正しく打っても意味が無い状態だった。
     *
     * **`∪ いま割り当てられている束` は落とせない。** 既存サイトの value は手打ちで、
     * 実在しないフォーム名も入っている。一致だけで絞ると
     * **「選ばれているのに候補に無い」**になり、Select が空白になって
     * **無関係な項目まで保存できなくなる**（R-5 の監査 指摘 1 とまったく同じ形）。
     * ▷ **「いま選ばれているものは必ず候補に入れる」は、この画面に限らない一般則である。**
     *
     * `$formKey` が null（新規作成）のときは絞れないので、これまでどおり全部返す。
     *
     * @return list<array<string, mixed>>
     */
    private function formGroups(FieldGroupRepository $groups, ?string $formKey = null, string $assignedId = ''): array
    {
        if (! $groups->isInstalled()) {
            return [];
        }

        $items = [];

        foreach ($groups->all() as $record) {
            if (! $this->assignedToForm($record, $formKey) && $record->id !== $assignedId) {
                continue;
            }

            $items[] = [
                'id' => $record->id,
                'label' => $record->label,
                'status' => $record->status,
                'fields' => count($record->fields),
            ];
        }

        return $items;
    }

    /** 割当先がフォームで、**指しているのがこのフォーム**か（key を渡さなければ種類だけ見る） */
    private function assignedToForm(FieldGroupRecord $record, ?string $formKey = null): bool
    {
        foreach ($record->locations as $location) {
            if ($location->kind !== FieldGroupLocationKind::Form) {
                continue;
            }

            if ($formKey === null || $location->value === $formKey) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{value: string, label: string}> */
    private function statuses(): array
    {
        return array_map(
            static fn (FormStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            FormStatus::cases(),
        );
    }

    /** 画面の導線を出すかどうかだけ。実際の拒否は operation 側が行う */
    private function canManage(Request $request): bool
    {
        return $this->has($request, Capability::SettingsManage);
    }

    private function canWriteContacts(Request $request): bool
    {
        return $this->has($request, Capability::ContactWrite);
    }

    private function has(Request $request, Capability $capability): bool
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
