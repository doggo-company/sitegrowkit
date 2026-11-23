<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\ContentTypes\ArchiveContentType;
use AgentCms\Core\Application\ContentTypes\CreateContentType;
use AgentCms\Core\Application\ContentTypes\ListContentTypeDefinitions;
use AgentCms\Core\Application\ContentTypes\ReorderContentTypes;
use AgentCms\Core\Application\ContentTypes\RestoreContentType;
use AgentCms\Core\Application\ContentTypes\SetContentTypeComments;
use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\ContentTypeBuilderPresets;
use AgentCms\Core\ContentTypes\Registration\ContentTypeRegistrationAllowlist;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\ContentTypes\Registration\PublishingQuickStart;
use AgentCms\Core\FieldGroups\FieldGroupRepository;
use AgentCms\Core\Permalink\PermalinkStructure;
use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 投稿タイプ（Content Type）の管理画面（Phase 9.4 / STAGE #17A・SPEC §1A）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・衝突検査・環境検査・確認・適用・rollback・監査は
 * すべて Application Service（`CreateContentType`）側にある。
 *
 * ## できること
 *
 * 一覧・作成・**編集・無効化・復元・並べ替え**（Phase 9.7 / STAGE 20C）。
 * 物理削除は置かない — 「削除」は無効化であり、テーブルも投稿も消さない。
 * 任意 SQL / 任意 PHP を受け取る入力は**存在しない** — 受け取るのは
 * ID・表示名・フィールド定義・allowlist された設定値だけである。
 *
 * ## 状態の判定を持たない
 *
 * archived かどうかを controller で判定しない。判定は
 * `ContentTypeLifecyclePolicy`（読み）と Application Service（書き）だけが持つ。
 *
 * ## Preview → Confirm → Apply
 *
 * `store()` は 1 本だが、確認トークンの有無で挙動が変わる。
 * トークン無し = 検証と計画だけ返して**何も変更しない**。
 * 既存の削除フロー（`SchemaResourceController::destroy`）と同じ
 * `ConfirmationGate` / `Inertia::flash('confirmation')` の作法に揃える。
 */
