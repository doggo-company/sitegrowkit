{{-- PageDocument: container（stack / row / grid） --}}
<div
    id="{{ $nodeId }}"
    class="doc-container doc-container--{{ $props['layout'] ?? 'stack' }} doc-cols-{{ $props['columns'] ?? 2 }} {{ $classes }}"
    @if($style) style="{{ $style }}" @endif
>{{ $children }}</div>
