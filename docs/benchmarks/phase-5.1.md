# Phase 5.1 — Real World Acceptance Test

Phase 5 で完成した Template Import Pipeline を、開発時に一度も使っていない実制作 HTML
（実制作サイトのデザインモック 9 ページ + 作業ファイル群）へ適用した受け入れテストの実測記録。
原本は Read Only。client 固有の生成物（manifest / 生成 Blade / seed / assets）は kit にはコミットしない
— kit に残すのは一般化された Import 機構と本ベンチマークのみ。

## Journal（時系列）

| 時刻 (JST) | イベント |
| --- | --- |
| 07:56 | Fresh session 開始。隔離ワークスペースで inspect → plan（Phase 5 パイプライン無改修） |
| 〜08:40 | 発見 7 問題を一般化して修正、`fix: harden template import for real-world projects`（6f4fcd7） |
| 09:30 | 承認済み判断を反映した fresh plan → `template apply --force`（27 ファイル） |
| 〜10:30 | apply 実測で追加の実案件問題を発見・一般化修正（standalone / CSP 外部化 / schema override 等）、再 apply 計 6 回（全て冪等） |
| 〜01:30 | 検証一式: Pest / vitest / E2E / verify / template verify / Visual A/B / Lighthouse A/B |
| 〜02:30 | Project isolation（旧 import の prune）実装・実測、責務分離コミット |

## Source / Manifest 実測（Manifest First）

| 項目 | 値 |
| --- | --- |
| 対象 HTML（entry） | 2,765 行 / 89,519 bytes（CSS 2,139 行が inline `<style>`、JS も全て inline） |
| HTML 全体 | 9 ページ + 空 index.html / 5,354 行 / 172KB |
| ソースディレクトリ全体 | 60 ファイル / 42.9MB（psd 34MB・打合せ議事録 docx・py スクリプト・ai/af・bak が同居する実案件の作業ディレクトリ） |
| manifest | 1,688 行 / 47.7KB = 対 HTML bytes 27.8% / 対ソース全体 0.11% |
| inspect / plan 実行時間 | 0.12s / 0.08s（決定論・LLM 不使用） |
| plan 判断までの原 Source 直接 Read | **0 件**（分類・CMS マッピング・除外判断はすべて manifest のみで実施） |
| apply 実装時の直接 Read | 詳細ページ 4 + news/contact + entry の構造部分 — substitution 設計に必要な DOM 詳細は manifest の守備範囲外（生成は決定論コードが source を読む設計） |

## Real World で発見した問題と一般化修正（10 件）

| # | 発見（改修前の挙動） | 一般化修正（改修後） |
| --- | --- | --- |
| 1 | 0 byte の index.html が warning なしで home に [CONFIDENT] マッチ（空トップページ化） | 実質空 HTML をページ候補から除外 + `empty-page` warning。`--entry` で home 明示指定、単一 HTML ファイル inspect 対応 |
| 2 | 「source 内 static asset を広く public へコピー」する方式が、psd / 議事録 docx / py / ai / bak まで公開対象化（情報漏えい） | Reference-Based Asset Allowlist（参照済み + Web 配信形式のみ。除外 34 件を理由付きで `assets.excluded` に記録）。security rule に MUST 化 |
| 3 | 共通 header 非抽出ソースで layout 方式だと nav / inline CSS / inline JS / main 外 DOM が全損 | standalone（preserve）モード — 全文書パススルー + CMS スロット注入のみ |
| 4 | Core 公開 CSP が inline `<script>` とハンドラ属性を全ブロック（nav・slider・reveal・フォント loader が全停止） | inline script の外部ファイル化（コード無変更・同位置）+ `onclick` 等の addEventListener 委譲（`return false` → preventDefault 写像） |
| 5 | inline script 内の動的組み立て外部 URL（Typekit）が external 検出から漏れ、CSP でフォントがブロック → 全ページ縦ズレ | inline script 内 URL 検出（既知カテゴリのみ）+ adobe-fonts CSP セット（script/style/font/connect/img） |
| 6 | Media 化した画像が inline CSS の background からも参照されており 404（dual-use） | `css_referenced` 追跡（inline `<style>` の url() 含む）+ dual-use asset の static 併存コピー |
| 7 | 原デザインに無い works.category（必須 + NOT NULL）が admin 編集を 500 にする | Project Scope Schema Override 機構（`content/schemas/overrides/<id>.json` を Core SchemaRegistry がマージ + optional 化 null の空文字境界処理）。Core schema は不変 |
| 8 | 静的モックフォーム（method=get / `onsubmit="return false"` / disabled submit / subject select なし）を接続できない | contact 候補判定の拡張 + モック属性の接続時解除 + server-side constant mapping（hidden input なし・schema 変更なし） |
| 9 | 見た目の繰り返り（sticker / silhouette / 装飾）まで Media 候補化。日付 plain text の News や詳細リンク型 Works が自動マッチしない | Content Media = CMS 統合された繰り返しの代表画像のみに限定。日付先頭テキスト News / 詳細ページリンク Works / works show の cross-page diff 統合（説明不能な差分は STOP） |
| 10 | 別テンプレート import 後も旧テンプレートのページ・asset が残って公開され続ける（Project isolation） | apply の prune（旧 record ∖ 新 plan: modules は backup 復元 / 他は削除 / 手動変更は残置）+ 未 import 標準ページの 404 化 + sitemap 除外 |

