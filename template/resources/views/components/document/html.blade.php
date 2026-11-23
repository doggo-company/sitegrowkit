{{-- PageDocument: HTML Component（Restricted HTML のみ。script / on* / 危険 URL は除去済み） --}}
<div id="{{ $nodeId }}" class="doc-html {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    {!! app(\AgentCms\Core\Html\RestrictedHtml::class)->clean((string) ($props['html'] ?? '')) !!}
</div>
