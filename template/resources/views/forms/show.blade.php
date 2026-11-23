{{-- フォームの入力画面（kit 既定）。載せる枠は `PublicFormLayout` が決める。
     見本の意匠をやめても、店の枠（見本・取り込んだテーマ）があればそれに載せる（UX 一周 2026-09-28） --}}
@php($formLayout = app(\App\Support\PublicFormLayout::class))
@php($onSite = $formLayout->name() === \App\Support\DocumentPageView::SITE_LAYOUT)
@extends($formLayout->name(), ['title' => $formLayout->title($form->name, (string) ($site['site_name'] ?? ''))])

@if ($onSite)
    @push('head')
        @include('components.site-frame-head')
    @endpush
@endif

@section('content')
@if ($onSite)
<section class="page-title">
    <div class="page-title-inner">
        <h1>{{ $form->name }}</h1>
    </div>
</section>
@endif
<section class="container section form-page">
  <div class="section-inner">
    @unless ($onSite)<h1>{{ $form->name }}</h1>@endunless

    @if ($form->description)
        <p class="form-page__lead">{{ $form->description }}</p>
    @endif

    @include('forms.partials.input')
  </div>
</section>
@endsection
