{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。 --}}
{{-- 説明文は **サイト設定が正本**。未設定のときも空にしない —
     空の og:description は「無い」のと同じで、共有されたときに何も伝わらない --}}
@extends('layouts.imported', ['sampleNotice' => true, 
    'title' => $site['site_name'].' | ホーム',
    // **`??` では足りない。** 空文字は「設定されている」ので素通りする
    'metaDescription' => ($site['seo_description'] ?? '')
        ?: ($site['tagline'] ?? '')
        ?: '植物のように育つウェブサイトをつくる、小さなデザインスタジオです。',
])

@section('content')
{{-- ボタンの文言は業種に合わせる（UX 一周 2026-09-28: 工務店でも「料金プランを見る」と出ていた。
     2 つ目のボタンが開く /service の見出しは業種の呼び名なので、それと同じ言葉にする） --}}
@php($industry = app(\App\Support\Industry\IndustrySetup::class)->profile())
<section class="hero">
      <div class="hero-inner">
        {{-- キャッチコピーと説明が空でも、帯を空にしない（UX 一周 2026-09-28: 生成直後は見出しも説明も無く、
             緑の帯にボタンだけが浮いて「壊れている」ように見えた）。空なら業種の見出し（〇〇の空欄）と、
             どこに書けば出るかの案内を出す。店のことは言い切らない --}}
        @php($heroTitle = trim((string) ($site['tagline'] ?? '')) ?: ($industry['heroTitle'] ?? (string) $site['site_name']))
        @php($heroLead = trim((string) ($site['hero_text'] ?? '')) ?: trim((string) ($site['seo_description'] ?? '')))
        <h1>{!! nl2br(e($heroTitle)) !!}</h1>
        @if ($heroLead !== '')
          <p>{{ $heroLead }}</p>
        @elseif (trim((string) ($site['tagline'] ?? '')) !== '')
          {{-- キャッチコピーはもう出ている。残っている空欄（説明）だけを案内する（UX 一周 第 15 周） --}}
          <p class="hero-guide">管理画面の「サイト設定」で「サイト説明」を入れると、ここに出ます。</p>
        @else
          <p class="hero-guide">ここはトップの見出しです。管理画面の「サイト設定」でキャッチコピーと説明を入れると、ここに出ます。</p>
        @endif
        <div class="hero-actions">
          <a class="btn" href="{{ route('contact.show') }}">{{ $industry['ctaLabel'] ?? '相談する' }}</a>
          <a class="btn btn-outline" href="{{ route('service') }}#plans">{{ $industry ? $industry['serviceNoun'].'を見る' : '料金プランを見る' }}</a>
        </div>
        {{-- 写真を入れる場所。見本のトップは差し替えられないので、どこで入れるかを案内する --}}
        <div class="hero-photo-slot">
          <p class="hero-photo-slot__title">ここに、お店の写真が入ります</p>
          <p class="hero-photo-slot__guide">管理画面の「ページ」でトップ用のページを作ると、画像のブロックで写真を置けます。</p>
        </div>
      </div>
    </section>

    {{-- `works` / `news` は kit の同梱サンプル（Phase 9.7 / STAGE 20B）。
         新しく生成するサイトには module ごと配られないので、route の有無で判定する。
         汎用の投稿タイプ向けの既定テンプレートは Stage 20C（Owner Decision OG-D）。 --}}
@if (Route::has('works.index'))
    <section class="section works-preview">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>最近の実績</h2>
        <div class="card-list">
@foreach ($works as $work)
  <article class="work-card">
              @if ($work->mainImage)
                {{-- 直 <img> にすると AVIF / WebP を素通りする。派生物は <x-media-img> が出す --}}
                <x-media-img :media="$work->mainImage" img-class="work-thumb" />
              @endif
              <h3>{{ $work->title }}</h3>
              <p class="work-card-meta">{{ $work->category }} / {{ $work->completed_at?->format('Y') }}</p>
            </article>
@endforeach
</div>
        <p class="section-more"><a href="{{ route('works.index') }}">実績一覧へ</a></p>
      </div>
    </section>
@endif

@if (Route::has('news.show'))
    <section class="section news">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>お知らせ</h2>
        <ul class="news-items">
@foreach ($news as $item)
  <li class="news-item"><time datetime="{{ $item->published_at?->format('Y-m-d') }}">{{ $item->published_at?->format('Y.m.d') }}</time><a href="{{ $item->publicPath() }}">{{ $item->title }}</a></li>
@endforeach
</ul>
      </div>
    </section>
@endif
@endsection
