# XServer Deployment ガイド（staging / 本番）

SiteGrowKit を XServer（共用レンタルサーバー）へデプロイする手順。設計判断の理由は ADR-0008 を参照。

> **§1〜§11 は staging を例に書いている。本番は §12 の通し手順を使う。**
> 本番だけの違い（メールが実際に届く・noindex を残さない・`--confirm-target` が要る）は §12 に集めた。

> XServer のサーバーパネル画面・仕様は変わる可能性がある。本書の公式仕様は 2026-08 時点の確認。パネル操作の詳細は XServer 公式マニュアル（https://www.xserver.ne.jp/manual/）を正とする。

## Prerequisites

- XServer 共用サーバー契約（全プラン可。PHP 8.3 以上が使えること）
- ローカルに Agent CMS プロジェクト（Docker 起動可能な状態: `agentcms dev`）
- デプロイ先ドメイン（初期ドメイン `サーバーID.xsrv.jp` でも可。無料SSL 対応）

## 1. SSH setup

XServer の SSH は**ポート 10022・公開鍵認証のみ**（パスワード認証不可）。

1. 鍵ペア作成（ローカル）: `ssh-keygen -t ed25519 -f ~/.ssh/xserver_staging -N ""`
2. サーバーパネル > **SSH設定** → 対象サーバーで「ONにする」
3. 同画面「公開鍵登録・設定」で `~/.ssh/xserver_staging.pub` の内容を登録
4. 疎通確認: `ssh -p 10022 -i ~/.ssh/xserver_staging サーバーID@サーバーID.xsrv.jp echo ok`
   - 国外 IP からはアクセス制限される場合がある（サーバーパネルで設定確認）

## 2. サーバー前提条件（API 自動 or パネル手動）

**推奨: XServer API（ADR-0009）。** XServer アカウント管理画面 > **APIキー管理** でキーを発行し（最小権限: 必要なら読み取り専用 + setup 時のみカスタムスコープ）、環境変数で渡す:

```bash
export XSERVER_API_KEY=xs_...   # 設定ファイル・Git・ログに書かない
agentcms deploy setup           # subdomain / SSL / DB / ユーザー / grant / shared/.env / Cron / PHP を一括整備
```

`deploy setup` は**作成のみ・冪等**（既存リソースはスキップ。削除・リストアは自動化しない）。実行前に対象一覧を提示して確認を取る。DB パスワードは生成してサーバー上の shared/.env へのみ書き込む（表示・ローカル保存しない）。

**API キーが無い場合の手動 fallback（サーバーパネル）:**

| 設定 | 場所 | 内容 |
| --- | --- | --- |
| PHP バージョン | PHP Ver.切替 | 対象ドメインを **PHP 8.3 以上**に |
| MySQL | MySQL設定 | DB・ユーザーを作成し、ユーザーを DB に追加（接続ホストは現行サーバーなら `localhost`。画面の表示値を正とする） |
| SSL | SSL設定 | 対象ドメインで無料独自SSLを ON（反映まで最大1時間） |
| Cron | Cron設定 | §7 のコマンドを毎分で登録 |

## 3. Directory layout

```
/home/<サーバーID>/
├── agentcms/staging/          ← appDir（Web 非公開領域）
│   ├── releases/<id>/         ← デプロイされた各リリース
│   ├── shared/.env            ← 秘密情報はここだけ（Git に入れない）
│   ├── shared/storage/        ← アップロード等の永続データ
│   ├── backups/               ← DB バックアップ
│   └── current -> releases/<id>
└── <ドメイン>/public_html/     ← docroot（index.php スタブ + 公開アセットのみ）
```

`.env`・vendor・ソース・DB バックアップは docroot の外にあり、Web からアクセスできない。

## 4. デプロイ設定（ローカル）

```bash
cp .agentcms/deploy/staging.example.json .agentcms/deploy/staging.json
# ホスト・サーバーID・ドメイン・鍵パスを編集（このファイルは gitignore 済み）
```

秘密情報（DB パスワード等）はこのファイルに**書かない**。書くのは接続先と配置パスのみ。

## 5. Environment（shared/.env）

初回のみ、サーバー上に `.env` を配置する:

