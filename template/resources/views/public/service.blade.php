{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。 --}}
@extends('layouts.imported', ['sampleNotice' => true, 'title' => $site['site_name'].' | Service', 'metaDescription' => $site['site_name'].' のサービス内容と料金プランのサンプルページです。'])

@section('content')
@php($industry = app(\App\Support\Industry\IndustrySetup::class)->profile())
@if ($industry)
    {{-- 業種を選んだあとの見本（UX 一周 2026-09-28）。金額や内容は「〇〇」のまま置き、店の人が書く --}}
    <section class="page-title">
      <div class="page-title-inner">
        <h1>{{ $industry['serviceNoun'] }}</h1>
        <p class="page-title-sub">Service</p>
      </div>
    </section>
    <x-breadcrumb current="{{ $industry['serviceNoun'] }}" />
    <section class="section plans" id="plans">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>{{ $industry['serviceNoun'] }}</h2>
        <p>このページは見本です。このページのコンテンツはデモ用です。「〇〇」の部分を、お店の内容に書き換えてください。</p>
        <table class="company-table">
          @foreach ($industry['serviceRows'] as [$name, $price])<tr><th>{{ $name }}</th><td>{{ $price }}</td></tr>@endforeach
        </table>
      </div>
    </section>
@else

<section class="page-title">
      <div class="page-title-inner">
        <h1>Service</h1>
        <p class="page-title-sub">サービス</p>
      </div>
    </section>

    {{-- 祖先はメニューの木から（P1 ⑤）。手書きしない --}}
    <x-breadcrumb current="Service" />

    <section class="section services">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>できること</h2>
        <div class="service-items">
          <div class="service-item">
            <h3>ウェブサイト制作</h3>
            <p>コーポレートサイト・EC・LP まで、目的に合わせた設計と実装を行います。</p>
          </div>
          <div class="service-item">
            <h3>ブランディング</h3>
            <p>ロゴ・パッケージ・紙媒体まで、ブランドの一貫した体験をデザインします。</p>
          </div>
          <div class="service-item">
            <h3>育成サポート</h3>
            <p>公開後の更新・分析・改善を伴走します。サイトは公開してからが本番です。</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section plans" id="plans">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>料金プラン</h2>
        <div class="plan-list">
          <div class="plan">
            <h3 class="plan-name">Seed</h3>
            <p class="plan-price">30万円〜</p>
            <p class="plan-desc">小規模サイト・LP 向けのシンプルなプランです。</p>
          </div>
          <div class="plan plan-featured">
            <h3 class="plan-name">Sprout</h3>
            <p class="plan-price">80万円〜</p>
            <p class="plan-desc">CMS を組み込んだコーポレートサイトの標準プランです。</p>
          </div>
          <div class="plan">
            <h3 class="plan-name">Forest</h3>
            <p class="plan-price">150万円〜</p>
            <p class="plan-desc">ブランディングから運用まで一貫して伴走するプランです。</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section faq">
      <div class="section-inner">
        <h2 class="section-title"><span class="icon-leaf" aria-hidden="true"></span>よくある質問</h2>
        <details class="faq-item">
          <summary>制作期間はどのくらいですか？</summary>
          <p>規模によりますが、コーポレートサイトで 2〜3 ヶ月が目安です。</p>
        </details>
        <details class="faq-item">
          <summary>公開後の更新もお願いできますか？</summary>
          <p>はい。育成サポートプランで更新・分析・改善まで伴走します。</p>
        </details>
        <details class="faq-item">
          <summary>遠方でも依頼できますか？</summary>
          <p>オンラインでの打ち合わせを基本としているため、全国からご依頼いただけます。</p>
        </details>
      </div>
    </section>

    
@include('components.imported.cta')
@endif
@endsection
