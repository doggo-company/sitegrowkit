# ADR-0017: 入れ子と参照の入力（Field 型の穴埋め）

- Status: Accepted
- Date: 2026-08-15
- Phase: 9.5-C（`docs/PHASE9_5_SPEC.md` §13 / F-3）
- Owner Gate: **上限 = 深さ 3 / 総数 200 / repeater 既定 50・最大 500** ／ **`file` は次 Stage へ分離**
- 前提: ADR-0004 Rev.2 / ADR-0006（Media）/ ADR-0015（overlay）/ ADR-0016（書き込み経路）

## Context

Field 契約は 23 型を宣言しているが、管理画面が値を入れられるのは 17 型で、
Field Group Builder が選べるのは 15 型だった（SPEC F-3 / F-4）。
**schema に書けるのに値を入れられない型**が残っていた。

9.5-C はその穴を埋める。ただし埋められない型が 1 つある。

## Decision

### 1. 対象は 5 型。`file` は含めない

| 型 | 9.5-C |
| --- | --- |
| `group` / `repeater` / `relation` / `relations` / `images` | **対象** |
| `file` | **対象外**（下記） |

`file` を外すのは、現在の Media 基盤が **画像専用**だからである。実測:

- `uploads.allowed_extensions` / `allowed_mimes` は画像のみ
- `UploadMedia` は保存前に `ImageProcessor::inspect()` を呼び、
  `getimagesize()` が読めないものを拒否する（PDF は
  「画像として読み込めないファイルです」で落ちる）
- `media` テーブルの `width` / `height` は **NOT NULL**
- 保存先は public disk の静的配信で、`Content-Disposition` も認可も通らない

一般ファイルを扱うには MIME allowlist の拡張・`width`/`height` の nullable 化
（= migration）・認可付きダウンロード応答・実行形式 / HTML の扱いという
**新しい判断**が要る。それは独立した Media Stage で行う。

**`file` を「対応済み」と誤認させない。**

- Field Group の定義 UI の型一覧に出さない
- 投稿の値 UI にも出さない（`SchemaFieldInput` は描画しない）
- route も公開契約も増やさない
- 画面には「未対応」と書かず、**そもそも選択肢に出さない**
  （出したうえで無効化すると「いつか使える」と読める）

同じ理由で **AVIF / WebP の画像バリエーション**も 9.5-C では触らない。

### 2. 上限（Owner 決定）

| 対象 | 上限 | 効かせる場所 |
| --- | --- | --- |
| 入れ子の深さ | **3** | 定義の保存時（`FieldGroupDraft`）と読み込み時（`FieldShape`）の**両方** |
| 1 束あたりの field 総数（入れ子を含む） | **200** | 同上 |
| `repeater` の `max`（行数） | **必須**。未指定は **50** へ正規化、**500** 超は拒否 | 定義時。投稿の値は `SchemaRules` の `max` で拒否 |

**JSON decoder の偶発的な最大深さを仕様上限として使わない。**
9.5-B 時点では `json_decode($raw, true, 32)` の副作用で深さ 15 以上が
「Maximum stack depth exceeded」になっていた。原因が読めないうえ、
仕様として決めた値でもない。上限は定数で明示し、
**超過は原因と許容値が分かる日本語**で拒否する。

同梱 schema は最大で深さ 2 / 総数 28（`product`）なので、この上限に触れない。

深さの数え方: トップレベルの field を **深さ 1** とする。
`group` の子は 2、その子は 3。深さ 4 は拒否する。

### 3. 入れ子にも同じ契約を再帰的に適用する

トップレベルだけ厳しくても、`group` の中が緩ければ crafted request で回避できる。
次のすべてを**入れ子の各段で**適用する。

- 型の allowlist（5 型 + 既存 15 型。`file` は含まない）
- 未知キーの拒否 / 予約 key（`taxonomies`）の拒否
- 同じ階層での key 重複の拒否
- `form` の割当先を持つ束での `richtext` 禁止

