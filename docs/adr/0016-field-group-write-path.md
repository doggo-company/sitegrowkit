# ADR-0016: Field Group を管理画面から編集する

- Status: Accepted
- Date: 2026-08-15
- Phase: 9.5-B（`docs/PHASE9_5_SPEC.md` §7 / §13）
- Owner Gate: **G1(c) / G2(a)+(c opt-in) / G3(b) / G4(a) / G5(c) / G6(a) / G7(b) / G8(b) / G9(b) / G10(b) / G11(a)**
- 前提: ADR-0015（overlay の読み取り契約）/ ADR-0004 Rev.2（`storage` キー）

## Context

9.5-A は overlay を **読む側**だけを作った。定義を増やす経路が無いので、
`SchemaMutationClassifier`（Phase 9.2B から存在する危険度分類）は
production で 1 度も呼ばれていなかった（SPEC F-2）。

9.5-B は書く側を開ける。書く側を開けると、次の 3 つが同時に現実になる。

1. 投稿タイプの項目が **管理画面から増える**（migration なしで）
2. 一度作った項目を **改名・削除できる**ようになる
3. 定義を壊す操作が **利用者の手で**実行できるようになる

## Decision

### 1. 値の置き場所（G2-(a)）

Field Group が足した項目は、型に関わらず **`data` JSON** へ入れる。
overlay は読み込み時に `storage: json` を付け、DB の宣言に `storage` を
書くことは**できない**（`FieldShape` が拒否する）。

カラム化（G2-(c)）は migration を伴う opt-in で、この Stage では開けない。
DB 側から `column` を選べると、**存在しないカラムへ書きに行って投稿の保存が壊れる**。

### 2. 値を消さない（G3-(b)）

| 操作 | 定義 | 投稿に入っている値 |
| --- | --- | --- |
| 項目を外す | 消える | **残る**（`data` JSON の中） |
| 項目を改名する | key が変わる | **残る**（旧 key のまま） |
| 束をアーカイブする | 残る | **残る** |
| 束を復元する | 戻る | 再び見える |

**物理削除の route も service も作らない。** 破壊的操作は DELETE 動詞ではなく
`POST .../archive` + 確認トークンに揃える（利用者の管理と同じ作法）。

確認画面には必ず「入力済みの値は削除されません」と書く。
「消える」と誤解させたまま同意を取らない。

### 3. 危険度分類を入口へつなぐ（SPEC F-2 の解消）

`SchemaMutationClassifier` の最初の呼び出し元が `UpdateFieldGroup` になる。

| 分類 | 例 | 扱い |
| --- | --- | --- |
| `safe` | 項目の追加 / 表示名 / 説明 / 選択肢の追加 | そのまま適用 |
| `migration_required` | 改名 / 型変更 / 必須化 | **確認ゲート** |
| `destructive` | 項目の削除 / 選択肢の削除 | **確認ゲート** |

`migration_required` は overlay では SQL の migration を要さないが、
**値の見え方が変わる**ので同じ重さで確認する。

### 4. 項目の同一性は immutable ID（G10-(b)）

作成時に全項目へ ULID を採番する。追跡は key ではなく ID で行うので、
改名しても PageDocument の binding が切れない。

**他の束の ID を持ち込めない。** 更新時に受け取れるのは、その束が現在持っている
ID だけである（他の束の項目を横取りできてしまうため）。

### 5. 確認トークンは「受け取った入力」へ束縛する

`ConfirmationGate` の指紋を、正規化後の宣言ではなく **入力そのもの**へ束縛する。
正規化後には新しい項目へ採番したばかりの ULID が混ざるので、
指紋に含めると 1 回目と 2 回目で必ず値が変わり、承認しても保存できなくなる。

### 6. 型の allowlist（9.5-B の範囲）

管理画面の入力 UI が描ける型だけを選ばせる。

