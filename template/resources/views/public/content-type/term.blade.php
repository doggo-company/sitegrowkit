{{--
    分類ごとの一覧（WP 一周 6 周目 2026-09-03）。

    型ごとの view（`public/<型 ID>/term.blade.php`）があればそちらが使われる。
    ここは「分類を作って付けたのに、訪問者からは辿れない」を作らないための受け皿で、
    見た目を作り込む場所ではない。
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
    'title' => $siteLayout === 'layouts.public' ? $archive['termName'].' | '.$label : $archive['termName'].' | '.$label.' | '.($site['site_name'] ?? ''),
    'metaDescription' => $label.'のうち、'.$archive['taxonomyLabel'].'が「'.$archive['termName'].'」のものです。',
])

@section('content')
{{-- 店の枠では、ページと同じ題の帯（page-title）にする（UX 一周 第11周: 記事の一覧だけ帯の高さと題の位置が違った） --}}
    @if ($siteLayout !== 'layouts.public')
    <section class="page-title">
        <div class="page-title-inner">
            <h1>{{ $archive['termName'] }}</h1>
            <p class="page-title-sub">{{ $archive['taxonomyLabel'] }}</p>
        </div>
    </section>
    @else
    <div class="page-hero">
        <div class="container">
            <h1 class="page-hero__title">{{ $archive['termName'] }}</h1>
            <p class="page-hero__sub">{{ $archive['taxonomyLabel'] }}</p>
        </div>
    </div>
    @endif

    <section class="section">
        <div class="container">
            @if ($items->isEmpty())
                <p>この分類の記事はまだありません。</p>
            @else
                <ul class="news-items">
                    @foreach ($items as $item)
                        <li class="news-item">
                            <x-media-img :media="$featuredImages[$item->getKey()] ?? null" img-class="article__thumbnail" />
                            @if ($item->published_at)
                                <time datetime="{{ $item->published_at->format('Y-m-d') }}">{{ $item->published_at->format('Y.m.d') }}</time>
                            @endif
                            @if ($hasDetail && $item->slug)
                                <a href="{{ $item->publicPath() }}">{{ $item->title }}</a>
                            @else
                                {{ $item->title }}
                            @endif
                            @if ($item->excerpt)
                                <p>{{ $item->excerpt }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="section__more">
                <a class="button button--outline" href="/{{ $slug }}">{{ $label }}一覧へ戻る</a>
            </p>
            <x-public-pagination :items="$items" />
        </div>
    </section>
@endsection
