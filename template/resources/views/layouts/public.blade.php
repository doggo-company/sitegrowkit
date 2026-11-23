{{-- 公開サイト共通レイアウト（Blade SSR + プレーン CSS。JS はページが必要とする場合のみ） --}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? "{$title} | {$site['site_name']}" : "{$site['site_name']} | {$site['tagline']}" }}</title>
    <meta name="description" content="{{ $metaDescription ?? $site['seo_description'] ?? '' }}">
    {{-- 正規 URL は**渡されたらそれ**（R-5）。トップに指定したページは
         `/` と `/page-slug` の両方で開けるので、両方が `/` を名乗る。
         渡されなければこれまでどおり現在の URL --}}
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta property="og:site_name" content="{{ $site['site_name'] }}">
    <meta property="og:title" content="{{ $title ?? $site['site_name'] }}">
    <meta property="og:description" content="{{ $metaDescription ?? $site['seo_description'] ?? '' }}">
    <meta property="og:type" content="{{ isset($ogType) ? $ogType : 'website' }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    @include('components.social-meta')
    {{-- ロゴとアイコンはサイト設定の公開画像を共通で読む。 --}}
    @php $brandImages = app(\App\Support\SiteBrandImages::class)->resolve($site ?? []); @endphp
    @include('components.site-icon', ['icon' => $brandImages['icon']])
    @vite('resources/css/public.css')
    @stack('head')
    @if (str_contains((string) ($document ?? ''), 'doc-cover'))
        <link rel="stylesheet" href="{{ asset('site-templates/base.css') }}">
    @endif
</head>
<body>
    <a class="skip-link" href="#main">本文へスキップ</a>

    {{-- component: public.site-header (registry: pub-header) --}}
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="site-header__logo" href="{{ route('home') }}">@include('components.site-logo', ['logo' => $brandImages['logo'], 'siteName' => $site['site_name']])</a>
            {{-- JS が動くときだけ折りたたむ。無効でも全リンクを辿れる。 --}}
            <button class="site-header__toggle" type="button" aria-controls="site-navigation" aria-expanded="false">メニュー</button>
            {{-- 導線は CMS のデータから（Phase 9.9 / P0-4）。要素と class は据え置き --}}
            <x-navigation menu="header" id="site-navigation" class="site-nav" aria-label="メイン" :fallback="true" />
        </div>
    </header>

    <main id="main">
        @yield('content')
    </main>

    {{-- component: public.site-footer (registry: pub-footer) --}}
    <footer class="site-footer">
        <div class="container site-footer__inner">
            <div>
                <p style="margin:0 0 0.25rem;font-weight:600;color:var(--color-text)">{{ $site['site_name'] }}</p>
                @if(!empty($site['address']))<p style="margin:0">{{ $site['address'] }}</p>@endif
                {{-- サイト設定の電話番号も出す（UX 一周 2026-09-28: 見本の枠をやめると、入れた電話番号が
                     公開面のどこにも出なくなっていた）。メールは出さない — サイト設定の欄は
                     「問い合わせの通知先」で、公開してよい宛先とは限らない（問い合わせはフォームで受ける） --}}
                @if(!empty($site['tel']))
                    <p style="margin:0.25rem 0 0"><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $site['tel']) }}">{{ $site['tel'] }}</a></p>
                @endif
            </div>
            @php($socialLinks = \App\Support\SocialLinks::from($site))
            @if($socialLinks !== [])
                {{-- サイト設定の X / Instagram（UX 一周 2026-09-28: 入れてもどこにも出ていなかった） --}}
                <p class="site-footer__social" style="margin:0">
                    @foreach($socialLinks as $link)<a href="{{ $link['href'] }}" rel="noopener" target="_blank">{{ $link['label'] }}</a>@if(! $loop->last) / @endif @endforeach
                </p>
            @endif
            <a href="{{ route('site.search') }}">サイト内検索</a>
            <x-navigation menu="footer" class="site-footer__nav" aria-label="フッター" />
        </div>
        <div class="container" style="margin-top:2rem">
            <small>&copy; {{ date('Y') }} {{ $site['site_name'] }}</small>
        </div>
    </footer>
    <script src="{{ asset('js/public-navigation.js') }}" defer></script>
</body>
</html>
