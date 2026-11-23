Web サイトからお問い合わせが届きました。

受付番号: #{{ $submission->id }}
受信日時: {{ $submission->created_at->format('Y-m-d H:i') }}

お名前: {{ $submission->name }}
メール: {{ $submission->email }}
会社名: {{ $submission->company ?? '（未入力）' }}
件名: {{ ['estimate' => 'お見積もり', 'consultation' => 'ご相談', 'other' => 'その他'][$submission->subject] ?? $submission->subject }}

---- お問い合わせ内容 ----
{{ $submission->message }}
--------------------------

管理画面: {{ url('/admin/contact/'.$submission->id) }}
