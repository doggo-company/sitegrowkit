{{-- サイト内検索。店の枠（見本・取り込んだテーマ）があればそれに載せる（UX 一周 2026-09-28） --}}
@php($frame = app(\App\Support\SiteFrame::class))
@php($onSite = $frame->onSite())
@extends($frame->layout(), ['title' => $frame->title('サイト内検索', (string) ($site['site_name'] ?? ''))])

@if ($onSite)
    @push('head')
        @include('components.site-frame-head')
        <meta name="robots" content="noindex, follow">
    @endpush
@endif

@section('content')
@if ($onSite)
<section class="page-title">
    <div class="page-title-inner">
        <h1>サイト内検索</h1>
    </div>
</section>
@endif
{{-- `container` は kit 既定の枠、`section-inner` は店の枠の CSS が持つ。相手側には定義が無いので両方書く --}}
<section class="section">
    <div class="container section-inner">
        @unless ($onSite)<h1>サイト内検索</h1>@endunless
        {{-- 検索語は自動エスケープし、検索条件もページ送りへ引き継ぐ。 --}}
        <form action="{{ route('site.search') }}" method="get" role="search" class="site-search">
            <label for="site-search-query">キーワード</label>
            <div class="site-search__controls">
                <input id="site-search-query" name="q" type="search" value="{{ $term }}" maxlength="200" placeholder="調べたい言葉を入力" required>
                <button type="submit">検索</button>
            </div>
            <p>公開記事の検索対象項目と、ページのタイトル・検索説明を探します。</p>
        </form>
        @if($term !== '')
            <p role="status">「{{ $term }}」の検索結果：{{ $results->total() }}件</p>
            @forelse($results as $result)
                <article class="site-search__result">
                    <p>{{ $result['label'] }}</p>
                    <h2><a href="{{ $result['url'] }}">{{ $result['title'] }}</a></h2>
                </article>
            @empty
                <p>該当するページがありません。別の言葉で検索してください。</p>
            @endforelse
            <x-public-pagination :items="$results" />
        @endif
    </div>
</section>
@endsection
