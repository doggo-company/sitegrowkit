{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。 --}}
@extends('layouts.imported', ['sampleNotice' => true, 'title' => $site['site_name'].' | Works', 'metaDescription' => $site['site_name'].' の制作実績一覧です。'])

@section('content')
<section class="page-title">
      <div class="page-title-inner">
        <h1>Works</h1>
        <p class="page-title-sub">制作実績</p>
      </div>
    </section>

    {{-- 祖先はメニューの木から（P1 ⑤）。手書きしない --}}
    <x-breadcrumb current="Works" />

    <section class="section works-list">
      <div class="section-inner">
        {{-- **探せない型では出さない**（B-14）。宣言が無い型では絞り込みが起きず、
             押せるのに結果が変わらない — 窓が在ること自体が嘘になる --}}
        @if ($searchable ?? true)
            {{-- 検索欄（P1 ④）。**GET なので、結果の URL がそのまま共有できる** --}}
            <form class="search-form" method="get" action="{{ route('works.index') }}" role="search">
                <label class="sr-only" for="works-search">実績を探す</label>
                <input id="works-search" type="search" name="q" value="{{ $search ?? '' }}" placeholder="実績を探す">
                <button type="submit">探す</button>
            </form>
        @endif

        <div class="card-list">
@forelse ($items as $work)
  <article class="work-card">
              @if ($work->mainImage)
                {{-- 直 <img> にすると AVIF / WebP を素通りする。派生物は <x-media-img> が出す --}}
                <x-media-img :media="$work->mainImage" img-class="work-thumb" />
              @endif
              <h3><a href="{{ $work->publicPath() }}">{{ $work->title }}</a></h3>
              <p class="work-card-meta">{{ $work->category }} / {{ $work->completed_at?->format('Y') }}</p>
            </article>
@empty
            {{-- **0 件は 0 件と言う。** 探した結果なのか、そもそも無いのかで言うことが違う --}}
            @if (($search ?? '') !== '')
              <p style="padding:2rem 0">
                「{{ $search }}」に一致する実績は見つかりません。
                <a href="{{ route('works.index') }}">すべての実績を見る</a>
              </p>
            @else
              <p style="padding:2rem 0">実績はまだありません。</p>
            @endif
@endforelse
</div>
        <x-public-pagination :items="$items" />
      </div>
    </section>

    
@include('components.imported.cta')
@endsection
