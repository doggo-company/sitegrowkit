# Phase 4 Benchmark — XServer Staging Deployment

- 実施日: 2026-08-08
- モデル: claude-fable-5（新規セッション / Phase 1〜3 の会話コンテキストなし）
- 状態: **Phase 4 完了 — DoD 20/20。** Staging 稼働 + Manual Acceptance（Contact 実送信 / iPhone 実機 PWA・Web Push）全項目成功（2026-08-09 01:53）
- Control Plane: XServer API（ADR-0009。setup で SSL/DB/Cron 等を自動化・手動 fallback あり）/ Deployment: SSH

## Definition of Done（20 項目）

| # | 項目 | 状態 |
| --- | --- | --- |
| 1 | XServer Provider Adapter | ✅ `deploy/providers/xserver.ts`（RemoteExecutor 境界越しのみ） |
| 2 | deploy doctor | ✅ 11 検査（PASS/WARN/FAIL + 何が/なぜ/どう直すか） |
| 3 | deploy plan | ✅ 秘密情報なしで target/release/artifact/env/migration/strategy/cron/backup/health を表示 |
| 4 | safe deploy artifact | ✅ dry-run 実測 9,067 files / 33.6MB（vendor + built assets 同梱） |
| 5 | secret exclusion | ✅ allow-list + 二重検査 + tar 実測で .env*・鍵 0 件（vendor CA bundle は正しく保持） |
| 6 | Staging environment strategy | ✅ shared/.env 単一置き場 + APP_ENV/APP_DEBUG/APP_KEY 事前検査で中止 |
| 7 | database migration safety | ✅ backup 成功が前提・current 切替前に実行・失敗時は旧 release 稼働継続・自動 DB 復元なし |
| 8 | backup | ✅ mysqldump + gzip -t + サイズ下限検証（存在だけで成功にしない）。失敗時 deploy 中止 |
| 9 | rollback strategy | ✅ current 切替 + docroot 再同期。DB は自動で戻さないことを明示（虚偽の「rollback 可能」を表示しない） |
| 10 | health check | ✅ 14 検査（TOP/healthz/admin/assets/Contact/PWA/セキュリティヘッダー/露出/noindex）。失敗時自動切り戻し |
| 11 | Cron / Queue strategy | ✅ DB Queue + schedule:run。パネル登録コマンド生成（自動変更しない） |
| 12 | HTTPS verification | ✅ **実測**: TLS 有効（SSL は API で active 確認）・TOP 200・secure cookie・HTTPS 強制 |
| 13 | Admin PWA verification | ✅ **実機完了**: standalone インストール / offline fallback / 通信復帰 / SW 更新（実機バグ 6 件を発見・修正して達成 — journal 参照） |
| 14 | Staging Contact verification | ✅ **実機完了**: 実 Turnstile 通過・DB 保存・管理者通知/自動返信 受信確認・staging リダイレクト実証・メール失敗時 DB 保持実証 |
| 15 | Lighthouse staging measurement | ✅ **実測**: 6 URL × desktop/mobile。perf 98–100 / a11y 100 / bp 96–100 / SEO 58–66（noindex による意図した減点。条件変更なし） |
| 16 | agentcms verify staging | ✅ **実測合格**（HTTP 外形検査 + laravel_root。実メール・実 Push は送らない） |
| 17 | XServer documentation | ✅ `docs/deployment/xserver.md`（prerequisites〜troubleshooting〜実機 Push 手順） |
| 18 | Phase 4 benchmark | ✅ 本書 + journal + metrics/phase-4.json |
| 19 | Fresh Session Analysis | ✅ 下記 |
| 20 | 既存 Phase 1〜3 テスト維持 | ✅ verify 9/9・Pest 107（+7）・E2E 41・Lighthouse local 全通過 |

## 実測値

| 指標 | Phase 3 | Phase 4（ローカル実装分） |
| --- | --- | --- |
| 所要時間 | 36 min | **36 min** |
| output tokens | 366,616 | **397,596**（+ 調査サブエージェント 58,127） |
| tool calls | 140 | **169** |
| Read（unique files） | 23 (22) | **39 (35)** |
| files changed | 47 | **28** |
| retry loops | 3 | **3** |
| human interventions | 0 | **0**（外部書き込み承認の停止は仕様どおりの設計点） |
| compactions | 0 | **0** |

品質ゲート: verify 9/9 / Pest 107 passed・2 skipped（StagingDeploymentTest 7 新規・初回グリーン）/ CLI vitest 36 passed（deploy 28 新規）/ E2E 41 passed / Lighthouse local mobile+desktop 全通過。

## Fresh Session Analysis

### Initial Context（最初に読んだもの）

CLAUDE.md（自動注入）→ `PHASE4_SPEC.md` → `.agentcms/architecture.md` / `rules/boundaries.md` / `commands.md` → `rules/{deployment,security,testing,coding}.md` → CLI ソース 6 ファイル（index/doctor/verify/ui/project/run）→ ADR-0003 → phase-3 metrics/benchmark 末尾。

### Files Needed Before Implementation

**17 ファイル**の Read で設計を確定し最初の Write に着手。総 Read 39 のうち残りは実装中の整合確認（routes / bootstrap / mailer / lighthouserc / 自作ファイルの Edit 前再読）。

