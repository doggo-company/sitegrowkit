# ADR-0014: 管理画面から Content Type を作る（Content Type Builder）

- Status: Accepted
- Date: 2026-08-14
- Phase: 9.4 / STAGE #17A
- Owner Gate: **G4 — 破壊的スキーマ変更の既定動作 = 拒否**

## Context

Phase 9.3 までで Schema 駆動の CRUD・Taxonomy・ページは揃ったが、
**Content Type を増やすにはリポジトリを編集する必要があった**（`content/schemas/*.json` +
`modules/<Name>/` + `ModulesServiceProvider` の 1 行）。

Owner の運用像は「通常運用や標準機能を AI 必須にしない」であり、
WordPress の Custom Post Type UI 相当の GUI が無いことは、
標準機能としての欠落にあたる（PHASE9_SPEC §5 で **Mis** と判定済み）。

一方で、Content Type の追加は **DB スキーマとファイルを不可逆に変える**操作である。
「AI に任意の SQL を実行させる」方向へ倒すと、製品の安全性の前提が崩れる。

## Decision

### 1. 生成物は「モジュール一式」。新しい永続化フォーマットを作らない

作成すると **3 つのファイル**が生成される。

| 生成物 | 例（id = `faq`） | 役割 |
| --- | --- | --- |
| Content Schema | `content/schemas/faq.json` | 項目定義の SSoT（**既存フォーマットのまま**） |
| モデル | `modules/Faq/Models/Faq.php` | schema id とテーブルを結ぶだけの 20 行 |
| migration | `modules/Faq/database/migrations/<ts>_create_faq_table.php` | カラム定義 |

schema には `"managedBy": "cms"` が入る。これは **Phase 9.2B から
`ContentSchema` に存在するキー**であり、STAGE #17A で新しい永続化形式は
1 つも追加していない（Data Structure Discipline）。

### 2. 登録簿を増やさない — ID からの決定的な導出

`content/schemas/*.json` が引き続き唯一の情報源である。
`managedBy: "cms"` の collection schema を見つけたら、モデルの FQCN は
**ID から決定的に導出**する（`ContentTypeIdentifier`）。

```
faq          → table faq          / Modules\Faq\Models\Faq          / /admin/faq
event_report → table event_report / Modules\EventReport\Models\...  / /admin/event_report
```

対応表ファイルを持たないので、「登録簿と実体がずれる」状態が原理的に発生しない。
テーブル名は **ID そのまま**（既存 news / works と同じ規約。owner 合意）。

### 3. Preview → Confirm → Apply（既存 ConfirmationGate を再利用）

`POST /admin/content-types` は 1 本だが、確認トークンの有無で挙動が変わる。

1. トークン無し … 検証 + 衝突検査 + 環境検査 + **計画の提示**。
   **この時点でファイルも DB も変わらない**
2. トークン有り … 適用

トークンは削除操作と同じ `ConfirmationGate` が発行し、1 回で使い切る。
束縛先は「ID + 入力の意味的な指紋」であり、**採番済みの field ULID や
実行時刻を含めない**（同じ内容を確認して適用しただけでトークンが
食い違うのを防ぐ）。

分類は既存の `OperationClassification::ExternalEffect` を使う。
**新しい enum case は増やさない**。

### 4. AI へは公開しない

`content.type.create` は `OperationCatalog` に **載せない**。
MCP tool も増えない（29 のまま）。Application Service 側でも
`OperationInterface` を Admin / CLI に限って二重に閉じる。

作った Content Type の**エントリ操作**は、これまでどおり
`content.*` の汎用操作で AI から扱える（型ごとにツールを増やさない方針は不変）。

### 5. 半端な状態を作らない

