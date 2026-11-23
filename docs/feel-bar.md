# 触り心地の合格線（feel bar）

管理画面を**押したときの手応え**の合格線。線を超えたら赤 = その版は出さない。

2026-11-05 にメンテナから「UI の触り心地が大事。ずっと言い続けてる」「毎回ここで終わるの」「そしてまた忘れて作り直しての繰り返し」。
決まりを足すだけで、触り心地が実際に良くなったことが一度も無かった。だから線を数字にして、
`node scripts/kit-verify.mjs` の必須の門に入れた。**次の版で誰が作っても、落ちたら止まる。**

## 合格線

| 項目 | 線 | 2026-11-05 の直す前 | 直した後 |
| --- | --- | --- | --- |
| 押下の見た目（押してから、押したものの色・影・位置が変わるまで） | **100ms 以内** | 押している 0.12 秒のあいだ変化なし（全ボタン） | 2〜21ms |
| 完了の表示（押してから「公開しました」等が出るまで） | **600ms 以内** | 作成 326・公開 296・保存 260ms | 作成 364・公開 290・保存 331ms |
| 画面のずれ（1 操作の CLS） | **0.1 以下** | 公開 0.083 | 0 |
| 画面が白くなる時間 | **0ms** | 0ms | 0ms |
| 押せる所の高さ（ボタン・選択肢・サイドバー・表の中のリンク） | **44px 以上** | ダッシュボード 42 個中 30 個が 44px 未満（最小 20px） | 0 個 |
| 「ページを作って公開」で、ブロック編集の画面に「公開する」がある | **ある** | 無い（設定画面へ戻って押す） | ある |
| スマホ（390×844）・タブレット縦（820×1180）のブロック編集で、なかほど・いちばん下までスクロールしても「公開する」が画面の中にあって押せる（知らせにも覆われない） | **押せる**。打っている間は帯を出さない（キーボードで入力欄を隠さない） | 上の帯にあり、下の欄で打ち終えた所からは画面の外（15 周目 T1） | 画面の下に留めた帯（`editor-bottom-bar`）。タブレットではサイドバーを避けて本文の幅に合わせる |
| 保存していない変更があるまま離れる（サイドバー・ブラウザの「戻る」） | **画面の中の窓で確かめる**（ブロック編集は保存を確定してから移る） | サイト設定・メニューは窓なしで消えた（14 周目 R14-2・R14-3） | 窓が出る（`tests/e2e/feel/leave.spec.ts`） |

- 文中の文字のリンク（説明文の中のリンク）は 44px の数に入れない（WCAG 2.5.8 の例外）
- サイドバーは**畳んだ状態（アイコンだけ）も**測る
- チェックボックスは箱が 16px でよい。測るのは押して切り替わる範囲（箱と、`for` で結んだ・箱を包むラベルのうち高い方）。サイト設定・フォームの作成・通知設定・投稿タイプの作成・ページ設定で測る
- 選択の欄（`状態` など）は押した瞬間に開く。開くことが見た目の変化なので、押下の見た目は測らない（完了・CLS・白は測る）
- 動きを減らす設定（`prefers-reduced-motion`）では、押したときに沈まず、色だけ変わる

## 測り方（門と同じ。変えるときは両方）

- Chromium・1366×768・**CPU 4 倍遅く**（ノート PC を想定。`Emulation.setCPUThrottlingRate`）
- 押すのは人と同じく **0.12 秒押してから離す**。Playwright の `click()` は 0 秒で離すので、押した見た目が測れない
- 時刻は**ページの中の時計**で取る（`pointerdown` から）。Playwright の待ちは 100ms 刻みで粗い
- 押下の見た目は、hover の色の移り変わりが終わってから（0.45 秒待ってから）押して比べる
- 3 つの流れを通す: ページを1枚作って公開／お知らせ（記事）を書いて公開／フォームの受信を開いて対応済みに

## 回し方

相手は **この作業ツリーから `agentcms create` → `agentcms dev` したプロジェクト**（利用者と同じ形）。

