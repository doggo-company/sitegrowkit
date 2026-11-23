{{--
  公開の口を持たない投稿タイプの preview（Phase 9.15 / STAGE 28A・SPEC §B.2）。

  **404 を返さない。** 「見つからない」のではなく「描く相手がいない」ので、
  404 だと壊れているように見える。何が無いのかを言う。
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>プレビューできません</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 3rem 1.5rem; line-height: 1.8; color: #1f2937; }
        main { max-width: 34rem; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        p { margin: 0 0 1rem; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $label }}には公開ページがありません</h1>
        <p>
            この投稿タイプは公開側の表示を持っていないため、プレビューする画面がありません。
            内容の確認は編集画面で行ってください。
        </p>
        <p><a href="{{ $backUrl }}">編集画面へ戻る</a></p>
    </main>
</body>
</html>
