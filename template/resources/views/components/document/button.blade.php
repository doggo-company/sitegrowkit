{{-- PageDocument: button（リンク。href は validator で scheme を検証済み） --}}
<p id="{{ $nodeId }}" class="doc-button-wrap {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    <a class="doc-button doc-button--{{ $props['variant'] ?? 'primary' }}" href="{{ $props['href'] }}">
        {{ $props['label'] }}
    </a>
</p>
