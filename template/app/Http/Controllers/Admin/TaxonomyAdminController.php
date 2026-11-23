<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\ArchiveTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\AttachTaxonomyToContentType;
use AgentCms\Core\Application\Taxonomy\CreateTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use AgentCms\Core\Application\Taxonomy\DeleteTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\DeleteTerm;
use AgentCms\Core\Application\Taxonomy\DetachTaxonomyFromContentType;
use AgentCms\Core\Application\Taxonomy\GetTaxonomy;
use AgentCms\Core\Application\Taxonomy\GetTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\ListTaxonomies;
use AgentCms\Core\Application\Taxonomy\ListTerms;
use AgentCms\Core\Application\Taxonomy\ReorderTerms;
use AgentCms\Core\Application\Taxonomy\RestoreTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\UpdateTaxonomyDefinition;
use AgentCms\Core\Application\Taxonomy\UpdateTerm;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\Reference\TermUsageCounter;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyKey;
use AgentCms\Core\Taxonomy\TaxonomyManagedBy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 分類の管理画面（Phase 9.3B / #16・SPEC §R）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 * 検証・認可・確認・監査・transaction・階層規則はすべて Application Service 側にある
 * （STAGE 2B の 7 操作をそのまま呼ぶ）。Model を直接触らない。
 *
 * ## API を保存経路にしない（AD-6 / §R）
 *
 * 管理画面の mutation は `api/v1` を呼ばず、**Admin Controller から
 * 同じ Application Service を `OperationContext::admin()` で**呼ぶ。
 * API は programmatic surface として別に維持する（§V.2-(2) と同じ方針）。
 *
 * ## 公開範囲
 *
 * **taxonomy 自体の作成・更新・削除 UI / route は置かない**（§P.2 — Phase 9.4 へ延期）。
 * ここにあるのは **taxonomy の read と term の操作**だけ。
 * URL には **taxonomy key と term の immutable ID** を使い、taxonomy の ULID は露出させない。
 */
final class TaxonomyAdminController
{
    /** term 一覧は管理 tree なので **archived も含めて**取得する（HR-2 / HR-3） */
    private const ADMIN_TREE_INCLUDES_ARCHIVED = true;

