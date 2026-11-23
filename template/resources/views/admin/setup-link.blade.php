<!DOCTYPE html>
{{--
    パスワード設定 URL の受け渡し画面（STAGE #18B）。

    ## Inertia を通さない理由

    Inertia のページは props を DOM（`data-page`）へ埋め、履歴にも残す。
    ここで扱うのは「一時的な鍵」なので、そのどれにも載せたくない。
    だから **この 1 画面だけ素の Blade** で返し、
    キャッシュも履歴も残さないヘッダーを付けて渡す。

    ## 画面に出す値

    URL・表示名・期限だけ。token を別に出さない（URL に含まれている）。
    値はすべて Blade の自動エスケープを通る。
--}}
<html lang="ja">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        {{-- 検索避けと参照元の遮断（URL が Referer で外へ出ないように） --}}
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
        <title>パスワード設定 URL — {{ config('app.name') }}</title>
        <style nonce="{{ $nonce }}">
            :root { color-scheme: light dark; }
            body {
                margin: 0;
                padding: 2rem 1rem;
                font-family: system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif;
                line-height: 1.7;
                background: #fff;
                color: #1c1917;
            }
            main { max-width: 44rem; margin: 0 auto; }
            .card {
                border: 1px solid #fcd34d;
                background: #fffbeb;
                border-radius: 0.75rem;
                padding: 1.25rem;
            }
            h1 { font-size: 1.25rem; margin: 0 0 0.75rem; }
            ul { margin: 0 0 1rem; padding-left: 1.25rem; }
            li { margin-bottom: 0.25rem; }
            .url-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
            input[type="text"] {
                flex: 1 1 20rem;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 0.8rem;
                padding: 0.5rem 0.75rem;
                border: 1px solid #d6d3d1;
                border-radius: 0.375rem;
                background: #fff;
                color: inherit;
            }
            button, a.button {
                display: inline-block;
                padding: 0.5rem 1rem;
                border-radius: 0.375rem;
                border: 1px solid #d6d3d1;
                background: #1c1917;
                color: #fff;
                font: inherit;
                text-decoration: none;
                cursor: pointer;
            }
            a.button { background: #fff; color: #1c1917; }
            #copy-status { min-height: 1.5rem; margin: 0.75rem 0 0; }
            .sr-only {
                position: absolute; width: 1px; height: 1px;
                padding: 0; margin: -1px; overflow: hidden;
                clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
            }
            @media (prefers-color-scheme: dark) {
                body { background: #0c0a09; color: #fafaf9; }
                .card { background: #1c1917; border-color: #a16207; }
                input[type="text"] { background: #0c0a09; border-color: #44403c; }
                button { background: #fafaf9; color: #0c0a09; }
                a.button { background: transparent; color: #fafaf9; border-color: #44403c; }
            }
        </style>
    </head>
    <body>
        <main>
            <section
                class="card"
                aria-labelledby="setup-link-heading"
                data-users-url="{{ route('admin.users.index') }}"
            >
                <h1 id="setup-link-heading">
                    {{ $userName }} さんのパスワード設定 URL
                </h1>

                <ul>
                    <li>
                        <strong>この URL を知っている人は、このアカウントのパスワードを設定できます。</strong>
                        本人だけが見られる経路で渡してください。
                    </li>
                    <li>有効期限は発行から {{ $expiresInMinutes }} 分です。</li>
                    <li>
                        <strong>この画面を離れると二度と表示できません。</strong>
                        必要になったら利用者の詳細画面から発行し直してください（発行し直すと、この URL は使えなくなります）。
                    </li>
                </ul>

                <div class="url-row">
                    <label class="sr-only" for="setup-link-url">パスワード設定 URL</label>
                    <input id="setup-link-url" type="text" value="{{ $url }}" readonly />
                    <button type="button" id="copy-button">URL をコピー</button>
                    <a class="button" href="{{ route('admin.users.index') }}">利用者一覧へ戻る</a>
                </div>

                <p id="copy-status" role="status" aria-live="polite"></p>
            </section>
        </main>

        {{--
            この script は **サーバーの値を 1 つも埋め込まない**。
            設定 URL も戻り先も DOM（属性）から読む。
            Blade の自動エスケープを通った属性値しか触らないので、
            値の混入で script が壊れることがない。
            nonce 付きなので、この応答の CSP では **これ以外の script が動かない**。
        --}}
        <script nonce="{{ $nonce }}">
            (function () {
                var card = document.querySelector('[data-users-url]');
                var input = document.getElementById('setup-link-url');
                var status = document.getElementById('copy-status');

                document.getElementById('copy-button').addEventListener('click', function () {
                    input.select();

                    if (!navigator.clipboard) {
                        status.textContent = 'コピーできませんでした。URL を選択して手動でコピーしてください。';
                        return;
                    }

                    navigator.clipboard.writeText(input.value).then(
                        function () { status.textContent = 'URL をクリップボードにコピーしました。'; },
                        function () { status.textContent = 'コピーできませんでした。URL を選択して手動でコピーしてください。'; }
                    );
                });

                /*
                 * 「戻る」で復元されたときは中身を見せない。
                 * no-store だけでは bfcache から戻る実装があるため、
                 * 復元を検知したら一覧へ送り返す。
                 */
                window.addEventListener('pageshow', function (event) {
                    if (event.persisted) {
                        window.location.replace(card.dataset.usersUrl);
                    }
                });
            })();
        </script>
    </body>
</html>
