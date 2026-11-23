{{-- PageDocument: cover（大きな写真と見出し）。初期テンプレート A/B/C のファーストビューと写真の帯。
     見出しは改行で行を分け、1 行ずつを折り返しにくい塊にする（スマホで「焼きたてを／どうぞ」の
     ような変な位置の改行を避ける。入り切らない長さのときだけ行の中で折り返す）。
     写真は無くても文字だけで出る（置いた直後に何も出ないのを避ける） --}}
@php
    $variant = in_array($props['variant'] ?? 'full', ['full', 'band', 'editorial'], true) ? ($props['variant'] ?? 'full') : 'full';
    $media = \Modules\Media\Models\Media::find($props['mediaId'] ?? null);

    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($props['title'] ?? '')) ?: []), static fn ($l) => $l !== ''));
    $buttons = array_values(array_filter([
        ['label' => (string) ($props['buttonLabel'] ?? ''), 'href' => (string) ($props['buttonHref'] ?? ''), 'kind' => 'primary'],
        ['label' => (string) ($props['button2Label'] ?? ''), 'href' => (string) ($props['button2Href'] ?? ''), 'kind' => 'secondary'],
    ], static fn ($b) => $b['label'] !== '' && $b['href'] !== ''));
    $alt = (string) ($props['alt'] ?? '');
    // 見出しの段は registry の enum（1 / 2）で検証済み
    $level = (int) ($props['level'] ?? 1) === 2 ? 2 : 1;

@endphp
<section id="{{ $nodeId }}" class="doc-cover doc-cover--{{ $variant }}{{ $media ? '' : ' doc-cover--no-photo' }} {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    @if ($media && in_array($variant, ['full', 'band'], true))
        <div class="doc-cover__bg">
            <x-media-img :media="$media" :alt="$alt !== '' ? $alt : ''" :loading="$variant === 'full' ? 'eager' : 'lazy'" />
        </div>
    @endif
    <div class="doc-cover__inner">
        <div class="doc-cover__copy">
            @if (($props['eyebrow'] ?? '') !== '')<p class="doc-cover__eyebrow">{{ $props['eyebrow'] }}</p>@endif
            <h{{ $level }} class="doc-cover__title">@foreach ($lines as $line)<span class="doc-cover__line">{{ $line }}</span>@endforeach</h{{ $level }}>
            @if (($props['lead'] ?? '') !== '')<p class="doc-cover__lead">{{ $props['lead'] }}</p>@endif
            @if ($buttons !== [])
                <p class="doc-cover__actions">
                    @foreach ($buttons as $button)
                        <a class="doc-cover__button doc-cover__button--{{ $button['kind'] }}" href="{{ $button['href'] }}">{{ $button['label'] }}</a>
                    @endforeach
                </p>
            @endif

        </div>
        @if ($variant === 'editorial' && $media)
            <figure class="doc-cover__photo">
                <x-media-img :media="$media" :alt="$alt" loading="eager" sizes="(min-width: 768px) 50vw, 100vw" />
                @if (($props['caption'] ?? '') !== '')<figcaption>{{ $props['caption'] }}</figcaption>@endif
            </figure>
        @endif
    </div>
</section>
