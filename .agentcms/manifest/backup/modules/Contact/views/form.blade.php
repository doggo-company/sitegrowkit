@extends('layouts.public', ['title' => 'お問い合わせ', 'metaDescription' => 'Web サイト制作・ブランディングのご相談はこちらのフォームからお問い合わせください。'])

@push('head')
    {{-- Turnstile widget（サーバー側 siteverify は ContactController で必須実行） --}}
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endpush

@section('content')
    <div class="page-hero">
        <div class="container">
            <h1 class="page-hero__title">お問い合わせ</h1>
            <p class="page-hero__sub">CONTACT</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            @if (session('contact_success'))
                <div class="alert" role="status">
                    <p style="margin:0"><strong>お問い合わせを受け付けました。</strong><br>
                    確認メールをお送りしています。届かない場合も受付は完了していますので、返信をお待ちください。</p>
                </div>
            @endif

            {{-- component: public.contact-form (registry: pub-contact-form) --}}
            <form class="form" method="post" action="{{ route('contact.store') }}" novalidate>
                @csrf

                <div class="form__field">
                    <label class="form__label" for="name">お名前<span class="form__required">必須</span></label>
                    <input class="form__input" type="text" id="name" name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}">
                    @error('name')<p class="form__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="form__field">
                    <label class="form__label" for="email">メールアドレス<span class="form__required">必須</span></label>
                    <input class="form__input" type="email" id="email" name="email" required maxlength="255" autocomplete="email" value="{{ old('email') }}">
                    @error('email')<p class="form__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="form__field">
                    <label class="form__label" for="company">会社名</label>
                    <input class="form__input" type="text" id="company" name="company" maxlength="120" autocomplete="organization" value="{{ old('company') }}">
                    @error('company')<p class="form__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="form__field">
                    <label class="form__label" for="subject">件名<span class="form__required">必須</span></label>
                    <select class="form__select" id="subject" name="subject" required>
                        <option value="" @selected(old('subject') === null)>選択してください</option>
                        <option value="estimate" @selected(old('subject') === 'estimate')>お見積もり</option>
                        <option value="consultation" @selected(old('subject') === 'consultation')>ご相談</option>
                        <option value="other" @selected(old('subject') === 'other')>その他</option>
                    </select>
                    @error('subject')<p class="form__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="form__field">
                    <label class="form__label" for="message">お問い合わせ内容<span class="form__required">必須</span></label>
                    <textarea class="form__textarea" id="message" name="message" rows="8" required maxlength="5000">{{ old('message') }}</textarea>
                    @error('message')<p class="form__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="form__field">
                    <div class="cf-turnstile" data-sitekey="{{ config('agentcms.turnstile.site_key') }}" data-language="ja"></div>
                    @error('turnstile')<p class="form__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <p class="form__note">送信いただいた内容への返信以外の目的で、個人情報を利用することはありません。</p>

                <div>
                    <button class="button" type="submit">送信する</button>
                </div>
            </form>
        </div>
    </section>
@endsection
