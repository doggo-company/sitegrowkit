# Phase 9.14 Benchmark — WordPress WXR Import / Rollback

**Baseline:** 27E 完了時点（833d9a4）
**Final Status:** **PASS**
**Research Question:** 十年分の WordPress を取り込み、取り消し、もう一度取り込めるか。
そのとき何を何回引いていて、どれだけ時間とメモリを使うのか。

> **27F は計測と記録である。** 出た数字を根拠に何かを直すことはしていない。
> 直すかどうかの判断材料をここに置く。

## 結果サマリ

| Acceptance | 環境 | 結果 | 備考 |
| --- | --- | --- | --- |
| kit 側 `agentcms verify` | repo dist build | **PASS** | 10/10 |
| 生成 project 側 `agentcms verify` | 使い捨て生成 project | **PASS** | 10/10。**27 系の変更込みでは初回**（それまで一度も回していない） |
| `ImportLedgerMysqlTest` | 隔離 MySQL 8.4.9 | **PASS** | 13/13 |
| Wxr 系全体（27F 時点） | 隔離 MySQL 8.4.9 | **PARTIAL** | 936 中 929 passed / **6 件が MySQL でだけ落ちた**（下記） |
| Wxr 系全体（**27F-b 後**） | 隔離 MySQL 8.4.9 | **PASS** | **1,066 中 1,065 passed・0 failed**（1 skip はこの計測テスト自身） |
| 実データ規模 e2e | 隔離 MySQL 8.4.9 | **PASS** | apply → rollback → 再 apply が通り、**複製が生まれない** |

## 実データ規模の計測

fixture は生成する（`template/tests/fixtures/wxr/generate.php`）。**生成物は repo に入れない。**
規模は記事 3,000 / コメント 12,000（うち返信 3,000）/ 分類項目 200（親子 2 段）/ 添付 500、
**8.2 MB**。1/5 の記事が `comment_status = closed`（受付設定の行を持つ束と持たない束を混ぜる）。

| 区間 | 秒 | 問い合わせ数 | 台帳 select | `content_comments` select | ピークメモリ |
| --- | --- | --- | --- | --- | --- |
| `plan` | **0.48** | 37 | 34 | 0 | 46.5 MB |
| `apply` | **67.3** | 130,602 | 21,734 | 0 | 82.5 MB |
| `rollback` | **113.6** | 97,908 | 6,004 | **15,000** | 88.5 MB |
| 再 `apply` | **66.4** | 130,600 | 21,734 | 0 | 93.5 MB |

取り消し後: 記事 0 / コメント 0 / 分類項目 0 / メディア 0 / **台帳の行 0**。
再取り込み後: 記事 3,000 / コメント 12,000 — **二重にならない**。

## 計測項目

### F-5 — 1 記事あたりの台帳照会数: **7.245**

`apply` の `import_records` への select 21,734 ÷ 記事 3,000。
記事だけでなく分類項目・コメント・メディアの照合もこの数に入っている
（コメントは記事の 4 倍あるので、実際には**コメント 1 件あたり 1 回**が主成分である）。

### F-8 — ファイルの走査回数: **7 回**（`apply` 6 + `plan` 1）

`apply` の内訳は fingerprint / graph / media / term / content / comment。

**経緯の訂正（実測）**: 「`ImportGraph` の切り出しで 1 回減った」という理解が
チーム内にあったが、`39b2c10`（切り出しの commit）の前後で
`ApplyWxrCommand` の `new WxrReader` は **3 → 4 に増えている**。
`ImportGraph` が避けたのは「**項目を作る側と記事を結び付ける側が
別々に組んだ場合の 2 周**」であって、絶対数が減ったわけではない。
**代替案より 1 回少ない**、が正しい。

### BundleJudge の二重問い合わせ: **束あたり 5 回**

取り消しの `content_comments` への select 15,000 ÷ 束 3,000。内訳は

1. `judge()` の一覧取得（transaction の外）
2. `ownedCommentIds()` の id 取得（同上）
3. `judge()` の一覧取得（transaction の中でやり直す）
4. `ownedCommentIds()` の id 取得（同上）
5. `deleteBundle()` が消す前に読む id

**2 回は掴んだ後の再判定のためで、意図している**（判定と削除の間に人が触れる隙を塞ぐ）。
27E-2 で「直さず 27F の測定対象へ」と決めた二重は 1〜2 と 3〜4 の組の中に在り、
`judge()` が引いた行から id を取れば 1 束あたり 2 回減る見込みである。**直していない。**

### 取り消しが取り込みより遅い

`rollback` 113.6 秒 対 `apply` 67.3 秒。問い合わせ数はむしろ取り消しの方が少ない
（97,908 対 130,602）ので、差は**1 束 = 1 transaction で消す**構造から来ていると読める。
束ごとに commit するのは途中で落ちても続きから進むためで、速度と引き換えにしている。
**実測しただけで、変えていない。**