```bash
scp -P 10022 -i ~/.ssh/xserver_staging .agentcms/deploy/env.staging.example \
  サーバーID@ホスト:/home/サーバーID/agentcms/staging/shared/.env
ssh -p 10022 -i ~/.ssh/xserver_staging サーバーID@ホスト
vi ~/agentcms/staging/shared/.env   # DB_* / MAIL_* / TURNSTILE_* / VAPID_* を設定
chmod 600 ~/agentcms/staging/shared/.env
```

- `APP_KEY`: ローカルで `docker compose exec app php artisan key:generate --show` の出力を貼る
- `APP_ENV=staging` / `APP_DEBUG=false` は必須（deploy が検査し、違えば中止する）
- `APP_TIMEZONE=Asia/Tokyo` を消さない。無いと UTC で動き、「10:00」に予約したページが日本時間の 19:00 まで出ない（ひな形と `deploy setup` には入っている。それより前に作った `shared/.env` には手で足す）
- staging のメールは `MAIL_STAGING_REDIRECT_TO`（未設定なら `MAIL_ADMIN_ADDRESS`）へ全件リダイレクトされ、実顧客へは送られない。どちらも未設定なら log 出力のみ

## 6. First deployment

```bash
agentcms deploy doctor     # SSH / PHP / 拡張 / MySQL / 権限 / HTTPS を診断
agentcms deploy plan       # 何が起きるかを確認（変更なし・秘密情報なし）
agentcms deploy staging    # backup → upload → migrate → 切替 → health check
```

- doctor が FAIL を出したら表示される修正手順に従う（`remote.phpBin` の明示指定も可）
- deploy は失敗ステップで安全に停止する。**同じコマンドの再実行は安全**（作り直し方式）
- 初回デプロイ後、admin ユーザーを作成:
  `ssh` でサーバーへ入り `cd ~/agentcms/staging/current && /usr/bin/php8.x artisan db:seed --class=AdminUserSeeder --force`（またはプロジェクトの手順に従う）

## 7. Cron（Laravel scheduler / DB Queue）

`XSERVER_API_KEY` があれば `agentcms deploy setup` が API で自動登録する。手動の場合は `agentcms deploy plan` が表示するコマンドをサーバーパネル > **Cron設定** に毎分で登録:

```
/usr/bin/php8.x /home/サーバーID/agentcms/staging/current/artisan schedule:run >/dev/null 2>&1
```

PHP パスは doctor が検出したものを使う（素の `php` は旧版のため使わない）。

## 8. Update deployment

```bash
agentcms deploy staging
```

毎回: DB バックアップ → 新 release 展開 → migration → current 切替 → docroot 同期 → health check。health check 失敗時は自動で旧 release へ切り戻す。

### 本番へ押す（staging 以外の target）

既定の target は `staging`。本番は `.agentcms/deploy/production.json`（`"target": "production"` — **ファイル名と一致**させる）を作ったうえで、**明示しないと選べない**:

```bash
agentcms deploy plan     -t production          # まず何が起きるかを見る
agentcms deploy staging  -t production --confirm-target production
```

- **`-y` だけでは本番へ通らない。** `--confirm-target <target>` で target 名をもう一度書く（打ち間違い・古いスクリプトをそこで止める）
- サーバー側 `shared/.env` は `APP_ENV=production`（deploy が検査し、違えば中止する）
- health check の noindex は target で向きが変わる: staging は `Disallow: /` が**必要**、本番は noindex が**残っていないこと**を見る
- docroot に **agentcms が置いたものではない `index.php`** があると deploy は停止する（既存サイトを上書きしないため。ファイルの backup は取らない）。内容を確認して退避・削除してから再実行する
- `agentcms deploy rollback -t production --confirm-target production` も同じ規則

## 9. Rollback

```bash
agentcms deploy rollback   # current を直前 release へ + docroot 再同期 + health check
```

- **DB は自動では戻さない**。migration を含む rollback は `backups/<id>-db.sql.gz` から手動復元:
  `gunzip < backups/<id>-db.sql.gz | mysql -h localhost -u <user> -p <db>`
- カラム追加のみ等、後方互換 migration なら DB 復元なしで旧 release が動くことが多い

## 10. Verify / Lighthouse

