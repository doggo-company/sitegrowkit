{{ $submission->name }} 様

お問い合わせいただきありがとうございます。
以下の内容で受け付けました。担当者より 2 営業日以内にご連絡いたします。

受付番号: #{{ $submission->id }}

件名: {{ ['estimate' => 'お見積もり', 'consultation' => 'ご相談', 'other' => 'その他'][$submission->subject] ?? $submission->subject }}

---- お問い合わせ内容 ----
{{ $submission->message }}
--------------------------

※ このメールは自動送信です。心当たりのない場合は破棄してください。

{{ $siteName }}
