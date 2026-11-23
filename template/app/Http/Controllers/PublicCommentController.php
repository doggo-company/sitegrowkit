<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Comments\Comment;
use AgentCms\Core\Comments\CommentBody;
use AgentCms\Core\Comments\CommentGate;
use AgentCms\Core\Comments\CommentStatus;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Mail\RecordedMailer;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Settings\SettingsStore;
use AgentCms\Core\Spam\HoneypotCheck;
use AgentCms\Core\Spam\SpamProtection;
use AgentCms\Core\Spam\SpamReason;
use AgentCms\Core\Spam\SpamSubject;
use App\Mail\CommentNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Notifications\NotificationType;
use Modules\Notifications\Notifier;

/**
 * コメントの受付（Phase 9.13 / STAGE 26D・SPEC §C）。
 *
 * ## route は 1 本だけ
 *
 * 表示は記事ページの中で行うので、投稿の口だけを置く。
 * 一覧や詳細の route を作ると、**承認前の本文へ直リンクできる口**ができる。
 *
 * ## 型が OFF なら 404
 *
 * 「隠す」ではなく **無い**（`WP_PARITY_BASELINE.md` §2.5）。
 * 403 でも「無効です」でもない — 型が OFF のサイトに、その口は存在しない。
 *
 * ## 送信の順序は変えない（ADR-0005 / OG12）
 *
 * ```
 * Validation → bot 対策 → DB 保存 → 管理者通知 → Push
 * ```
 *
 * **保存はメールより先。** 通知が全部落ちても、書かれたものは残る。
 * コメント投稿者への返信メールは送らない（SPEC §A.3）。
 */
final class PublicCommentController
{
    public function store(
        Request $request,
        string $contentType,
        string $contentId,
        CommentGate $gate,
        ContentTypeResolver $types,
        ContentTypeSettingsResolver $settings,
        SpamProtection $spam,
        RecordedMailer $mailer,
        SettingsStore $site,
        Notifier $notifier,
    ): RedirectResponse {
        $entryId = (int) $contentId;

        // 1. 型が OFF なら、この口は無い
        abort_unless($gate->enabledForType($contentType), 404);

        $entry = $this->publishedEntry($types, $contentType, $entryId);

        abort_if($entry === null, 404);

        $back = $this->entryUrl($settings, $contentType, $entry);

        // 2. 記事が閉じているときは、画面は出るが受け付けない
        if (! $gate->acceptsNew($contentType, $entryId)) {
            return redirect($back.'#comments')
                ->withErrors(['comment' => 'この記事のコメントは受付を終了しました。']);
        }

        // 3. Validation
        $validated = $this->validate($request, $contentType);

        if ($validated instanceof RedirectResponse) {
            return $validated;
        }

        // 4. bot 対策。**記事ごとではなく型ごとに数える**（荒らしは別の記事へも書く）
        $blocked = $spam->inspect($request, new SpamSubject(
            kind: 'comment',
            id: $contentType,
            ratePerMinute: (int) config('agentcms.comments.rate_limit_per_minute', 3),
        ));

        if ($blocked !== null) {
            return $this->rejected($blocked, $back);
        }

        // 5. DB へ保存（通知より先。以降の失敗は書かれたものを失わせない）
        $comment = $this->store_($request, $contentType, $entryId, $validated);

        // 6. 管理者通知（落ちてもコメントは残す）
        $adminTo = (string) ($site->get('contact_email') ?: config('agentcms.mail.admin_to'));

        if ($adminTo !== '') {
            $mailer->send(new CommentNotification($comment), $adminTo);
        }

        // 7. Push。**本文も投稿者名も載せない**（端末のロック画面に他人の文章を流さない）
        $notifier->send(
            NotificationType::CommentNew,
            '新しいコメント',
            '承認待ちのコメントが届きました',
            url('/admin/comments'),
        );

        return redirect($back.'#comments')->with(
            'comment_posted',
            $comment->status()->isPublic()
                ? 'コメントを投稿しました。'
                : 'コメントを受け付けました。承認後に表示されます。',
        );
    }

