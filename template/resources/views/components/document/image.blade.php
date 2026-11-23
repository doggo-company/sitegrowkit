{{-- PageDocument: image。装飾指定なら alt="" にして読み上げから外す（Phase 8.5-F の方針） --}}
@php($media = \Modules\Media\Models\Media::find($props['mediaId'] ?? null))
@if($media)
    <figure id="{{ $nodeId }}" class="doc-image {{ $classes }}" @if($style) style="{{ $style }}" @endif>
        {{-- 装飾目的なら空、代替テキストが書いてあればそれ、無ければメディアの説明 --}}
        <x-media-img :media="$media" :alt="($props['decorative'] ?? false) ? '' : ((string) ($props['alt'] ?? '') !== '' ? (string) $props['alt'] : null)" />
    </figure>
@elseif(($context ?? null)?->preview)
    {{-- 下書き（編集画面の Canvas とプレビュー）だけに出す枠。置いた直後に何も出ず、
         置けたのか分からなかった（UX 一周 2026-09-28）。公開面には出さない --}}
    <figure id="{{ $nodeId }}" class="doc-image doc-image--empty {{ $classes }}" @if($style) style="{{ $style }}" @endif>
        <span>まだ画像を選んでいません（「画像を選択」から選べます）</span>
    </figure>
@endif
