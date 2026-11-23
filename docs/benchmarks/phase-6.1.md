# Phase 6.1 Benchmark — Real World Figma Acceptance & AI Layout Interpretation

- 実施日: 2026-08-09（Fresh Claude Code Session、Stage 0〜6 約 75 分）
- Baseline: `v0.6.0-phase6-figma-manifest`（78ac88d、origin/main と一致・working tree clean を実測）
- Source: **Real World Figma A**（Phase 6 で未使用の実制作 production design。Client Isolation のため file 名・file key・node id・URL・文言・フォント名は記録しない）
- 実行環境: kit リポジトリ外の isolated workspace（`agentcms create` + sqlite + host `php artisan serve`）

## Acceptance

**PARTIAL VISUAL ACCEPTANCE**

- Desktop は AI Layout Interpretation により Visual Fidelity が明確に改善（A1 47.418% → B 29.248%、**−18.170pp / 相対 −38.3%** — SPEC §36 の「15pp 絶対削減」達成）
- Resource Parity（A1）により Fonts / SVG / 画像の効果と AI Layout Interpretation の効果を分離して計測できた
- semantic HTML / accessibility / responsive safety / CMS boundary / client isolation を維持。unclassified major regression なし
- Mobile も改善（33.868% → 28.533%、−5.335pp / 相対 −15.8%）したが数値目標は未達。立証可能な制約: Figma Image API の render 上限 16384px により 0.6822 倍でしか比較できない / 設計 Mobile frame の末尾約 32% が空白 / 実装 content 高が設計比 約 91%

## Source Coverage（SPEC §3）

| capability | 状態 |
| --- | --- |
| Desktop screen | ✅ 1（1728×14374。別版 frame 2 件は ALTERNATE DESIGN FRAME — NOT SELECTED として記録） |
| Mobile screen | ✅ 1（402×24015 — **別ページに存在し名前 pairing 不能** → Human-approved pairing） |
| Components / Component Sets | ✅ 2 / NOT COVERED BY THIS SOURCE |
| Auto Layout / Absolute Layout | ✅ 混在（**両 screen root が絶対配置**） |
| Images / Vectors | ✅ 17 / 14 |
| Custom Fonts | ✅ 6 families（全て project 未導入 → 全て web-loadable、human 承認で使用 weight のみ読込） |
| Repeated Content / CMS 候補 | ✅ 3 groups（9/8/3 items — すべて STATIC 維持の human 判断） |
| Interactions | NOT COVERED（prototype データは pipeline 対象外。accordion / mobile menu は INFERRED WEB BEHAVIOR として実装・記録） |

## Figma Acquisition（Read Only）

- **Figma write operations: 0** / scopes: `file_metadata:read` + `file_content:read` のみ / rate limit 0
- API calls: file_meta 5 / file_nodes 2（selection 変更時のみ。**再 inspect は全て Raw Cache hit = Tier 1 zero**）/ renders 1 / image_fills 1 / SVG export 2 / CDN 36
- 別途 手動 triage 約 12 call（token 作成直後の一時的 403 の切り分け + Context Gap 記録済みの depth 限定構造読み 1 回）

## Manifest First

- raw 688.6KB / 749 nodes → Manifest 358.0KB（**ratio 52.0%** — TEXT 密度の高い実案件で synthetic より圧縮率が低い）
- **AI Raw JSON Read（whole file）= 0 / targeted Raw Node Read = 1**（depth=2 の構造のみ。TEXT node 名経由で約 2KB の設計文が付随露出 — 正直に記録）
- Manifest determinism: 両 selection で sha256 完全一致

## Visual A/B（pixelmatch 0.1・白パディング・vs Figma render）

| 段階 | Desktop 1728px | Mobile 402px（0.6822 倍比較） |
| --- | --- | --- |
| A0 — Raw Deterministic | 47.627% | 34.523% |
| A1 — Resource-normalized（fonts + SVG。layout 補正なし） | 47.418% | 33.868% |
| B0 — AI 実装初版 | 40.378% | 28.183% |
| B1 — Correction 1（画像割当修正 / aspect-ratio + object-fit） | 37.649% | 29.563% |
| B2 — Correction 2（境界スキャンによる縦整合） | **29.248%** | **28.533%** |
| B3 — Correction 3（container 幅補正） | 29.535% | 28.533% |

- **Final = B2**。B3 は FAQ/footer 帯を改善（−11.8 / −5.5pp）したが中間 3 領域を悪化（+10.3 / +5.1 / +5.1pp）させ global +0.287pp。semantics / a11y / responsive に差が無いため revert し、計測上最良の non-regressive state を採用（悪化 iteration も隠さず全記録）
- **fonts だけでは global diff はほぼ動かない**（A0→A1 Desktop −0.209pp）— 改善は AI Layout Interpretation によるものと分離実証
- correction は規定 3 回で終了。mobile は 3 回で横ばいのため anti-loop 停止（第 4 回なし）

