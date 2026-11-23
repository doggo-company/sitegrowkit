{{-- 登録が外れた component。document は壊さず、公開側では何も出さない --}}
@if(config('app.debug'))
    <div class="doc-unknown" data-node="{{ $nodeId }}">
        <small>未登録のブロック（{{ $type }}）です。</small>
    </div>
@endif
