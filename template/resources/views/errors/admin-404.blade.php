<!DOCTYPE html>
<html lang="ja">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ページが見つかりません</title></head>
<body><main><h1>ページが見つかりません</h1>
@if($publicFallback ?? false)
<p><a href="/">トップへ戻る</a></p>
@else
<p><a href="/admin">管理画面へ戻る</a></p>
@endif
</main></body></html>
