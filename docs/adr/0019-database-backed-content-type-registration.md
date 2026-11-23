# ADR-0019: 投稿タイプの登録を DB に置く

- Status: **Accepted**（Phase 9.7 / Owner Decision OG-H = (B)、2026-08-15）
- Date: 2026-08-15
- Phase: 9.7（正本は `docs/PHASE9_7_SPEC.md` §I / §F.5.1 / §M）
- 前提: ADR-0004 Rev.2（schema format）/ ADR-0014（Content Type Builder）/
  ADR-0015・0016（Field Group overlay と書き込み経路）/ ADR-0012（Application Service）

## Context

ADR-0014 は Content Type Builder を **ファイル正本**で作った。

- 型の定義は `content/schemas/<id>.json`
- 型を作ると `modules/<Studly>/Models/<Studly>.php` と migration ファイルが**生成される**
- `ContentTypeEnvironment` は、書けない環境では**始める前に**止める（ADR-0014 §7）

この構造は「開発機で型を作り、git に載せ、deploy する」なら正しい。
**しかし Owner は Agent CMS を汎用 CMS と定義した**（`PHASE9_7_SPEC.md` §A.1 R1 / R2）。
サイトの持ち主が**本番の管理画面から**投稿タイプを追加・編集・無効化・削除できることが要件である。

そこで実測すると、本番の形によって成立しない。

| 本番の形 | ファイル正本で起きること |
| --- | --- |
| immutable FS / read-only container | **型を作れない**（環境検査が止める） |
| 複数 replica | **1 台にだけファイルが書かれる**。他の replica はその型を知らない |
| deploy で入れ替わる FS | 次の deploy で**書いたファイルが消える** |
| opcache | 生成した PHP が**すぐには読まれない** |

さらに Agent CMS は **1 つの型 = 1 つのテーブル**である（WordPress のように
`wp_posts` へ `post_type` 列で相乗りしていない）。つまり型を作ることは **DDL を打つこと**であり、
「どの経路で DDL を打つか」を決めない限り、この要件は満たせない。

「本番では管理画面から型を作らない運用にする」で済ませることはできない。
それは R1 / R2 を実質的に取り下げることになる。

## Decision

### 1. cms-managed 投稿タイプの正本を DB に置く

`content_types` 表が、**管理画面から作られた型の登録情報と設定の単一情報源**である。

- 型を作るのに **ファイルを書かない**
- 型ごとの **PHP モデルファイルを生成しない**（汎用モデルが検証済み内部 ID から `setTable` する）
- 管理 route は **総称 `{type}`** で登録し、**boot で型の集合を列挙しない**

これで immutable FS でも複数 replica でも成立する（DDL は共有 DB に効くため）。

### 2. `content/schemas/*.json` は import / export の成果物にする

正本ではなくなるが、**捨てない**。

| 用途 | 内容 |
| --- | --- |
| export | `content_types` の行を JSON へ書き出す。**git に載せて版管理できる**（ファイル正本の唯一の利点を残す） |
| import | 別環境（staging → 本番、kit → 生成 project）へ適用する |
| review | 型の変更を diff で読む |

### 3. 1 型 1 テーブルと Field の storage 分類は変えない

WordPress 方式（共有 1 テーブル + meta）は**採らない**。
一覧・検索・並べ替え・件数の速さは column にデータが載っていることから来ており、
それを捨てると Agent CMS の実装の前提（`Field::storage` の column / json 分類、
`ContentValues` の値分割、`admin.list` / `admin.search`）が一斉に崩れる。

### 4. DDL は transaction に入れない

`Schema::create` も `DROP TABLE` も**暗黙 commit を起こす**。したがって
「作って、失敗したら無かったことにする」は書けない。代わりに**段階を分けて冪等にする**。

```
作成: 登録行 insert（lifecycle=provisioning）→ commit → Schema::create → 登録行を ready へ
撤去: lifecycle=deprovisioning → DML の塊で行を消す → DROP TABLE → tombstoned
```

