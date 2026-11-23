# ADR-0004: Content Schema 形式

- Status: Accepted
- Date: 2026-08-08

## Context

仕様（CONTENT SCHEMA）は「管理画面の定義の中心が Schema である」構造を要求。Phase 1 では完全なコード生成は不要だが、Schema から Admin UI / Validation / Model 設定が導出される必要がある。

## Decision

`content/schemas/<type>.json` に 1 タイプ 1 ファイルで定義する。DB は実カラム方式（EAV / JSON カラムにしない）。Phase 1 では Migration は module 内に手書きし、Schema との整合はテストで機械検証する。

```json
{
  "id": "works",
  "label": "実績",
  "kind": "collection",
  "fields": [
    { "key": "title", "type": "text", "label": "タイトル", "required": true, "max": 120 },
    { "key": "slug", "type": "slug", "from": "title", "unique": true },
    { "key": "main_image", "type": "image" },
    { "key": "category", "type": "select", "choices": ["web", "graphic", "branding"] },
    { "key": "completed_at", "type": "date" },
    { "key": "excerpt", "type": "textarea", "max": 300 },
    { "key": "body", "type": "richtext" },
    { "key": "status", "type": "status" },
    { "key": "published_at", "type": "datetime" },
    { "key": "seo_title", "type": "text", "group": "seo", "max": 120 },
    { "key": "seo_description", "type": "textarea", "group": "seo", "max": 200 }
  ],
  "admin": { "list": ["title", "category", "status", "published_at"], "search": ["title", "excerpt"] }
}
```

- `kind`: `collection`（一覧を持つ） | `settings`（単一） | `submissions`（外部入力、admin は閲覧・状態変更のみ）
- Field `type`（Phase 1）: `text / textarea / richtext / email / image / select / date / datetime / slug / status / boolean`
- `status` type は `draft | published` の 2 値（進行状態のみ。保管状態等の別軸を混ぜない）
- 導出できる値は持たない（例: URL は slug から導出、published 判定は status + published_at から導出）
- Schema → Validation は core の `SchemaRules`、Schema → Admin form は core の `SchemaForm` が担う。管理画面 CRUD はこの導出のみを使い、手書きフォーム定義との二重管理を禁止する

## Consequences

- フィールド追加は「schema 編集 + migration 追加」の 2 箇所（Phase 1）。将来 codegen で 1 箇所化（ROADMAP）
- Schema とテーブルの整合はテスト（各 field key がカラムに存在する）で担保する

---

## 改訂: Phase 9.2B（2026-08-12）

Custom Fields の導入により、本 ADR の形式を次のとおり拡張した。
詳細は `docs/PHASE9_2A_SPEC.md`（決定材料）と `docs/PHASE9_2B_REPORT.md`（実装記録）。

- Field に `id`（immutable ULID）/ `default` / `help` / `validation` /
  `fields`（入れ子）/ `target`（relation の参照先）/
  `searchable` / `filterable` / `sortable` を追加した
- 表示上のまとまりを表していた `group` は **`fieldset` へ改名**し、
  `group` は入れ子フィールド型になった（旧 `"group": "seo"` は読み込み時のみ別名として受ける。
  新規 schema には出力しない）
- 永続化は Hybrid（owner 決定 G2）。**primitive はカラム、
  group / repeater / images / relations は `data` JSON**。同じ値を二重に保存しない
- `SchemaTableParityTest` は **カラムに保存されるフィールドだけ**をカラムと突き合わせる

---

## 改訂 Rev.2: Phase 9.5-B（2026-08-15）

Field Group（`docs/adr/0015-field-group-overlay.md`）の導入により、
**保存先を宣言で上書きできる**ようにした。

- Field に任意キー **`storage`** を追加した。値は `"column"` / `"json"` の 2 値
  （既存の `FieldStorage` と同じ語彙。新しい語彙を作っていない）
- **省略時は従来どおり型から導出する。** 同梱 schema はこのキーを書かないので、
  出力も挙動も 1 バイトも変わらない
- 未知の値は黙って型導出へ落とさず **例外**にする（書いたのに効かない、を作らない）

```json
// 省略 = 従来どおり（text はカラム）
{ "key": "title", "type": "text", "label": "タイトル" }

// 明示 = data JSON へ入れる（Field Group が overlay で足す項目はこの形）
{ "key": "spec_weight", "type": "text", "label": "重量", "storage": "json" }
```

### なぜ導出をやめて宣言にしたのか

「導出できる値は持たない」が本 ADR の原則である。`storage` はその例外にあたるが、
**Field Group が足す項目の保存先は型から導出できない**ためである。

同じ `text` でも、`content/schemas/*.json` が宣言した項目は専用カラムを持ち、
Field Group が足した項目は対象テーブルにカラムが無い（owner 決定 G2-(a)）。
型が同じで保存先が違う以上、どちらであるかは宣言で持つしかない。

### 付随する変更

- `news` / `works` に **nullable な `data` JSON カラム**を足した
  （Field Group の値の受け皿。既存行は NULL、index なし、既存列は不変）
- CMS が作る Content Type は `ContentTypeGenerator` が最初から `data` を持つ
- `SchemaTableParityTest` は従来どおり **カラムに保存されるフィールドだけ**を
  突き合わせるので、`data` の追加では変わらない
