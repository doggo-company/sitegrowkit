{{-- PageDocument: hours（営業時間）。1 行 1 つ、「曜日｜時間」。定休日は別の欄 --}}
@php($rows = \App\Support\Document\PairRows::parse((string) ($props['rows'] ?? '')))
<div id="{{ $nodeId }}" class="doc-pairs doc-hours {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    <table class="doc-pairs__table">
        @if (($props['caption'] ?? '') !== '')<caption class="doc-pairs__caption">{{ $props['caption'] }}</caption>@endif
        <tbody>
            @foreach ($rows as [$day, $time])
                <tr><th scope="row">{{ $day }}</th><td>{{ $time }}</td></tr>
            @endforeach
            @if (($props['closed'] ?? '') !== '')
                <tr class="doc-hours__closed"><th scope="row">定休日</th><td>{{ $props['closed'] }}</td></tr>
            @endif
        </tbody>
    </table>
    @if (($props['note'] ?? '') !== '')<p class="doc-pairs__note">{{ $props['note'] }}</p>@endif
</div>
