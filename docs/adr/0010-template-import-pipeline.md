# ADR-0010: Static HTML/CSS/JS → Agent CMS Import Pipeline

- Status: Accepted
- Date: 2026-08-09
- Phase: 5

## Context

Phase 5 は既存の Static HTML/CSS/JS テンプレートを、デザインを保持したまま（Preserve First）Agent CMS プロジェクトへ変換するパイプラインを導入する。中心要件は (1) AI が原ファイルを何度も読まずに済む決定論的 inspect → Template Manifest、(2) 変換判断を理由付きで示す Plan、(3) 安全な Apply、(4) 視覚回帰を含む Verify。

## Decisions

### 1. CLI 構成

`agentcms template inspect|plan|apply|verify` の 4 サブコマンド。Phase 4 までの `template inspect` を Manifest v2 へ拡張し、他 3 コマンドを新設する。命名は spec §5 のまま採用（既存規約と衝突なし）。

### 2. Template Manifest v2（`.agentcms/manifest/template.json`）

- `version: 2`。v1（Phase 4）からの追加: `fingerprint`（ファイル毎 sha256 + 全体 combined hash）、`head_metadata`、`css_analysis`、`js_analysis`、`image_classification`（static asset / media candidate）、`common_structures`（confidence / reason / occurrences 付き）、`warnings`（secrets は redact、unsupported / build-tool 検出）、`source_stats`（files / lines / bytes — Token Efficiency 計測用）
- 決定論: 全リストは安定ソート。同一入力 → バイト同一出力（snapshot test で担保）
- 形式変更は本 ADR の改訂として扱う（ADR-0004 の inspect 節を置き換え）

### 3. Plan（`.agentcms/manifest/template-plan.json` + `docs/template-import-plan.md`）

- Manifest のみから決定論的に生成（AI 不要）。pages / shared components / CMS candidates / keep-static / JS 方針 / form field mapping / schema 変更提案 / asset 分類を、各判断に `reason` と `confidence`（CONFIDENT / REVIEW / UNKNOWN）を付けて出力
- Manifest の fingerprint を継承し、apply 時の stale 検出に使う

### 4. Apply — 出力先と安全性

- 出力先はカレント Agent CMS プロジェクト（kit リポジトリでは `template/`）。**元テンプレートは read-only**
- 生成物はすべて Project 層: `resources/views/public/*.blade.php`（ページ）、`resources/views/layouts/imported.blade.php`（共有 header/footer からのレイアウト）、`modules/Works/views/index.blade.php`（CMS 統合）、`modules/Contact/views/form.blade.php`（既存 Contact Pipeline 接続）、`public/imported/{css,js,images,fonts}`（asset。元ディレクトリ構造保持）、`database/seeders/TemplateContentSeeder.php`（fixture parity データ + Media 統合）
- 安全性: (a) source 再ハッシュと Plan fingerprint 不一致 → 拒否（re-inspect / replan 要求）、(b) 既存ファイルとの conflict → STOP（一覧提示）。`--force` でのみ上書きし、上書き前に `.agentcms/manifest/backup/` へ退避、(c) 適用結果を `.agentcms/manifest/template-apply.json`（provenance: source → target 対応 + 書き込みファイル hash）へ記録し、同一 Plan の再 Apply は no-op、書き込み後に手動変更されたファイルへの再 Apply は conflict 扱い
- News など fixture に無いページは既存レイアウトのまま残る（既知の制約。Real World では追加ページの再 import で対応する将来課題）

### 5. Core 拡張: CSP 追加オリジン（唯一の Core 変更）

インポートされたテンプレートは外部依存（Google Fonts / CDN スクリプト等）を持つが、`PublicCspMiddleware` は Turnstile のみ許可のハードコードだった。テンプレート都合の Core 直編集は禁止（境界規則）のため、**config 駆動の CSP 追加オリジン**という一般化可能な拡張ポイントを Core へ追加する:

