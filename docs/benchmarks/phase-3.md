# Phase 3 Benchmark — Admin PWA + Web Push

実測値: `.agentcms/metrics/phase-3.json` / 時系列: `phase-3-journal.md`

Phase 3 の主目的は機能実装に加えて、**会話コンテキストを持たない新規セッションの AI が、Agent CMS Kit（CLAUDE.md + .agentcms/）だけを頼りに無駄な再探索なしで業務品質の機能を追加できるか**の検証。

## Definition of Done — 15/15

| # | 項目 | 結果 | 検証方法 |
| --- | --- | --- | --- |
| 1 | Web App Manifest | ✅ | E2E: name/short_name/start_url/scope/display/theme/background/icons(192·512·maskable) + 実体 200 |
| 2 | Service Worker | ✅ | E2E: `/admin/sw.js` 配信ヘッダー（`Service-Worker-Allowed: /admin`・no-cache）+ 実ブラウザで scope `/admin` 登録 |
| 3 | installable 構成 | ✅ | manifest 必要条件 + SW fetch handler + standalone。localhost = secure context で Chrome インストール可 |
| 4 | Notification permission UX | ✅ | 権限要求は「通知を有効にする」ボタン起点のみ。初回表示での自動ダイアログなし。iOS 向け「ホーム画面に追加」案内あり。非対応環境でも管理画面は正常動作 |
| 5 | Push subscription 登録 | ✅ | E2E: API 201 + upsert。Pest: 複数端末/認可/バリデーション 7 tests |
| 6 | User notification settings | ✅ | E2E: 種別 OFF→保存→リロード後も保持→ON 復帰。type は enum 単一情報源 |
| 7 | Contact 新規 Push フロー | ✅ | Pest: 保存後に title/body/URL 付き Push（本文・メールアドレスは payload に含めない） |
| 8 | Email / Push 独立 | ✅ | Pest: Email 例外でも Contact 保存 + Push 2 通（新規 + メール失敗通知）。Push 例外/失敗でも Contact 保存・メール送信に影響なし |
| 9 | Invalid subscription handling | ✅ | Pest: 404/410（isSubscriptionExpired）→ 削除・再試行なし。一時的エラー → 保持 |
| 10 | Security | ✅ | 認証必須（未認証 401/419）・本人スコープ削除（他ユーザー endpoint 無効果を Pest 検証）・VAPID 秘密鍵は .env のみ・endpoint 非ログ・SW は認証を迂回しない（HTML/API 非キャッシュ） |
| 11 | E2E | ✅ | 41 passed（既存 30 + 新規 11）。下記「E2E 15 項目の分担」 |
| 12 | Lighthouse | ✅ | mobile + desktop × 7 URL 全アサーション通過。公開サイトに manifest/SW を出さないことを E2E で確認（劣化ゼロ） |
| 13 | agentcms verify | ✅ | 9/9 通過 |
| 14 | Phase 3 Benchmark | ✅ | 本ファイル + journal + metrics/phase-3.json |
| 15 | Fresh Session Analysis | ✅ | 下記 |

## E2E 15 項目の分担（実 Push の限界を隠さない）

仕様の E2E 15 項目のうち、**ブラウザへの実 Push 受信は CI で安定再現できない**ため、仕様の指示どおり `PushTransport` 境界で分離した。

| 仕様項目 | Playwright | Pest |
| --- | --- | --- |
| 1-4 manifest / SW 登録 / PWA 条件 / 通知設定画面 | ✅ 実ブラウザ | — |
| 5 subscription 登録 API | ✅ | ✅ |
| 6 認証なし登録拒否 | ✅ (401/419) | ✅ (401) |
| 7 他ユーザー subscription 操作拒否 | —（シードユーザーが 1 人のため） | ✅ 所有者スコープ検証 |
| 8 preference 保存 | ✅ リロード後保持 | ✅ |
| 9-11 Contact 送信 / DB 保存 / Email 通知 | ✅（既存 contact.spec: Mailpit 2 通） | ✅ |
| 12 Push 送信処理呼び出し | 間接（下記 13 経由） | ✅ Fake で payload まで検証 |
| 13 Push 失敗でも Contact 残存 | ✅ **実 Transport**（到達不能 endpoint 登録 → フォーム送信 → 一覧に残存） | ✅ |
| 14 Email 失敗でも Push 独立 | — | ✅ |
| 15 無効 subscription 処理 | — | ✅ |

**実 Push で未検証の範囲**: Push Service（FCM 等）経由の実受信・通知タップ → Contact 詳細遷移・SW の push/notificationclick ハンドラ実行。下記の手動確認手順で補う。

## Manual Acceptance（ローカル実端末確認手順）

1. `.env` に VAPID キー設定（`php artisan notifications:vapid-keys`）→ `agentcms dev`
2. Chrome (PC) で `http://localhost:8180/admin` → ログイン → アドレスバーのインストールアイコン、または通知設定ページの「インストール」から PWA をインストール
3. 通知設定 → 「通知を有効にする」→ ブラウザの許可ダイアログで許可
4. 別ブラウザ（シークレット等）で `http://localhost:8180/contact` からお問い合わせ送信
5. Push 通知「新しいお問い合わせ — {名前}さんからお問い合わせが届きました」を受信 → クリック → 該当 Contact 詳細画面が開く

制約（明記）: localhost は Chrome では secure context としてフル動作するが、**iOS / iPadOS 実機は HTTPS 必須**（localhost 不可）のため、iOS の実機確認は HTTPS を持つ環境（Phase 4 以降のデプロイ先）で行う。本番接続は行っていない。

## 実測サマリー

