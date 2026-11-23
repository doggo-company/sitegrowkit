{{-- 分類ごとの一覧（R05・2026-09-09）。`/works/{分類のキー}/{項目の綴り}`。
     一覧（index）と同じ見本サイトの意匠で、**この項目が付いた公開実績だけ**を並べる。
     一覧のカードは詳細へ繋がっていないが、ここは分類から辿ってきた人が
     記事へ進めるように、見出しを詳細へのリンクにする。 --}}
@extends('layouts.imported', ['sampleNotice' => true, 
    'title' => $site['site_name'].' | Works | '.$archive['termName'],
    'metaDescription' => $site['site_name'].' の制作実績のうち、'.$archive['taxonomyLabel'].'が「'.$archive['termName'].'」のものです。',
])

@section('content')
<section class="page-title">
      <div class="page-title-inner">
        <h1>{{ $archive['termName'] }}</h1>
        <p class="page-title-sub">{{ $archive['taxonomyLabel'] }}</p>
      </div>
    </section>

    {{-- 祖先はメニューの木から（実績一覧まで）、末端（項目名）はここから渡す --}}
    <x-breadcrumb :current="$archive['termName']" />

    <section class="section works-list">
      <div class="section-inner">
        <div class="card-list">
@forelse ($items as $work)
  <article class="work-card">
              @if ($work->mainImage)
                {{-- 直 <img> にすると AVIF / WebP を素通りする。派生物は <x-media-img> が出す --}}
                <x-media-img :media="$work->mainImage" img-class="work-thumb" />
              @endif
              <h3>@if ($work->publicPath())<a href="{{ $work->publicPath() }}">{{ $work->title }}</a>@else{{ $work->title }}@endif</h3>
              <p class="work-card-meta">{{ $work->category }} / {{ $work->completed_at?->format('Y') }}</p>
            </article>
@empty
            {{-- **0 件は 0 件と言う。** 黙って空の一覧を出さない --}}
            <p style="padding:2rem 0">この分類の実績はまだありません。</p>
@endforelse
</div>
        <p class="section-more"><a class="btn btn-outline" href="{{ route('works.index') }}">実績一覧へ戻る</a></p>
        <x-public-pagination :items="$items" />
      </div>
    </section>

@include('components.imported.cta')
@endsection
