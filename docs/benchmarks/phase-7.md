# Phase 7 Benchmark — Productization, Distribution & First-Run Experience

**Baseline:** `v0.6.1-phase6.1-real-world` (4109131)
**Final Status:** **PASS**
**Research Question:** 初めて Agent CMS Kit を受け取ったユーザーが、supported environment で「受け取り → prerequisite 確認 → project 作成 → local 起動 → AI Agent Context 準備完了」までを 30 分以内・undocumented manual intervention なしで完了できるか。

## 結果サマリ

| Acceptance | 環境 | 結果 | 備考 |
| --- | --- | --- | --- |
| Baseline A（v0.6.1） | macOS / warm cache | **PARTIAL** | 到達はしたが source 読解 3 件・undocumented recovery 5 件・BLOCKER 3・SECURITY BLOCKER 1 |
| Productized B RC1 | macOS / warm cache | **PASS** | 7/7 目標・失敗 0・source 読解 0 |
| Windows RC1 | Windows 11 + WSL2 実機 | **FAIL** | 3 release blockers（下記）— 履歴として保持 |
| Windows RC2（generic fixes 後） | Windows 11 + WSL2 実機 | **PASS** | 12 コマンド・failures 0・96 秒で working project |
| **Phase 7 Final** | — | **PASS** | |

## 主要指標（Baseline A → Productized B/RC2）

| 指標 | Baseline A | macOS RC1 | Windows RC2 |
| --- | --- | --- | --- |
| 最終状態 | PARTIAL | PASS | PASS |
| undocumented source reads | 3 | 0 | 0 |
| undocumented manual recovery | 5 | 0 | 0 |
| BLOCKER / SECURITY BLOCKER | 3 / 1 | 0 / 0 | 0 / 0 |
| 失敗コマンド / リトライ | 5 / 4 | 0 / 0 | 0 / 0 |
| human interventions | 0（判断要 3） | 0（判断要 3・軽微） | 0 |
| generated AI Context | なし（最大の欠陥） | READY | READY |
| verify | 手動 git init 後 9/9 | 9/9 | 9/9 |
| total elapsed（warm） | 6m28s | 2m53s | 12m30s（total）/ 96s（working project） |
| host PHP | 暗黙依存あり | — | **不要（実測）** |
| root-owned host artifacts | — | 0 | 0 |

> **速度の解釈に関する注意**: すべて warm cache（Docker layer / pnpm / composer）での計測であり、true cold-cache first-run は未計測。Phase 7 の PASS 根拠は速度ではなく **undocumented knowledge / blockers / manual intervention / reverse-engineering をゼロにしたこと**にある。

## Windows RC1 の 3 Release Blockers（FAIL 履歴・修正済み）

1. **pnpm version nondeterminism** — version pin が無く、環境の pnpm 11 が build 承認で hard fail（`ERR_PNPM_IGNORED_BUILDS`）。→ `packageManager: pnpm@10.33.0` pin + `onlyBuiltDependencies` 明示 + doctor の version 不一致検出 + 正確な診断 + packaging の generic placeholder scan
2. **WSL2 uid/gid bind-mount permission** — host uid 1000 vs container uid 82 で storage/ 書き込み不可 → HTTP 500。→ `HOST_UID`/`HOST_GID` build args で PHP-FPM を host user 実行、exec の `--user` 統一（root-owned files 0）、dev/doctor の container 書き込み検査（chmod 777 不採用）
3. **undeclared host PHP dependency** — vite build の wayfinder plugin が host php を要求し Product Contract と矛盾。→ 生成を Docker PHP runtime 内で実行、build は host php 不在時に plugin 自動スキップ（macOS で php 隠し検証済み: dev + verify 9/9）

## Definition of Done 達成状況（要点）

- 配布: `.tgz`（npm install -g）+ Release Bundle zip + SHA256SUMS + outer `.zip.sha256` + release-manifest（version / minimums は実 constraint 由来）
- 除外・スキャン自動化: 禁止パス / secret / client identifier / 未解決 placeholder → 全 577 ファイル通過（導入時に実リーク 2 件を検出・修正した実効性あり）
- Version SSoT: `VERSION` = CLI package = `agentcms --version`、project へ `kit_version` / `template_schema_version` / `agent_context_version` 記録（git 非依存）
- Golden Path: `doctor → create → dev` で public 200 / login 200 / DB ready / frontend build / AI Context READY（health check 通過が成功条件）
- Security: 共有 default 管理者パスワード廃止（project ごとに secure random、`.agentcms/local/` 0600・gitignored・AI context 対象外）
- Agent Context: canonical `.agentcms/` → 決定論的変換 → template → project → sync-agents（二重 SSoT なし・drift 検証あり）
- Isolation: compose identity / volume / port の project 固有化（別 project の暗黙再利用なし — 実測で新 volume 生成を確認）
- Update: 契約（所有権・version 可視性・サイレント上書き禁止）と手動手順を docs/UPGRADING.md に定義（自動 updater は非スコープ — SPEC Non-Goal 準拠）
- Platform: macOS（Apple Silicon）+ Windows 11 WSL2（WSL 2.6.3 / Ubuntu 24.04 / Docker Desktop 4.65 / x86_64）の実測範囲を Verified として文書化（全環境保証はしない）

## Remaining Known Limitations（PASS を妨げない）

1. true cold-cache first-run timing 未計測（warm cache 実測のみ）
2. multi-project concurrent dev の完全 acceptance 未実施（port 自動選択の実働は確認済み）
3. deploy / Figma / Template Import は今回の Productization Acceptance 対象外（Phase 4-6.1 の検証状態を維持）
4. browser UI 操作の検証は macOS 側中心。Windows は HTTP/curl 主体
5. doctor の self-project port warning がやや noisy（自 project 稼働中も注意表示）
6. Composer PSR-4 notices が cosmetic に残る
7. Phase 6.1 からの既知制限（mobile visual 数値目標・crop transform CSS 等）は据え置き

## 成果物

- Release: `agent-cms-kit-v0.7.0-rc2.zip`（+ `.zip.sha256`）— Windows RC2 acceptance 証拠
- 提案 tag: `v0.7.0-productization`（human 承認後）
