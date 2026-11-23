{{--
    公開した投稿タイプの詳細（Phase 9.7 / STAGE 20C・OG-D = (a)）。

    型ごとの view（`public/<型 ID>/show.blade.php`）があればそちらが使われる。
--}}
{{-- サイトの枠（見本・取り込んだテーマ）があればそれに載せる（UX 一周 2026-09-28: 記事の一覧と詳細だけ
     素朴な枠になり、同じ店のサイトに見えなかった）。部品の CSS は site-parts.css --}}
@php($siteLayout = app(\App\Support\DocumentPageView::class)->siteLayout())
@if ($siteLayout !== 'layouts.public')
    @push('head')
        @vite('resources/css/site-parts.css')
    @endpush
@endif
@extends($siteLayout, [
    'title' => $siteLayout === 'layouts.public' ? $item->title : $item->title.' | '.($site['site_name'] ?? ''),
    'metaDescription' => $item->excerpt ?: ($body ? mb_substr(strip_tags($body), 0, 120) : $label),
    'ogType' => 'article',
])

@section('content')
{{-- 店の枠では、ページと同じ題の帯（page-title）にする（UX 一周 第11周: 記事の一覧だけ帯の高さと題の位置が違った） --}}
    @php($onSiteBand = $siteLayout !== 'layouts.public')
    <{{ $onSiteBand ? 'section' : 'div' }} class="{{ $onSiteBand ? 'page-title' : 'page-hero' }}">
        <div class="{{ $onSiteBand ? 'page-title-inner' : 'container' }}">
            <h1 @unless ($onSiteBand) class="page-hero__title" @endunless>{{ $item->title }}</h1>
            @if ($item->published_at)
                <p class="{{ $onSiteBand ? 'page-title-sub' : 'page-hero__sub' }}">
                    <time datetime="{{ $item->published_at->format('Y-m-d') }}">{{ $item->published_at->format('Y.m.d') }}</time>
                </p>
            @endif
        </div>
    </{{ $onSiteBand ? 'section' : 'div' }}>

    <article class="section">
        <div class="container">
            <div class="article">
                {{-- 公開画像だけを読み込む。下書きプレビューでも同じ見え方にする。 --}}
                <x-media-img :media="$featuredImages[$item->getKey()] ?? app(\App\Support\FeaturedImages::class)->forEntries([$item])[$item->getKey()] ?? null" loading="eager" img-class="article__featured" />
                @if ($body !== null)
                    {{-- richtext は core Purifier を通した HTML のみ raw 出力してよい（.agentcms/rules/coding.md） --}}
                    <div class="article__body">{!! $body !!}</div>
                @endif
            </div>
            {{--
                記事に付いている分類（6 周目 2026-09-03）。

                押せる先（一覧を持たない型では無い）は `TermArchiveLinks` が決めている。
                `news` / `works` の詳細と**同じ部品**で出す（R05・2026-09-09）。
            --}}
            <x-assigned-terms :taxonomies="$taxonomies ?? []" />
            @if ($hasArchive)
                <p class="section__more">
                    {{-- 店の枠では、枠のボタン（店の色の枠線）にする（UX 一周 第11周: 黒い枠の丸いボタンだけ浮いていた） --}}
                    <a class="{{ $onSiteBand ? 'btn btn-outline' : 'button button--outline' }}" href="/{{ $slug }}">{{ $label }}一覧へ戻る</a>
                </p>
            @endif
        </div>
    </article>

    {{-- 型が OFF なら 1 バイトも出ない（Phase 9.13 / P0-9） --}}
    @unless ($preview ?? false)
        <x-comments :content-type="$contentType->id" :entry="$item" />
    @endunless
@endsection
