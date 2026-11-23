# Phase 2 Benchmark — Media Module

- 開始: 2026-08-08 17:03 / 終了: 17:25 JST（約 22 分）
- モデル: claude-fable-5（切り替えなし）
- 機械可読データ: [.agentcms/metrics/phase-2.json](../../.agentcms/metrics/phase-2.json) / 逐次記録: [phase-2-journal.md](phase-2-journal.md)
- **計測上の注意**: Phase 1 と同一セッションの継続のため、`.agentcms/` の効果に「会話コンテキスト持ち越し」が上乗せされている。純粋な Kit 効果の検証は新規セッションで行う Phase 3 の課題

## Definition of Done 判定（すべて実測根拠）

| 項目 | 判定 | 根拠 |
| --- | --- | --- |
| 画像アップロード | ✅ | MediaTest + E2E（ブラウザ実アップロード） |
| Media Entity 保存 | ✅ | name/alt/path/mime/size/width/height/has_webp を保存（MediaTest） |
| alt 編集 | ✅ | PATCH /admin/media + E2E |
| Works から Media 選択 | ✅ | E2E: picker で選択 → 保存 |
| 公開サイト反映 | ✅ | E2E: /works/festival-keyvisual に実画像表示 |
| 画像最適化 | ✅ | width/height/alt/loading/decoding 出力、WebP `<picture>`、ヒーローは eager（機械的 lazy 禁止を遵守）、aspect-ratio は width/height 属性で維持 |
| 使用中画像削除保護 | ✅ | アプリ層拒否 + 使用箇所表示（Pest）+ UI 無効化（E2E）+ FK restrictOnDelete |
| 不正画像拒否 | ✅ | 破損 PNG / SVG / 過大寸法を拒否（Pest + E2E） |
| tests | ✅ | Pest **80 passed / 2 skipped**（Media 12 件新規、初回から全通過。Phase 1 テストは削除・弱体化なし — Works の画像検証は Media へ責務移動、ADR-0006） |
| E2E | ✅ | Playwright **30 passed**（仕様の REQUIRED E2E 13 項目を含む） |
| Lighthouse | ✅ | **全 7 URL × Desktop/Mobile × 4 カテゴリ 100 維持**（画像ヘビーな works 詳細を対象に追加。LCP mobile 0.9s / desktop 0.2s） |
| agentcms verify | ✅ | 9/9 通過（ゲートの弱体化なし。PHPStan 対象に新規コードも含む） |
| Phase 2 Benchmark 記録 | ✅ | 本ファイル + journal + metrics（実測差分） |

**DoD: 13 / 13 達成。**

## Metrics（実測）

| Metric | Phase 2 | （参考: Phase 1） |
| --- | --- | --- |
| Duration | 22 分 | 67 分 |
| Output tokens | 169,450 | 667,968 |
| Input tokens（非キャッシュ） | 244 | 1,122 |
| Cache read tokens | 61,794,327 | 133,710,658 |
| Cache creation tokens | 229,771 | 1,129,068 |
| Total token activity | 62,193,792 | 135,508,816 |
| Tool calls | 84 | 357 |
| **Read（ファイル探索）** | **2** | 13 |
| Files changed | 45 | 452 |
| Test runs（Pest/E2E/LH） | 4 / 3 / 2 | 6 / 6 / 1 |
| **Failed tests** | **0** | 2 + E2E 失敗ラン 4 |
| Retry loops | 4（全て型注釈） | 13 |
| Human interventions | 0 | 0 |
| Context compactions | 0 | 0 |

作業内容が異なるため削減率は主張しない（仕様の指示）。以下の定性分析が本題。

## Phase 1 → Phase 2 Comparison

### Reused Knowledge（そのまま使えた仕組み）