| 守ること | 手段 |
| --- | --- |
| 途中まで書けた内容を読ませない | 同ディレクトリの一時ファイルへ書き切り、`fsync` してから `link()` で公開 |
| 二重生成しない | 同一 ID の cache lock（計画再確認 → rollback 完了まで保持）+ `link()` の EEXIST |
| 型が「半分だけ存在する」状態を作らない | **schema を最後に公開**する（モデル → migration → テーブル → schema） |
| 失敗して残骸を残さない | 作ったものだけを逆順に取り消す。取り消せなかったものは画面に出す |

`rollback` は **自分が作ったものだけ**を消す。`hasTable()` が真だから消す、
という判断はしない（並行実行の相手の成果物を巻き添えにしないため）。

### 6. 破壊的変更は拒否（Owner Gate G4）

STAGE #17A でできるのは **新規追加だけ**である。
編集・フィールド削除・改名・型変更・必須化・choices 削除・
Content Type 削除・テーブル削除は **route も UI も置かない**。
次 Stage の Owner Gate 対象。

### 7. Production では「始める前に」止める

`ContentTypeEnvironment` が適用前に次を確かめ、1 つでも欠ければ変更を始めない。

- `content/schemas` と `modules/` に書けるか
- Composer のオートローダーが `classmap-authoritative` でないか
- ルートキャッシュが有効なとき `bootstrap/cache` に書けるか
- データベースへ到達できるか

事前検査は生成時の失敗を覆えないので、`route:cache` の再生成は
**終了コードを見て**失敗なら rollback させる。

### 8. 公開サイトは対象外

公開 Blade・permalink・メニューへの追加・一覧テンプレートの自動生成は行わない。
未知のデザインを推測で作らないという Preserve First の方針（ADR-0010）と同じ理由。
公開 route を持たない型では、管理画面の「公開ページを見る」も出さない。

## Consequences

### 良くなること

- コード編集なしで投稿タイプを増やせる（CPT UI 相当の基盤）
- 追加直後から 管理画面 / API / MCP / Application Service のすべてで使える
- サイドバーが実行時の登録簿から作られ、`news` / `works` の hardcode が消えた

### 引き受けるコスト

- **本番のファイルシステムへ書き込む**。読み取り専用リリースでは作成できない
  （事前検査で明示的に止める）
- ルートキャッシュを使う環境では作成のたびに再生成が要る
- 生成した PHP は PSR-4 で読み込む。`classmap-authoritative` な
  オートローダーとは両立しない

### 既存への影響

- `ContentSchema` / `Field` / `SchemaResourceController` は無変更
- `Result::confirmationRequired()` に **省略可能な** `$data` を追加（後方互換）
- `SchemaFieldInput.vue` に `number` / `url` / `tel` / `radio` / `checkbox` の入力を追加
  （これまで schema にあっても入力欄が無かった型。`product` の該当項目も編集できるようになる）
- `core/config/agentcms.php` に `modules_path` を追加（既定は `base_path('modules')`）

## Alternatives considered

### 動的モデル（PHP を生成しない）

`core` に汎用モデルを置き、テーブルと schema を実行時に束縛する案。
PHP ファイルを増やさずに済むが、

- migration は結局ファイルとして必要（`migrate:fresh` を成立させるため）
- `ContentTypeResolver` と各 Application Service の static 呼び出しを
  全面的に書き換える必要があり、既存 news / works / product の回帰リスクが大きい

生成する PHP は ID からの決定的な定型文（自由入力を 1 文字も含まない）なので、
リスクの小さい側を選んだ。

### `cms_` テーブル接頭辞

衝突事故を構造的に避けられるが、code 管理（接頭辞なし）と
CMS 管理（接頭辞あり）で規約が 2 種類に割れる。
衝突は作成時に拒否できるので、規約を 1 つに保つ方を選んだ。

## References

- `docs/PHASE9_SPEC.md` Phase 9.4
- `template/core/src/ContentTypes/`
- `template/core/src/Application/ContentTypes/`
- ADR-0004（Content Schema フォーマット）/ ADR-0012（Application Service）
