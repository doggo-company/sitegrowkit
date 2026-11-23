{{-- agentcms:bundled-sample — **kit に同梱の見本サイト**です。
     **直して構いません。**この案件のものとして扱われ、初回の `template apply` でも conflict になりません
     （手を入れたあとに apply を打つと、**「これは kit の見本です」と言ったうえで conflict** になります。黙って消しません）。
     取り込みで置き換えたくないなら、消さずにそのまま置いておいて構いません。 --}}
@php
    /*
     * 🔴 **ナビはメニュー設定から描く**（WP 一周 2 周目 2026-09-03）。
     *
     * ここは About / Service / Contact を**直に書いていた**ので、
     * メニュー画面で並べ替えても足しても、**この見本ページだけ変わらなかった**。
     * 投稿一覧やページ（`layouts/public.blade.php`）には反映されるので、
     * **同じサイトの中でページによってナビが違う**状態になっていた。
     * 買った人はトップを見るので「メニューが効いていない」と受け取る。
     *
     * `<x-navigation>` を使わず `MenuTree` を直に読んでいるのは、
     * **この見本テーマの CSS が `.gnav ul li a` に当たっている**ためである。
     * component は「要素・class は呼び出し側が決める」と言っていて、平の <a> を出す。
     * 正本（項目と順序）は同じものを読み、**組み方だけこちらが持つ**。
     */
    $tree = app(\AgentCms\Core\Menus\MenuTree::class);
    $headerNav = $tree->forKey('header', request()->path());
    $footerNav = $tree->forKey('footer', request()->path());

    // 訪問者にナビが無い状態を公開しない（`<x-navigation :fallback="true">` と同じ考え方）
    $usesDefaultNav = $headerNav === [];

    if ($headerNav === []) {
        $headerNav = array_map(
            static fn (array $l): array => $l + ['target' => '_self', 'rel' => null, 'current' => false],
            \App\Support\DefaultNavigation::links(),
        );
    }
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if (!empty($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
    @endif

    {{-- SEO 標準セット（P1 ①）。**ページごとに書かない** —
         新しいページを足した人が忘れるし、忘れたことに誰も気づかない。
         `layouts/public.blade.php` は元から持っていたので、こちらへ揃える --}}
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta property="og:site_name" content="{{ $site['site_name'] ?? config('app.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $metaDescription ?? '' }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    @include('components.social-meta')
    {{-- ロゴとアイコンはサイト設定の公開画像を共通で読む。 --}}
    @php $brandImages = app(\App\Support\SiteBrandImages::class)->resolve($site ?? []); @endphp
    @include('components.site-icon', ['icon' => $brandImages['icon']])
    {{-- 同梱サンプルは外部 CDN のフォントを読まない（style.css の system stack を使う） --}}
    <link rel="stylesheet" href="{{ asset('imported/css/style.css') }}">
    @stack('head')
    @if (($starterA ?? false) || str_contains((string) ($document ?? ''), 'doc-cover'))
        <link rel="stylesheet" href="{{ asset('site-templates/base.css') }}">
    @endif
    @if ($starterA ?? false)
        @foreach (['fonts/shippori-mincho.css', 'fonts/cormorant.css', 'a.css'] as $sheet)
            <link rel="stylesheet" href="{{ asset('site-templates/'.$sheet) }}">
        @endforeach
    @endif
</head>
<body @if ($starterA ?? false) class="site-tpl site-tpl-a" @endif>
    {{-- 本文へ飛ぶ入口（キーボード・読み上げ）。ページもこの枠に載るようになったので、kit 既定の枠と揃える（UX 一周 2026-09-28） --}}
    <a class="skip-link" href="#main">本文へスキップ</a>
    <header class="header" id="site-header">
        <div class="header-inner">
          <a class="logo logo-text" href="{{ route('home') }}">@include('components.site-logo', ['logo' => $brandImages['logo'], 'siteName' => $site['site_name']])</a>
          <button class="nav-toggle" type="button" aria-controls="global-nav" aria-expanded="false">
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="visually-hidden">メニュー</span>
          </button>
          <nav class="gnav" id="global-nav" aria-label="グローバル"@if ($usesDefaultNav && (!empty($band) || ($siteFrame ?? false))) data-default-navigation="true"@endif>
            <ul>
{{-- 2 階層目（R03 / R05・2026-09-09）。
     `MenuTree` は `children` を返しているのに、ここは 1 階層しか描いていなかった —
     メニュー画面で入れ子にしても、見本サイトでは**子が 1 つも出ない**。
     子は `<ul class="gnav__sub">` に入れ、親 <a> の隣に開閉ボタンを置く:
       - PC のキーボード … Tabで開閉ボタンへ進み、Enter/Spaceで開いてから子へ進める
       - タッチ / hover の無い環境 … ボタン（`gnav-submenu.js`）で開く。**ホバーだけにしない**
       - スマホ（nav-toggle で開く幅）… 子は親の下に常に並ぶ（ボタンは出ない）
     target / rel / aria-current は `components/navigation.blade.php` と同じ付け方。
     非公開・ゴミ箱の除外は `MenuTree` に任せる（ここで 2 つ目の判定を作らない）。
     既定の導線（`DefaultNavigation`）は `children` を持たないので `?? []` で受ける。 --}}
@foreach ($headerNav as $item)
@php $children = $item['children'] ?? []; @endphp
              <li class="gnav__item{{ $children !== [] ? ' gnav__item--has-children' : '' }}">
                <a href="{{ $item['url'] }}"@if (($item['target'] ?? '_self') !== '_self') target="{{ $item['target'] }}"@endif @if ($item['rel'] ?? null) rel="{{ $item['rel'] }}"@endif @if ($item['current'] ?? false) aria-current="page"@endif>{{ $item['title'] }}</a>
@if ($children !== [])
                <button class="gnav__toggle" type="button" aria-expanded="false" aria-controls="gnav-sub-{{ $item['id'] }}"><span class="visually-hidden">{{ $item['title'] }}のサブメニュー</span></button>
                <ul class="gnav__sub" id="gnav-sub-{{ $item['id'] }}">
@foreach ($children as $child)
                  <li class="gnav__sub-item"><a href="{{ $child['url'] }}"@if (($child['target'] ?? '_self') !== '_self') target="{{ $child['target'] }}"@endif @if ($child['rel'] ?? null) rel="{{ $child['rel'] }}"@endif @if ($child['current'] ?? false) aria-current="page"@endif>{{ $child['title'] }}</a></li>
@endforeach
                </ul>
@endif
              </li>
@endforeach
            </ul>
          </nav>
        </div>
      </header>

    <main id="main" tabindex="-1">
        @yield('content')
    </main>

    <footer class="footer" id="site-footer">
        <div class="footer-inner">
          <div class="footer-about">
            <p class="footer-logo">{{ $site['site_name'] }}</p>
            <p class="footer-address">@if (!empty($site['address'])){{ $site['address'] }}@endif<br>
              @if (!empty($site['tel']))<a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $site['tel']) }}">{{ $site['tel'] }}</a>@endif
              {{-- 見本のページ（$sampleNotice）以外には、問い合わせの通知先メールを出さない。
                   サイト設定のその欄は通知先で、公開してよい宛先とは限らない（kit 既定の枠と同じ判断） --}}
              @if (!empty($sampleNotice))@if (!empty($site['tel']) && !empty($site['contact_email']))/@endif @if (!empty($site['contact_email']))<a href="mailto:{{ $site['contact_email'] }}">{{ $site['contact_email'] }}</a>@endif @endif</p>
          </div>
{{-- フッターは**空にしてよい**（意図して空にした本を勝手に生やさない）。header と違って fallback を使わない --}}
@if ($footerNav !== [])
          <ul class="footer-nav">
{{-- フッターの 2 階層目は**常に見せる**（開閉しない）。フッターに hover も focus の仕掛けも無い --}}
@foreach ($footerNav as $item)
@php $children = $item['children'] ?? []; @endphp
            <li class="footer-nav__item">
              <a href="{{ $item['url'] }}"@if (($item['target'] ?? '_self') !== '_self') target="{{ $item['target'] }}"@endif @if ($item['rel'] ?? null) rel="{{ $item['rel'] }}"@endif @if ($item['current'] ?? false) aria-current="page"@endif>{{ $item['title'] }}</a>