- **SchemaResourceController / SchemaFieldInput**: Works 編集画面は works.json のフィールド型を `media` に変えるだけで picker が出た（管理画面のコード変更は SchemaFieldInput への 1 分岐追加のみ）
- **UploadValidator**: 拡張子/MIME 内容検査・UUID 再生成・非実行領域保存をそのまま利用（dir 引数の追加のみ）
- **モジュール規約**（PSR-4 `Modules\<Name>`、migration/views 自動ロード）: modules/Media 追加に composer.json 編集ゼロ
- **E2E 基盤**: storageState ログイン雛形・Playwright 設定・Mailpit/Turnstile テストキー構成
- **verify / Lighthouse 一式**: 設定変更なしでそのまま品質ゲートとして機能

### Avoided Exploration(読み直さずに済んだもの)

- リポジトリ全探索: **0 回**。ファイル Read は 2 回のみ（ContentSchema constructor の正確な行、整形後の Inspector）
- Fortify / 認証 / Docker / nginx / ポート構成: 一切触れる必要なし
- ※ 同一セッション継続のため、この項目は「会話コンテキスト残存」の寄与が大きい点に注意（メトリクス注記参照）

### Avoided Decisions（既存ルールで判断不要だったもの）

- 置き場所（core vs modules）: boundaries.md の「core = 語彙とサービス / modules = Entity と画面」原則から機械的に導出
- バリデーションの書き方・fillable/casts・テストの置き場所・ルート命名・admin ミドルウェア: すべて既存規約どおり
- 「導出できるものは永続化しない」→ 使用状況テーブルを作らない判断が即決（ADR-0004 の原則）

### Avoided Failures（Phase 1 の失敗が Kit に焼き込まれ再発しなかったもの）

- 大文字小文字 FS でのモジュール配置事故: PSR-4 規約が boundaries.md に明文化済みで再発せず（Phase 2 の配置ミス 0）
- nginx fastcgi buffer 502 / APP_TIMEZONE 不整合 / ポート衝突 / rate limit との衝突: テンプレートに修正済みのため **E2E 失敗ラン 0 回**（Phase 1 は 4 回）
- webkit 未導入問題: Playwright 構成が Pixel 7/chromium で固定済み

### New Waste（Phase 2 で新しく発生した無駄）

1. PHPStan の型注釈調整 3 巡（getimagesize の shape / Collection->all() の list 型 / 同一式ナローイング）。実行時バグは 0 件で、すべて静的解析の作法の問題
2. 複数行 HTML への単純 grep で `<picture>` 出力を誤って「無い」と判断し、DB 確認 1 回を浪費
3. python ワンライナーの cwd 取り違え 1 回
4. verify 実行前の Pint 掛け忘れ 1 回（自動整形で即解決）

### Rules to Add（次回さらに削るために Kit へ追加すべき知識）

1. **coding.md へ**: Larastan 頻出パターン集 — 「`Collection->values()->all()` は list と推論されない → `array_values()`」「同一式の assertSame([]) 後は再呼び出しが array{} に固定される → 状態を変えるテストはメソッド分割」「`getContent()` は string|false」
2. **coding.md へ**: 複数行 HTML の検証は grep でなく `tr -d '\n'` + 正規表現、または Pest/E2E アサーションを使う
3. **commands.md へ**: 「コミット前に `pint` を先に流す」を verify 手順に明記（もしくは verify に --fix モードを追加）
4. **Skill 候補**: 「新コンテンツタイプ/フィールド型の追加手順」チェックリスト（schema → migration → 型追加 → SchemaFieldInput 分岐 → parity テスト更新 → registry 更新）。今回この順序を記憶で実行したが、Manifest 化すれば新規セッションでも迷わない

## Phase 3 へ引き継ぐ事項

- **新規セッションで計測する**こと（同一セッションバイアスの排除）
- Media の将来拡張: gallery / hero / OGP は schema に media フィールドを足すだけの設計になっている（ADR-0006）
- Storage Adapter: R2/S3 は disk 設定追加のみで対応できる境界を維持
- responsive image（srcset）は media テーブルに派生サイズを持たせず、必要時に variant 生成方式を検討
- PWA + Push 通知（Phase 3 スコープ、DO NOT BUILD リストから解禁）
