{{-- PageDocument で管理されたページ（Phase 9.1D）。公開側に Vue runtime は不要 --}}
{{-- 載せる layout は `DocumentPageView` が決める（B-38 の 2 本立て）。
     取り込んだ意匠の layout が在ればそちら、無ければ kit 既定 --}}
@extends($layout ?? 'layouts.public')

@section('content')
    @push('head')
        {{-- DocumentRenderer はブロックを先に文字列化するため、ページ側で一度だけ読込む。 --}}
        @if (str_contains((string) $document, 'class="cf-turnstile"'))
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif
        {{-- 検索除外は設定欄だけでなく公開結果にも出す。 --}}
        @if ($noindex ?? false)
            <meta name="robots" content="noindex, follow">
        @endif
        {{-- Design Token は runtime に CSS custom property として出力する（build 不要） --}}
        <style>{!! $themeCss !!}</style>
        @if (!empty($siteAccent))
            {{-- サイトの枠の主な色（見本なら --green）に合わせる。無ければテーマの色のまま --}}
            <style>:root { --color-primary: var(--green, {{ $siteAccent }}); }</style>
        @endif
        @vite('resources/css/document.css')
        {{-- 店の枠にフォームのブロックを置いたとき、入力欄とボタンが素の HTML だった（UX 一周 2026-09-28） --}}
        @if (!empty($siteAccent) && str_contains((string) $document, 'form-page__form'))
            <link rel="stylesheet" href="{{ asset('forms.css') }}">
        @endif
    @endpush

    {{-- サイトの枠（見本・取り込んだテーマ）に載せるときは、見本と同じ帯を出す（UX 一周 2026-09-28） --}}
    @include('public.partials.doc-band', ['band' => $band ?? null])

    {!! $document !!}
@endsection