    public function index(Request $request, ListTaxonomies $list): Response
    {
        $result = $list->execute($this->context($request));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/taxonomy/Index', [
            'taxonomies' => $result->data['taxonomies'] ?? [],
        ]);
    }

    /** 作成フォーム。key の文法は `TaxonomyKey` が単一情報源 */
    public function create(SchemaRegistry $schemas): Response
    {
        return Inertia::render('admin/taxonomy/Create', [
            'options' => [
                'keyPattern' => '^[a-z][a-z0-9_]*$',
                'keyRequirement' => TaxonomyKey::requirement(),
                'contentTypes' => $this->editorialTypes($schemas),
            ],
        ]);
    }

    public function store(Request $request, CreateTaxonomyDefinition $create): RedirectResponse
    {
        $result = $create->execute($this->input($request), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()
            ->route('admin.taxonomies.index')
            ->with('success', '分類を作成しました');
    }

    /** 定義の編集画面（cms 管理だけ）。code 管理は読み取り専用なので開かない */
    public function edit(string $key, SchemaRegistry $schemas, GetTaxonomyDefinition $definition): Response
    {
        $taxonomy = Taxonomy::query()->where('key', $key)->first();

        abort_if($taxonomy === null || $taxonomy->managed_by === TaxonomyManagedBy::Code, 404);

        return Inertia::render('admin/taxonomy/Edit', [
            'taxonomy' => $definition->present($taxonomy),
            'options' => [
                'contentTypes' => $this->editorialTypes($schemas),
            ],
        ]);
    }

    public function update(string $key, Request $request, UpdateTaxonomyDefinition $update): RedirectResponse
    {
        $result = $update->execute([...$this->input($request), 'key' => $key], $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['lock_version' => (string) $result->message]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return redirect()->route('admin.taxonomies.index')->with('success', '分類の設定を保存しました');
    }

    public function archive(string $key, Request $request, ArchiveTaxonomyDefinition $archive): RedirectResponse
    {
        return $this->definitionResponse(
            $archive->execute($key, $this->lockVersion($request), $this->context($request)),
            '分類を無効にしました。いつでも元に戻せます',
        );
    }

    public function restore(string $key, Request $request, RestoreTaxonomyDefinition $restore): RedirectResponse
    {
        return $this->definitionResponse(
            $restore->execute($key, $this->lockVersion($request), $this->context($request)),
            '分類を元に戻しました',
        );
    }

    /** 一度も使っていない定義だけを消す（項目も割り当ても在るなら通らない） */
    public function remove(string $key, Request $request, DeleteTaxonomyDefinition $delete): RedirectResponse
    {
        return $this->definitionResponse(
            $delete->execute($key, $this->lockVersion($request), $this->context($request)),
            '未使用の分類を削除しました',
        );
    }

    public function attach(string $key, Request $request, AttachTaxonomyToContentType $attach): RedirectResponse
    {
        return $this->definitionResponse(
            $attach->execute(
                $key,
                (string) $request->input('content_type'),
                $request->boolean('multiple', true),
                $request->boolean('required'),
                $this->context($request),
            ),
            '投稿タイプへ関連付けました',
            // 関連付けは設定画面の中の作業。**一覧へ飛ばさない**（続けて付け外しできる）
            stay: true,
        );
    }

    public function detach(string $key, Request $request, DetachTaxonomyFromContentType $detach): RedirectResponse
    {
        return $this->definitionResponse(
            $detach->execute($key, (string) $request->input('content_type'), $this->context($request)),
            '関連付けを外しました（付いている分類は消えていません）',
            stay: true,
        );
    }

    public function show(
        Request $request,
        GetTaxonomy $get,
        ListTerms $terms,
        TermUsageCounter $usage,
        string $key,
    ): Response {
        $context = $this->context($request);
        $taxonomy = $get->execute($key, $context);

        if (! $taxonomy->isSuccess()) {
            $this->abortFor($taxonomy);
        }

        // HR-3: 管理 tree は includeArchived=true。archived parent 配下の active child を
        // 孤児 root として描かないために、tree を再構成できる完全な集合を取る
        $list = $terms->execute($key, $context, self::ADMIN_TREE_INCLUDES_ARCHIVED);

        if (! $list->isSuccess()) {
            $this->abortFor($list);
        }

        /** @var list<array<string, mixed>> $items */
        $items = $list->data['terms'] ?? [];

        return Inertia::render('admin/taxonomy/Show', [
            'taxonomy' => $taxonomy->data['taxonomy'] ?? null,
            'terms' => $items,
            // 使用件数は保存値ではなく導出値（§R）。表示のたびに数える
            'usage' => (object) $usage->forTerms(array_map(
                static fn (array $term): string => (string) $term['id'],
                $items,
            )),
            'treeComplete' => $list->meta['treeComplete'] ?? false,
        ]);
    }

    /**
     * URL の `{key}` が **taxonomy の唯一の指定手段**。
     *
     * 入力は `CreateTerm` の受理キーだけへ絞る。**未指定と明示 null を潰さない**ため、
     * 送られてきたキーだけを渡す（`only()` は存在するキーのみ返す）。
     */
    public function storeTerm(Request $request, CreateTerm $create, string $key): RedirectResponse
    {
        $input = $request->only(['name', 'slug', 'description', 'parentId']);
        $input['taxonomyKey'] = $key;

        return $this->redirectFor(
            $create->execute($input, $this->context($request)),
            $key,
            '項目を追加しました',
        );
    }

    /** archive / unarchive も `archived` で行う（専用 route を作らない — §I.5） */
    public function updateTerm(Request $request, UpdateTerm $update, string $id): RedirectResponse
    {
        $result = $update->execute(
            $id,
            $request->only(['name', 'slug', 'description', 'parentId', 'archived']),
            $this->context($request),
        );

        return $this->redirectFor($result, null, $this->updateMessage($result));
    }

    /**
     * 同一 parent 内の並べ替え。`orderedTermIds` は **その親の兄弟全件**
     * （archived を含む）でなければならない — 検証は Application Service が行う。
     */
    public function reorderTerms(Request $request, ReorderTerms $reorder, string $key): RedirectResponse
    {
        $parentId = $request->input('parentId');
        $ordered = $request->input('orderedTermIds');

        $result = $reorder->execute(
            $key,
            is_string($parentId) && $parentId !== '' ? $parentId : null,
            is_array($ordered) ? $ordered : [],
            $this->context($request),
        );

        return $this->redirectFor($result, $key, '並び順を変更しました');
    }

    /**
     * **破壊的操作**。参照が残っていれば confirmation を発行せず conflict になる。
     *
     * - conflict … **なぜ削除できないか**を Reference Inspector の結果で具体的に示し、
     *   archive を代替として提示する（§I.5）。**conflict を confirmation へ変換しない**
     * - confirmation required … 影響範囲を返して止まる。token 付きの再送で削除する
     */
    public function deleteTerm(Request $request, DeleteTerm $delete, string $id): RedirectResponse
    {
        $result = $delete->execute($id, $this->context($request));

        // 参照あり / 調査未完了。**確認ダイアログへ落とさない**（fail-closed も「使用中」と偽らない）
        if ($result->status === OperationStatus::Conflict) {
            Inertia::flash('termDeleteConflict', [
                'termId' => $id,
                'message' => $result->message,
                'references' => $result->data['references'] ?? null,
            ]);

            return back();
        }

        // 破壊的操作なので、token 無しの 1 回目は確認内容を返して止まる。
        // 管理画面も AI も **同じ ConfirmationGate** を通る
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        return $this->redirectFor($result, null, '項目を削除しました');
    }

    /** archive / unarchive を区別して伝える（利用者が何が起きたか分かるように） */
    private function updateMessage(Result $result): string
    {
        return match ($result->meta['action'] ?? null) {
            'archive' => '項目をアーカイブしました',
            'unarchive' => 'アーカイブを解除しました',
            default => '項目を更新しました',
        };
    }

    /**
     * `Result` → 管理画面のリダイレクト。
     *
     * `$key` が null のときは元の画面へ戻る（term ID しか持たない操作）。
     */
    private function redirectFor(Result $result, ?string $key, string $success): RedirectResponse
    {
        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::Conflict) {
            // 競合は入力の誤りではないので、画面へ理由をそのまま見せて止める
            throw ValidationException::withMessages([
                'conflict' => [$result->message ?? '同時に別の変更があったため実行できませんでした'],
            ]);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        $redirect = $key === null
            ? back()
            : redirect()->route('admin.taxonomies.show', ['key' => $key]);

        return $redirect->with('success', $success);
    }

    /**
     * 定義まわりの応答は同じ形にする（画面ごとに分岐を増やさない）。
     *
     * `$stay` は「その画面に留まる」。関連付けの付け外しは設定画面の中の作業なので、
     * 一覧へ飛ばすと**結果を確かめられないまま文脈を失う**。
     */
    private function definitionResponse(Result $result, string $success, bool $stay = false): RedirectResponse
    {
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['lock_version' => (string) $result->message]);
        }

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return $stay
            ? back()->with('success', $success)
            : redirect()->route('admin.taxonomies.index')->with('success', $success);
    }

    private function lockVersion(Request $request): int
    {
        $raw = $request->input('lock_version');

        return is_numeric($raw) ? (int) $raw : -1;
    }

    /**
     * Application Service へ渡す入力（制御値だけ除く）。
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_diff_key($request->all(), [
            'confirmation_token' => true, '_token' => true, '_method' => true,
        ]);
    }

    /**
     * 関連付けの相手になり得る投稿タイプ。
     *
     * **`media` などの system facility は出さない**（判定は EditorialCollections が持つ）。
     *
     * @return list<array{id: string, label: string}>
     */
    private function editorialTypes(SchemaRegistry $schemas): array
    {
        $types = [];

        foreach ($schemas->declared() as $id => $schema) {
            if (EditorialCollections::isEditorial($schema)) {
                $types[] = ['id' => $id, 'label' => $schema->label];
            }
        }

        return $types;
    }

    /** 誰が・どの経路で操作しているか（確認トークンがあれば同梱） */
    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    /** 失敗した Result を HTTP の例外へ写す */
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