final class ContentTypeAdminController
{
    public function index(Request $request, ListContentTypeDefinitions $list): Response
    {
        $result = $list->execute($this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/content-types/Index', [
            'types' => $result->data['types'] ?? [],
            // 設定を読めなかったことを画面が言えるようにする（黙って空にしない）
            'readiness' => $result->data['readiness'] ?? 'indeterminate',
            'readinessMessage' => $result->data['readinessMessage'] ?? null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/content-types/Create', [
            'options' => ContentTypeBuilderPresets::formOptions(),
        ]);
    }

    /**
     * 編集画面（Phase 9.7 / STAGE 20C）。
     *
     * **第 2 のカスタムフィールド編集 UI を作らない。** 出すのは
     * 「この型が持つ基本項目（schema 由来・読み取り専用）」と
     * 「割り当てられている Field Group への導線」だけである。
     */
    public function edit(
        string $type,
        SchemaRegistry $schemas,
        ContentTypeSettingsResolver $resolver,
        FieldGroupRepository $fieldGroups,
    ): Response {
        $schema = $schemas->declared()[$type] ?? null;

        abort_if($schema === null || ! EditorialCollections::isManageable($schema), 404);

        $settings = $resolver->for($type);

        return Inertia::render('admin/content-types/Edit', [
            'contentType' => [
                'id' => $schema->id,
                'label' => $schema->label,
                'table' => $schema->table,
                'managedBy' => $schema->managedBy,
                'settings' => $settings->toArray(),
                // 型側でコメントを使えるか（受付の実体は後続 Stage）
                'commentsEnabled' => $settings->commentsEnabled,
                // schema 由来の基本項目。**ここでは編集できない**（項目は Field Group の仕事）
                'baseFields' => array_map(static fn ($field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'required' => $field->required,
                ], $schema->fields),
                'fieldGroups' => $this->assignedFieldGroups($fieldGroups, $type),
                'fieldGroupsUrl' => route('admin.field-groups.index', absolute: false),
            ],
            'options' => [
                'registrationKeys' => array_keys(ContentTypeRegistrationAllowlist::REGISTRATION),
                'displayKeys' => array_keys(ContentTypeRegistrationAllowlist::DISPLAY),
                'icons' => ContentTypeRegistrationAllowlist::ICONS,
                'slugPattern' => '^[a-z0-9-]{1,60}$',
                // URL の構造（P1 ③）。**名前と例は enum が持つ**（画面と core でずれないように）
                'permalinks' => array_map(static fn (PermalinkStructure $s): array => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'example' => $s->example((string) ($settings->publicSlug ?? '')),
                ], PermalinkStructure::cases()),
            ],
            'readiness' => $resolver->readiness()->name,
        ]);
    }

    public function update(string $type, Request $request, UpdateContentType $update): RedirectResponse
    {
        $result = $update->execute(
            [...$this->input($request), 'id' => $type],
            $this->context($request),
        );

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['lock_version' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()
            ->route('admin.content-types.index')
            ->with('success', '投稿タイプの設定を保存しました');
    }

    public function archive(string $type, Request $request, ArchiveContentType $archive): RedirectResponse
    {
        return $this->lifecycleResponse(
            $archive->execute($type, $this->lockVersion($request), $this->context($request)),
            '投稿タイプを無効にしました。いつでも元に戻せます',
        );
    }

    public function restore(string $type, Request $request, RestoreContentType $restore): RedirectResponse
    {
        return $this->lifecycleResponse(
            $restore->execute($type, $this->lockVersion($request), $this->context($request)),
            '投稿タイプを元に戻しました',
        );
    }

    /**
     * 型側のコメント利用可否（Phase 9.7 / STAGE 20E）。
     *
     * 🔴 **文言を 2026-09-04 に入れ替えている（WP 一周 8 周目 / 効くもの ①）。**
     *
     * ここには「受け付ける仕組み・承認・通知は後続 Stage（P0-9）で、この route は
     * 関与しない」と書いてあり、済んだあとの帯もそう言っていた —
     * 「受け付ける仕組みは今後の更新で追加します」。
     *
     * **その後続 Stage はもう入っている。** 実物で確かめた: ON にした直後、
     * 公開ページの記事に投稿欄が出て、そこから送れて、管理画面の「コメント」に
     * 承認待ちとして並ぶ。押す前の確認ダイアログも「受け付けが始まります」と言う。
     *
     * つまり同じ操作の 2 秒間で、正反対のことを 2 回言っていた。
     * しかも帯のほうが「まだ動いていない」に倒れているので、オーナーは承認待ちを
     * 見に行かない。その間、公開サイトは名前とメールアドレスつきの書き込みを
     * 受け続けていて、**気づく手がかりが 1 つも無い**。
     */
    public function comments(string $type, Request $request, SetContentTypeComments $comments): RedirectResponse
    {
        $enabled = $request->boolean('comments_enabled');

        return $this->lifecycleResponse(
            $comments->execute($type, $enabled, $this->lockVersion($request), $this->context($request)),
            $enabled
                ? 'この投稿タイプの公開ページで、コメントの受け付けが始まりました。届いたものは承認するまで公開ページには出ません（管理メニューの「コメント」に並びます）'
                : 'この投稿タイプでコメントを使わないようにしました（書き込まれたコメントは残ります）',
        );
    }

    /**
     * 「記事を書けるようにする」1 クリック（WP 一周 2026-09-03 / 効くもの ①）。
     *
     * **既定は変えていない。** 空の投稿メニューを並べない B-39（2026-08-24）はそのままで、
     * ここが変えるのは**そこから抜け出すまでの手数**だけである。
     * 5 か所（サイドバー・公開スラッグ・公開する・個別 URL・一覧 URL）を 1 回にまとめる。
     *
     * 払い出しは `PublishingQuickStart`、検証と書き込みと監査は `UpdateContentType`。
     * この controller は判定を持たない（`update()` と同じ経路を通す）。
     */
    public function quickStart(
        string $type,
        Request $request,
        UpdateContentType $update,
        ContentTypeSettingsResolver $settings,
    ): RedirectResponse {
        $payload = PublishingQuickStart::payloadFor($settings->for($type));
        $result = $update->execute($payload, $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            return $this->lifecycleResponse($result, '');
        }

        /*
         * 記事を書いている画面から押したときは、**その画面に戻す**（UX 一周 2026-09-28）。
         * 投稿を「公開」にしてもサイトに出ない、という案内の中から押すので、
         * 書きかけを捨てて一覧へ飛ばさない。
         */
        if ($request->boolean('stay')) {
            return redirect()->back()->with(
                'success',
                '公開サイトに出せるようにしました。「公開」にした記事は /'.$payload['public_slug'].' に並びます',
            );
        }

        // **書ける場所へ連れて行く。** 押した人がやりたいのは設定ではなく記事を書くことである
        if (Route::has("admin.{$type}.index")) {
            return redirect()
                ->route("admin.{$type}.index")
                ->with('success', '記事を書けるようにしました。「新規作成」から書けます');
        }

        return $this->lifecycleResponse($result, '記事を書けるようにしました');
    }

    public function reorder(Request $request, ReorderContentTypes $reorder): RedirectResponse
    {
        $items = $request->input('items');
        $result = $reorder->execute(is_array($items) ? array_values($items) : [], $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        return $this->lifecycleResponse($result, '管理メニューの並び順を保存しました');
    }

    /** archive / restore / reorder の応答は同じ形にする（画面ごとに分岐を増やさない） */
    private function lifecycleResponse(Result $result, string $success): RedirectResponse
    {
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['lock_version' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()->route('admin.content-types.index')->with('success', $success);
    }

    private function lockVersion(Request $request): int
    {
        $raw = $request->input('lock_version');

        return is_numeric($raw) ? (int) $raw : -1;
    }

    /**
     * この型に割り当てられている Field Group（**表示だけ**）。
     *
     * @return list<array{id: string, label: string, status: string, fieldCount: int, editUrl: string}>
     */
    private function assignedFieldGroups(FieldGroupRepository $repository, string $type): array
    {
        if (! $repository->isInstalled()) {
            return [];
        }

        $assigned = [];

        foreach ($repository->all() as $group) {
            foreach ($group->locations as $location) {
                if ($location->value !== $type) {
                    continue;
                }

                $assigned[] = [
                    'id' => $group->id,
                    'label' => $group->label,
                    'status' => $group->status->value,
                    'fieldCount' => count($group->fields),
                    'editUrl' => route('admin.field-groups.edit', ['id' => $group->id], absolute: false),
                ];

                break;
            }
        }

        return $assigned;
    }

    public function store(Request $request, CreateContentType $create): RedirectResponse
    {
        $result = $create->execute($this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        // 1 回目は確認内容（= 何が起きるかの計画）を返して止まる。
        // 管理画面も AI も同じ ConfirmationGate を通る
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());
            Inertia::flash('plan', $result->data['plan'] ?? null);

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            // 環境要因（書き込み不可 / DB 到達不可）と rollback 済みの失敗は
            // 例外ではなく画面のメッセージにする（原因が読める形で残す）。
            //
            // **取り消しきれなかった残骸は必ず出す。** 「失敗しました」だけだと、
            // 生成途中のファイルが残っているのに誰も気づけない。
            // notes 側は base_path からの相対パスで、絶対パスも秘密も含まない
            $errors = ['id' => (string) $result->message];
            $rollback = $result->data['rollback'] ?? null;

            if (is_array($rollback) && $rollback['completed'] === false) {
                $errors['rollback'] = array_values(array_map(strval(...), $rollback['notes'] ?? []));
            }

            return back()->withErrors($errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        $created = $result->data['contentType'] ?? [];
        $message = "投稿タイプ「{$request->string('label')}」を作成しました（{$created['adminUrl']}）";

        // 作成は成功したが監査ログを書けなかった場合、**黙って成功にしない**。
        // 作成物を巻き戻すほうが実害が大きいので、成功のまま警告を添える
        if (($result->meta['auditRecorded'] ?? true) === false) {
            $message .= '（監査ログを記録できませんでした。サーバーのログを確認してください）';
        }

        return redirect()
            ->route('admin.content-types.index')
            ->with('success', $message);
    }

    /**
     * Application Service へ渡す入力。
     *
     * **キーを拾い直して組み立て直さない**（Phase 9.3B / #17 と同じ方針）。
     * 受理キーの判定・型の検証・拒否は `ContentTypeDraft` が一元的に行う契約なので、
     * ここで先回りして落とすと「送ったのに黙って捨てられた」状態になる。
     *
     * 除くのは確認トークンだけ（値ではなく制御情報のため）。
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_diff_key($request->all(), ['confirmation_token' => true, '_token' => true, '_method' => true]);
    }

    /** 誰が・どの経路で操作しているか（確認トークンがあれば同梱） */
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
