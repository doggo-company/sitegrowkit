# ADR-0006: Media Module の設計

- Status: Accepted
- Date: 2026-08-08 (Phase 2)

## Context

Phase 2 仕様は「画像を文字列パスではなく Media Entity として扱い、Content Schema から参照できる構造」を要求。既存の Core/Project 境界（ADR-0003）と Schema First（ADR-0004）を壊さずに追加する。

## Decision

### 配置

- **core**（再利用可能な語彙・サービス）: field type `media` の追加（SchemaRules で `exists:media,id` 導出）、`ImageProcessor`（寸法検査 / 不正画像拒否 / WebP 生成）、`MediaUsageInspector`（使用箇所の動的導出）
- **modules/Media**（Project 層）: Media モデル・migration・管理コントローラ・テスト。News/Works と同じモジュール規約に従う
- 将来 Core Update 機構ができた時点で Media モジュールごと core へ昇格する選択肢を残す（Phase 3+）

### Media Entity

```
media: id, name(管理用名称), alt, path, mime, size, width, height, has_webp, timestamps
```

- `path` は UploadValidator が再生成した UUID ファイル名（`storage/app/public/media/`、実行可能領域外）。ユーザーには path / UUID / MIME / disk を入力・意識させない
- `has_webp` は「WebP 変換を実行できたか」という処理結果の記録（環境の GD 能力に依存し、ファイル存在確認の IO なしには導出できないため永続化する）
- WebP は同名 `.webp` を生成（Capability Detection: `imagewebp` が無い環境ではスキップし `<img>` フォールバックのみ）。追加ライブラリは導入しない（GD のみ、既に Docker イメージに同梱）

### Works 統合

- `works.main_image (string)` → `main_image_id (FK → media, nullable)` へ schema / migration / seeder を変更
- FK は `restrictOnDelete`（使用中 Media の削除を DB レベルでも防ぐ多層防御）

### 使用中 / 使用箇所の判定

- 使用状況は **導出値**（キャッシュテーブルを作らない）。`MediaUsageInspector` が全 Schema から `media` type のフィールドを列挙し、参照テーブルを whereIn で検索する
- このために ContentSchema にオプション `table`（省略時は schema id）を追加する（contact_submissions のような不一致に対応）

### 削除安全性

1. アプリ層: 削除時に MediaUsageInspector で使用箇所を確認 → 使用中は拒否し使用箇所を表示
2. DB 層: FK restrictOnDelete
3. 未使用のみ: 確認ダイアログ → ファイル（original + webp）と行を削除

## Consequences

- 他コンテンツタイプ（gallery / hero 等）は schema に `{ "type": "media" }` フィールドを足すだけで同じ Media を参照できる
- Storage Adapter 境界は Laravel の disk 抽象（config `agentcms.uploads.disk`）に一本化。R2/S3 は将来 disk 追加のみで対応（コード変更なしが目標）
- Phase 1 の WorksAdminCrudTest の画像アップロード検証（SVG 拒否等）は Media 側のテストへ**移動**する（弱体化ではなく責務の移動。Works は `exists` 検証に変わる）
