@extends('layouts.public', ['title' => 'サービス', 'metaDescription' => 'Web サイト制作・ブランディング・グラフィックデザイン・運用サポートのサービス内容をご紹介します。'])

@section('content')
    <div class="page-hero">
        <div class="container">
            <h1 class="page-hero__title">サービス</h1>
            <p class="page-hero__sub">SERVICE</p>
        </div>
    </div>

    <section class="section" aria-labelledby="service-list">
        <div class="container">
            <h2 class="section__title" id="service-list">提供サービス</h2>
            {{-- component: public.service-list (registry: pub-service-list) --}}
            <ul class="service-list">
                <li class="service-list__item">
                    <span class="service-list__num">01</span>
                    <h3>Web サイト制作</h3>
                    <p>コーポレートサイト・サービスサイト・EC サイトの企画から設計・実装・公開まで。更新しやすい CMS を組み込み、公開後の運用まで見据えて構築します。</p>
                </li>
                <li class="service-list__item">
                    <span class="service-list__num">02</span>
                    <h3>ブランディング</h3>
                    <p>ロゴ・カラー・タイポグラフィなどのブランド基盤づくり。名刺やパッケージまで、体験全体で一貫した世界観を設計します。</p>
                </li>
                <li class="service-list__item">
                    <span class="service-list__num">03</span>
                    <h3>グラフィックデザイン</h3>
                    <p>ポスター・パンフレット・キービジュアルなどの制作。Web と紙を横断した展開にも対応します。</p>
                </li>
                <li class="service-list__item">
                    <span class="service-list__num">04</span>
                    <h3>運用サポート</h3>
                    <p>公開後の更新代行・改善提案・アクセス解析レポートなど、サイトを育てるための継続的なサポートを提供します。</p>
                </li>
            </ul>
        </div>
    </section>

    <section class="section section--alt" aria-labelledby="service-flow">
        <div class="container">
            <h2 class="section__title" id="service-flow">進め方</h2>
            <div class="article">
                <p>ヒアリング → お見積もり → 設計 → デザイン → 実装 → 公開 → 運用、という流れで進めます。標準的なコーポレートサイトで、ヒアリングから公開まで約 2〜3 ヶ月です。</p>
            </div>
            <p class="section__more"><a class="button" href="{{ route('contact.show') }}">相談してみる</a></p>
        </div>
    </section>
@endsection
