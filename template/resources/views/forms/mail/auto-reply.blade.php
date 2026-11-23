@php
    use AgentCms\Core\Forms\FormValueText;
    $body = trim((string) $form->autoreply_body);
    $signature = trim((string) $form->autoreply_signature);
@endphp
{{-- text メールなので **エスケープしない**。`{{ }}` を使うと引用符が
     &quot; のまま届く。HTML として解釈される経路が無いので XSS にならない --}}
@if ($body !== ''){!! $body !!}
@else
このたびは {{ $siteName }} へお問い合わせいただきありがとうございます。
以下の内容で受け付けました。担当者より改めてご連絡いたします。
@endif

----------------------------------------
@foreach ($submission->orderedValueKeys() as $key)
{{ $submission->labelFor($key) }}: {{ FormValueText::of(null, $submission->values()[$key] ?? null) }}
@endforeach
----------------------------------------

※ このメールは自動送信です。返信いただいてもお答えできない場合があります。
{{-- 署名は本文と別に持つ（R-11 ③）。未設定なら従来どおりサイト名 1 行 --}}
@if ($signature !== ''){!! $signature !!}
@else
{{ $siteName }}
@endif
