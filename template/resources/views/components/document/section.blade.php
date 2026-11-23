{{-- PageDocument: section（Phase 9.1D / P0-C1） --}}
{{-- 背景・余白は `type: token` の prop から renderer が組み立てる。ここでは値を触らない --}}
{{-- `pattern` は初期テンプレート（A/B/C）が形を整えるための目印。値は registry の enum で検証済み。
     `eyebrow` は飾りの小さな英字（About など）。読み上げでは読まない --}}
@php($pattern = (string) ($props['pattern'] ?? 'plain'))
<section
    id="{{ $nodeId }}"
    class="doc-section{{ $pattern !== '' && $pattern !== 'plain' ? ' doc-section--'.$pattern : '' }} {{ $classes }}"
    @if($style) style="{{ $style }}" @endif
>
    <div class="container" @if (($node['key'] ?? '') === 'bread-menu') id="bread-menu" tabindex="-1" @endif>@if (($props['eyebrow'] ?? '') !== '')<p class="doc-eyebrow" aria-hidden="true">{{ $props['eyebrow'] }}</p>@endif{{ $children }}</div>
</section>