```bash
agentcms verify staging    # health / PWA / セキュリティ / noindex / 露出チェック一括
```

Lighthouse は staging URL に対して実測し、ローカルの 100 点を保証値として扱わない:

```bash
cd template && pnpm lighthouse:staging   # LHCI_STAGING_URL=https://... を env で指定
```

低下した場合は TTFB / 圧縮 / キャッシュヘッダー / 画像を分析する。スコアのためにテスト条件を変えない。

## 11. PWA / Push 実機確認（手動 Acceptance）

iPhone / iPadOS（Home Screen Web App）:

1. iPhone の Safari で `https://<ドメイン>/login` を開きログイン
2. 共有シート → **ホーム画面に追加**
3. ホーム画面から起動（standalone 表示になること）
4. 管理画面 > 設定 > 通知 で通知を有効化（許可ダイアログで許可）
5. 別端末で公開サイトの Contact フォームを送信（Turnstile 突破が必要）
6. iPhone に Push が届くこと（**payload に氏名以上の個人情報が出ないこと**を確認）
7. Push をタップ → Contact 詳細画面へ遷移すること
8. 機内モードで PWA を起動 → offline フォールバックが表示されること

結果は自動テストと分けて `docs/benchmarks/phase-4.md` の手動確認欄に記録する。

---

## 12. 本番を公開する（通し手順）

**staging と本番の一番の違いは、間違いが人に届くこと。**
メールは実際の宛先へ飛び、ページは検索に載る。**押す前に止められる場所を全部通る。**

### 12-1. 準備

- [ ] 本番ドメインの DNS が XServer を向いている（レジストラ側の作業。**kit は関与しない**）
- [ ] 本番用の SSH 鍵を作る（staging と分ける）: `ssh-keygen -t ed25519 -f ~/.ssh/xserver_production -N ""`
- [ ] サーバーパネル > SSH設定 で公開鍵を登録し、疎通を確認する
- [ ] 無料SSL を発行する（`deploy setup` が API で行う。手動でも可）

### 12-2. 設定を作る

```bash
cp .agentcms/deploy/production.example.json .agentcms/deploy/production.json
```

- [ ] `"target": "production"` と**ファイル名が一致**していること（ずれると読み込みで落ちる）
- [ ] `app.url` に本番ドメインを書く
- [ ] 🔴 **`api.parentDomain` を確認する。** `example.co.jp` のような**2 段の公開接尾辞**は
      URL から自動では割り切れないことがある。**次の `setup` が読み上げるので、そこで確かめる**

### 12-3. サーバー側を作る

```bash
agentcms deploy setup -t production
```

- [ ] 🔴 **「サブドメイン ◯◯ を作成（親ドメイン: ◯◯ / docroot: ◯◯）」を読む。**
      **親ドメインが自分の契約ドメインでなければ、そこで止める**（`api.parentDomain` を書いて再実行）
- [ ] `shared/.env` が作られる。**中身を埋める**（下記）

### 12-4. `shared/.env` を埋める

`.agentcms/deploy/env.production.example` が対応表。**特に**:

- [ ] `APP_KEY`（`php artisan key:generate --show` の出力）
- [ ] `APP_TIMEZONE=Asia/Tokyo` が在る（無いと予約公開が 9 時間遅れる）
- [ ] `DB_*`（サーバーパネル > MySQL設定 で作った値）
- [ ] 🔴 **`MAIL_MAILER` を `log` にしない。** log は**1 通も届かないのに、エラーも出ない**
- [ ] 🔴 **`MAIL_FROM_ADDRESS` を実際に送れるアドレスにする。**
      空や `example.` は**受信側が弾く**（`smtp` でも届かない側に倒れる）
- [ ] `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD`
- [ ] `TURNSTILE_*`（本番キー）

### 12-5. 押す前に見る

```bash
php artisan site:readiness
agentcms deploy doctor -t production
agentcms deploy plan   -t production
```

- [ ] 🔴 **`site:readiness` が通る（exit 0）。**
      **公開面に「準備中」「仮のもの」「ダミー」等が残っていないか**と、
      **問い合わせの通知先が本物か**を、**実際にページを描いて**確かめる。

      **残っていても 200 で開くので、目で見ても気づけない。**
      2026-08-28、施工事例の詳細 4 枚に「ダミー」が出ていたことに、
      トップと一覧だけ見ていて気づいていなかった。

      落ちたら**どのページの何が残っているか**を表で出すので、埋めて再実行する。