```
# 1 回だけ: 相手を作る（場所はどこでもよい。kit の外に置く）
node packages/cli/dist/index.js create <場所>/feel-proj
cd <場所>/feel-proj && node <kit>/packages/cli/dist/index.js dev

# kit の門（画面に効く場所を触ったら、触り心地の門も必ず回る）
AGENTCMS_FEEL_PROJECT=<場所>/feel-proj node scripts/kit-verify.mjs

# 触り心地の門だけ
cd template && AGENTCMS_FEEL_PROJECT=<場所>/feel-proj pnpm exec playwright test -c playwright.feel.config.ts
```

- `AGENTCMS_FEEL_PROJECT` が無い・相手に繋がらないときは**赤**（回さずに緑と言わない）
- kit の `template/` そのものを相手にしない。見本サイトの作りが違い（メニューはコードで持つ・問い合わせはフォームの部品ではない）、受信とメニューの流れを通せない（2026-11-05 実測。標準の E2E の `contact.spec`・`ux-round7/9`・`ux-walk` のメニューも同じ理由で rc18 のままでも赤）
- 相手には**いまの作業ツリーの画面**が出ている必要がある。作業ツリーで直したら、`template/` の変えたファイルを相手へ写して build し直す（`docker compose run --rm -T node pnpm build`）。古い画面で緑になっても何も守らない
- 門は DB を作り直さない。ページ・記事・受信を 1 件ずつ足すだけ
- 標準の E2E（`pnpm e2e`）にも `tests/e2e/feel/` は入る

## 直したこと（2026-11-05）

1. **押した瞬間に見た目が変わる** — ボタン・サイドバー・選択の欄・選択肢に `:active`（沈む 1px・少し暗く）。文字のリンクは押すと薄くなる（`resources/css/app.css`）
2. **押せる所は 44px** — ボタンの `default`/`sm`/`icon*`、サイドバーの項目、選択の欄と選択肢、記事の書式の帯、表の中のリンク
3. **ブロック編集の画面に「公開する」** — 置いたらそのまま公開できる（`/publish` に `from=editor` を付けると編集の画面へ戻る）。予約中のページには出さない（予約を「いま」で上書きしないため）
4. **打った内容を黙って消さない** — 守りの無かった編集画面 16 枚に `useLeaveGuard`。ブロック編集は離れる直前に打ちかけを送り、保存が済んでから移る（R14-1 はこちらでは再現できなかったので、予防として入れた）
5. **スマホのブロック編集の「公開する」を画面の下に留める**（15 周目 T1）— 1024px 未満（スマホ・タブレット）。帯は本文の左端と幅に合わせ、タブレットで出ているサイドバーに重ねない。打っている間は出さない。知らせ（toast）は帯の上に出す

## やってはいけない形

- カードの上辺・左辺だけの色の太線、新しい角丸の飾り（メンテナ NG 済み）
- 押した手応えのためだけに、大きく動く・跳ねる動き（動きを減らす設定で消えないもの）

## 15 周目の採点（rc19）から足した見張り（`template/tests/e2e/feel/round15.spec.ts` ほか）

