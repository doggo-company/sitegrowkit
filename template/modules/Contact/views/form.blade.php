{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。 --}}
@extends('layouts.imported', ['sampleNotice' => true, 'title' => $site['site_name'].' | Contact', 'metaDescription' => $site['site_name'].' へのお問い合わせフォームです。'])

@push('head')
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endpush

@section('content')
<section class="page-title">
      <div class="page-title-inner">
        <h1>Contact</h1>
        <p class="page-title-sub">お問い合わせ</p>
      </div>
    </section>

    {{-- 祖先はメニューの木から（P1 ⑤）。手書きしない --}}
    <x-breadcrumb current="Contact" />

    <section class="section contact-body">
      <div class="section-inner">
        <p class="contact-lead">制作のご相談・お見積もりは下記フォームからお送りください。<br>
          お急ぎの場合は <a href="tel:075-000-0000">075-000-0000</a> までお電話ください。</p>
        @if (session('contact_success'))
<div class="contact-success" role="status"><p><strong>お問い合わせを受け付けました。</strong><br>確認メールをお送りしています。届かない場合も受付は完了しています。</p></div>
@endif
@if (isset($rateLimitMessage))
<div class="contact-error" role="alert"><p>{{ $rateLimitMessage }}</p></div>
@endif
<form class="contact-form" action="{{ route('contact.store') }}" method="post">
    @csrf
          <div class="field">
            <label for="name">お名前</label>
            <input type="text" id="name" name="name" required value="{{ old('name') }}">
@error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="email">メールアドレス</label>
            <input type="email" id="email" name="email" required value="{{ old('email') }}">
@error('email')<p class="form-error" role="alert">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="type">ご用件</label>
            <select id="type" name="subject">
              <option value="estimate" @selected(old('subject') === 'estimate')>お見積もり</option>
              <option value="other" @selected(old('subject') === 'other')>その他</option>
            </select>
@error('subject')<p class="form-error" role="alert">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="message">内容</label>
            <textarea id="message" name="message" rows="6" required>{{ old('message') }}</textarea>
@error('message')<p class="form-error" role="alert">{{ $message }}</p>@enderror
          </div>
          <div class="field field-checkbox">
            <label><input type="checkbox" name="privacy" required @checked(old('privacy'))> <a href="{{ route('about') }}">プライバシーポリシー</a>に同意する</label>
          </div>
          
          <div class="cf-turnstile" data-sitekey="{{ config('agentcms.turnstile.site_key') }}" data-language="ja"></div>
@error('turnstile')<p class="form-error" role="alert">{{ $message }}</p>@enderror
<button type="submit" class="btn">送信</button>
        </form>
      </div>
    </section>
@endsection
