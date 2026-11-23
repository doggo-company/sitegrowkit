@extends('layouts.public', [
    'title' => $item->seo_title ?? $item->title,
    'metaDescription' => $item->seo_description ?: ($item->excerpt ?: mb_substr(strip_tags($item->body), 0, 120)),
    'ogType' => 'article',
])

@section('content')
    <div class="page-hero">
        <div class="container">
            {{-- 祖先はメニューの木から、末端（記事名）はここから渡す（P1 ⑤） --}}
            <x-breadcrumb :current="$item->title" />

            <h1 class="page-hero__title">{{ $item->title }}</h1>
            <p class="page-hero__sub">
                {{-- 下書きの preview（Phase 9.15 / STAGE 28A）はまだ公開日を持たない。
                     **偽の日付を置かない** — 置くと編集する人に「その日に出る」と信じさせる --}}
                @if ($item->published_at)
                    <time datetime="{{ $item->published_at->format('Y-m-d') }}">{{ $item->published_at->format('Y.m.d') }}</time>
                @else
                    <span>公開日未定</span>
                @endif
            </p>
        </div>
    </div>

    <article class="section">
        <div class="container">
            <div class="article">
                <x-media-img :media="$featuredImages[$item->getKey()] ?? app(\App\Support\FeaturedImages::class)->forEntries([$item])[$item->getKey()] ?? null" loading="eager" img-class="article__featured" />
                {{-- richtext は core Purifier を通した HTML のみ raw 出力してよい（.agentcms/rules/coding.md） --}}
                <div class="article__body">{!! $body !!}</div>
            </div>
            {{-- 記事に付いている分類（R05）。押せる先は `TermArchiveLinks` が決め、付いていなければ何も出ない --}}
            <x-assigned-terms :taxonomies="$taxonomies ?? []" />
            <p class="section__more"><a class="button button--outline" href="{{ route('news.index') }}">ニュース一覧へ戻る</a></p>
        </div>
    </article>
@endsection