    // --- 内部 ----------------------------------------------------------

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    private function validate(Request $request, string $contentType): array|RedirectResponse
    {
        $requireEmail = (bool) config('agentcms.comments.require_email', false);

        $validator = Validator::make($request->all(), [
            'author_name' => ['required', 'string', 'max:80'],
            'author_email' => [$requireEmail ? 'required' : 'nullable', 'string', 'email', 'max:255'],
            'author_url' => ['nullable', 'string', 'url', 'max:255'],
            'body' => ['required', 'string', 'max:'.CommentBody::MAX_LENGTH],
            'parent_id' => ['nullable', 'integer'],
        ], [], [
            'author_name' => 'お名前',
            'author_email' => 'メールアドレス',
            'author_url' => 'ウェブサイト',
            'body' => 'コメント',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function store_(Request $request, string $contentType, int $entryId, array $validated): Comment
    {
        $body = CommentBody::normalize((string) $validated['body']);
        $parent = $this->parentFor($validated['parent_id'] ?? null, $contentType, $entryId);

        $comment = new Comment;
        $comment->forceFill([
            'content_type' => $contentType,
            'content_id' => $entryId,
            'parent_id' => $parent,
            'status' => $this->initialStatus($body, $request, $contentType, $entryId)->value,
            'author_name' => trim((string) $validated['author_name']),
            'author_email' => $this->text($validated['author_email'] ?? null),
            'author_url' => $this->text($validated['author_url'] ?? null),
            // **原文のまま持つ。** 無害化は出すときに行う（§F.3）
            'body' => $body,
            'is_staff' => false,
            'ip_hash' => $this->ipHash($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        $comment->save();

        return $comment;
    }

    /**
     * 最初の状態（SPEC §B.4 / §F.2）。
     *
     * 既定は承認待ち。自動承認を入れている場合でも、
     * **リンクが多い**か**直前に同じ相手が書いている**なら承認待ちのままにする。
     * 自動承認は「普段の投稿を楽にする」ためのもので、
     * スパムの通り道にするためのものではない。
     */
    private function initialStatus(string $body, Request $request, string $contentType, int $entryId): CommentStatus
    {
        if (CommentBody::looksLikeLinkSpam($body)) {
            // 拒否ではなく `spam` へ落とす。誤判定で人の文章を捨てない（OG6）
            return CommentStatus::Spam;
        }

        if (! (bool) config('agentcms.comments.auto_approve', false)) {
            return CommentStatus::Pending;
        }

        return $this->postedRecently($request, $contentType, $entryId)
            ? CommentStatus::Pending
            : CommentStatus::Approved;
    }

    /** 同じ相手が直前に書いているか（自動承認を効かせない — §F.2） */
    private function postedRecently(Request $request, string $contentType, int $entryId): bool
    {
        $hash = $this->ipHash($request);

        if ($hash === null) {
            return false;
        }

        return Comment::query()
            ->where('content_type', $contentType)
            ->where('content_id', $entryId)
            ->where('ip_hash', $hash)
            ->where('created_at', '>=', now()->subSeconds(30))
            ->exists();
    }

    /**
     * 返信先（1 段まで・同じ記事のものだけ）。
     *
     * 別の記事のコメント id を送られても**黙って親なしにする**。
     * 断ると「返信できないフォーム」に見えるが、実際には書けている。
     */
    private function parentFor(mixed $parentId, string $contentType, int $entryId): ?int
    {
        if (! is_numeric($parentId)) {
            return null;
        }

        $parent = Comment::query()
            ->where('id', (int) $parentId)
            ->where('content_type', $contentType)
            ->where('content_id', $entryId)
            ->whereNull('parent_id')
            ->where('status', CommentStatus::Approved->value)
            ->first();

        return $parent === null ? null : (int) $parent->getKey();
    }

    private function rejected(SpamReason $reason, string $back): RedirectResponse
    {
        return match ($reason) {
            // 弾いたことを教えない（OG10）。保存はしていない
            SpamReason::Honeypot => redirect($back.'#comments')
                ->with('comment_posted', 'コメントを受け付けました。承認後に表示されます。'),
            SpamReason::RateLimit => abort(429, '投稿が集中しています。しばらく待ってからお試しください。'),
            SpamReason::Bot => redirect($back.'#comments')
                ->withErrors([
                    'cf-turnstile-response' => 'ボット確認が完了していません。'
                        .'画面の確認欄にチェックが入るのを待ってから、もう一度送信してください。',
                ])
                ->withInput(),
        };
    }

    /** 公開済みの記事だけ（下書きにコメントを付けさせない） */
    private function publishedEntry(ContentTypeResolver $types, string $contentType, int $entryId): ?Model
    {
        if (! $types->knows($contentType)) {
            return null;
        }

        return $types->newModel($contentType)->newQuery()->scopes('published')->find($entryId);
    }

    /**
     * 戻り先。**戻れないなら投稿もさせない**ので、slug が無い型はここへ来ない。
     *
     * **形は `ContentPermalink` が決める**（P1 ③ 段 2・F-40）。ここで自分で組むと、
     * URL の構造を変えた型で**コメントのあとに旧い URL へ戻される**（そして 404 になる）。
     */
    private function entryUrl(ContentTypeSettingsResolver $settings, string $contentType, Model $entry): string
    {
        return app(ContentPermalink::class)->entry($contentType, $entry) ?? url('/');
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** 生の IP は持たない（9.10 / 9.12 と同じ形） */
    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip === null ? null : hash('sha256', (string) config('app.key').'|'.$ip);
    }

    /** 人には見えない項目の名前（view が使う） */
    public static function honeypotField(): string
    {
        return HoneypotCheck::FIELD;
    }
}
