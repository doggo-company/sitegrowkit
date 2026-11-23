{{-- PageDocument: richtext。{!! !!} を使ってよいのは Purifier 通過後だけ（coding.md） --}}
@php($html = (string) ($props['html'] ?? ''))
@if(trim($html) === '' && ($context ?? null)?->preview)
{{-- まだ何も書いていない本文（UX 一周 3 周目 N3）。編集画面とプレビューだけに薄い字で出す --}}
<div id="{{ $nodeId }}" class="doc-richtext doc-placeholder {{ $classes }}" @if($style) style="{{ $style }}" @endif data-placeholder>
    <p>本文を入力</p>
</div>
@else
<div id="{{ $nodeId }}" class="doc-richtext {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    {!! app(\AgentCms\Core\Html\Purifier::class)->clean($html) !!}
</div>
@endif
