@extends('layouts.public', ['title' => 'ニュース', 'metaDescription' => $site['site_name'].'からのお知らせ一覧です。'])

@section('content')
    <div class="page-hero">
        <div class="container">
            <h1 class="page-hero__title">ニュース</h1>
            <p class="page-hero__sub">NEWS</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            {{-- **探せない型では出さない**（B-14）。宣言が無い型では絞り込みが起きず、
                 押せるのに結果が変わらない — 窓が在ること自体が嘘になる --}}
            @if ($searchable ?? true)
                {{-- 検索欄（P1 ④）。**GET なので、結果の URL がそのまま共有できる** --}}
                <form class="search-form" method="get" action="{{ route('news.index') }}" role="search">
                    <label class="sr-only" for="news-search">記事を探す</label>
                    <input
                        id="news-search"
                        type="search"
                        name="q"
                        value="{{ $search ?? '' }}"
                        placeholder="記事を探す"
                    >
                    <button type="submit">探す</button>
                </form>
            @endif

            <ul class="news-list">
                @forelse ($items as $item)
                    <li class="news-list__item">
                        <a href="{{ $item->publicPath() }}">
                            <x-media-img :media="$featuredImages[$item->getKey()] ?? null" img-class="article__thumbnail" />
                            <time class="news-list__date" datetime="{{ $item->published_at->format('Y-m-d') }}">{{ $item->published_at->format('Y.m.d') }}</time>
                            <span class="news-list__title">{{ $item->title }}</span>
                        </a>
                        @if ($item->excerpt)
                            <p>{{ $item->excerpt }}</p>
                        @endif
                    </li>
                @empty
                    {{-- **0 件は 0 件と言う。** 黙って空の一覧を出さない。
                         探した結果なのか、そもそも無いのかで**言うことが違う** --}}
                    @if (($search ?? '') !== '')
                        <li style="padding:2rem 0">
                            「{{ $search }}」に一致する記事は見つかりません。
                            <a href="{{ route('news.index') }}">すべての記事を見る</a>
                        </li>
                    @else
                        <li style="padding:2rem 0">お知らせはまだありません。</li>
                    @endif
                @endforelse
            </ul>

            @if ($items->hasPages())
                <nav aria-label="ページ送り">
                    <ul class="pagination">
                        @if ($items->onFirstPage())
                            <li><span aria-disabled="true">前へ</span></li>
                        @else
                            <li><a href="{{ $items->previousPageUrl() }}" rel="prev">前へ</a></li>
                        @endif
                        @foreach ($items->getUrlRange(1, $items->lastPage()) as $page => $url)
                            <li>
                                @if ($page === $items->currentPage())
                                    <span aria-current="page">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}">{{ $page }}</a>
                                @endif
                            </li>
                        @endforeach
                        @if ($items->hasMorePages())
                            <li><a href="{{ $items->nextPageUrl() }}" rel="next">次へ</a></li>
                        @else
                            <li><span aria-disabled="true">次へ</span></li>
                        @endif
                    </ul>
                </nav>
            @endif
        </div>
    </section>
@endsection
