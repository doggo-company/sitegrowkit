<?php

declare(strict_types=1);

namespace Modules\Media\Http;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Media\MediaKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Media\Application\DeleteMedia;
use Modules\Media\Application\GetMedia;
use Modules\Media\Application\ListMedia;
use Modules\Media\Application\ReplaceMedia;
use Modules\Media\Application\UpdateMedia;
use Modules\Media\Application\UploadMedia;
use Modules\Media\Models\Media;

/**
 * メディア管理。クライアント操作は「アップロード → alt 入力 → 選択 → 保存」のみ（Phase 2 仕様 ADMIN UX）。
 * 削除は使用箇所を確認するまで実行しない（DELETE SAFETY / ADR-0006）。
 *
 * Phase 9.1B 以降、業務ロジックは `Modules\Media\Application` 側にある。
 */
final class AdminMediaController
{
    public function index(Request $request, ListMedia $list): Response
    {
        $kind = $this->kind($request);
        $items = $list->paginate($this->context($request), kind: $kind);

        if ($items instanceof Result) {
            $this->abortFor($items);
        }

        return Inertia::render('admin/media/Index', [
            'items' => [
                ...$items->withQueryString()->toArray(),
                'data' => $list->withUsage($items),
            ],
            // タブの現在地。`null` は「すべて」
            'kind' => $kind?->value,
        ]);
    }

    /**
     * MediaPicker 用の軽量 JSON（管理画面内でのみ使用）。
     *
     * `?kind=` で種別を絞る。`media` / `images` は画像だけ、`file` はファイルだけを
     * 出したいが、**画面で隠すだけにしない** — 種別の食い違いは server validation が拒否する。
     */
    public function select(Request $request): JsonResponse
    {
        $kind = $this->kind($request);

        return response()->json([
            'media' => Media::query()
                ->when($kind !== null, fn ($q) => $q->where('kind', $kind?->value))
                ->with('variants')
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (Media $m) => $m->toPickerArray()),
        ]);
    }

    /** `?kind=` の読み取り。知らない値は「すべて」として扱う（画面を壊さない） */
    private function kind(Request $request): ?MediaKind
    {
        $raw = $request->query('kind');

        return is_string($raw) ? MediaKind::tryFrom($raw) : null;
    }

    public function store(Request $request, UploadMedia $upload): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'name' => ['nullable', 'string', 'max:120'],
            'alt' => ['nullable', 'string', 'max:200'],
        ]);

        $result = $upload->execute(
            $request->file('file'),
            $this->context($request),
            $validated['name'] ?? null,
            $validated['alt'] ?? null,
        );

        /*
         * 編集画面の「画像を選択」からその場で上げたときは、上げたものを返す（UX 一周 2026-09-28）。
         * 画面を移らずに、そのまま選べるようにするため。検証の失敗は Laravel が 422 で返す。
         */
        if ($request->wantsJson() && $result->isSuccess()) {
            return response()->json(['data' => ['media' => $result->data['media'] ?? null]], 201);
        }

        return $this->backFor($result, $this->noun($result).'をアップロードしました');
    }

    /** 専用の編集ページ（modal をやめて 1 ページで完結させる — F-UX7） */
    public function edit(Request $request, GetMedia $get, int $id): Response
    {
        $media = $get->find($id, $this->context($request));

        if ($media instanceof Result) {
            $this->abortFor($media);
        }

        return Inertia::render('admin/media/Edit', [
            'media' => $get->present($media),
        ]);
    }

    /** メディアの差し替え（F-UX6）。id は維持され、種別は変えられない（SPEC §3.7） */
    public function replace(Request $request, ReplaceMedia $replace, int $id): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        $result = $replace->execute($id, $request->file('file'), $this->context($request));

        return $this->backFor($result, $this->noun($result).'を差し替えました');
    }

    public function update(Request $request, UpdateMedia $update, int $id): RedirectResponse
    {
        $result = $update->execute($id, $request->all(), $this->context($request));

        // 公開範囲の変更は外向きの効果なので、token 無しの 1 回目は確認内容を返して止まる
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        return $this->backFor($result, 'メディア情報を更新しました');
    }

    public function destroy(Request $request, DeleteMedia $delete, int $id): RedirectResponse
    {
        $result = $delete->execute($id, $this->context($request));

        // 使用中のメディアは削除させない。使用箇所をフォームエラーとして返す
        if ($result->status === OperationStatus::Conflict) {
            return back()->withErrors(['media' => (string) $result->message]);
        }

        // 破壊的操作なので、token 無しの 1 回目は確認内容を返して止まる（STEP 7）
        if ($result->status === OperationStatus::ConfirmationRequired) {
            Inertia::flash('confirmation', $result->confirmation?->toArray());

            return back();
        }

        return $this->backFor($result, $this->noun($result).'を削除しました');
    }

    /**
     * 結果が指すメディアの呼び名。
     *
     * 「画像をアップロードしました」と固定していると、PDF を上げた人に
     * 嘘を伝えることになる。種別は結果が持っているので、そこから引く。
     */
    private function noun(Result $result): string
    {
        $kind = $result->data['media']['kind'] ?? $result->data['kind'] ?? null;

        return MediaKind::tryFrom(is_string($kind) ? $kind : '')?->label() ?? 'メディア';
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();
        $token = $request->input('confirmation_token');

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name)
            ->withConfirmationToken(is_string($token) && $token !== '' ? $token : null);
    }

    private function backFor(Result $result, string $success): RedirectResponse
    {
        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return back()->with('success', $success);
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
