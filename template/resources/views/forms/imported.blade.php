{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。

     ## この view は何か（R-12 / 受付の一本化）

     **フォームを、この案件の意匠で描く view** です。在れば `PublicFormController` がこちらを使い、
     無ければ kit 既定（`forms/show.blade.php`）に落ちます（判定は `inputView()` の 1 か所）。

     取り込んだ案件では `agentcms template apply` が**原本の意匠で**この位置に作り直します。
     同梱見本の分は、見本サイトの `/contact` と**同じ外側**（`page-title` / パンくず /
     `contact-form` / `btn`）を持たせてあります — **一本化しても見た目が変わらない**ようにするためです。

     ## 全部のフォームがこの意匠になる

     案件が持っている意匠は 1 つなので、フォームごとに見た目を変える理由がありません。
     見出しと説明は `$form` から来るので、2 本目のフォームを作ってもそのまま使えます。 --}}
@extends('layouts.imported', [
    'title' => $site['site_name'].' | '.$form->name,
    'metaDescription' => $form->description ?: $site['site_name'].' へのお問い合わせフォームです。',
])

@push('head')
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endpush

@section('content')
<section class="page-title">
      <div class="page-title-inner">
        {{-- 副題は出さない。見本の原本は英語の見出し＋日本語の副題だったが、
             ここはフォームの名前で描くので、そのまま置くと同じ語が 2 行並ぶ --}}
        <h1>{{ $form->name }}</h1>
      </div>
    </section>

    {{-- 祖先はメニューの木から（P1 ⑤）。手書きしない --}}
    <x-breadcrumb :current="$form->name" />

    <section class="section contact-body">
      <div class="section-inner">
        @if ($form->description)
          <p class="contact-lead">{{ $form->description }}</p>
        @endif

        @if (isset($rateLimitMessage))
          <p class="form-error" role="alert">{{ $rateLimitMessage }}</p>
        @endif
        @if ($closed)
          {{-- 受付を終了したフォーム。URL は生きているが送信はできない（SPEC §C.1） --}}
          <p class="form-closed" role="status">このフォームは受付を終了しました。</p>
        @else
          @if ($errors->any())
            <p class="form-error" role="alert">入力内容をご確認ください。</p>
          @endif

<form class="contact-form" action="{{ $action }}" method="post" novalidate>
    @csrf
          @foreach ($fields as $field)
            @php $fieldId = 'f-'.$field->key; @endphp
            <div class="field">
              {{-- 必須は**押す前に**分かるようにする（WP 一周 7 周目 2026-09-03）。
                   この form は `novalidate` なので、書かないとブラウザも止めない —
                   送信を押して、サーバーに叱られて初めて分かることになる。
                   kit 既定（`forms/partials/field.blade.php`）は前から出していた。
                   出していなかったのはこちらだけで、**同梱の見本はこちらを使う**。 --}}
              <label for="{{ $fieldId }}">{{ $field->label }}@if($field->required)<span class="field__required">必須</span>@endif</label>
              @include('forms.partials.imported-control', ['field' => $field, 'fieldId' => $fieldId])
              @error($field->key)<p class="form-error" role="alert">{{ $message }}</p>@enderror
            </div>
          @endforeach

          {{-- 罠の欄。人には見えない（`HoneypotCheck` が名前を決める） --}}
          <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label for="{{ $honeypot }}">この欄は入力しないでください</label>
            <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
          </div>

          @if ($turnstileSiteKey !== '')
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-language="ja"></div>
            @error('cf-turnstile-response')<p class="form-error" role="alert">{{ $message }}</p>@enderror
          @endif

          <button type="submit" class="btn">{{ $submitLabel }}</button>
        </form>
        @endif
      </div>
    </section>
@endsection
