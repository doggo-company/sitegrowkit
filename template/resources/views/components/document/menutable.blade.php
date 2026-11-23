{{-- PageDocument: menutable（メニュー表）。1 行 1 品、「品名｜値段」。値は Blade が必ずエスケープする --}}
@php($rows = \App\Support\Document\PairRows::parse((string) ($props['items'] ?? '')))
<div id="{{ $nodeId }}" class="doc-pairs doc-menutable {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    <table class="doc-pairs__table">
        @if (($props['caption'] ?? '') !== '')<caption class="doc-pairs__caption">{{ $props['caption'] }}</caption>@endif
        <tbody>
            @foreach ($rows as [$name, $price])
                <tr><th scope="row">{{ $name }}</th><td>{{ $price }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @if (($props['note'] ?? '') !== '')<p class="doc-pairs__note">{{ $props['note'] }}</p>@endif
</div>