> **この数字を sqlite で測り直さないこと。** 遅さの正体は
> **1 束ごとの commit が MySQL で払う代金**（fsync / redo）である。sqlite は
> その代金がまるで違うので**順位が逆転し**、「速いから問題ない」と読めてしまう。
> ここに書いた秒数は**隔離 MySQL 8.4.9 での値**であり、比べてよいのは同じ driver の上だけである。

## MySQL でだけ落ちた 6 件（**27F が見つけ、27F-b で直した**）

sqlite でしか通らない検証だった、という発見である。
**計測の結果を根拠に本体を変えたのではない** — 検証そのものが片方の driver でしか
成立していなかった、という可搬性の修理である。**MySQL 全掃引は 27F-b で緑になった。**

### (A) `SELECT … FOR UPDATE` が「許していない書き込み」に見える — 5 件

`WatchesDatabaseWrites::looksLikeWrite()` は `for update` を含む文を書き込みとして数え、
`touchesOnly()` は `insert` / `update` / `delete` の形しか確かめられない。
そのため**許した表に対する行ロックが「許していない書き込み」として報告される**。

sqlite は `lockForUpdate()` を何にもコンパイルしないので、この経路は今まで存在しなかった。
27E-2 が行ロックを入れたときも、sqlite では見えていない。

- `ApplyWxrContentTest::test_許した表以外は1行も変わらない`
- `ApplyWxrTermTest` / `ApplyWxrCommentTest` / `ApplyWxrAssignmentTest` の同名
- `RollbackWxrTest::test_取り消しは許した表以外に書かない`

**安全側の失敗である**（見逃しではなく、余分に赤くなる）。
ただしこの 5 件が MySQL で回せない間、**書き込み範囲の保証は sqlite でだけ働いていた**。

**27F-b の直し方**: `locksOnly()` を足し、「1 つの表の行を掴んだだけ」と**言い切れる形**の
`select … for update` を見逃す。`join` や CTE が付いたものは従来どおり赤い。
**許した表が空のとき（`plan` の「1 行も書かない」の証明）は見逃さない** —
`plan` が行を掴むのは異常だからで、この非対称は意図している。

**掴んだ表は許可の一覧と突き合わせない。** 実測でその差が出た — 取り込みは
`taxonomies` の行を掴むが `taxonomies` へは 1 行も書かない（`CreateTerm` の
lock 順序が Taxonomy 行 → Term 行）。突き合わせると「書いてよい表」に `taxonomies` を
足すことになり、**そこへの本物の書き込みまで見逃す**。ロックはデータを 1 行も
変えないので、変わったかどうかを見張るこの道具の対象ではない。

### (B) `content_id` に ULID を入れている fixture — 1 件

`TargetRowLookupTest::test_分類項目とコメントとメディアも読む` の
`comment()` ヘルパーが `content_comments.content_id`（`unsignedBigInteger`）へ
`Str::ulid()` を入れる。sqlite は受け取るが、MySQL は
`SQLSTATE[01000] 1265 Data truncated` で止まる。

そのテストが確かめているのは「コメントの行が在ると読めるか」だけで、
`content_id` の値は関係ない。**製品側の欠陥ではなく、fixture がその driver でしか
成立しない形だった**という発見である。

**27F-b の直し方**: 列の型に合う整数を置いた。

## Definition of Done 達成状況（§I の 27F 受入）

| 受入 | 結果 |
| --- | --- |
| 両側 `agentcms verify` 10/10 | **達成**（kit 側・生成 project 側とも 10/10） |
| 実データ規模の WXR で通る | **達成**（3,000 記事の apply → rollback → 再 apply） |

## Remaining Known Limitations

1. `BundleJudge` の二重問い合わせ（束あたり 2 回ぶん）は測っただけで直していない
2. 取り消しが取り込みの 1.7 倍遅い。原因の当たりは付いているが変えていない
   （**判断済み** — 1 束 = 1 transaction は安全性の構造で、§D.5 とのトレードオフとして受け入れる）
3. **計測は `RefreshDatabase` の中で行った。** 各テストが transaction に包まれるため、
   本体の `DB::transaction` は savepoint になる。実運用の commit とは違うので、
   **秒数は相対比較のためのものであって、本番の所要時間ではない**
4. メディアの取得は `Http::fake` で即答させている。**ネットワークの時間は測っていない**
   （相手のサーバー次第の数字に意味が無いため）
5. 規模は 1 通りだけ（3,000 記事）。**件数を変えたときの伸び方は測っていない**
6. 生成 project 側の verify は 1 回だけ・macOS のみ。他 platform は未実測
