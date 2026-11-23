# SiteGrowKit — はじめにお読みください

SiteGrowKit を使っていただきありがとうございます。この 1 ページの手順だけで、最初のサイトが手元で起動します。

> **名前について:** 旧名は Agent CMS Kit です。zip / `.tgz` のファイル名・CLI コマンド（`agentcms`）・`.agentcms/` ディレクトリは改名していません。以降の手順に旧名が出てきますが、そのまま打って正しく動きます。

## 1. お使いの環境を選ぶ

| 環境 | 進み方 |
| --- | --- |
| **Mac**(Apple Silicon) | このまま §2 へ進む |
| **Windows 11** | 先に [docs/WINDOWS_WSL2.md](docs/WINDOWS_WSL2.md) で WSL2 を準備 → 以降の手順はすべて **WSL2 のターミナル内**で実行 |

> **Windows の方へ(重要):** zip の展開もプロジェクト作成も、WSL2 の Linux 側(`~/` 配下)で行ってください。`/mnt/c` や `/mnt/d` を作業場所にすると、動作が大幅に遅くなり、permission の問題も起きます。

## 2. ダウンロードの検証(推奨・コピペ 1 行)

zip と一緒にダウンロードした `agent-cms-kit-v<version>.zip.sha256` を、zip と同じフォルダに置いた状態で:

**Mac:**

```bash
shasum -a 256 -c agent-cms-kit-v<version>.zip.sha256
```

**Windows(WSL2 内):**

```bash
sha256sum -c agent-cms-kit-v<version>.zip.sha256
```

`agent-cms-kit-v<version>.zip: OK` と表示されれば成功です。
**OK 以外が表示された場合は、そのまま進めず入手元からダウンロードし直してください。**
`.sha256` ファイルは zip と同じ場所に置いて配ります(自分で `node scripts/package-release.mjs` を流して作った場合は、`release/` に zip と並んで出力されます)。

## 3. 必要なもの

- **Docker Desktop**(起動しておく)
- **Node.js 20 以上**(`node --version` で確認 — `agentcms` コマンドの実行に使います)

**任意:** **Git**(バージョン管理を使う場合のみ。セットアップにも `agentcms verify` にも必須ではありません)

ホストへの PHP / Composer / **pnpm** のインストールは**不要**です(Docker Golden Path 内で完結します)。

AI エージェントと開発する場合は、お使いの AI コーディングエージェント(Claude Code / Codex / Cursor など)を別途ご用意ください。エージェント本体と各サービス / API の利用料金は本キットには含まれません(特定のベンダー契約が必須になることはありません)。

ランタイムのライセンス認証・phone-home・常時オンライン検証はないため、通常のローカル開発に常時インターネット接続は不要です。ただし初回ダウンロード・依存パッケージや Docker イメージの取得・AI サービスの利用・アップデート時にはネットワークが必要です(完全オフライン製品ではありません)。

> **うまくいかないときは、先にこれを 1 行だけ回してください**(導入せずに環境だけ調べます):
> `npx --yes --package ./agent-cms-kit-<version>.tgz agentcms doctor`
> 次の §4 の `npm install -g` が **permission で転ぶ環境**(WSL2 で Node を apt から入れた場合など)でも、これは動きます。「グローバル導入先の書き込み」に ✖ が出たら、その行の「修正」に従ってください。

## 4. インストール

zip を展開すると `agent-cms-kit-v<version>` というフォルダができます。**その中に入ってから**実行してください(`.tgz` はこのフォルダの中にあります)。

```bash
unzip agent-cms-kit-v<version>.zip
cd agent-cms-kit-v<version>

npm install -g ./agent-cms-kit-<version>.tgz
agentcms --version   # <version> と表示されれば導入完了
```

> ファイル名の `v` の有無に注意してください。**zip とフォルダには `v` が付き**(`agent-cms-kit-v<version>`)、**`.tgz` には付きません**(`agent-cms-kit-<version>.tgz`)。

(WSL2 で `unzip` が無い場合は先に `sudo apt install unzip`)

## 5. 最初のサイトを起動する

```bash
agentcms setup
```

ブラウザでセットアップ画面が開きます。あとは画面の案内どおりに、サイト名と保存場所を入力して進むだけです(環境の確認・プロジェクトの作成・起動まで自動で行います。初回は Docker イメージの取得に時間がかかります)。完了画面から「サイトを見る」「管理画面を開く」へ進めます。

<details>
<summary>ターミナルで操作したい場合(上級者向け)</summary>