@if ($children !== [])
              <ul class="footer-nav__sub">
@foreach ($children as $child)
                <li class="footer-nav__sub-item"><a href="{{ $child['url'] }}"@if (($child['target'] ?? '_self') !== '_self') target="{{ $child['target'] }}"@endif @if ($child['rel'] ?? null) rel="{{ $child['rel'] }}"@endif @if ($child['current'] ?? false) aria-current="page"@endif>{{ $child['title'] }}</a></li>
@endforeach
              </ul>
@endif
            </li>
@endforeach
          </ul>
@endif
          {{-- サイト設定の X / Instagram と、サイト内検索への入口（kit 既定の枠と同じもの）。
               店の枠では出ていなかったので、Instagram を入れてもどこにも出なかった（UX 一周 2026-09-28） --}}
          @php($socialLinks = \App\Support\SocialLinks::from($site ?? []))
          <p class="footer-links">
            @foreach ($socialLinks as $link)<a href="{{ $link['href'] }}" rel="noopener" target="_blank">{{ $link['label'] }}</a> / @endforeach
            <a href="{{ route('site.search') }}">サイト内検索</a>
          </p>
          {{-- 見本の断り書きは、同梱の見本のページ（$sampleNotice を渡すもの）にだけ出す。
               店の人が作ったページやフォームに出すと、そのページまで「見本のまま」と
               公開前チェックに言われる（UX 一周 2026-09-28） --}}
          @if (!empty($sampleNotice))
          <p class="footer-note">本サイトは SiteGrowKit に同梱のサンプルサイトです（コンテンツはデモ用）。</p>
          @endif
          <p class="copyright">&copy; {{ now()->year }} {{ $site['site_name'] }}</p>
        </div>
      </footer>

    <script src="{{ asset('imported/js/main.js') }}"></script>
    {{-- 2 階層目の開閉（R03 / R05）。同一オリジンの外部ファイルなので CSP を緩めない --}}
    <script src="{{ asset('imported/js/gnav-submenu.js') }}"></script>
    <script src="{{ asset('imported/js/navigation.js') }}"></script>
    @if ($starterA ?? false)<script src="{{ asset('site-templates/a.js') }}"></script>@endif
    @stack('scripts')
</body>
</html>
