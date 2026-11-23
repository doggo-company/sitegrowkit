# WordPress からの引っ越し

いま動いている WordPress の記事を、SiteGrowKit へ持ち込む手順です。
**ターミナルで 4 つコマンドを打つだけ**で、途中でやめても戻せます。

> **先に知っておいてください**
>
> - 持ち込めるのは **記事・分類・コメント・（指定すれば）添付画像**です
> - **対応するページは取り込めます。メニューの自動移行は対象外です。** 事前診断で変換できないデータを確認してください
> - プラグイン・テーマ・shortcode は動きません。shortcode は**文字のまま本文に残ります**
> - 引っ越しは **1 案件につき 1 回**の作業です。画面（ボタン）は用意していません —
>   数百 MB のファイルを管理画面から上げる口は開けたくないのと、
>   実行前に backup と下見の確認が要るためです

---

## コマンドの打ち方

この文書のコマンドは、すべて**プロジェクトのフォルダの中**（`agentcms create` で作ったフォルダ）で
`agentcms dev` が動いている状態で打ってください。`docker compose` はそのフォルダの
`docker-compose.yml` を見ます。

WXR ファイルもプロジェクトのフォルダの中に置いてください。
コンテナから見えるのはそこだけなので、デスクトップに置いたままでは読めません。

---

## 0. WordPress から書き出す

WordPress の管理画面で **ツール → エクスポート → すべてのコンテンツ** を選び、
`xxx.WordPress.yyyy-mm-dd.xml` をダウンロードします。これが WXR ファイルです。

プロジェクトのフォルダに置いておくと、以降の手順が楽です。

---

## 1. 何が入っているかを見る（ファイルだけ・DB は読みません）

```bash
docker compose exec app php artisan agentcms:wxr-inspect wp-export.xml
```

item の件数、種類（post / page / attachment …）、状態、分類、
shortcode が残っているか、本文が大きすぎる item が無いかが出ます。

分類は「**延べ / 参照 N 種 / 定義 M 件**」の 3 つが出ます。
🔴 **定義が 0 件でも取り込めます**（2026-09-04 変更）。定義ブロック（`<wp:category>`）
が無いだけで、記事側には名前が入っているためです。ただし**親子関係は分かりません**
— `agentcms:wxr-plan` / `agentcms:wxr-apply` は既定で、親子の無い**平らな項目**として
作ります。従来どおり止めたい（書き出し直しを求めたい）場合は、
`--strict-term-definitions` を付けてください。

**この段階では、こちらのサイトの状態は一切見ていません。** ファイルの中身だけです。

---

## 2. 受け皿を用意する — plan に作らせる

WXR にある分類（`category` / `post_tag` など）は、**こちらに同じ受け皿が要ります。**

`--create-taxonomies` を付けて plan を 1 回走らせると、足りないものを作ります。

```bash
docker compose exec app php artisan agentcms:wxr-plan wp-export.xml \
  --type=post:post \
  --taxonomy=category:wp_category \
  --create-taxonomies

docker compose exec app php artisan agentcms:taxonomy-sync
```

作ったファイルは一覧で出ます。**既にあるものは上書きしません**（手で直したものは残ります）。
`--create-taxonomies` を付けないかぎり、plan は 1 バイトも書きません。

見せる名前（`label`）と親子の有無は、`--taxonomy=` の**左**（WordPress 側の名前）から決まります。
`category` なら「カテゴリー」で親子あり、`post_tag` なら「タグ」で平らです。
WordPress の独自分類は、左に書いた名前がそのまま `label` になるので、**あとで日本語に直してください**
（`content/taxonomies/<キー>.json` の `label` を書き換えて `agentcms:taxonomy-sync`）。

<details>
<summary>手で書く場合（コード管理を自分で決めたいとき）</summary>

ファイルは 2 つです。**どちらも管理画面ではできません。**

**① 分類そのもの** — `content/taxonomies/wp_category.json`

```json
{
  "key": "wp_category",
  "label": "カテゴリー",
  "hierarchical": true,
  "managed_by": "code"
}
```

**② 入れ先の型への宣言** — `content/schemas/post.json` の `"admin"` の手前へ

```json
  "taxonomies": [
    { "key": "wp_category", "multiple": true, "required": false }
  ],
```

書いたら `agentcms:taxonomy-sync` を実行します。

</details>

🔴 **管理画面の「分類管理」で先に作らないでください。** そこで作った分類（CMS 管理）は
取り込みからは見えず、**同じキーのファイル定義と衝突して `taxonomy-sync` が止まります**。
キーは後から変えられず、一度でも使った分類は消せません。
（衝突したときは、`taxonomy-sync` が抜け方を出します）

記事の入れ先も決めておきます（`post` を `news` に入れるのか、`post` のままにするのか）。
**入れ先の投稿タイプが管理画面に出ていること**を先に確かめてください
（ダッシュボードの「記事を書けるようにする」で出せます）。出ていない型を
`--type=` に書くと、plan が `unknown_type` で止まります。

## 3. 下見をする（ここでも何も書きません）

```bash
docker compose exec app php artisan agentcms:wxr-plan wp-export.xml \
  --type=post:news \
  --taxonomy=category:news_category
```

- `--type=<WXR の post_type>:<こちらの型>` — 記事の入れ先
- `--taxonomy=<WXR の分類>:<こちらの分類>` — 分類の入れ先
- `--media` — 添付画像も持ち込むとき（付けないと画像は入りません）
- `--strict-term-definitions` — 分類の定義が 1 件も無いとき、平らな項目を作らず
  従来どおり止める（既定は付けなくても取り込めます）

