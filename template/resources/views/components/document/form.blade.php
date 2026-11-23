{{-- フォームは通常の送信経路を共用する。プレビューでは入力・送信を無効化する。 --}}
@if (!$context->export)
@php
    $input = app(\App\Support\PublicFormInput::class)->embedded((string) ($props['formKey'] ?? ''), $nodeId, $context->preview);
@endphp
<div id="{{ $nodeId }}" class="doc-form {{ $classes }}" @if($style) style="{{ $style }}" @endif>
    @if ($input !== null)
        <h2>{{ $input['form']->name }}</h2>
        @if ($input['form']->description)<p>{{ $input['form']->description }}</p>@endif
        @include('forms.partials.input', $input)
    @elseif ($context->preview)
        <p role="status">このブロックの設定にある「配置するフォーム」で、受付中のフォームを選んでください。下書きや入力項目のないフォームは公開されません。</p>
    @endif
</div>
@endif
