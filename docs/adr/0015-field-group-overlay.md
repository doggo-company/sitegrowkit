# ADR-0015: Field Group を DB overlay として重ねる

- Status: Accepted
- Date: 2026-08-15
- Phase: 9.5-A / P0-1（`docs/PHASE9_5_SPEC.md` §4.2 / §10）
- Owner Gate: **G1 / G4 / G6 / G7 / G8 / G9 / G11 を推奨値で確定**

## Context

`docs/PHASE9_5_SPEC.md` は、コンテンツ投稿・ページ・将来のフォームが
**1 つの Field 語彙**を共有できるようにするための土台を求めている。
現状の制約は同書 §1.2 の F-1 / F-5 / F-7 に尽きる。

- Field 定義を後から編集する経路が無い（投稿タイプは create のみ）
- **Field Group という再利用の単位が無い**（フィールドは投稿タイプ 1 つに属する配列）
- 正本がファイルなので、**読み取り専用リリースでは定義を触れない**

9.5-A はこのうち「読む側」だけを作る。**書く経路はこの Stage では作らない。**
`SchemaRegistry` は全 Content Type・全管理画面・全 API の入口なので、
ここを壊すと製品が同時に全滅する。そのため
**overlay が空のとき、既存の出力と 1 バイトも変わらない**ことを先に固定する。

## Decision

### G1 — 正本は hybrid。ファイルが勝つ

| 対象 | 正本 |
| --- | --- |
| コード管理 schema（`content/schemas/*.json` + `overrides/*.json`） | **ファイル** |
| CMS が作った Field Group | **DB** |

読み込み時に `SchemaRegistry` が DB を **overlay として重ねる**。
`overrides/` と同じ考え方を 1 段足すだけで、新しい読み込み機構を作らない。

**優先順の規則（これがこの ADR の中心）:**

| 規則 | 内容 |
| --- | --- |
| 追加のみ | DB は **ファイルに無い key を足す**ことしかできない |
| ファイル優先 | 同じ key があれば **ファイル側の定義がそのまま残る**。DB 側は捨てられる |
| 削除不可 | DB からコード管理 field を**削除できない**（削除を表す構文を作らない） |
| 型変更不可 | 同じ key の type を DB から変えられない（= ファイル優先の帰結） |
| 順序 | ファイル側の順序を保ち、**その後ろに** overlay 分を足す |
| 型の集合は不変 | overlay は **schema id を増やさない**。Field Group は Content Type を作らない |

最後の 1 行が効く。overlay が Content Type を増やさないので、
**route 登録とモデル登録は DB を読まなくてよい**（下の「boot は DB へ触れない」）。

### G4 — location は `kind` + `value` の 2 列

条件式（AND / OR）を作らない。まず「どこに割り当てるか」だけを持つ。
条件分岐は conditional logic と同時に検討する（SPEC §9）。

### G11 — 9.5-A の location kind は `content_type` と `form` の 2 値だけ

`page_template` は **enum に入れない**。予約語として文書に書くだけで、
**実行時にも UI にも出さない**。binding の型整合を Visual Page Editor（P0-C5 /
`docs/PHASE9_6_SPEC.md` §4.9）と同時に決めるほうが安全であるため。

DB に `page_template` の location 行が入っていた場合、その行は
**どの Content Type にも一致しない**（fail closed）。推測して割り当てない。

### G6 — 既存 `news` / `works` / `pages` は移行しない

共存させる。既存データ・既存 schema ファイル・既存テーブルへ一切触れない。
可視化（SPEC §8 Step 5）も 9.5-A では行わない。

### G7 — MCP へ公開しない

Field Group は構造を変える操作であり、不可逆である。
ADR-0014 が Content Type 作成で同じ判断をしている。
**`OperationCatalog` に 1 件も足さない**（9.5-A の read 操作も含めて公開しない）。

### G8 — 読み取り専用 FS でも成立する