- `core/config/agentcms.php` に `csp` 節（`script_src` / `style_src` / `font_src` / `img_src` / `connect_src`、env `AGENTCMS_CSP_*` のカンマ区切り、デフォルト空 = 挙動不変）
- Apply は検出済み外部依存から必要オリジンを `.env` / `.env.example` へ追記（Plan に WARN として列挙。勝手な削除・置換はしない）

### 6. Works / News / Contact 統合

- Works: 繰り返しカード構造を `@foreach` 化し既存 Works schema へ接続。カード meta `"EC / 2026"` 形式は `category` + `completed_at` 年へ分解。fixture のカテゴリ集合が schema の `choices` に無い場合、Plan が choices 追加を `schema_changes` として提案し Apply が適用する（大量拡張はしない。選択肢の追加のみ）
- News: トップの news リスト繰り返しを News schema へ接続
- Contact: フォームの見た目を保持したまま `route('contact.store')` + `@csrf` + Turnstile widget + `@error` / `old()` を注入。HTML に無い schema 項目（company）は追加しない（Preserve First）。マッピング不能な項目は Plan に表示して捨てない

### 6.2 Plan v3 — 投稿タイプごと持ち込む（R-13 Stage 2・2026-08-22）

Plan 形式を **v3** にした。v2 を v3 として読むと `seeds` の形の違いで **seed が 0 件に見える**ので、apply は v2 を解釈せず作り直しを求める（読み取り専用の provenance は `importer/seeds.ts` が旧形式も読む）。

- **`content_types[]`**（新設）: `{id, label, preset, confidence, reason}`。**fields は持たない** — 展開は PHP 側 `ContentTypeBuilderPresets` の仕事で、TS が持つと正本が 2 つになる（F-18）。`reason` には **ID をあとから変更できない**旨を必ず書く（承認の材料）。受け皿の型が在るときは空で、従来どおり既存型へ載せる
- **`seeds`**: `{works: [...], news: [...]}` → `{site, collections: [{type, model, shape, rows}]}`。**型名が field 名だった**のをやめた。`shape` は行の形（`cards` / `dated`）で型名と独立。`model` は持つ — CMS が作った型は `Modules\Works\Models\Works`、kit の例は `…\Work` と規則が違い、生成の段では schema を持っていないため導出できない
- **公開面**: CMS が持つ型は名前付き route を作らない（`Route::fallback` が受ける）。ページは `resources/views/public/<type>/index.blade.php` へ生成し、取り込んだデザインのまま CMS 駆動になる
- **apply の順序**: `prepared → files_written → types_created → db_committed → recorded`。型は**戻せる物（ファイル）を全部済ませてから**作り、**巻き戻さない**。1 つでも作れた／結果が分からない場合は phase を進め、次の実行が**ファイルを巻き戻さない**ようにする
- **座**: `agentcms:create-content-type`（`agentcms:import-terms` と同じ内部経路）。`OperationCatalog` には載せない = **AI へは公開しない**

### 6.1 Manifest v2 の追加項目（R-16 ③・2026-08-22）

`forms[].submit_label`（`string | null`）を追加した。送信ボタンの**原文そのまま**の文字で、ボタンが無い / 文字が無ければ `null`。version は 2 のまま（読む側は `?? null`）。

理由: plan は manifest だけを見て判断する（HTML を読み直さない）。取り込んだフォームは動作するのに送信ボタンが「準備中」のままだと**利用者に嘘をつく**が、manifest に文字が無いと**それを言うことすらできない**。**書き換えはしない**（作り話をしない）— `NOT_READY_WORDS`（`importer/plan.ts` に 1 か所）に当たったら、原文と当たった語を出典として plan の REVIEW に出す。

### 7. Visual Regression 閾値

static fixture（file:// 直接表示）と変換後サイトを同一 viewport（desktop 1280×800 / mobile 375×812 相当）で全ページ比較する。**閾値: 各ページ diff pixel ratio ≤ 2%**（pixelmatch, threshold 0.1）。