| 指標 | Phase 1 (fresh・kit なし) | Phase 2 (継続セッション) | **Phase 3 (fresh・kit あり)** |
| --- | --- | --- | --- |
| 作業時間 | 67 min | 22 min | **36 min** |
| output tokens | 667,968 | 169,450 | **366,616** |
| tool calls | 357 | 84 | **140** |
| Read 回数 | 13 | 2 | **23**（22 ユニーク） |
| retry loops | 13 | 4 | **3** |
| test 失敗 | 2 | 0 | 1（実装順序どおりの想定内） |
| E2E 失敗 run | 4 | 0 | 2（テスト自体の競合、アプリ欠陥ゼロ） |
| human interventions | — | 0 | **0** |
| compactions | — | 0 | **0** |

## Fresh Session Analysis

### Initial Context（最初に読んだもの）

CLAUDE.md（自動注入）→ `PHASE3_SPEC.md` → `.agentcms/architecture.md` → `rules/boundaries.md` → `rules/{coding,security,testing,deployment}.md` + `commands.md` → `context/map.md` → `ADR-0005` → `ContactController` / `RecordedMailer` → `routes/admin.php` / `AppSidebar.vue` → `metrics/phase-2.json`。

### Files Needed Before Implementation

**15 ファイル**の Read で設計（ASSUMPTIONS 5 点）を確定し最初の Write に着手。総 Read 23 のうち残り 8 は実装中のパターン確認（テスト規約・UI コンポーネント API・E2E セレクタ）。

### Repository Exploration

無目的な全体探索は**ゼロ**。構成把握は `ls`/`find`/`grep`（対象を名指し）のみで、「とりあえず読む」Read はなかった。CLAUDE.md の Project Map と `.agentcms/context/map.md` が「どこに何があるか」を直接解決した。

### Existing Knowledge Reused（.agentcms からそのまま使えたもの）

- コマンド一式（`agentcms verify` / docker exec 系 / `pnpm e2e` / ports）— 環境調査ゼロ
- Contact pipeline の順序固定 + 「メール失敗でもデータを失わない」原則（ADR-0005）→ Push にそのまま拡張
- モジュール規約（PSR-4 / migrations 自動ロード / tests 配置 / composer.json 編集不要）→ Notifications モジュールが一発で機能
- テストパターン（FakeVerifier swap / User::factory / RefreshDatabase）と E2E パターン（storageState / Turnstile 待機 / セレクタ）
- 「pint → verify の順」ルール → Phase 2 で起きた format ゲート失敗を回避

### Missing Knowledge（Kit に不足していた情報 → 今回補充）

1. **PHP 8.5 という実行系バージョンの含意**が明文化されておらず、web-push v8 の古い依存（safe v2）が全 HTTP レスポンスへ警告を混入する問題を踏んだ → `rules/coding.md` に Dependencies ルール追加
2. **非コンテンツ型機能の置き場所**の明文規定なし（Media の前例から推論で解決）→ ADR-0007 に明文化
3. SW scope のトレイリングスラッシュ罠（`/admin/` だと start_url `/admin` が scope 外）→ ADR-0007 に記録
4. Admin フロントエンドの API 詳細（Checkbox / flash toast）は registry になく都度ソース確認（公開側 registry しか存在しない）— 実害小のため registry 拡張は見送り

### Avoided Decisions(既存ルールで判断不要だった内容）

技術スタック選定・メール transport・テスト構成・品質基準（Lighthouse 100 目標）・認証方式（Fortify only / custom auth 禁止）・公開サイト CSS 方針。設計判断は「Phase 3 固有の 5 仮定」だけで済んだ。

### Avoided Failures（Phase 1/2 の知識で防げた失敗）

- 導出状態の永続化（`notification_state` 相当のカラムや revoked フラグを持たない）— ADR-0005 の 2 軸分離原則の再適用
- pint 未実行での verify 失敗（Phase 2 で 1 回発生 → 今回ゼロ）
- 公開サイトへの Tailwind / SW 混入
- Larastan の既知パターン群（記録済みのため再発見コストゼロ）

### New Waste（今回新しく発生した無駄）

- web-push v8 経由の回り道（composer が古い major を解決 → 調査 + 差し替えで約 4 ツールコール）
- E2E の保存競合デバッグ 2 ラウンド（アプリ欠陥ではなくテストの書き方）
- `pnpm e2e -- pwa.spec.ts` がフィルタとして効かず全 spec 実行（実害なし・時間微増）

## Rule / Skill 改善

- **追加**: `rules/coding.md` Dependencies 節（composer major 解決の確認）/ `rules/security.md` Web Push 節（VAPID・payload privacy・endpoint 非ログ・無効 subscription）/ ADR-0007 / `context/map.md` に PWA・Notifications の行 → `sync-agents` で CLAUDE.md / AGENTS.md 再生成済み
- **「新しい Schema field type を追加する手順」の Skill 化評価**: **見送り**。Phase 3 は schema field type を一切触らず、使用機会のない手順書は検証不能なまま腐るリスクが利益を上回る（rules/coding.md の「Schema first」+ registry で現状は足りている）。次に field type 追加が実際に発生する Phase で、その作業自体を使って書き起こし・検証するのが正しいタイミング。

## Phase 4 へ引き継ぐ事項

- iOS 実機での Push 受信確認は HTTPS 環境が前提（デプロイ Phase で実施）
- 通知種別の拡張（backup/deploy/security/update/review）は `NotificationType` に case 追加のみで可能な構造を維持すること
- ユーザー種別（クライアント / 制作者）での通知項目出し分けは `visibleTypes()` が拡張点
- Push の非同期化が必要になったら Phase 1 の Database Queue + Cron に載せる（現在は同期・ベストエフォート）
- E2E の `pnpm e2e -- <file>` はフィルタにならない（`pnpm e2e <file>` or `--grep`）