Field Group の正本は DB なので、schema ディレクトリが書けない環境でも
一覧の読み取りが成立する。9.5-A の面は読み取りだけなので、
**ファイル書き込みを 1 度も要求しない**。

### G9 — content と form は Field 定義だけ共用する

保存先（content テーブル / submissions）と検証の強さ（公開フォームは常に外部入力）が
違うため、**定義だけ共有し、保存・検証・通知は分ける**。
9.5-A では `form` は location kind として受理するだけで、実行面を作らない。

### 9.5-A で「作らない」もの

作成・編集・archive・復元・並べ替えの route / service / ボタン、
MCP 公開、公開 API、AI operation、`page_template` の実行面、
Visual Editor の binding UI、既存データの移行。

## 永続化（3 テーブル）

```json
// field_groups.fields（JSON）は **既存 Field 契約そのまま**。新しい語彙を作らない
[
  { "id": "01K...", "key": "spec_weight", "type": "text", "label": "重量" }
]
```

| テーブル | 列 |
| --- | --- |
| `field_groups` | `id`(ULID) / `key` unique / `label` / `sort_order` / `status` / `fields`(JSON) / timestamps |
| `field_group_locations` | `field_group_id` / `kind` / `value`（複合 unique + 逆引き index） |
| `field_group_revisions` | `field_group_id` / `revision` / `snapshot`(JSON) / `actor_id` / `actor_label` / `created_at` |

- 列名は `order` ではなく **`sort_order`**。`order` は SQL の予約語であり、
  既存の `taxonomy_terms.sort_order` と表記を揃える
- **導出できる値は持たない**。割当先の label・field 数・最終更新者名は保存しない
  （`actor_label` だけは例外で、利用者が無効化・改名されても
  「誰が保存したか」が読めるようにするため。`audit_logs.actor_label` と同じ扱い）
- **FK は全て RESTRICT**（ADR-0013 / Taxonomy と同じ。CASCADE を使わない）
- index は 767 バイト以内（共有ホスティングの MySQL 5.7 世代互換）

`field_group_revisions` は 9.5-A では**書かれない**。
G5 推奨（全 revision 保存）の置き場所を先に確保しておくためだけに作る。
「後からテーブルを足す」ほうが読み取り専用環境で詰むため、
**追加 migration は今この 1 本にまとめる**。

## fail closed

`SchemaRegistry` は製品の全画面が通る場所なので、
**疑わしいものを推測で通さない**。

| 状況 | 挙動 |
| --- | --- |
| `field_groups` テーブルが無い | overlay 無しと同じ（**既存と完全に同値**） |
| DB へ到達できない / 権限が無い | **例外をそのまま投げる**。空として扱わない |
| `fields` JSON が壊れている | その Field Group は**何も足さない**。一覧に「定義エラー」として出す |
| 未知の Field 型 | 同上（`Field::fromArray()` の検証をそのまま使う） |
| JSON は有効だが**値の形**が違う（`choices` が文字列、`fields` が文字列、`label` が配列…） | 同上。`FieldShape` が DB 境界で先に弾く（入れ子も同じ保証） |
| 未知の location kind | その location は**どこにも一致しない** |
| `page_template` の location | 同上（G11。予約語であって実行値ではない） |
| `status` が `published` 以外 | overlay に**参加しない**（draft / archived は効かない） |
| 割当先の Content Type が存在しない | 何も起きない（存在する schema にしか重ねない） |

「壊れた定義を黙って空として扱う」と「DB 障害を黙って空として扱う」は別物である。
前者は**画面に出す**ことで silent にしない。後者は**投げる**。

### 形と意味を分ける

`Field::fromArray()` は「型が既知か」「select に choices があるか」という**意味**を見る。
渡された配列の各キーが期待した形であることは前提にしている。ファイル由来の schema なら
レビューを通るのでそれで足りるが、**DB は外部入力**である。

そこで DB 境界にだけ `FieldShape`（**形**の検証）を置く。型の一覧や必須条件は
書き写さない — 写すと 2 か所が食い違う。理由の文字列には**キー名と期待する形**だけを入れ、
値そのものは入れない（`OperationAudit` の metadata 規約と同じ）。