| 項目 | 線 | 試験 |
| --- | --- | --- |
| フォームの通知先が空のとき、届く先をフォームの画面とダッシュボードで同じに言う（G2） | 同じ宛先 | round15.spec.ts・UxRelease20261106Round15Test |
| 公開前チェックの行には、どれにも直しに行ける所がある（G3） | 押す所 1 つ | round15.spec.ts（行がある環境で）・UxRelease20261106Round15Test |
| 「上へ」「下へ」を押したあと、フォーカスがそのボタンに残る（T4） | BODY に戻らない | round15.spec.ts |
| 「項目を追加」で、新しい欄が画面の中に出てフォーカスが入る（T8） | 画面の中・フォーカス | round15.spec.ts |
| 別の画面へ移ったら、前の画面の知らせは残らない（T7） | 0 件 | round15.spec.ts |
| スマホのブロック編集の上の帯（T6） | 240px 以下（3 段） | round15.spec.ts |
| setup の画面のボタンは押している間沈む・送っている間 aria-busy・「場所を選ぶ」が折れない（T2・T5） | 形 | packages/cli/tests/wizard-feel.test.ts |
| ページの URL とフォルダ名の候補は、setup とページの作成で同じ関数（`reading-slug.mjs`）が形態素解析（kuromoji・IPADIC 全体）で読む。読めない字は落とす（焼き上がり時間 → yaki-agari-jikan、吉祥寺 → kichijouji、松本珈琲店 → matsumoto-kohi-ten）（17 周目 G1） | 同じ候補・変な綴りを作らない | reading-slug.test.ts・wizard-project-name.test.ts・round17.spec.ts・PageSlugAutoDraftTest |
| スマホの下の帯の保存の表示は、切れずに全部読める（16 周目 N1） | 2 行まで・切れない | round16.spec.ts |
| setup の Docker の失敗は原因ごとの言葉（ネットワークの空き切れなら、止められる試作環境をフォルダで並べる。自動では止めない）（18 周目 G-env） | 原因ごとの言葉 | docker-failure.test.ts |
| 題の読みはサーバー（PHP・IPADIC を SQLite にした辞書・Viterbi）が出す。ブラウザに辞書を送らない。4G 相当・空のキャッシュでも、打ってから候補まで 1 秒以内・「作成する」から次の画面まで 1 秒以内。URL は読み（仮の URL は作らない）（19 周目） | 1 秒以内・辞書を送らない | round19.spec.ts |
| 使われている URL は、候補に出す前に -2 を付けて見せる。作った URL が見せた候補と違えば、作成の知らせで正直に言う（19 周目 U3） | 見せた候補 = 作った URL | round19.spec.ts・UxRelease20261106Round19Test |
| 公開中のページの URL が仮の形（page・page-日付）のままなら、公開前チェックが拾う（19 周目 U1） | 拾う | UxRelease20261106Round19Test |
| 管理画面・MCP・API（PHP）と setup（Node・kuromoji）が、今夜の例（23 周目で 81 例）で同じ候補になる | 一致 | reading-examples.json（UxRelease20261106Round19Test・wizard-project-name.test.ts） |
| 置き換え表（定番のページ名 → 英語）は題名がその語だけのとき（21 周目で下の Y1 の決まりに替えた）。ほかの語が付いた題は全部読む（吉祥寺高橋トリミングサロン → kichijouji-takahashi-trimming-salon・料金表 → price）（20 周目 R1） | 地名・名字が残る | round20.spec.ts・UxRelease20261106Round20Test |
| カタカナ語は英語の表記（JMdict 由来の表）。表に無いものは音（ネイルサロン → nail-salon・ペットホテル → pet-hotel）（20 周目 R2） | 一貫 | round20.spec.ts・UxRelease20261106Round20Test |
| 題名が「定番名＋ありふれた付け足し（のご案内・方法・紹介・一覧 など・表は付け足しの側）」だけなら定番名の英語（料金のご案内 → price・アクセス方法 → access）。それ以外は全部読む（鎌倉スタッフ紹介 → kamakura-staff-shoukai）（21 周目 Y1） | 決まりは 1 つ | round21.spec.ts・UxRelease20261106Round21Test |
| 英語でない外来語（JMdict の出所の言語）は英語の訳語を使わず音（パン → pan・アルバイト → arubaito）。の＋お・の＋ご は区切る（no-o・no-go）（21 周目 Y2） | 音・区切る | round21.spec.ts・UxRelease20261106Round21Test |
| setup のフォルダ名の候補: 読みの辞書は画面を開いた時点で先読み。返事が 300ms より遅ければ「読みの辞書を準備しています…」を出す（21 周目 Y3: 初回 11.3 秒、何も出なかった） | 先読み・知らせ | wizard-feel.test.ts |
| 入力欄に打った直後に「公開する」を 1 回押すと公開される。押した見た目（公開しています…）はすぐ。保存中でも押しを捨てず、保存が済んでから公開する。管理画面の書き込みと描き直しは同じ session の中で順に処理する（session blocking・BlockConcurrentSessionWrites。同時に走ると遅れた方が session を書き戻し、知らせを消していた。読みの候補などの GET は順にしない）（23 周目 #1: 3 回中 2 回、1 回目が効かなかった） | 1 回で公開・知らせが出る | round23.spec.ts・SessionBlockingTest |
| PC 1366×768 で画像ブロックを置くと「画像を選択」が右の欄の中に全部見える（背の低い画面では「追加する」のタイルを横長にして低くする）（23 周目 #7） | 見切れない | round23.spec.ts |
| 題名の中の読みがずれる語（七五三 → shichi-go-san・二十歳 → hatachi など）は例外の表（core/resources/reading/reading-exceptions.json）で読む。表の語は全部テストの例に入れる（23 周目 #4） | 例外の表 | UxRelease20261106Round23Test・reading-examples.json |
| 公開中のページの URL を変えたら、前の URL から新しい URL へ 301。2 回変えても転送は 2 段にならない。下書きは作らない（20 周目） | 301・1 段 | UxRelease20261106Round20Test |

