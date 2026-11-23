{{-- リンクの目的が読み上げでも分かるよう、ロゴの代替文はサイト名にする。 --}}
@if ($logo)
    <img class="site-brand__image" src="{{ $logo->url() }}" alt="{{ $siteName }}" @if ($logo->width) width="{{ $logo->width }}" @endif @if ($logo->height) height="{{ $logo->height }}" @endif>
@else
    {{ $siteName }}
@endif
