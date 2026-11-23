{{-- component: public.comments (Phase 9.13 / STAGE 26D・SPEC §C.3)

     記事に付いたコメントと、その投稿欄。

     **型が OFF なら 1 バイトも出さない。** 見出しも件数も出さない — 「隠す」ではなく
     無い（`WP_PARITY_BASELINE.md` §2.5）。判定は `CommentGate` の 1 か所だけが持つ。

     受付を終えた記事でも、**承認済みのコメントは出し続ける**。止まるのは新規受付だけ。

     本文は `{{ }}` で必ずエスケープする。**HTML を 1 つも許さない**（§F.3）。
     改行だけ <br> にするので `nl2br(e(...))` の順で通す（先に e、あとから nl2br）。 --}}
@props(['contentType', 'entry'])

@php
    $gate = app(\AgentCms\Core\Comments\CommentGate::class);
    $entryId = (int) $entry->getKey();
    $enabled = $gate->enabledForType($contentType);
    $thread = $enabled
        ? \AgentCms\Core\Comments\CommentThread::of($contentType, $entryId)
        : \AgentCms\Core\Comments\CommentThread::empty();
    $accepting = $enabled && $gate->acceptsNew($contentType, $entryId);
    $honeypot = \AgentCms\Core\Spam\HoneypotCheck::FIELD;
    $turnstileSiteKey = (string) config('agentcms.turnstile.site_key', '');
@endphp

@if ($enabled)
    @if ($turnstileSiteKey !== '')
        @push('head')
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endpush
    @endif

    <section class="section comments" id="comments">
        <div class="container">
            {{-- 数えるのは承認済みだけ。「3 件」と出て 1 件しか見えない、を作らない --}}
            <h2 class="section__title">コメント（{{ $thread->count }}）</h2>

            @if (session('comment_posted'))
                <p class="comments__notice" role="status">{{ session('comment_posted') }}</p>
            @endif

            @error('comment')
                <p class="comments__notice comments__notice--error" role="alert">{{ $message }}</p>
            @enderror

            @if ($thread->items === [])
                <p class="comments__empty">まだコメントはありません。</p>
            @else
                <ol class="comments__list">
                    @foreach ($thread->items as $comment)
                        <li class="comments__item" id="comment-{{ $comment['id'] }}">
                            <p class="comments__meta">
                                @if ($comment['author_url'])
                                    {{-- 未認証の相手が入れた URL。検索評価を渡さない --}}
                                    <a href="{{ $comment['author_url'] }}" rel="nofollow ugc noopener" class="comments__author">{{ $comment['author_name'] }}</a>
                                @else
                                    <span class="comments__author">{{ $comment['author_name'] }}</span>
                                @endif
                                @if ($comment['is_staff'])
                                    <span class="comments__badge">運営</span>
                                @endif
                                @if ($comment['created_at'])
                                    <time datetime="{{ $comment['created_at'] }}">{{ substr($comment['created_at'], 0, 10) }}</time>
                                @endif
                            </p>
                            <div class="comments__body">{!! nl2br(e($comment['body'])) !!}</div>

                            @if ($comment['replies'] !== [])
                                <ol class="comments__replies">
                                    @foreach ($comment['replies'] as $reply)
                                        <li class="comments__item" id="comment-{{ $reply['id'] }}">
                                            <p class="comments__meta">
                                                <span class="comments__author">{{ $reply['author_name'] }}</span>
                                                @if ($reply['is_staff'])
                                                    <span class="comments__badge">運営</span>
                                                @endif
                                                @if ($reply['created_at'])
                                                    <time datetime="{{ $reply['created_at'] }}">{{ substr($reply['created_at'], 0, 10) }}</time>
                                                @endif
                                            </p>
                                            <div class="comments__body">{!! nl2br(e($reply['body'])) !!}</div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($accepting)
                <form
                    class="comments__form"
                    method="post"
                    action="{{ url("/comments/{$contentType}/{$entryId}") }}"
                    novalidate
                >
                    @csrf

                    <h3 class="comments__form-title">コメントを書く</h3>

                    <div class="form-field">
                        <label class="form-field__label" for="c-author-name">
                            お名前<span class="form-field__required">必須</span>
                        </label>
                        <input type="text" id="c-author-name" name="author_name" maxlength="80"
                            value="{{ old('author_name') }}" required
                            @error('author_name') aria-invalid="true" aria-describedby="c-author-name-error" @enderror>
                        @error('author_name')
                            <p class="form-field__error" id="c-author-name-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label class="form-field__label" for="c-author-email">
                            メールアドレス
                            @if (config('agentcms.comments.require_email'))<span class="form-field__required">必須</span>@endif
                        </label>
                        <input type="email" id="c-author-email" name="author_email" maxlength="255"
                            value="{{ old('author_email') }}"
                            aria-describedby="c-author-email-help"
                            @error('author_email') aria-invalid="true" @enderror>
                        {{-- 公開しないことを、集める場所で言う --}}
                        <p class="form-field__help" id="c-author-email-help">公開されません。</p>
                        @error('author_email')
                            <p class="form-field__error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label class="form-field__label" for="c-body">
                            コメント<span class="form-field__required">必須</span>
                        </label>
                        <textarea id="c-body" name="body" rows="6"
                            maxlength="{{ \AgentCms\Core\Comments\CommentBody::MAX_LENGTH }}" required
                            @error('body') aria-invalid="true" aria-describedby="c-body-error" @enderror>{{ old('body') }}</textarea>
                        @error('body')
                            <p class="form-field__error" id="c-body-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- 人には見えない項目（§F.2）。位置と大きさで隠し、Tab と支援技術から外す --}}
                    <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                        <label for="{{ $honeypot }}">この欄は入力しないでください</label>
                        <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
                    </div>

                    @if ($turnstileSiteKey !== '')
                        <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-language="ja"></div>
                        @error('cf-turnstile-response')
                            <p class="form-field__error" role="alert">{{ $message }}</p>
                        @enderror
                    @endif

                    <button type="submit" class="form-page__submit">コメントを送信</button>
                </form>
            @else
                {{-- URL は生きている。無言で欄だけ消さない --}}
                <p class="comments__closed" role="status">この記事のコメントは受付を終了しました。</p>
            @endif
        </div>
    </section>
@endif
