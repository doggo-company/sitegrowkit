{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。 --}}
@extends('layouts.imported', ['sampleNotice' => true, 'title' => $site['site_name'].' | About', 'metaDescription' => $site['site_name'].' の会社概要ページです。'])

@section('content')
@php($industry = app(\App\Support\Industry\IndustrySetup::class)->profile())
@if ($industry)
    {{-- 業種を選んだあとの見本（UX 一周 2026-09-28）。店のことを言い切る文は置かず、
         何を書く場所かだけを案内する（ファクトチェックのルール） --}}
    <section class="page-title">
      <div class="page-title-inner">
        <h1>私たちについて</h1>
        <p class="page-title-sub">About</p>
      </div>
    </section>
    <x-breadcrumb current="私たちについて" />
    <section class="section about-body">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>このページに書くこと</h2>
        <p>このページは「{{ $industry['label'] }}」のサイトの見本です。このページのコンテンツはデモ用です。次のようなことを、お店の言葉で書いてください。</p>
        <ul>
          @foreach ($industry['aboutHints'] as $hint)<li>{{ $hint }}</li>@endforeach
        </ul>
      </div>
    </section>
    <section class="section company">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>基本情報</h2>
        <table class="company-table">
          <tr><th>名前</th><td>{{ $site['site_name'] }}</td></tr>
          @if (!empty($site['address']))<tr><th>所在地</th><td>{{ $site['address'] }}</td></tr>@endif
          @if (!empty($site['tel']))<tr><th>電話番号</th><td>{{ $site['tel'] }}</td></tr>@endif
        </table>
      </div>
    </section>
@else

<section class="page-title">
      <div class="page-title-inner">
        <h1>About</h1>
        <p class="page-title-sub">私たちについて</p>
      </div>
    </section>

    {{-- 祖先はメニューの木から（P1 ⑤）。手書きしない --}}
    <x-breadcrumb current="About" />

    <section class="section about-body">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>スタジオについて</h2>
        <p>{{ $site['site_name'] }} は、小さなデザインスタジオという設定のサンプルサイトです。<br>
          ウェブサイトを「作って終わり」にせず、長く手入れしながら育てていく制作の進め方を大切にしています。</p>
        <p>このページのコンテンツはデモ用です。実際の案件では、管理画面のサイト設定とあわせて自由に書き換えて使えます。制作のご相談導線の例は <a href="{{ route('contact.show') }}">お問い合わせ</a> をご覧ください。</p>
      </div>
    </section>

    <section class="section company">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>会社概要</h2>
        <table class="company-table">
          <tr><th>社名</th><td>{{ $site['site_name'] }}（サンプル）</td></tr>
          @if (!empty($site['address']))<tr><th>所在地</th><td>{{ $site['address'] }}</td></tr>@endif
          <tr><th>事業内容</th><td>ウェブサイト制作 / ブランディング / グラフィックデザイン</td></tr>
        </table>
      </div>
    </section>
@endif
@endsection