`Field::fromArray()` の呼び出しは `TypeError` / `ValueError` だけを
`InvalidArgumentException` へ変換する取りこぼし用の網で囲む。
`Error` や `RuntimeException` は**そのまま投げる**（実装の不具合まで握りつぶさない）。

弾くのは「落ちる形」だけではない。**黙って捨てられる形**も弾く。

| 入力 | 何が起きるか |
| --- | --- |
| `"choices": {"a": "b"}` | `array_map` を素通りして、**キーを捨てた list** になる |
| `"validation": [1, 2]` | `SchemaRules` は文字キーしか見ないので、**全ルールが無視される** |

どちらも保存できてしまい、書いた人は効いていると誤解する。

**`validation` の空だけは例外。** `json_decode($json, true)` は `{}` と `[]` を
どちらも空配列にするため、空 object と空 list を区別できない。
空はどちらでも「ルールなし」で害が無いので、後方互換として受理する。

## boot は DB へ触れない

`routes/admin.php` と `ModulesServiceProvider::boot()` は
`CmsManagedContentTypes::discover()` で「どんな Content Type があるか」を尋ねる。
overlay は **schema id を増やさない**ので、この問いの答えは overlay の有無で変わらない。

そこで `SchemaRegistry` に 2 つの入口を置く。

| メソッド | 中身 | 使う場所 |
| --- | --- | --- |
| `declared()` | ファイル宣言だけ（**DB を読まない**） | route 登録 / モデル登録 |
| `all()` / `get()` | overlay 込みの effective schema | 画面 / 検証 / API |

これがないと、**MySQL が落ちているときに route 登録が例外を投げ**、
`/health` が `{"ok":false,"db":false}` を返せず 500 になる
（deploy の health check と auto-rollback が壊れる）。

## memoization と寿命

`SchemaRegistry` は既に `$schemas` へメモ化している。overlay も同じ instance 内で
1 度だけ DB を読む。**1 リクエスト 1 クエリ**（SPEC §11）。

問題は「同じ instance」がいつまで生きるかである。

| 登録 | 対象 | 理由 |
| --- | --- | --- |
| **scoped** | `SchemaRegistry` / `SchemaFieldOverlay` / `FieldGroupRepository` / `ReferenceInspector` | runtime に DB を読む。リクエスト / job の境界で捨てる |
| singleton | `ComponentRegistry` / `ThemeTokens` / `TaxonomyDefinitionRegistry` / `ConsumerRegistry` | ファイルだけを読む。runtime に変わらない |

**singleton にしてはいけない。** 「container は 1 リクエスト 1 つ」は PHP-FPM でしか
成り立たず、Octane や長寿命 worker では instance が process に残って
**次のリクエストでも古い Field Group を返す**。`scoped` は 1 リクエスト内では
singleton と同じで、境界で `forgetScopedInstances()` により捨てられる。

`ReferenceInspector` は provider の中へ effective な `SchemaRegistry` を
**捕まえる**ので、捨てる境界を registry と揃える必要がある。

## Consequences

- 正本が 2 か所になる。上の優先順の表が唯一の裁定規則である
- overlay で足した項目は既定で検索・並び替えに使えない（SPEC §6 案 B の帰結）。
  カラム化は 9.5-B 以降の opt-in
- 生成プロジェクトへ Field Group を持ち出すには export が要る（SPEC §7）
- `agentcms:taxonomy-check` は effective schema を読むため、DB が落ちていると失敗する。
  taxonomy 宣言は Field Group から増えないので判定結果は変わらないが、
  実行条件が「DB あり」に寄る

## References

- `docs/PHASE9_5_SPEC.md` §4.2 / §5 / §8 / §10
- `docs/WP_PARITY_BASELINE.md` §0.1（ACF の移植ではない）
- ADR-0004（content schema format）/ ADR-0013（PageDocument）/ ADR-0014（Content Type Builder）
