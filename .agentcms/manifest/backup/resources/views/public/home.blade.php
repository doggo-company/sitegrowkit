@extends('layouts.public')

@section('content')
    <div class="hero">
        <div class="container">
            <h1 class="hero__title">{!! nl2br(e($site['tagline'])) !!}</h1>
            <p class="hero__lead">{{ $site['seo_description'] }}</p>
        </div>
    </div>

    <section class="section section--alt" aria-labelledby="home-works">
        <div class="container">
            <h2 class="section__title" id="home-works">実績</h2>
            {{-- component: public.card-work (registry: pub-card-work) --}}
            <ul class="card-grid">
                @foreach ($works as $work)
                    <li>
                        <a class="card" href="{{ route('works.show', $work->slug) }}">
                            <span class="card__thumb">
                                @if ($work->mainImage)
                                    <x-media-img :media="$work->mainImage" loading="lazy" />
                                @else
                                    <span aria-hidden="true">{{ $work->category }}</span>
                                @endif
                            </span>
                            <span class="card__title">{{ $work->title }}</span>
                            <span class="card__meta">{{ $work->category }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="section__more"><a class="button button--outline" href="{{ route('works.index') }}">実績一覧へ</a></p>
        </div>
    </section>

    <section class="section" aria-labelledby="home-news">
        <div class="container">
            <h2 class="section__title" id="home-news">ニュース</h2>
            {{-- component: public.news-list (registry: pub-news-list) --}}
            <ul class="news-list">
                @foreach ($news as $item)
                    <li class="news-list__item">
                        <a href="{{ route('news.show', $item->slug) }}">
                            <time class="news-list__date" datetime="{{ $item->published_at->format('Y-m-d') }}">{{ $item->published_at->format('Y.m.d') }}</time>
                            <span class="news-list__title">{{ $item->title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="section__more"><a class="button button--outline" href="{{ route('news.index') }}">ニュース一覧へ</a></p>
        </div>
    </section>

    <section class="section section--alt" aria-labelledby="home-contact">
        <div class="container">
            <h2 class="section__title" id="home-contact">お問い合わせ</h2>
            <p>Web サイト制作・ブランディングのご相談を承っています。まずはお気軽にご連絡ください。</p>
            <p class="section__more"><a class="button" href="{{ route('contact.show') }}">お問い合わせフォームへ</a></p>
        </div>
    </section>
@endsection