根拠: 同一 CSS・同一フォント・同一画像ソースを使うため理想は 0% だが、(a) Media 経由画像の webp 再エンコード、(b) フォントレンダリングのサブピクセル差、(c) Turnstile widget（変換後のみ存在）を許容する必要がある。layout collapse / 画像欠落 / フォント欠落は 2% を大きく超えるため検出できる。Turnstile 領域はマスクして比較する。

### 8. Fixture

`examples/html-template/`（Green Leaf Design）を Phase 5 要件（5 ページ / responsive nav / anchor / meta・OGP / font-face / CSS url() asset / accordion）へ拡張する。spec §36 の `examples/html-template-phase5/` は例示であり、fixture を単一に保ち CLAUDE.md の Project Map と整合させるため既存ディレクトリを拡張する。fixture での成功は任意サイト変換の証明ではない（spec §37）。

## Consequences

- AI は Manifest（数百行）だけで変換計画を判断でき、原 HTML 全読みを避けられる（Phase 5 ベンチマークで実測）
- Core の CSP が config 可能になり、今後の外部依存を持つテンプレート全般に適用できる
- 再 import / diff apply（Phase 6+）は provenance と fingerprint を基盤にできる

## Revision 1 — Phase 5.1 Real World Acceptance Test からの一般化（2026-08-09）

未知の実制作 HTML（Real World Acceptance Source）への適用で発見された問題を Pipeline へ一般化した。

### Manifest v2 追加フィールド（additive）

- `entry: string | null` — home (/) として扱うページ。優先順: `--entry` 明示指定 > 単一ファイル inspect の対象 > 実質空でない `index.html`。0 byte / 本文なしの HTML はページ候補から除外し `empty-page` warning を出す（空 index.html が home へ誤マッチする事故防止）
- `referenced_assets: string[]` — HTML 属性 / CSS `url()` / 非ページ内部リンク（pdf 等）から実際に参照される local asset。public copy 候補はこの集合に限る
- `template inspect <path>` は単一 HTML ファイルも受け付ける（親ディレクトリを source とし、対象ページ + 参照 asset のみ解析。作業ディレクトリの無関係ファイルを巻き込まない）

### Plan v1 追加フィールド（additive）

- `entry` — Manifest の entry を転記。entry ページが home [CONFIDENT]、entry ≠ index.html のとき index.html は home へマッチさせない
- `assets.excluded: {path, kind, reason}[]` — Reference-Based Asset Allowlist の監査証跡。kind: `unreferenced` / `blocked-extension` / `not-allowlisted` / `needs-approval`（pdf）。Web 配信 allowlist: jpg/jpeg/png/webp/avif/gif/svg/ico/woff/woff2/ttf/otf/css/js。psd/ai/af*/doc(x)/xls(x)/py/sh/bak/env/archive/map 等は copy 禁止（`.agentcms/rules/security.md` 参照）
- `cms_integrations[].server_constants: {key, value, note}[]` — HTML に存在しない必須 schema フィールドへの SERVER-SIDE constant mapping（hidden input 不使用。UI へフィールドを追加しない）
- `schema_changes[].scope: "project"` / `change: "add_choices" | "make_optional"` — schema 変更は常に project scope（Core は変更しない）。REVIEW の提案は apply が自動適用しない
- `seeds.works[].completed_year: number | null` — 原デザインに年が無い場合 null（completed_at を捏造しない）

### 判断ルールの一般化

- Media Library 登録は「CMS 統合された繰り返しのコンテンツ画像（item 代表画像）」のみ。テキストを持たない装飾繰り返し（sticker 等）・付随画像は Static Design Asset。svg は uploads allow-list に従い Media 登録しない
- Works: `"category / year"` meta が無くても、全 item が heading + image + 実在詳細ページへの link を持つ card_grid は Works 候補（REVIEW）。category 概念が無ければ project override（make_optional）を提案し架空値を補完しない
- News: time 要素が無くても、news の手掛かり（container / page 名）を持つ「日付 + テキスト」繰り返しは News 候補（REVIEW）。title/body 分割は確定せず全文を title として最小マッピング。一覧とサブページの同一記事は日付+テキストで dedupe
- Contact: method=get / action なしの静的モックフォームも contact 系フィールド（email + name/message）があれば接続候補（REVIEW）。統合されなかった card_grid も keep_static として必ず disposition を持つ