**入るもの**の件数と、**気をつけること**が出ます。
問題が無ければ最後に **64 桁の値（plan-hash）** が出ます。これを 5 で使います。

止まったときは、出ている理由をそのまま読んでください。よくあるのは:

| 出る理由 | 意味 | 直しかた |
| --- | --- | --- |
| `missing_taxonomy` | 受け皿の分類が**ファイルで定義されていない** | `content/taxonomies/<キー>.json` を作って `agentcms:taxonomy-sync`（§2 ①） |
| `taxonomy_not_synced` | ファイルは在るが DB へ同期していない | `agentcms:taxonomy-sync` |
| `taxonomy_not_declared_for_type` | 分類は在るが、その型に宣言していない | `content/schemas/<型>.json` に `taxonomies` を足す（§2 ②） |
| `term_definition_missing` | 同じ分類に**他の**定義はあるのに、この項目だけ WXR に定義が見当たらない（書き出しが壊れている・途中で切れている疑い） | 元の WordPress で「**すべてのコンテンツ**」を選んで書き出し直す |
| `flat_taxonomy_term_created`（止まりません） | その分類に定義が**1 件も無い**ので、記事側の名前から**平らな項目**（親子無し）を作りました | そのままで問題なければ何もしなくてよい。従来どおり止めたいときは `--strict-term-definitions` を付けてもう一度 |
| `unknown_type` | `--type=` で入れ先を決めていない post_type がある | `--type=<WXR の型>:<こちらの型>` で対応づける |
| `type_not_public` | 入れ先の型が**公開されない設定**。入れても誰の目にも触れない | ダッシュボードの「**記事を書けるようにする**」を押す（サイドバー・公開スラッグ・公開する・個別ページ・一覧ページを 1 回でまとめて設定します）。手で直すなら 設計 > 投稿タイプ > 設定 |
| `type_comments_disabled` | コメント付きの記事だが、入れ先の型がコメントを使わない設定 | 投稿タイプの設定で「コメントを使えるようにする」 |
| `meta_dropped`（止まりません） | WordPress のカスタムフィールドに、こちらの型で対応する欄が無い | 落としてよければ何もしなくてよい。残したいなら先に `content/schemas/<型>.json` へ欄を足す |

🔴 **素のプロジェクトで `--type=post:post` を打つと、まず `type_not_public` で止まります。**
`post` は作られていますが、**公開する設定はまだ入っていない**ためです。ダッシュボードの
「記事を書けるようにする」を先に 1 回押してください（§2 の最後にも同じことを書いています）。

**`skipped_nav_menu_item` / `skipped_attachment` / `shortcode_present` は止まりません。** ページは release-20260909 から `pages` へ入ります（`docs/release-20260909/wxr-pages.md`）。
「持ち込まないもの」を黙って消さずに数えて出しているだけです。

---

## 4. backup を取る

```bash
docker compose exec app php artisan agentcms:backup
```

取り込みは大量の行を作ります。**必ず先に取ってください。**

---

## 5. 取り込む

**3 の最後に出た `agentcms:wxr-apply` の行を、そのままコピーして貼るのが一番確実です。**
ファイルも 64 桁の値も指定も、3 で使ったものがそのまま入っています
（`docker compose exec app` だけ頭に付けてください）。下は、その行がどういう形になるかの例です。

```bash
docker compose exec app php artisan agentcms:wxr-apply wp-export.xml \
  --i-have-a-backup \
  --plan-hash=<3 で出た 64 桁> \
  --type=post:news \
  --taxonomy=category:news_category
```

🔴 **手で打ち直すときは、`--type` / `--taxonomy` / `--media` / `--strict-term-definitions` を 3 と同じものにしてください。**
違うものを渡すと plan-hash が一致せず、止まります。
（ファイルか指定が変わったのに、古い下見のまま流すのを防ぐためです）

`--taxonomy=` を落とすと、**分類はちゃんと在るのに `missing_taxonomy` /
`taxonomy_not_declared_for_type` で止まります**（WXR 側の綴りを、こちらの分類の
キーとして探しにいくためです）。この 2 つが出たら、まず指定の付け忘れを疑ってください。

終わると **取り込みの結果**（何がいくつ入ったか）と、**取り込みの id** が出ます。
結果は 3 の下見と同じ書き方なので、**予告と見比べて**ください。id は 6 で使うので控えておきます。

```
取り込みの結果:
  create                        8 件
    content                     5 件
    term                        3 件
取り込みを記録しました: 01J...
```

`skip_existing` は「台帳に既にあるので作らなかった」ものです（2 回流したときはこれになります）。

---

## 6. 見て、必要なら戻す

管理画面で記事・分類・コメントを確認します。

やり直したいときは:

```bash
docker compose exec app php artisan agentcms:wxr-rollback <5 で出た id> --i-have-a-backup
```

**こちらが作ったもののうち、取り込んだあとに触られていないものだけ**を消します。
あなたが編集した記事は残ります（勝手に消しません）。

---

## うまくいかないとき

| 症状 | 見るところ |
| --- | --- |
| `Command "agentcms:wxr-plan" is not defined` | 古い版です。アップデートしてください |
| plan で `missing_taxonomy` が消えない | 分類の**綴り**（slug）が合っているか。`--taxonomy=` の左が WXR 側、右がこちら側です |
| plan-hash が一致しない | 3 と 5 で `--type` / `--taxonomy` / `--media` / `--strict-term-definitions` を揃えているか。ファイルを差し替えていないか |
| 画像が入らない | `--media` を付けているか。Media モジュールを外した構成では止まります |
| shortcode が本文に残っている | 仕様です。展開も削除もしません。取り込んだあとに置き換えてください |
