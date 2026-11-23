{{-- 設定がなければ他社や製品のアイコンを勝手に表示しない。 --}}
@if ($icon)
    <link rel="icon" href="{{ $icon->url() }}" type="{{ $icon->mime }}">
@endif
