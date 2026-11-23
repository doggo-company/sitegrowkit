{{-- PageDocument: collection。取得は ListContent が行い、ここは表示だけ --}}
@php($type = $node['binding']['type'] ?? null)
<div id="{{ $nodeId }}" class="doc-collection doc-collection--{{ $props['layout'] ?? 'cards' }} {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    @if(count($items) === 0)
        <p class="doc-collection__empty">{{ $props['emptyState'] ?? 'まだ登録がありません' }}</p>
    @else
        <ul class="doc-collection__list">
            @foreach($items as $item)
                <li class="doc-collection__item">
                    @php($url = $type && \Illuminate\Support\Facades\Route::has("{$type}.show") && ! empty($item['slug'])
                        ? route("{$type}.show", ['slug' => $item['slug']], absolute: false)
                        : null)
                    @if($url)
                        <a href="{{ $url }}">{{ $item['title'] ?? $item['id'] }}</a>
                    @else
                        <span>{{ $item['title'] ?? $item['id'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
