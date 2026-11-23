@extends('layouts.public', ['title' => '実績', 'metaDescription' => $site['site_name'].'の制作実績一覧です。'])

@section('content')
    <div class="page-hero">
        <div class="container">
            <h1 class="page-hero__title">実績</h1>
            <p class="page-hero__sub">WORKS</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <ul class="card-grid">
                @forelse ($items as $work)
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
                            <span class="card__meta">{{ $work->category }}@if($work->completed_at) ・{{ $work->completed_at->format('Y年n月') }}@endif</span>
                        </a>
                    </li>
                @empty
                    <li>実績はまだありません。</li>
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
