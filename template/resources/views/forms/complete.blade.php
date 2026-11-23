{{-- 載せる layout は controller が決める（`PublicFormLayout`）。
     入力画面が案件の意匠で描かれているなら、出口も同じ意匠に載せる --}}
@extends($layout, ['title' => $title])

@push('head')
    {{-- 2 つの layout のどちらに載っても同じに見えるようにする（R-12）。
         案件の意匠は `public.css` を読まないので、確認・完了画面のぶんだけここで補う --}}
    <link rel="stylesheet" href="{{ asset('forms.css') }}">
@endpush

@section('content')
{{-- 外枠の class は**両方の layout に効く形**にする（R-12 / 受付の一本化）。
     `container` は kit 既定（`public.css`）、`section` / `section-inner` は
     取り込んだ意匠の CSS が持つ。**互いに相手側では定義が無い**ので、
     両方書いても衝突しない — 載る layout がどちらでも余白が付く。 --}}
{{-- 店の枠では、入力画面と同じ題の帯を出す（UX 一周 第10周: 送った途端に帯が消え、別の画面に見えた） --}}
@php($onSiteBand = $layout === \App\Support\DocumentPageView::SITE_LAYOUT)
@if ($onSiteBand)
<section class="page-title">
  <div class="page-title-inner">
    <h1>{{ $form->name }}</h1>
  </div>
</section>
@endif
<section class="container section form-page">
  <div class="section-inner">
    @unless ($onSiteBand)<h1>{{ $form->name }}</h1>@endunless
    <div class="form-page__complete" role="status">
        <p>{{ $message }}</p>
    </div>
    <p><a href="{{ route('home') }}">トップページへ戻る</a></p>
  </div>
</section>
@endsection