途中で落ちたら**続きから再開する**。詳細は `PHASE9_7_SPEC.md` §I.3 と §F.3.2。

### 5. ファイル型と DB 型を同じ read model で合成する

移行を強制しない。**既存の生成 project は 1 行も変えずに動き続ける。**

| 規則 | 内容 |
| --- | --- |
| 合成 | ファイル型（`SchemaRegistry::declared()`）と DB 型を 1 つの一覧にする |
| ID 衝突 | **ファイル型が勝つ**（ADR-0015 の overlay と同じ向き） |
| 既定値 | ファイル型に `content_types` の行が無くても `active` / `ready` / `menu_order = 0` / コメント無効 / リビジョン有効として読む |
| 移行 | **任意**。`import` で移せるが、移さなくても動く。migration は表を作るだけで、既存の型を 1 件も import しない |

## Supersedes

**ADR-0014 のうち、cms-managed 型について次を前提にしている部分だけを置き換える。**

| ADR-0014 の記述 | 本 ADR での扱い |
| --- | --- |
| cms-managed 型の正本が `content/schemas/<id>.json` であること | **置き換え**。正本は `content_types` |
| 型ごとに `modules/<Studly>/Models/<Studly>.php` を生成すること | **置き換え**。汎用モデルが `setTable` する |
| 型ごとに migration ファイルを生成すること | **置き換え**。適用時に `Schema::create` する |
| 書けない FS では型を作れないこと（§7 `ContentTypeEnvironment`） | **置き換え**。DB へ書ければ作れる |
| 補償を「作ったファイルを逆順に取り消す」で行うこと（§5） | **置き換え**。段階のやり直し（DDL は戻せない） |

**維持するもの（置き換えない）:**

| ADR-0014 の記述 | 理由 |
| --- | --- |
| `ContentTypeIdentifier` による ID の一元検証（`\A[a-z][a-z0-9_]{1,39}\z`、予約語、決定的な導出） | **識別子注入を構造的に防いでいる唯一の場所**。DB 正本でも `Schema::create` のテーブル名はここを通ったものだけを使う |
| preview → 明示確認 → apply（§3） | 変えない |
| Admin-only operation を `OperationCatalog` に載せないこと（§4） | 変えない |
| **code-managed 型**（`managedBy: "code"`。開発者 / AI が module で足す拡張） | **そのまま維持する**。ファイルが正本であり、生成もしない |

## Consequences

### 得るもの

- **本番の管理画面から型を作れる。** immutable FS / 複数 replica / deploy で消える FS のいずれでも成立する
- **boot が型の集合を知らなくてよい。** 総称 route にすれば、`SchemaRegistry::declared()` を
  route 登録で読む必要が無くなる（ファイル型のためには読み続ける）
- **生成物が減る。** 型を作るたびに PHP と migration が git に積まれることが無くなる

### 失うもの・代償

| 代償 | 内容 |
| --- | --- |
| 型ごとの PHP モデルに手を入れられない | scope / accessor / cast を型ごとに書きたいなら **code-managed 型**（`managedBy: "code"`）で作る。拡張機構としてそちらは残る |
| 実行時 DDL | 要求処理の中で `Schema::create` / `DROP TABLE` を打つ。**確認・権限・監査・fail-closed・段階の冪等性**を必須にする |
| 孤児が起き得る | 「登録行だけある」「テーブルだけある」が起き得る。診断コマンドで検出し、**自動では消さない** |
| git で型の変更を追えない | `export` で JSON を書き出して git に載せる運用で埋める |
| 読み取り経路が 2 つになる | ファイル型と DB 型。**合成は 1 か所**（`ContentTypeSettingsResolver` / read model）に閉じる |

### この ADR が決めていないこと

- 作成 apply（runtime DDL の実行）、編集・archive・復元・並べ替えの UI と operation — **Stage 20C 以降**
- taxonomy 定義の正本（OG-G = `TaxonomyManagedBy` で分離）の実装 — **Stage 20D**
- purge の実装 — **P0-6（backup）の後**
