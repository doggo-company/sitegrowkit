# ADR-0018: Media に種別と派生物を持たせる

- Status: **Accepted**（Phase 9.5-D 実装 Stage D-I で確定）
- Date: 2026-08-15
- Phase: 9.5-D（正本は `docs/PHASE9_5D_SPEC.md`）
- 前提: ADR-0006（Media Module）/ ADR-0004 Rev.2 / ADR-0017 §1（`file` を 9.5-C から分離した理由）

## Context

ADR-0006 の Media Entity は **画像 1 種類**を前提にしている。

- 許可リストは画像 MIME のみ
- `UploadMedia` は保存前に `ImageProcessor::inspect()` を通し、
  `getimagesize()` が読めないものを拒否する
- `width` / `height` は **NOT NULL**
- 派生物は `has_webp`(bool) の 1 列で、path は**原本の拡張子を置換**したもの

9.5-C の preflight で、この構造のままでは `file` 型を実装できないことが実測で確認された
（PDF は inspect と store の両方で落ちる）。ADR-0017 は `file` を「Media 基盤の
独立 Stage へ分離する」と決めた。本 ADR がその Stage の構造を決める。

加えて、現行の派生物の持ち方には 2 つの穴がある。

1. **派生物が原本に結合している**: パスが原本と同じ名前空間にあり、原本の拡張子から
   導出される。実体名は UUID なので**上書きは起きない**が、format を増やすたびに
   導出規則が増え、replace の補償で「消してよいファイル」を名前の推測で決めることになり、
   将来 disk を分けることもできない
2. **形式を増やせない**: `has_webp` は bool 1 列なので、AVIF を足すたびに列が増える

## Decision

### 1. `kind` で種別を分ける（データを分けない）

`media` に `kind = image | file` を持たせる。**テーブルは分けない。**

分けない理由は、利用者から見て「メディアライブラリは 1 つ」だからである（Owner Decision D1）。
テーブルを分けると、一覧・検索・使用中判定・削除保護をすべて 2 系統に増やすことになる。
既に `MediaUsageInspector` は `media` / `file` / `image` / `images` を横断して走査しており、
その構造をそのまま使える。

分けるのは **validation / storage / picker** の 3 つだけである。

### 2. 置き場所は種別で変える

| kind | disk | 到達性 |
| --- | --- | --- |
| `image` | `public` | 直リンクで見える（従来どおり） |
| `file` | `local`（private） | **web から直接到達できない。controller 経由だけ** |

一般ファイルを public disk へ置かないのは、**直リンク = ブラウザが内容を解釈する経路**を
作らないためである。`visibility=public` でも配信は controller を通し、
`Content-Disposition: attachment` と `X-Content-Type-Options: nosniff` を必ず付ける。

`visibility` は `public | private` の 2 値で既定は `public`（Owner Decision D4）。
`private` は認可済みのみで、**未認可には 404 を返す**（403 は「その ID は在る」と教えてしまう）。

`disk` を列で持つのは、同じテーブルの中で置き場所が分かれるからである。
**型からも kind からも導出しない** — 将来 disk を差し替えたときに、
既存行がどこに在るかを言えなくなる。

### 3. 派生物は行にする

`media_variants(media_id, format, disk, path, mime, size, width, height)` を作り、
`media_id + format` を unique にする。

- **原本は必ず残す。** 派生物は「あれば速い」もので、無くても成立する
- path は原本と**別の名前空間**（`media/variants/<uuid>/<format>.<format>`）。
  拡張子置換をやめるので衝突しない
- **状態を列で持たない。** 行が在れば ready、無ければ未生成である。
  「失敗した」を書くと、環境を直したあとに再生成できなくなる
- FK は **RESTRICT**（Taxonomy / Field Group と同じ方針）。掃除は Application 層が明示的に行う

### 4. 生成は同期・bounded best-effort

共有ホスティング（XServer）で動くことを前提にするので、**queue 常駐を必須にしない**。
1 upload で最大 2 つ（avif / webp）を同期生成し、

- 実行環境に能力が無い（**decode と encode を関数ごとに判定**する。
  `imageavif` はあるが `imagecreatefromavif` が無い環境が実在する）
- 対象外（SVG / GIF。GIF は静止・animated とも原本のみでアニメーションを保つ）
- 画素数・メモリ budget の超過
- 生成中の失敗

のいずれでも **upload 自体は成功させる**。派生物が無ければ原本で配信する。

容量・画素の上限は **既存の画像 validator を正本**にする（数字を 2 か所に作らない）。
quality だけ設定可能にする。

### 5. 配信は `<picture>` で AVIF → WebP → 原本

**DB に行がある variant だけ** `<source>` に出す。
ファイルの存在確認を配信のたびに行わない（IO を増やさない）。
ブラウザが対応していなければ `<img>` の原本へ落ちる。

### 6. `file` field は「Media の ID を選ぶ単一参照」

multipart を直接受け取らない（Owner Decision D5）。
これにより、9.5-C で `image` を入れ子に置けなかった理由（`<key>_file` の回収が
トップレベル専用）が `file` には当たらず、**入れ子でも保存できる**。

kind の食い違い（`media` に file を、`file` に image を指す）は
**server validation で拒否**する（`Rule::exists(...)->where('kind', ...)` で
存在と種別を 1 本のクエリで見る）。画面で隠すだけにしない。

**`media.replace` で kind を変えることも禁止**する。差し替えで kind が変わると、
既に保存されている参照の契約が後から破られ、参照側は ID しか持たないので気づけない。

## Consequences

- 「メディアライブラリは 1 つ」という利用者の見え方を保ったまま、
  画像と一般ファイルで異なる安全性の要求を満たせる
- 形式を増やすときに列を増やさなくてよい（`format` の値が増えるだけ）
- 既存の `has_webp` は**当面残す**。読み取りは variants を先に見て、無ければ
  `has_webp` を見る（legacy fallback）。**migration では backfill しない**
  （filesystem や config に依存させない）。写し替えは再実行可能な
  dry-run 既定のコマンドで行う。列の削除は別 Stage
- 派生物の孤児ファイルが残り得る。DB に無いファイルは害が小さく、
  ファイルの無い DB 行は画面が壊れるので、**前者へ倒す**。回収は掃除コマンド（dry-run 既定）
- 一般ファイルは controller 経由なので、画像より配信コストが高い。
  CDN / 外部ストレージは disk 抽象のままにして本 Stage では扱わない

## Owner Gate（確定済み）

`docs/PHASE9_5D_SPEC.md` §12 の **OG1〜OG7 はすべて確定済み**。特に **OG2 の rollback は fail-closed**
（file 行 / variant 行 / null 寸法が 1 件でもあれば何も変更せず停止。部分 rollback を禁止）。
初稿の「no-op で列を残す」は、`kind` を失った file 行が image として扱われるため却下した。
