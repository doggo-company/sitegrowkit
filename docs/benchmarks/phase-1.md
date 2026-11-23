# Phase 1 Benchmark（Baseline 確定）

Phase 1 開発そのものを Agent CMS Kit の最初のベンチマークとして記録した。
将来の「Vanilla Laravel vs Agent CMS Kit」比較（[../BENCHMARK.md](../BENCHMARK.md)）の Baseline。

- 開始: 2026-08-08 02:45:48 JST / 終了: 2026-08-08 03:52:55 JST（約 67 分）
- モデル: claude-fable-5（Claude Code、切り替えなし）
- 機械可読データ: [.agentcms/metrics/phase-1.json](../../.agentcms/metrics/phase-1.json)
- 逐次記録: [phase-1-journal.md](phase-1-journal.md)

---

## Definition of Done 判定

仕様書に明示的な DoD セクションが無いため QUALITY GATE + 成果物要件から導出した（ADR-0002）。
判定根拠はすべて実際に実行した検証コマンドの結果。

### 成果物

- [x] D1. docs/PRODUCT.md / ARCHITECTURE.md / ROADMAP.md / SECURITY.md / BENCHMARK.md / docs/adr/（ADR-0001〜0005）
- [x] D2. .agentcms/ SSoT（architecture / commands / boundaries / coding / security / deployment / testing / registry×2 / context/map）— ルール本文の重複コピーなし
- [x] D3. CLAUDE.md / AGENTS.md は `agentcms sync-agents` で .agentcms/ から生成（直接編集禁止ヘッダー付き）
- [x] D4. キット本体（packages/cli, examples/）と生成される CMS プロジェクト（template/）を分離。`agentcms create` で新規案件生成を実測確認
- [x] D5. Docker（php8.5-fpm / nginx / MySQL 8.4 / Mailpit）で稼働。ホスト PHP/MySQL 不要（CLI は Node のみ要求）
- [x] D6. Laravel 13.24 + 公式 Vue Starter Kit。Fortify による authentication / password reset / 2FA / login rate limiting（独自認証なし。rate limit は E2E 中に 429 を実測）。公開登録は無効化し管理者は Seeder 作成
- [x] D7. Core（template/core = agentcms/core path package）/ Project（app + modules + content/schemas）分離。境界は ARCHITECTURE.md と .agentcms/rules/boundaries.md に明文化。拡張は SchemaModel / TurnstileVerifier 等の公開契約経由
- [x] D8. Content Schema（content/schemas/*.json）が管理画面 form / list / validation / model casts の唯一の定義源（SchemaRegistry / SchemaRules / SchemaResourceController / SchemaFieldInput）。Schema⇔テーブル整合はテストで機械検証
- [x] D9. 公開 5 ページ（TOP/ABOUT/SERVICE/WORKS/CONTACT）+ News/Works 一覧・詳細を Blade SSR + プレーン CSS で実装（公開側 Tailwind 不使用、Vue は不使用・JS は Turnstile のみ）
- [x] D10. Contact パイプライン: Validation → Turnstile siteverify（サーバー側必須）→ DB 保存 → 管理者通知 → 自動返信 → 結果記録。**メール失敗でもデータを失わないことをテストで証明**（ContactPipelineTest::test_mail_failure_does_not_lose_submission）。状態は 2 軸分離（ADR-0005）。dev/test は Cloudflare 公式テストキー
- [x] D11. CLI: create / doctor / dev / stop / test / verify / template inspect / sync-agents（Node/TS、Bash 非依存、Mac/WSL2 想定）。エラーは「何が・なぜ・どう直すか」を構造化表示（doctor / dev / verify で実装）
- [x] D12. template inspect: 決定論的解析 → Manifest（pages / assets / css / js / external / shared_candidates / forms / repeats）。examples/html-template で検証、vitest 8 件 + スナップショットで決定論性を担保
- [x] D13. Preserve First 原則を .agentcms/rules/coding.md に明文化（Tailwind 変換禁止 / クラス名変更禁止 / 勝手な改善禁止 / 不要な Vue 化禁止 / Manifest 先読み）

### 品質ゲート（実行結果）

- [x] Q1. Pest: **66 passed / 2 skipped**（282 assertions。skip は無効化した登録機能の Fortify テスト = 正しい挙動）
- [x] Q2. Pint: PASS
- [x] Q3. PHPStan (Larastan) level 7（core/ modules/ 含む）: **0 errors**
- [x] Q4. vue-tsc: PASS（e2e テスト含む）
- [x] Q5. ESLint: PASS
- [x] Q6. vite production build: PASS
- [x] Q7. Playwright E2E: **25 passed**（管理 CRUD・Contact 実送信・Mailpit 実受信・Desktop/Mobile スクリーンショット取得）
- [x] Q8. broken-link check: PASS（全ページの内部リンク + sitemap 全 URL を 200 確認、E2E 内で実行）
- [x] Q9. Lighthouse CI: **全 6 ページ × Desktop/Mobile × 4 カテゴリすべて 100**（スコア操作目的の実装なし）
- [x] Q10. Performance Budget: lighthouserc に定義（script 100KB / css 30KB / doc 50KB / total 500KB / third-party 5 件）し assert 済み
- [x] Q11. Secret scan: PASS（Git 管理下に .env / 鍵ファイル / 高確度シークレットパターンなし。verify のゲートとして常時実行）
- [x] Q12. composer audit / pnpm audit --prod: 脆弱性 0

### ベンチマーク

- [x] B1. 実測トークン（transcript usage）で記録。exact_token_usage_available: true
- [x] B2. Token Waste Analysis（下記）

**DoD: 28 / 28 達成。**

---

## Final Metrics

| Metric                | Result |
| --------------------- | ------ |
| Development duration  | 67 分（2026-08-08 02:45 → 03:52 JST） |
| Model(s) used         | claude-fable-5（切り替えなし） |
| Total tokens          | 135,508,816 |
| Input tokens          | 1,122（非キャッシュ） |
| Output tokens         | 667,968 |
| Cache read tokens     | 133,710,658 |
| Cache creation tokens | 1,129,068 |
| Sessions              | 1（Phase 1 作業。事前セットアップの別セッション 1 は対象外として記録） |
| Files read            | Read ツール 13 回（＋Bash 経由の部分読み取り多数。行数は N/A） |
| Files changed         | 452 ファイル（Git 管理、最終時点）/ commit 7 回 |
| Test runs             | Pest 6 + vitest 1 + Playwright 6 + Lighthouse 1 = 14 |
| Failed tests          | Pest 2 件（Carbon 型アサーション誤り・CSRF テスト手法誤り）+ E2E 失敗ラン 4 回 |
| Build failures        | vite 1（wayfinder register 消滅）+ Docker image 1（intl 拡張） |
| Retry loops           | 13（同一エラー 3 回以上の繰り返しなし） |
| Context compactions   | 0 |
| Human interventions   | 0（/goal 以降、確認・修正依頼なしで完走） |

トークンの実測方法: Claude Code セッション transcript（.jsonl）の assistant メッセージ usage フィールドを合算。ツール呼び出しは 357 回（Write 143 / Bash 111 / Edit 49 / Read 13 / Task 系 39 / WebSearch 1 / ToolSearch 1）。

---

## Token Waste Analysis

### Token Usage

- 大半は **cache read（133.7M）**。563 メッセージ × 長大化するコンテキストの再読が支配的で、実際の生成は output 668k
- output の主な内訳: PHP/Vue/TS/Blade/CSS 約 140 ファイルの新規作成、設定ファイル、ドキュメント・ADR・ベンチマーク記録、テストコード

### Avoidable Waste

失敗として正直に記録する（成功に整形しない）:

1. **モジュールファイルの自己削除**（最大の無駄）: macOS の大文字小文字非区別 FS で `mkdir News` + `rm -rf news` により、直前に書いた 7 ファイルを自分で消し、全て書き直した。PSR-4 マッピングとディレクトリ構成の突き合わせを書く前にしなかったのが根本原因
2. **Docker ビルドログの切り詰め**: 初回 `dev` のログを `tail` パイプで保存したためエラー本文が失われ、ビルドをもう一度回した
3. **Welcome.vue の二段階廃棄**: register リンクを手修正した直後にファイルごと削除が必要と判明
4. **E2E の 4 回の失敗ラン**: webkit 未インストール / nginx バッファ / タイムゾーン / rate limit。各 1 回で解決したが、既知の環境前提（Inertia のヘッダーサイズ、JST 運用）が最初から Kit にあれば 0 回にできた
5. 仕様ファイル名 typo（PHASE1_SPEC ≠ PHASE1_SPE）による Read 1 回の空振り

### Useful Context

- starter kit の composer.json / package.json / fortify 設定 / 既存テストの事前確認は、認証・品質ゲートの再発明を防ぎ明確に元が取れた
- Packagist API での dev-main 要件確認（Laravel 13 対応の確証）
- `.agentcms/context/map.md` を自分で作った後は、リポジトリ全探索を一度もしていない

### Agent CMS Opportunity

この 67 分のうち、Kit が既に存在すれば丸ごと消えるのは:

- 環境構築・Docker 調整・ポート/バッファ/TZ の罠（≒ 20 分、失敗ランの大半）
- 管理 CRUD / Contact パイプライン / セキュリティヘッダーの実装（Schema 定義だけで済む）
- 品質ゲートの配線（Playwright/LHCI/PHPStan/verify 一式）

残るのは「コンテンツタイプの定義」「公開デザイン」「文言」だけで、これが本製品の価値仮説（トークンを環境構築とやり直しで浪費しない）の初期エビデンスとなる。

### Rules to Add

今回の失敗から Kit へ追加すべきもの:

1. **rules/coding.md**: 「ファイル配置の前に autoload マッピングと突き合わせる」「大文字小文字非区別 FS でのリネームは一時名経由 or git mv 必須」→ 追記済み候補（Phase 2 で反映）
2. **CLI**: dev / build の全ログを常にファイルへ保存し、失敗時にパスを提示する（tail 切り詰め事故の再発防止）
3. **テンプレート既定値**: nginx fastcgi buffer / APP_TIMEZONE / ポート既定を今回の修正込みで保持（済み — 以後の案件では発生しない）
4. **E2E 雛形**: storageState ログインを最初から同梱（rate limit と共存）（済み）
5. **doctor**: ポート検査は接続ベース（済み）。Playwright ブラウザの導入検査を追加する（Phase 2）
