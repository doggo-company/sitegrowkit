@extends('layouts.public', [
    'title' => $item->seo_title ?? $item->title,
    'metaDescription' => $item->seo_description ?? ($item->excerpt ?? mb_substr(strip_tags($item->body), 0, 120)),
    'ogType' => 'article',
])

@section('content')
    <div class="page-hero">
        <div class="container">
            {{-- 祖先はメニューの木から、末端（記事名）はここから渡す（P1 ⑤） --}}
            <x-breadcrumb :current="$item->title" />

            <h1 class="page-hero__title">{{ $item->title }}</h1>
            <p class="page-hero__sub">{{ $item->category }}@if($item->completed_at) ・{{ $item->completed_at->format('Y年n月') }}@endif</p>
        </div>
    </div>

    <article class="section">
        <div class="container">
            <div class="article">
                @if ($item->mainImage)
                    {{-- ファーストビュー画像のため lazy にしない（Phase 2 仕様 PUBLIC OUTPUT） --}}
                    <div class="article__hero">
                        <x-media-img :media="$item->mainImage" loading="eager" />
                    </div>
                @endif
                @if ($item->excerpt)
                    <p class="article__meta">{{ $item->excerpt }}</p>
                @endif
                <div class="article__body">{!! $body !!}</div>
            </div>
            {{-- 記事に付いている分類（R05）。押せる先は `TermArchiveLinks` が決め、付いていなければ何も出ない --}}
            <x-assigned-terms :taxonomies="$taxonomies ?? []" />
            <p class="section__more"><a class="button button--outline" href="{{ route('works.index') }}">実績一覧へ戻る</a></p>
        </div>
    </article>
@endsection
