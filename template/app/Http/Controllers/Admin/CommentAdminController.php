<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\Comments\GetComment;
use AgentCms\Core\Application\Comments\ListComments;
use AgentCms\Core\Application\Comments\ReplyToComment;
use AgentCms\Core\Application\Comments\SetCommentStatus;
use AgentCms\Core\Application\Comments\SetEntryCommentOpen;
use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Comments\CommentStatus;
use App\Models\User;
use App\Support\Capability;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * コメントの管理画面（Phase 9.13 / STAGE 26E・SPEC §D）。
 *
 * ## 責務
 *
 * request の受け取りと `Result` の描画だけ。**業務ロジックを持たない。**
 *
 * ## 承認待ちを最初に出す
 *
 * 既定の絞り込みは `pending`。**最初に見るべきものを最初に出す。**
 * 全件を出して「未承認はどれ？」と探させない。
 *
 * ## 記事のタイトルを添える
 *
 * `(content_type, content_id)` だけでは、どの記事に付いたコメントか分からない。
 * 型が読めない・記事が消えている場合は**そう書いて残す**（黙って消さない）。
 */
final class CommentAdminController
{
    public function index(Request $request, ListComments $list, ContentTypeResolver $types): Response
    {
        $result = $list->execute(
            $this->context($request),
            (string) $request->query('status', CommentStatus::Pending->value),
            $this->nullableString($request->query('contentType')),
            $request->filled('contentId') ? (int) $request->query('contentId') : null,
            (string) $request->query('q', ''),
            (int) $request->query('page', 1),
        );

        $this->abortUnlessSuccess($result);

        return Inertia::render('admin/comments/Index', [
            'comments' => $this->withEntryTitles($result->data['comments'], $types),
            'meta' => $result->meta,
            'statuses' => CommentStatus::options(),
            'canModerate' => $this->canModerate($request),
        ]);
    }

    /**
     * 1 件の詳細。
     *
     * **その記事の受付設定もここに出す。** コメントを読んでいるときこそ
     * 「この記事は閉じておこう」と思う場面で、設定を探しに行かせない。
     */
    public function show(
        Request $request,
        GetComment $get,
        ContentTypeResolver $types,
        CommentGate $gate,
        string $id,
    ): Response {
        $result = $get->execute((int) $id, $this->context($request));

        $this->abortUnlessSuccess($result);

        $comment = $result->data['comment'];
        $contentType = (string) $comment['content_type'];
        $contentId = (int) $comment['content_id'];

        return Inertia::render('admin/comments/Show', [
            'comment' => $comment + [
                'entry_title' => $this->entryTitle($types, $contentType, $contentId),
            ],
            'entry' => [
                'enabled' => $gate->enabledForType($contentType),
                // null = 型に従う（`false` と取り違えない）
                'isOpen' => $gate->entryOpen($contentType, $contentId),
                'acceptsNew' => $gate->acceptsNew($contentType, $contentId),
            ],
            'statuses' => CommentStatus::options(),
            'canModerate' => $this->canModerate($request),
            'canWriteContent' => $this->canWriteContent($request),
        ]);
    }

    /** まとめて承認できる（1 件ずつ押させない）。**まとめての物理削除は無い** */
    public function status(Request $request, SetCommentStatus $set): RedirectResponse
    {
        // **`array_values` を通す。** 細工された body は文字列キーの配列でも来る
        $ids = array_values(array_map(intval(...), (array) $request->input('ids', [])));

        $result = $set->execute($ids, (string) $request->input('status', ''), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '状態を変更しました');
    }

    public function reply(Request $request, ReplyToComment $reply, string $id): RedirectResponse
    {
        $result = $reply->execute((int) $id, (string) $request->input('body', ''), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if ($result->status === OperationStatus::Conflict) {
            return back()->with('error', (string) $result->message);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', '返信しました');
    }

    /** 記事ごとの受付可否（権限は `ContentWrite` — 記事の設定の一部だから） */
    public function entryOpen(
        Request $request,
        SetEntryCommentOpen $set,
        string $contentType,
        string $contentId,
    ): RedirectResponse {
        $raw = $request->input('is_open');

        $result = $set->execute(
            $contentType,
            (int) $contentId,
            // **null は「型に従う」。** 空文字と取り違えない
            $raw === null || $raw === '' ? null : $request->boolean('is_open'),
            $this->context($request),
        );

        if ($result->status === OperationStatus::Conflict) {
            return back()->with('error', (string) $result->message);
        }

        $this->abortUnlessSuccess($result);

        return back()->with('success', 'コメントの受付設定を変更しました');
    }

    // --- 内部 ----------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $comments
     * @return list<array<string, mixed>>
     */
    private function withEntryTitles(array $comments, ContentTypeResolver $types): array
    {
        return array_map(
            fn (array $comment): array => $comment + [
                'entry_title' => $this->entryTitle(
                    $types,
                    (string) $comment['content_type'],
                    (int) $comment['content_id'],
                ),
            ],
            $comments,
        );
    }

    /** 記事のタイトル。**読めなければそう書く**（黙って空にしない） */
    private function entryTitle(ContentTypeResolver $types, string $contentType, int $contentId): ?string
    {
        if (! $types->knows($contentType)) {
            return null;
        }

        $entry = $types->newModel($contentType)->newQuery()->find($contentId);

        return $entry === null ? null : (string) $entry->getAttribute('title');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** 画面の導線を出すかどうかだけ。実際の拒否は operation 側が行う */
    private function canModerate(Request $request): bool
    {
        return $this->has($request, Capability::CommentModerate);
    }

    /** 記事ごとの受付は記事の設定なので `ContentWrite` */
    private function canWriteContent(Request $request): bool
    {
        return $this->has($request, Capability::ContentWrite);
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
