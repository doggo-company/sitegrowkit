# ADR-0020: モジュールが持つ能力を Core から使うための extension point

- Status: **Accepted**（Phase 9.14 / STAGE 27E-3、2026-08-18）
- Date: 2026-08-18
- Phase: 9.14（正本は `docs/PHASE9_14_SPEC.md` §C.6 / §D.3 / §E.1）
- 前提: `.agentcms/rules/boundaries.md`（MUST）/ ADR-0012（Application Service）/
  ADR-0018（Media kinds と variants）

## Context

WXR の取り込みに `--media` を足す段（27E-3）で、依存の向きが逆になった。

取り込みの実装は Core にある。

- `core/src/Wxr/Apply/` — 分類項目・記事・コメントを入れる 3 つの importer
- `core/src/Wxr/Console/ApplyWxrCommand.php` — それらを呼ぶ入口
- `core/src/Wxr/Rollback/` — 取り消し（27E-1・27E-2）

一方、メディアを保存する口と消す口は**モジュールにある**。

- `Modules\Media\Application\UploadMedia` / `DeleteMedia`（`modules/Media/`）
- `Modules\Media\Models\Media`

`boundaries.md` は次を **MUST** としている。

> Do not add dependencies from `core/` to `app/` or `modules/` —
> dependency direction is always project → core.

実測: この ADR より前、**`core/src` から `Modules\` を参照しているファイルは 1 つも無い**
（参照しているのは `app/` だけ）。つまりこの規則は今日まで守られてきた。

素直に書くと `core/src/Wxr/Apply/MediaImporter.php` が `Modules\Media\Application\UploadMedia`
を参照し、**その規則を最初に破るファイル**になる。同じ壁は取り消し側にもある —
メディアの取り消しはファイルの実体も消すので、その口（`DeleteMedia`）もモジュール側にある。

`boundaries.md` は逃げ道も示している。

> Extend Core ONLY via its published extension points: interfaces/events/config
> under `core/src/Contracts/` and `core/config/`. If an extension point is missing,
> that is a kit-level change (ADR + core release), not a local hack.

`core/src/Contracts/` は**まだ存在しない**。つまりこれは「足りない extension point」の
最初の事例であり、条項どおり ADR が要る。

### なぜ「メディアを取り込まない」で済ませられないか

§C.6（OG5）は「既定では取りに行かない。`--media` を付けたときだけ」を決めている。
**取りに行かない既定は残る。** ここで問われているのは、付けたときに何を通るかである。

保存を通す先が `UploadMedia` でなければならない理由は §E.1 にある —
`UploadValidator`（拡張子と中身 MIME の突き合わせ）・`FileInspector`・`SvgSanitizer` が
そこで働く。取り込み専用の保存経路を作れば、**取り込みだけ検査を通らない穴**になる。
他人のサイトから落としてきたファイルは、その穴を最も踏みやすい入力である。

## Decision

### 1. Core は interface を持ち、実装は project 側に置く

`core/src/Contracts/MediaIntake.php` を Core が定義する。

```
store(string $path, string $filename, OperationContext $context): ?array{id, url}
forget(string $mediaId, OperationContext $context): bool
```

- 実装は `modules/Media/Application/UploadMediaIntake.php` で、`UploadMedia` と
  `DeleteMedia` を呼ぶだけ。**検査はすべてそこで効く**
- binding は `app/Providers/`。**依存の向きは project → core のまま**
- Core は `Modules\` を 1 度も名指ししない

### 2. URL の組み立ても contract の側で吸収する

`store()` は保存した先の URL を返す。Core が `Media` model に触らないためであり、
もう 1 つ実務的な理由がある — `Media::toPickerArray()` の `url` は**画像のときだけ**
値が入り、一般ファイルでは `null` になる（`downloadUrl()` が別に要る）。
どちらも本文の置換に要るので、**どちらを返すかを決めるのは Media を持っている側**である。

### 3. bind されていない project では、断る

実装が bind されていなければ `--media` は**動かない**。黙って添付を飛ばさない（§A.3）。

```
メディアを取り込む口がこの環境にありません（MediaIntake が bind されていません）。
--media を外して実行するか、Media モジュールを有効にしてください。
```

**fail-loud を選ぶ理由**: 黙って飛ばすと「メディアが 1 枚も無い移行」が成功として残る。
十年分の記事に画像が 1 枚も付いていないことに気づくのは、たいてい移行から数日後である。

### 4. この形は「モジュールの能力を Core から使う」一般の型である

Media が最初の実例で、置き場は `core/src/Contracts/`。次に同じ必要が出たときも
**ここへ interface を足し、実装と binding は project 側**に置く。

## Consequences

- Core は `modules/` を知らないまま、モジュールの能力を使える。boundaries の MUST を破らない
- 検査（`UploadValidator` / `FileInspector` / `SvgSanitizer`）は 1 か所のまま。
  取り込みだけが通る保存経路は増えない（§E.1）
- Core の公開面が 1 つ増える。`core/src/Contracts/` に置いたものは**外向きの約束**になり、
  変えるには同じ重さの手続きが要る
- bind を忘れた project では `--media` が止まる。**止まる方を選んだ**（上の 3）
- 生成 project 側の `app/Providers/` に binding が要る。テンプレートに含めるので、
  新規 project では最初から入っている

## Alternatives considered

| 案 | 内容 | 採らない理由 |
| --- | --- | --- |
| 2 | WXR のメディアの段だけ `modules/` に置く | 呼ぶ `ApplyWxrCommand` が Core なので、**同じ違反**になる |
| 3 | Core から `media` 表へ直接 insert する | **§E.1 違反。** `UploadValidator` / `FileInspector` / `SvgSanitizer` を通らない保存経路が生まれる。他人のサイトのファイルが最初にそこを踏む |
| 4 | メディアを別コマンドにして `apply` の後に走らせる | 本文の URL 置換が**記事を作った後**になる。`UpdateContent` を通って投稿の行の `updated_at` が動き、取り消しの (a) が「人が触った」と読む — **取り込んだ直後の記事が 1 件も取り消せなくなる**（§D.3） |

案 4 の却下は 27E-2 の束判定との相互作用から来ている。層をまたいで効くので、
**メディアの段を後ろに置く形は将来も採れない**。取り込みの順（メディア → 記事）は
§C.6 の設計の一部である。
