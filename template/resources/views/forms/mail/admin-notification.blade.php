@php
    use AgentCms\Core\Forms\FormValueText;
@endphp
【{{ $form->name }}】に新しい送信が届きました。

受信番号: #{{ $submission->id }}
受信日時: {{ $submission->created_at?->format('Y-m-d H:i') }}

----------------------------------------
@foreach ($submission->orderedValueKeys() as $key)
{{ $submission->labelFor($key) }}: {{ FormValueText::of(null, $submission->values()[$key] ?? null) }}
@endforeach
----------------------------------------

管理画面で確認してください。