その他の修正: `@php` 内 HTML の DOM 再パース問題（プレースホルダー方式）/ apply no-op 経路の env・パッチ取りこぼし / template verify の false positive 3 種（HTML コメント内参照・`{slug}` route・inline CSS サイト）/ news dedupe の前方一致化。

retries: 再 apply 6 回（すべて冪等・conflict 0）。未解決の failure: 0。

## 改修前 / 改修後（plan 出力）

| 項目 | 改修前 | 改修後 |
| --- | --- | --- |
| home | 空の index.html [CONFIDENT]（事故） | entry 指定ページ [CONFIDENT] |
| CMS 統合 | 0 | contact-form / news-list / works-cards |
| static copy | 35 件（psd・docx・py 含む） | 17 件（全て参照済み Web asset。dual-use 含む） |
| Media 登録 | 15 件（装飾 sticker 混入） | 2 件（Content Media のみ） |
| 除外記録 | なし | 34 件（blocked-extension 17 / unreferenced 17、理由付き） |
| routes | 標準 5 ページのみ・8 ページ REVIEW | 9 ページ全確定（works 詳細 4 → works.show 統合、汎用 route 生成） |
| seeds | 0 | works 4（category "" / 年 null — 捏造なし）・news 3（body "" — 本文を生成しない）・site 2（未確定値は投入しない） |

## Visual Regression 実測（原本 file:// vs 変換後、Desktop 1280×800 / Mobile 375×812、pixelmatch threshold 0.1）

**7 ページ（home / company / recruit / works 詳細 ×4）は Desktop / Mobile とも diff 0.000%。**
ただし以下 2 ページには差分があり、「全ページ pixel perfect」ではない:

| ページ | Desktop | Mobile | 理由 |
| --- | --- | --- | --- |
| contact | **1.535%** | **3.844%** | Turnstile widget 挿入（Contact Pipeline 接続に必須）による送信ボタン以下のシフト。フォーム本体はピクセル一致 |
| news 一覧 | **3.684%** | **6.003%** | 承認済み CMS data mapping — 同一記事の title を 1 本化したため、原本サブページ側の延長ダミーテキストとの差 |

CSS / class / DOM / typography / spacing / animation / responsive の**変換由来の退行は 0**。
E2E の visual spec は manifest / plan 駆動へ一般化し、上記 2 ページは理由付き個別閾値（contact 5% / news 8%）。

## Lighthouse A/B（静的原本を同一条件で実測してベースライン化）

| 計測 | 変換後 | 静的原本 | 判定 |
| --- | --- | --- | --- |
| works 詳細 desktop perf | 0.96–0.97 | 0.96–0.97 | 同値 |
| works 詳細 mobile perf | 0.63 | 0.63（交互 3 回とも） | 同値 — Google Fonts 5 families / 149KB render-blocking CSS がデザイン由来 |
| contact / news mobile perf | 0.84 / 0.86 | 0.82 / 0.89 | 同値帯（マシン負荷分散 ±0.2 を確認済み） |
| home perf | 計測不能（NO_LCP） | 計測不能（NO_LCP） | 両側同一（ヒーロー構造由来） |
| SEO | 0.92 | 0.91 | 同値（原本に meta description なし） |
| a11y / best-practices | 0.93–1.0 / 1.0 | — | PASS |

**変換起因の性能回帰なし。** lighthouserc は import 先ページ + A/B 根拠の閾値
（mobile perf 0.55 / desktop 0.80 / SEO・a11y 0.90 — Green Leaf 較正値 0.90/0.95 はこのデザインには適用不能）へ更新。

## 品質ゲート（最終）

| ゲート | 結果 |
| --- | --- |
| vitest (CLI) | 131 / 131（real-world 合成 fixture テスト 25 件 + prune テスト含む） |
| Pest | 115 passed / 2 skipped（server constant / show 統合 / schema override / isolation の検証を追加） |
| Playwright E2E | 70 / 70（responsive nav・slider・Lenis・anchor・Contact 実送信 → Mailpit・admin Media 差し替え → 公開反映） |
| agentcms verify | 9 / 9（pint / phpstan / pest / vue-tsc / eslint / build / secret scan / audits） |
| template verify | 18 / 18（32 URL リンク切れなし） |
| Project isolation | /about /service → 404・sitemap 除外・/works は module 基準ビューへ復元（prune 実測: 削除 15 / 復元 1） |

## 結論

- Manifest First は plan 判断まで原 Source Read 0 件を実証。決定論 pipeline（inspect/plan/apply/verify）は LLM 無しで完走
- 実案件で発見された 10 問題はすべて kit の一般機構として修正済み（fixture 依存の hack なし。検証は合成 fixture の自動テストで再現）
- client 固有の生成物は kit 配布物に含めない（本ドキュメントと一般化コードのみをコミット）