- [ ] **doctor に FAIL が 1 つも無い。** とくに `mail` が PASS
      （FAIL のままだと**問い合わせが 1 通も届かない**）
- [ ] plan の内容が想定どおり

### 12-6. 押す

```bash
agentcms deploy staging -t production --confirm-target production
```

- **`-y` だけでは通らない。** `--confirm-target` で target 名をもう一度書く
- docroot に **kit が置いたものではない `index.php`** があると停止する（既存サイトを上書きしないため）

### 12-7. 🔴 デプロイ後に確かめる（**ここまでやって完了**）

**「デプロイが通った」と「使える」は別。** 通っただけでは、下は 1 つも保証されない。

- [ ] `/healthz` が 200
- [ ] 🔴 **叩く前に、コンパイル結果を捨てる。**

      ```
      php artisan view:clear && php artisan view:cache
      ```

      **捨てないと、古いコンパイル結果を見てしまう。**2026-08-28、
      「公開 URL すべて 200」と報告したが**壊れる前の結果を見ていた**。
      ソースは前から壊れていて、**施工事例の詳細が全滅していた**。
      **`view:cache` が通らなければ、そこで止める**（本番は必ずこれを通る）。

- [ ] **公開 URL を全部叩く**（トップ・会社情報・お知らせ・実績・お問い合わせ …）。
      **1 本ずつ。** 再 apply や prune で**別のページが落ちていることがある**
- [ ] **noindex が残っていない**（本番で `Disallow: /` が残ると、検索に一切載らない）
- [ ] `sitemap.xml` に **404 になる URL が載っていない**
      （**一覧だけでなく詳細も 1 件ずつ開く**。一覧が 200 でも詳細が 500 のことがある）
- [ ] 🔴 **`/contact` から実際に 1 通送る**
  - [ ] 受信箱に届く
  - [ ] **届かなければ、管理画面のお問い合わせ（またはフォームの受信）で
        `admin_notification_error` を見る** — 記録に残っていれば「送ろうとして失敗した」、
        何も無ければ「そもそも送っていない」（`MAIL_MAILER=log` を疑う）
  - [ ] **迷惑メールに入っていないかも見る**（差出人ドメインの SPF / DKIM は
        メールサーバー側の設定であり、kit は関与しない）
- [ ] 管理画面にログインできる
- [ ] **画面の文言が本番のものになっている** — `php artisan site:readiness` を
      **本番でもう一度**通す（12-5 と同じもの）。**本番の DB は staging と中身が違う**ので、
      staging で通っても本番で残っていることがある

### 12-8. 切り戻すとき

§9 を見る。**DB は自動では戻らない。**

---

## Troubleshooting

| 症状 | 原因と対処 |
| --- | --- |
| doctor: SSH FAIL | SSH設定 OFF / 鍵未登録 / 国外IP制限。ポートは 10022 |
| doctor: PHP FAIL | PHP Ver.切替が未設定。`remote.phpBin` に `/usr/bin/php8.x` を明示しても良い |
| deploy: shared/.env 検査で停止 | 手順 5 を実施。APP_ENV/APP_DEBUG/APP_KEY を確認 |
| deploy: DB バックアップ失敗 | DB 未作成 / shared/.env の DB_* 誤り。パネルの MySQL設定と突き合わせる |
| health: 500 | `~/agentcms/staging/shared/storage/logs/laravel.log` を確認 |
| health: アセット 404 | docroot 同期失敗。`agentcms deploy staging` を再実行 |
| / が XServer 初期ページのまま | DirectoryIndex は index.html が index.php より優先。deploy が初期生成ページを自動退避するが、ユーザー作成らしき index.html/htm/shtml/cgi があると停止する — 内容確認のうえ手動で退避・削除 |
| Media 画像が 404 | docroot の `storage` symlink を確認（`ls -l public_html/storage`） |
| メールが届かない | staging は全件 `MAIL_STAGING_REDIRECT_TO` 宛。log mailer フォールバック中は laravel.log に出力される |
