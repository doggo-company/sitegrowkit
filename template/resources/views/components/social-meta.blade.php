{{-- どの公開 layout も同じ順序で SNS 画像を選ぶ。未設定なら画像を捏造しない。 --}}
@php
    $socialImage = app(\App\Support\SocialImage::class)->resolve($site ?? [], ($item ?? null) instanceof \Illuminate\Database\Eloquent\Model ? $item : null);
@endphp
<meta name="twitter:card" content="{{ $socialImage ? 'summary_large_image' : 'summary' }}">
@if ($socialImage)
    <meta property="og:image" content="{{ url($socialImage->url()) }}">
    @if ($socialImage->alt)<meta property="og:image:alt" content="{{ $socialImage->alt }}">@endif
    @if ($socialImage->width)<meta property="og:image:width" content="{{ $socialImage->width }}">@endif
    @if ($socialImage->height)<meta property="og:image:height" content="{{ $socialImage->height }}">@endif
@endif
{{-- 記事の公開画面だけに構造化データを出す。@jsonの標準HEXエスケープでscript脱出を防ぐ。 --}}
@if (($ogType ?? '') === 'article' && ($item ?? null) instanceof \Illuminate\Database\Eloquent\Model && !($preview ?? false))
    @php
        $articleStructuredData = app(\App\Support\ArticleStructuredData::class)->forEntry($item, $site ?? [], $socialImage, $canonical ?? url()->current());
    @endphp
    <script type="application/ld+json">@json($articleStructuredData)</script>
@endif