### Existing Knowledge Reused（Kit からそのまま使えたもの）

- CLI の構造規約（commands/<name>.ts + ui.Failure「何が/なぜ/どう直すか」+ run.ts）→ deploy 系 5 コマンドが既存流儀に一発で整合
- `agentcms verify` / docker exec / pnpm e2e / lighthouse のコマンド一式 — 環境調査ゼロ
- 「pint → verify の順」「テストを弱めない」「Larastan 既知パターン」— format ゲート失敗ゼロ
- RecordedMailer / mail 抽象（Phase 1 の「transport をハードコードしない」設計）→ `Mail::alwaysTo` の staging 安全化が AppServiceProvider 1 箇所で成立
- PWA ルートパス・SW scope・セキュリティヘッダーの既存仕様 → health check 項目を実装から逆引きせず map で特定

### Provider-specific Exploration

XServer 公式仕様の調査はサブエージェントへ委任（58k tokens・並行実行）。SSH 10022/公開鍵のみ・php8.x バージョン付き CLI パス・Node 非サポート明言・Composer 未保証・docroot 固定・Cron はパネルのみ、を公式ソースで確認し、**未確認事項（symlink 運用の公式見解・rsync 可否）は推測実装せず** entry_stub 既定 + doctor 検出に落とした。

### Avoided Exploration（Kit により不要だった探索）

リポジトリ全体探索ゼロ。技術スタック・品質基準・モジュール規約・E2E/Lighthouse の実行方法・メール/Push の安全方針はすべて `.agentcms/` と ADR から取得。Phase 1 比で「どこに何があるか」の探索コストがほぼ消えた。

### Deployment Failures（実サーバーで発生した問題）

未計測（実デプロイ未実施）。ローカルで発生した環境系失敗は Docker ファイル同期遅延による composer ENOENT の 1 件（vendor 非コピー方式へ設計変更して恒久回避）。

### Rules to Add（一般化できる知見）

- `rules/deployment.md` を Phase 4 仕様へ全面更新（Provider 境界 / artifact allow-list / 秘密情報の置き場 / DB 復元は人間判断）
- vendor 内 `.pem`（CA bundle）を秘密情報として除外してはいけない — 一般則として artifact ルールへ明文化
- Provider 固有知識（XServer のパス・パネル手順）は Core ルールに混ぜず `docs/deployment/xserver.md` と ADR-0008 に隔離

## 手動確認記録（実機）

| 項目 | 結果 |
| --- | --- |
| deploy doctor（実 XServer） | ✅ 最終実測 23:45 — **全 PASS**（MySQL 実接続含む）。PHP CLI 8.5.5 / Web PHP 8.3.30 / Composer 1.10.10 プリインストール確認 |
| deploy setup（Control Plane） | ✅ 実測 — subdomain / SSL(active) / DB / ユーザー / grant / shared/.env / Cron を API + SSH で自動整備。冪等性・修復経路（DB_HOST / パスワード再設定）を実地実証 |
| XServer への実デプロイ | ✅ **release 20260808234128 稼働**（3 回目で成功。1 回目 DB_HOST・2 回目 composer platform で安全停止 → 根本修正） |
| staging URL / HTTPS | ✅ https://staging.example.com（TLS 有効・health 17 項目 PASS・verify staging 合格） |
| rollback point | **healthy rollback point = 20260808234128（Phase 4 初）**。previous 20260808225052 は既知の 500 のため「正常復旧可能な rollback point」とは扱わない |
| Lighthouse staging (desktop/mobile) | ✅ 実測 — perf 98–100 / a11y 100 / bp 96–100 / SEO 58–66（noindex 由来・意図どおり） |
| Contact 実送信 / 実メール | ✅ 実機完了（実 Turnstile・DB #1〜#9・メール 2 通受信・失敗時 DB 保持を SMTP 450 で実証） |
| iPhone Home Screen PWA + 実 Push 受信 | ✅ 実機完了（standalone / web.push.apple.com subscription / Push 受信 / **タップ→Contact 詳細遷移** / offline / 復帰） |
| Turnstile ウィジェット | Cloudflare API で自動作成（キーは shared/.env へ直接設定・非表示） |
| 実機で発見・修正した kit バグ | 6 件（DirectoryIndex 誤認識は事前レビュー由来。詳細 journal）: iOS navigate 無効 / manifest scope 罠 / login に manifest なし / **current symlink 経由 boot による OPcache 衝突（realpath 化）** / SW 更新滞留（update()+skipWaiting）/ iOS SW ナビ API 全滅 → Cache API 遷移方式 |

## Phase 5 へ引き継ぐ事項

- 実デプロイ実施後、本書の「未実施」欄と metrics の dod_status を実測値で更新すること
- staging Lighthouse は noindex により SEO の is-crawlable が意図的に落ちる（既知・正常。スコアを整えるための条件変更をしない）
- Production deploy は未実装（DO NOT BUILD）。実装時は `deploy staging` の workflow を target 汎化し、destructive migration の自動実行禁止を強制すること
- 他 Provider（Lolipop/Sakura/generic SSH）は `providers/<name>.ts` 追加 + doctor 検査差し替えで載る構造
