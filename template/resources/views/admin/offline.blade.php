<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>オフライン - 管理画面</title>
    {{-- SW にプリキャッシュされる唯一のページ。管理データは一切含めない・見せない --}}
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
            background: #fafafa;
            color: #18181b;
        }
        .box { text-align: center; padding: 2rem; }
        h1 { font-size: 1.25rem; margin-bottom: 0.5rem; }
        p { color: #52525b; font-size: 0.9rem; line-height: 1.7; }
        button {
            margin-top: 1.5rem;
            padding: 0.6rem 1.5rem;
            border: 1px solid #d4d4d8;
            border-radius: 0.5rem;
            background: #fff;
            font-size: 0.9rem;
            cursor: pointer;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #18181b; color: #fafafa; }
            p { color: #a1a1aa; }
            button { background: #27272a; border-color: #3f3f46; color: #fafafa; }
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>オフラインです</h1>
        <p>
            ネットワークに接続されていないため、管理画面を表示できません。<br>
            最新の管理データを表示するには接続が必要です。<br>
            オフラインでの編集・公開はできません。
        </p>
        <button onclick="location.reload()">再読み込み</button>
    </div>
</body>
</html>