管理画面と crafted request は **同じ契約**を通る。
クライアント側の検証は体験のためのもので、**server が正本**である。

### 3.1 入れ子に置けない型

| 型 | 入れ子 | 理由 |
| --- | --- | --- |
| `image` | **不可**（トップレベルのみ） | 値がアップロードそのもの（`<key>_file`）で、投稿フォームはトップレベルのキーしか回収しない。入れ子に置くと入力欄は出るのに**保存されない** |
| `media` | 可 | Media ID の参照なので、他の値と同じ道で保存される |

入れ子で画像を持ちたい場合は `media` を使う。

### 3.2 `max` は整数

`Field::$max` は `?int` である。`"1.5"` や `"1e2"` を受けて `(int)` でキャストすると
**書いた値と効く値が黙って食い違う**（1.5 → 1）。整数と十進整数の文字列だけ受理する。

### 4. 子 field にも immutable ULID

`group` / `repeater` の子にも ULID を採番する。label / key を変えても、
並べ替えても維持する（ADR-0016 の G10-(b) を入れ子へ広げる）。

`repeater` の行の `_id` は Phase 9.2B から `ContentValues` が採番・維持しており、
**送られてきた未知の `_id` は新規行として採番し直す**（識別子の偽装防止）。
この既存契約は変えない。

**1 つの ID は 1 回しか使えない。** 同じ既存 ID を 2 つの項目へ付けられると、
`SchemaMutationClassifier` が identity で突き合わせるときに片方がもう片方を上書きし、
**削除や改名を「変更なし」に見せられる**。トップレベルと入れ子をまたいで数える。

### 5. `ContentReferences` を再帰させる

これまで `validate()` と `jsonReferences()` は `$schema->fields` の
**トップレベルだけ**を走査していた。`group` / `repeater` の中に `relation` を置くと、

- 参照先の存在確認が効かない
- 参照されている側を**削除できてしまう**（RESTRICT が働かない）

`MediaUsageInspector` は Phase 9.2B から再帰済みで、media 側にこの穴は無い。
参照側も同じ深さまで効かせる。

### 6. relation の候補取得

| 規則 | 内容 |
| --- | --- |
| 面 | **管理画面の内部 route だけ**。公開 API と MCP operation は増やさない |
| 認可 | 認証必須 + capability 必須（既存の operation を通す） |
| 対象 | `SchemaRegistry` 等の共通境界から **実際に CRUD できる collection** だけを解決する |
| hardcode | 投稿タイプ名・module 名・table 名を書かない |
| 件数 | 上限あり。**初期 HTML へ全件を投入しない** |
| 検索 | 可能。すべて query builder の binding を通す |
| 既存の選択 | `?ids=` で **選択中の ID だけ**名前解決できる。候補の先頭 20 件の外にあっても「ID 42」と出さない |
| 競合 | 検索は debounce し、投げ直すときは前の要求を abort する（**古い応答で新しい結果を上書きしない**） |

### 7. `images` と MediaPicker

`images` は Media ID の **順序を保持**し、重複を拒否し、追加・削除・並べ替えができる。
既存の `MediaPicker`（単一選択）と責務を共通化するが、
**単一選択の既存利用を壊さない**（`image` / `media` 型は従来どおり）。

画像 URL・利用者入力を監査 metadata やログへ出さない。

## Consequences

- `schema に書けるのに値を入れられない型` は `file` だけになる
- 入れ子の値は `data` JSON に入るので、一覧の絞り込み・並べ替えには使えない
  （ADR-0016 G2-(a) の帰結。変わらない）
- `ContentReferences` の再帰化で、参照先の削除保護が入れ子まで効くようになる。
  既存の一覧・削除の挙動は「これまで見逃していた参照を見つける」方向にだけ変わる
- 上限を定数で持つので、緩めるときは 1 か所を変えて理由を書けばよい
- 一般ファイルと画像バリエーションは次の Media Stage の入力になる
  （推奨は 9.5-C の STOP REPORT に整理する）
