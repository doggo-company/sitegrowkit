{{-- 分類ごとの一覧（R05・2026-09-09）。`/news/{分類のキー}/{項目の綴り}`。
     一覧（index）と同じ見た目で、**この項目が付いた公開記事だけ**を並べる。
     見た目を作り込む場所ではない — 「分類を作って付けたのに、訪問者からは辿れない」を作らないための受け皿。 --}}
@extends('layouts.public', [
    'title' => $archive['termName'].' | ニュース',
    'metaDescription' => $site['site_name'].'のニュースのうち、'.$archive['taxonomyLabel'].'が「'.$archive['termName'].'」のものです。',
])

@section('content')
    <div class="page-hero">
        <div class="container">
            {{-- 祖先はメニューの木から（ニュース一覧まで）、末端（項目名）はここから渡す --}}
            <x-breadcrumb :current="$archive['termName']" />

            <h1 class="page-hero__title">{{ $archive['termName'] }}</h1>
            <p class="page-hero__sub">{{ $archive['taxonomyLabel'] }}</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
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
                    {{-- **0 件は 0 件と言う。** 黙って空の一覧を出さない --}}
                    <li style="padding:2rem 0">この分類の記事はまだありません。</li>
                @endforelse
            </ul>

            <p class="section__more"><a class="button button--outline" href="{{ route('news.index') }}">ニュース一覧へ戻る</a></p>
            <x-public-pagination :items="$items" />
        </div>
    </section>
@endsection