### Revision 1 追補 — Real World Apply で確定した機構（2026-08-09 apply 実測後）

- **Standalone（preserve）モード**: 共通 header/footer が抽出できないソース（`layout.header_selector === null`）は layout へ分解せず、原 HTML 文書全体（inline `<style>` / nav / 装飾 / メタデータ）を standalone Blade として保持する。変換は CMS スロット注入・参照書き換え・inline CSS `url()` 書き換えのみ
- **inline JS の CSP 対応**: Core の公開 CSP は inline script とハンドラ属性の実行を許可しない。apply は inline `<script>` を `public/imported/js/<page>-inline-<n>.js` へ（コード無変更・同位置の `<script src>`）、`onclick` 等のハンドラ属性を `data-agentcms-ev` マーカー + addEventListener 委譲（`return false` は preventDefault へ写像）へ外部化する
- **works show 統合**: N 枚の静的詳細ページを cross-page diff で解析し、差分が「title / 連番 / メイン画像 / ナビの current」で説明できる場合のみ単一 show テンプレートへ統合（説明できない差分は throw = STOP）。連番は `$__works` の published_at desc 順から導出
- **contact モック解除**: `onsubmit="return false"` と disabled submit は Pipeline 接続時に解除（テキストは保持）。server constant はバリデーション除外 + サーバ側代入（ContactController への冪等文字列パッチ）
- **Project Scope Schema Override（機構実装）**: `content/schemas/overrides/<id>.json` を Core SchemaRegistry がフィールド属性マージで読み込む。apply は `make_optional` [CONFIDENT] を override として書き込む（kit 標準 schema 本体は不変）。optional 化された文字列系フィールドの null は、NOT NULL カラムへは SchemaResourceController が空文字で保存する。`hidden` は admin UI 未対応（今後の拡張候補）
- **adobe-fonts CSP**: inline script 内の外部 URL（既知カテゴリのみ）を external として検出。Typekit は script/style/font = use.typekit.net、connect = use.typekit.net + p.typekit.net、img = p.typekit.net を要求する
- **dual-use assets**: Media 化される画像でも CSS `url()` から参照されるものは `public/imported/` へも copy する（`css_referenced` — inline `<style>` 由来を含む）
- **template verify の一般化**: HTML コメント内参照を broken link 判定から除外 / `{slug}` route は seed の実 slug で検証 / inline CSS サイトでは `<style>` 存在で styled 判定
- **Visual Regression の一般化**: `template-visual.spec.ts` は manifest / plan 駆動（source パスと比較ページを `.agentcms/manifest/` から導出）。閾値は既定 2%、構造上避けられない差分は**理由付き**の個別許容。個別許容は spec の中ではなく `.agentcms/visual-thresholds.json` に宣言する（R-15 Stage 2。`reason` 必須 = 理由の無い緩和を置けない・宣言が無ければ既定 2% のみ）。この spec は生成プロジェクトへも配る唯一の e2e であり、設定は `globalSetup` を持たない `playwright.visual.config.ts`（`pnpm e2e:visual`）
- **Lighthouse 較正（実案件 A/B 実測 — docs/benchmarks/phase-5.1.md）**: 変換後 ≒ 静的原本（works mobile 0.63 = 0.63、desktop 0.96–0.97 同値、home は両側 NO_LCP）。低スコアはデザイン由来（Google Fonts 5 families / 149KB render-blocking CSS、meta description なし → SEO 0.91–0.92）。閾値は A/B 根拠で mobile perf 0.55 / desktop 0.80 / SEO 0.90 / a11y 0.90（home の a11y 0.93 も原本由来）
