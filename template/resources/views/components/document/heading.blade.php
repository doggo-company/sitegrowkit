{{-- PageDocument: heading。level は registry の enum で検証済み --}}
@php($level = (int) ($props['level'] ?? 2))
@php($text = (string) ($props['text'] ?? ''))
@if(trim($text) !== '')
<h{{ $level }} id="{{ $nodeId }}" class="doc-heading {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    {{ $text }}
</h{{ $level }}>
@elseif(($context ?? null)?->preview)
{{-- 置いたばかりで、まだ何も書いていない見出し（UX 一周 3 周目 N3）。仮の文字は**値に入れない**ので、
     編集画面の Canvas とプレビューにだけ、薄い字で何を書く場所かを出す。公開面には何も出さない --}}
<h{{ $level }} id="{{ $nodeId }}" class="doc-heading doc-placeholder {{ $classes }}" @if($style) style="{{ $style }}" @endif data-placeholder>
    見出しを入力
</h{{ $level }}>
@endif
