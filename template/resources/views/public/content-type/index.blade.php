{{--
    公開した投稿タイプの一覧（Phase 9.7 / STAGE 20C・OG-D = (a)）。

    **最小の既定テンプレート**である。型ごとの view（`public/<型 ID>/index.blade.php`）が
    あればそちらが使われる。ここは「公開にしたのに何も出ない」を作らないための受け皿で、
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
    'title' => $siteLayout === 'layouts.public' ? $label : $label.' | '.($site['site_name'] ?? ''),
    'metaDescription' => $label.'の一覧です。',
])

@section('content')
{{-- 店の枠では、ページと同じ題の帯（page-title）にする（UX 一周 第11周: 記事の一覧だけ帯の高さと題の位置が違った） --}}
    @if ($siteLayout !== 'layouts.public')
    <section class="page-title">
        <div class="page-title-inner">
            <h1>{{ $label }}</h1>
        </div>
    </section>
    @else
    <div class="page-hero">
        <div class="container">
            <h1 class="page-hero__title">{{ $label }}</h1>
        </div>
    </div>
    @endif

    <section class="section">
        <div class="container">
            @if ($searchable ?? false)
                {{-- 一覧と検索は同じ公開条件。検索語はBladeの既定でエスケープする。 --}}
                <form class="search-form" method="get" action="{{ url()->current() }}" role="search">
                    <label for="content-search">{{ $label }}を探す</label>
                    <input id="content-search" type="search" name="q" value="{{ $search ?? '' }}" maxlength="200">
                    <button type="submit">探す</button>
                </form>
            @endif
            @if ($items->isEmpty())
                @if (($search ?? '') !== '')
                    <p>「{{ $search }}」に一致する記事は見つかりません。<a href="{{ url()->current() }}">すべて表示</a></p>
                @else
                    <p>まだ公開されているものはありません。</p>
                @endif
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
            <x-public-pagination :items="$items" />
        </div>
    </section>
@endsection
