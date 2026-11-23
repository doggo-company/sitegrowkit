新しいコメントが届きました。

受信番号: #{{ $comment->id }}
投稿日時: {{ $comment->created_at?->format('Y-m-d H:i') }}
状態:     {{ $comment->status()->label() }}
記事:     {{ $comment->content_type }} #{{ $comment->content_id }}
投稿者:   {{ $comment->author_name }}

----------------------------------------
{{ $comment->body }}
----------------------------------------

管理画面で承認してください。