```bash
agentcms doctor          # 環境診断。✖ が出たら表示される「修正」に従う
agentcms create my-site  # プロジェクト生成
cd my-site
agentcms dev             # 起動(初回は Docker イメージ取得で数分かかります)
```

</details>

セットアップ完了後の画面(および `agentcms dev` の成功メッセージ)に、公開サイト URL・管理画面ログイン URL が表示されます。
**管理者パスワードはこのプロジェクト専用に自動生成**され、`my-site/.agentcms/local/admin-credentials.json` に保存されています(Git には含まれません)。

## 6. 起動できたら、この 6 歩

**ここが一番迷うところです。** 管理画面にログインすると、ダッシュボードに同じ 6 歩が並んでいます。
済んだものには自動でチェックが付く（どこかに保存しているのではなく、サイトの状態を見ています）ので、
上から順にどうぞ。

1. **サイトの名前を決める** — いまは製品名のままです（サイト設定）
2. **最初のページか記事を出す** — 1 本公開すると、動いていることを目で確かめられます
3. **トップページを自分の言葉にする** — トップ用のページを作って公開し、編集画面の「このページをトップページにする」を押します。
   押すまでは、公開サイトのトップに見本の言葉（「〇〇（地域）の〇〇」）が出たままです
4. **メニューを案件に合わせる** — いまは見本のままです
5. **問い合わせが自分に届くようにする** — 通知先が見本のままだと、送った人には「送信完了」と
   出るのに**誰にも届きません**
6. **公開の前にひととおり見る** — 開けないページや、仮のままの言葉が残っていないかを確かめます
   （公開前チェック）

デザインや機能を案件に合わせて育てるのは、この 6 歩のあとです（次項）。

### 6 歩が済んだら、サーバーに出す

公開前チェックで直すものが 0 件になったら、文章の内容も確かめてから、サーバーに出してインターネットで見られるようにします。これは 6 歩のあとの作業です。

用意するものは、XServer の契約（全プラン可・PHP 8.3 以上）、公開に使うドメイン（初期ドメイン「サーバーID.xsrv.jp」でも可）、SSH の鍵（サーバーパネルで公開鍵を登録）です。
XServer の API キーがあると、DB・SSL・Cron などを `agentcms deploy setup` で準備できます。無ければサーバーパネルで手作業で準備します。

手順書は、zip を展開したフォルダの [docs/deployment/xserver.md](docs/deployment/xserver.md) です。管理画面からは開けません。

進める順番:

1. デプロイ設定を作る（手順書 §4）: `cp .agentcms/deploy/staging.example.json .agentcms/deploy/staging.json` でコピーし、ホスト・サーバーID・ドメイン・鍵の場所を書きます。パスワードなどの秘密は、このファイルに書きません。`agentcms deploy` のコマンドは、どれもこのファイルが無いと先へ進みません。
2. サーバー側を準備する（手順書 §2・§5）: API キーがあれば `agentcms deploy setup`、無ければサーバーパネルで手作業。サーバーの `shared/.env` も埋めます。
3. `agentcms deploy doctor` → `agentcms deploy plan` → `agentcms deploy staging` の順に、まずステージングで確かめます（手順書 §6）。本番は手順書の §12 を使ってください。

## 7. 次はどこへ

- **インターネットに公開する（サーバーに出す）** → [XServer の手順書](docs/deployment/xserver.md)
- **AI エージェントと開発を始める** → [docs/AI_AGENTS.md](docs/AI_AGENTS.md)(最初のプロンプト例、Claude Code / Codex 系 / Cursor での使い方)
- **WordPress から記事を持ち込む** → [docs/MIGRATE_FROM_WORDPRESS.md](docs/MIGRATE_FROM_WORDPRESS.md)(下見 → backup → 取り込み → 戻す。対応するページも取り込めます。メニューの自動移行は対象外)
- **うまくいかないとき** → [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)(`agentcms doctor` の出力が最初の手がかりです)
- **ライセンス** → [LICENSE](LICENSE)(GNU General Public License v3.0 or later)/ [COPYRIGHT](COPYRIGHT)。第三者 OSS の帰属表示は [THIRD-PARTY-LICENSES.md](THIRD-PARTY-LICENSES.md) にあります。

## 日々の更新を始める

記事・ページ・入力項目・URL変更の操作は [編集者向けクイックスタート](docs/EDITOR_QUICKSTART.md) を参照してください。配布版の確認と更新前の作業は [更新手順](docs/UPGRADING.md) にまとめています。