| | 型 |
| --- | --- |
| 選べる | text / textarea / richtext / number / url / email / tel / date / datetime / boolean / select / radio / checkbox / image / media |
| 選べない（9.5-C で開ける） | group / repeater / relation / relations / images / file — **入力 UI が無い**（SPEC F-3） |
| 選べない（恒久） | slug / status — 投稿タイプ固有の意味（一意性・公開状態）を持つ |

無い型を選べると「作れたのに値を入れられない」になる。

### 7. 公開フォームでは richtext を選ばせない（G9-(b)）

`form` の割当先を持つ束では richtext を選べない。公開フォームは常に外部入力で、
XSS 面を増やす型を持ち込ませない。定義は共用するが、実行の強さは分ける。

### 8. キーの衝突は書く側でも拒否する

読み込み時はファイルが勝って overlay 側が捨てられる（ADR-0015）。
だから作れてしまうと **「作ったのに出ない」** になる。

- ファイル宣言と同じ key → 拒否
- 同じ投稿タイプに当たる **別の公開中の束**と同じ key → 拒否
- 予約 key（`taxonomies`）→ 拒否
- 束の中での重複 → 拒否

復元も同じ理由で衝突を調べる。アーカイブ中に同じ key が増えているかもしれない。

### 9. 版はすべて残す（G5-(c)）

create / update / archive / restore / reorder のたびに `field_group_revisions` へ
**保存後の状態**を 1 行残す。中身は定義そのもの（key / label / status / 並び順 /
項目 / 割当先）で、投稿の値は 1 つも含まない。

draft / publish の運用は Phase 2。9.5-B の status は実質 `published` と `archived` の
2 値で回す（作成 = published / archive = archived / restore = published）。

### 10. 並べ替えは全件をまとめて受け取る

部分的な並び替えを受け取らない。欠けた束の位置が黙って変わり、
「触っていない束の順番が入れ替わった」が起きるため。
送られた list は現在ある束の全 ID と**ちょうど一致**していなければならない。

### 11. AI へは公開しない（G7-(b)）

`OperationCatalog` に 1 件も足さない。公開 API の route も増やさない。
構造を変える操作は不可逆で、ADR-0014 が Content Type 作成で同じ判断をしている。

## 面

| route | operation | 分類 |
| --- | --- | --- |
| `GET /admin/field-groups` | `field_group.list` | Read |
| `GET /admin/field-groups/create` | — | — |
| `POST /admin/field-groups` | `field_group.create` | Write |
| `POST /admin/field-groups/reorder` | `field_group.reorder` | Write |
| `GET /admin/field-groups/{id}` | `field_group.get` | Read |
| `PATCH /admin/field-groups/{id}` | `field_group.update` | Write / **Destructive** |
| `POST /admin/field-groups/{id}/archive` | `field_group.archive` | **Destructive** |
| `POST /admin/field-groups/{id}/restore` | `field_group.restore` | Write |

capability はすべて `content_type.manage`（投稿タイプと同格）。
`{id}` は ULID の形でしか route に入らない。

監査 metadata は **key / 変更の種別 / 件数**だけ。値・選択肢・利用者入力は載せない
（`SchemaMutationClassifier` の理由文には選択肢の値が入るので、
確認画面には出すが監査には渡さない）。

## Consequences

- 投稿タイプの項目を、リポジトリを編集せずに増やせるようになった。
  読み取り専用リリースでも動く（G8）
- 足した項目は `data` JSON なので、**一覧の絞り込み・並べ替えに使えない**（G2-(a) の帰結）。
  画面にもそう書いてある。カラム化が要るなら 9.5-C 以降の opt-in
- 書き込みは 1 リクエスト 1 操作を前提にする。読み取り側は scoped でメモ化されるので、
  同じリクエストの中で 2 回書くと 2 回目は古い状態を見る（DB の unique が最後の砦）
- `group` / `repeater` の入力 UI が無いので、繰り返し項目はまだ作れない（9.5-C）