### Region 分析（Desktop・band 実測 + 目視）

Header / Concept / Brands 帯 / Solution / CTA / Access = MATCH〜MINOR。Hero / TEMPUR・Sealy / Portfolio = MINOR（写真 crop 差 = ASSET ENCODING、組版差 = FLEX/GRID INTERPRETATION）。Safety & Guide / FAQ = MINOR〜MAJOR（行組版差の累積）。IMPLEMENTATION REGRESSION（構造・内容欠落）= 0、CONTENT 発明 = 0。

## Quality（Stage 6-7 実測）

- CLI vitest **208/208**（Stage 7 の synthetic 回帰 27 件を含む）
- isolated project: Pint / PHPStan / Pest 113 / vue-tsc / ESLint / Vite build / composer audit 0 / pnpm audit 0 — 全 pass（host PHP 実行。site は isolation のため意図的に git-less → secret scan は手動 grep で token 0 を確認）
- **figma verify: 7 pass / 0 fail** — AI 編集 4 ファイルは `figma interpret` の記録 hash と一致して PASS。改ざん再現テストで FAIL へ落ちることも live 実測（hash 保護は弱めていない）
- kit template: `agentcms verify` 9/9（Docker runtime）+ Playwright E2E green
- Lighthouse B: Desktop perf 0.95 / a11y 0.95 / bp 1.0（A1 の 0.80 から改善）。Mobile perf 0.68（LCP 20.8s→8.8s。残コスト = 未最適化 PNG = design-inherent + image cost）。font cost 90 req / 約 1.6MB。SEO 0.58 は意図的 noindex
- Accessibility: h1 一意 / 見出し順序 / landmark / alt / overflow なし / キーボード操作可 / 実 link 23（Baseline A は 1）

## Generalized Kit Hardening（Stage 7 — 実測 failure 由来のみ・client 固有物 0）

| Gap | 一般化修正 | 回帰テスト |
| --- | --- | --- |
| 1. 名前依存 pairing | generic signal（viewport class / text 共有 / asset 共有）で pairing 候補を提示（自動確定なし・CONFIDENT/REVIEW/UNKNOWN 維持） | ✅ |
| 2. vector identity 喪失 | placeholder class / CSS 寸法 / export target を asset identity で追跡（同名 vector が潰れない） | ✅ |
| 3. AI interpretation provenance | `figma interpret` で generated→interpreted を記録。verify は記録 hash 一致時のみ PASS（保護は不変） | ✅ |
| 4. fake link / div 化 | 検証済み destination（route / Figma hyperlink）のみ `<a>`。不明は REVIEW、`href="#"` 生成禁止 | ✅ |
| 5. image fill 対応 | node→fill reference の identity 維持 + scaleMode / crop transform を Design Intent として Manifest 保持・object-fit 変換 | ✅ |
| 6. 未使用 use 生成 | 選択 mapping が必要とする import のみ出力 | ✅ |
| 7. verify の Docker 前提 | runtime capability 判定（Docker or 互換 host PHP + vendor。不能時は WHAT/WHY/FIX） | ✅ |
| 8. 数字始まり route 名 | route name のみ identifier-safe 化（Wayfinder TS 生成対応。URL は不変） | ✅ |
| 9. verify runtime の project 誤帰属 | compose label（working_dir）で container の project identity を検証。別プロジェクトの Docker は使わず host fallback / 双方不可なら FAIL（Stage 7 で発見・**v0.6.1 前に修正済み**） | ✅ |

## Known Limitations

1. Mobile の数値目標未達（render scale 上限 / 設計 frame 空白尾部 / content 高 91%）
2. 同名・同寸の vector は REST メタデータだけでは区別不能
3. secret-scan は git 前提（意図的 git-less の isolated acceptance では手動 scan で代替）
4. crop transform は Manifest に保持されるが、生成 CSS transform への適用は未実装

※ 当初 known limitation としていた「compose 固定 project 名による verify runtime の誤帰属」は
Stage 7 で発見した generic verification correctness bug として **v0.6.1 前に修正済み**（Gap 9。
別プロジェクト Docker 稼働中の host fallback / FAIL を Fake unit + live で実測）

## Recommendation

Real World 2 例目（今回とレイアウト特性の異なる design）での再現確認を推奨。Mobile fidelity は「セクション単位の高さ整合の自動フィードバック」（screenshot→設計境界の自動比較を correction loop へ供給）が最有力の次打ち手。pipeline 自体は次 product phase へ進める品質にある。