## 24 周目の採点（rc29）から足した見張り

画面の試験は `template/tests/e2e/feel/round24.spec.ts`。以下は回帰を検出する条件で、配布や本人の体験評価の承認ではない。

| 項目 | 線 | 試験 |
| --- | --- | --- |
| 同じページを二つのタブで編集し、古いタブの保存が失敗したあとに読み込み直す（#1） | 読み込み前に「この画面で打った文が消えます」と確認・コピーできる。「この画面に残る」で入力を保つ。読み込み後は保存されていない文の案内から該当欄を戻し、下書き保存できる。保存済みの一時コピーは片付け、別タブの書きかけは消さない | round24.spec.ts・useEditorRecovery.test.ts・page-editor.isolated.spec.ts |
| 長い題名からの URL 候補（#2） | 読みを 180 字以内で語の切れ目に制限し、末尾にハイフンを残さない。URL 空のまま作成でき、候補と作成後の URL が一致する。PHP と setup の読みは同じ結果 | round24.spec.ts・PageSlugAutoDraftTest・UxRelease20261106Round19Test・wizard-project-name.test.ts・reading-examples.json |
| 編集者のダッシュボードの設定案内（#3） | 権限の無い設定へ進むリンクを出さず、「オーナーに頼んでください」と理由を出す | round24.spec.ts・UxReleaseRound24OtherTest |
| 地名「谷中」の読み（#4） | PHP と setup が共通の `core/resources/reading/reading-exceptions.json` を使い、`yanaka` にする。単独と店名入りの例を共有する | round24.spec.ts・UxRelease20261106Round19Test・wizard-project-name.test.ts・reading-examples.json |
| 招待からの初回パスワード設定（#5） | ログイン画面の完了表示は「パスワードを設定しました。」。既存利用者の変更は「再設定」のまま | round24.spec.ts・UxReleaseRound24OtherTest |
| お問い合わせの送信制限（#6） | 429 のまま日本語の再送案内と入力内容を同じ画面に残す。標準・取り込みフォーム・確認画面の送信経路を守る | round24.spec.ts（PC・スマホ）・UxReleaseRound24OtherTest・cms-form-substitute.test.ts |

## 25 周目の採点（rc30）から足した見張り

| 項目 | 線 | 試験 |
| --- | --- | --- |
| 画像39MBを選ぶ（#1） | 送信前に「20MBまで。この画像は39MB」と表示し、POSTしない。HTMLの413も日本語で上限を伝える。画像ファイル上限20MiB、multipart用リクエスト上限はPHP/nginxとも25MiB | round25-upload.spec.ts（PC・スマホ）・ImageUploadSizePolicyTest |
| 復元の「戻す」と「文をコピーする」（#2） | 操作の間隔12px以上、復元ができる | round25.spec.ts（PC・スマホ） |
| 編集者のフォーム直接URL（#3） | 「この設定はオーナーだけが変えられます」、設定は変更できず保存ボタンを出さない | round25.spec.ts |
| 古いタブの保存状態（#4） | 競合中は「保存できていません」に統一、入力を保つ | round25.spec.ts |
| カタカナ語と長音（#5） | ビザ→visa、センター→center、英語表にないピャーピョー→pyaapyoo。PHP/setup共通の例で一致 | UxRelease20261106Round25SlugTest・wizard-project-name.test.ts・reading-examples.json |
