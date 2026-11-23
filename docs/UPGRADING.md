# Upgrading — アップデートの考え方と手順

このドキュメントは SiteGrowKit のアップデート契約（何が守られるか）と、現行バージョン（0.9.x）での手順を説明します。

> **現状の正直な表明**: 本バージョンに、あなたのプロジェクトを書き換える `agentcms update` は**ありません**。
> あるのは **`agentcms update check`** — 受け取った配布物が発行者のものかを確認し、いま使っている版と比べ、
> 構造契約が合うかを表示する command です。**確認が通っても、それは「更新済み」ではありません。**
> 適用（`core/` の差し替え・migrate・`project.json` の書き換え）は、この文書の手順に沿って**あなたが**行います。
> 「最新版をダウンロードして全部上書き」は絶対に行わないでください。

## 0. 3 つのものの役割（先に読む）

| もの | 何を示すか | どこから来るか |
| --- | --- | --- |
| `SHA256SUMS` / `.zip.sha256` | ファイルが**壊れていない**こと（転送中の破損） | 配布物と一緒 |
| `signed-release.json` + `signed-release.json.sig` | 配布物が**発行者の出したもの**であること（真正性）。manifest には版・sha256・サイズ・構造契約の版が入り、署名（Ed25519）がその byte 列を保証する | 配布物と一緒 |
| **信頼鍵**（発行者の公開鍵 PEM） | **誰を発行者と認めるか**。これだけは**配布物とは別の経路**で受け取る | 配布元の案内（リリースのページなど） |

SHA だけでは「壊れていない」ことしか言えません。差し替えられた配布物にも、差し替えた人が書いた SHA が付いてきます。
発行者を確認できるのは署名だけで、署名を確認できるのは**別経路で得た信頼鍵**だけです。
**配布物の中に鍵が入っていても、それを信頼鍵として使わないでください**（差し替えた人が自分の鍵を入れられます）。

## 1. 初回だけ: 信頼鍵を別経路で受け取る

1. 配布元の案内（リリースのページなど）に書かれた**公開鍵 PEM**と**鍵指紋**（`sha256:…`）を控える
2. 公開鍵 PEM をプロジェクトの外の安全な場所に保存する（例: `~/.config/sitegrowkit/trusted-release-key.pem`）。
   プロジェクトに入れる必要はありません。入れるなら `.agentcms/local/` の中に置き、コミットしないでください
3. `agentcms update check` が表示する「信頼鍵 sha256:…」の先頭 16 桁が、案内の指紋と一致することを**目で**確認する

指紋が違えば、その鍵は使わないでください。

## 2. 挙動が変わる更新（読んでからアップデートしてください）

### 投稿の削除が「ゴミ箱へ移動」になりました（Phase 9.8）

**呼び出しの形は変わりませんが、結果が変わります。** 今までどおり呼べる、という意味では
互換ですが、**消したはずのものが残っている**のはあなたのシステムから見れば挙動の変更です。

| | 以前 | いまのバージョン |
| --- | --- | --- |
| `content.delete` | **物理削除**（元に戻せない） | **ゴミ箱へ移動**（元に戻せる）。互換のための名前 |
| 正式な名前 | — | **`content.trash`**。新しく書くときはこちらを使ってください |
| 元に戻す | できない | `content.restore`（**現時点では管理画面のみ**。AI / MCP へは公開していません） |
| 完全に削除する | `content.delete` | **提供していません**。バックアップと全体復元ができるまで、戻せない操作は増やしません |

- ゴミ箱にある投稿は、**一覧・検索・件数・公開ページ・API・MCP・sitemap のどこにも出ません**。ID 直指定も 404 です
- 行そのものは消えていないので、**slug は占有されたまま**です（黙って別の slug には変えません）
- 分類の割り当ては消えません。ゴミ箱の投稿が使う画像は**引き続き「使用中」**です
- 戻すとき、公開状態を持つ投稿タイプは**下書きへ**戻ります

**意味が変わる前に取った確認トークンは、意図的に無効です。** 確認は取り直しになります。

### `lock_version`（同時編集の検出）

- **移行期間のあいだ、送らなくても動きます。** 既存クライアントはそのまま動きます
- **送った場合は必ず検証します。** 間に他の人が更新していれば上書きせず「他の人がこのコンテンツを更新しました」を返します
- 壊れた値（`"abc"` や負の数）は入力エラーとして断ります
- 保存に成功すると応答に**新しい `lock_version`** が入ります。次の保存にはそれを添えてください
- 将来のバージョンで**必須になります**

## 3. アップデート契約（このバージョンで保証されること）

1. **バージョンの可視性** — 自分がどのバージョンを使っているかを常に特定できる
   - CLI: `agentcms --version`
   - プロジェクト: `.agentcms/project.json` の `kit_version`（生成時に記録。**あなたが適用を終えて verify を通したときだけ**書き換える）
2. **所有権の分離** — 何が Kit 管理で、何があなたのものかが明確
   - CORE MANAGED: `core/`（読み取り専用。アップデートで更新される）
   - PROJECT OWNED: `app/` `modules/` `content/schemas/`（overrides 含む） `resources/` `.env`（アップデートで**上書きされない**）
   - GENERATED: `CLAUDE.md` `AGENTS.md`（`sync-agents` で再生成）、import 生成物（provenance 管理）
   - BUNDLED SAMPLE: **kit に同梱の見本サイト**。先頭に `agentcms:bundled-sample` と書いてある（下記）
   - LOCAL: `.agentcms/local/`（配布物に含まれず、更新対象外。`update check` の記録もここ）
3. **互換性の検出** — `template_schema_version` / `agent_context_version` が変わる更新は、リリースノートに移行手順が明記され、`agentcms update check` が「構造契約が変わります」と表示する
4. **サイレント上書きの禁止** — 判断が必要な差分は、あなたの確認なしに適用されない。**署名確認が通っても、何も適用されない**

### `agentcms:bundled-sample` — 直してよいもの

kit は、生成した直後から**見て動くサイト**を同梱している（トップ・About・Service・
問い合わせフォーム・実績一覧・その layout）。**これは PROJECT OWNED である。直して構わない。**

▷ **消しても構わない。**取り込み（`template apply`）で置き換わるものは置き換わる。

▷ **触っていなければ、初回の `template apply` は黙って置き換える**（conflict にしない）。
  **手を入れたあとなら conflict になる** — そのとき「これは kit の見本です」と言ってから止まる。
  **黙って消すことはしない。**

▷ 🔴 **前は「自動生成だから手で直すな」と書いてあった（B-61）。嘘だった。**
  実際には kit 自身が**38 コミット分の手直し**を入れていたし、**conflict にもならない。**

▷ **目印と表示は同じ 1 行である**（`packages/cli/src/importer/bundled-samples.ts` の
  `BUNDLED_SAMPLE_MARK`）。`tests/bundled-defaults.test.ts` が両方を見ている。

## 4. 手順 A — 配布物を確認する（`agentcms update check`）

ネットへは出ません。ローカルのファイルだけを突き合わせます。

```bash
# 配布元から取得したもの: 配布 zip を展開したディレクトリに tgz / signed-release.json / .sig がある
agentcms update check \
  --manifest  ~/Downloads/agent-cms-kit-v<new>/signed-release.json \
  --signature ~/Downloads/agent-cms-kit-v<new>/signed-release.json.sig \
  --public-key ~/.config/sitegrowkit/trusted-release-key.pem \
  --archive   ~/Downloads/agent-cms-kit-v<new>/agent-cms-kit-<new>.tgz
```

プロジェクトの中で実行すると `.agentcms/project.json` の `kit_version` と比べます（外で実行するなら `--project <dir>`）。

表示の読み方:

| 行 | 意味 |
| --- | --- |
| `✔ 署名: 検証済み（… 信頼鍵 sha256:…）` | manifest は指定した信頼鍵の持ち主が署名したもの。**指紋を案内と見比べる** |
| `✔ アーカイブ: 実体照合済み` | 手元の tgz のサイズと sha256 が、署名済み manifest の記述と一致 |
| `▲ アーカイブ: 未照合` | `--archive` を付けていない。**署名だけでは手元のファイルの実体は確認できていない**。付けて再実行する |
| `判定: 新しい版があります / 同じ版 / 古い版 / 比較できません` | `project.json` との前後関係。**「比較できません」を「最新」とは言わない** |
| `互換性: template_schema_version 1 → 1 …（契約番号は同じ…）` | 構造契約は同じ。**ただし契約番号が同じでも DB migration が増えている版はある**（例: `imported_page_layouts`）。migration の有無は手順 B で参照プロジェクトの `database/migrations` と差分を見て決める |
| `互換性: template_schema_version 1 → 2 …（構造契約が変わります）` | リリースノートの移行手順が**必須** |
| `▲ まだ何も適用していません` | 常に出る。この command は適用しない |
| `✔ 導入可能性: … 次工程へ進めます` | 署名（と実体）が確認でき、新しい版（または同じ版）で構造契約も合う。**これだけが手順 B へ進める状態**（exit 0） |
| `▲ 移行確認待ち` | 構造契約が進む、またはこの CLI の契約が候補より古い。移行手順を確認するまで進まない（exit 2） |
| `✖ 導入を進めません` | 候補が古い版 / 契約が後退 / 利用中の版が不明。理由が全部出る（exit 2） |

**署名が確認できたことと、導入を進めてよいことは別です。** 署名は「発行者のものか」しか言いません。
進めてよいかは「導入可能性」の行が言い、exit code もそれに従います（0 = 次工程へ、1 = 確認できず、2 = 確認できたが進めない）。

`✖ 配布物の真正性を確認できませんでした` が出たら、**その配布物は導入しない**でください。原因（署名不一致・署名欠落・鍵の取り違え・改変・アーカイブ不一致）と次の一手が「原因 / 修正」に出ます。
`✖ 導入を進めません` も同じく導入しません（古い版への差し戻しは別の作業です。利用中の版が不明なら、まず `project.json` を直します）。

結果は `.agentcms/local/update-check.json` に記録されます（非公開・gitignored・秘密情報・絶対パス・URL は含まない。`--no-record` で書かない）。
記録は乱数名の一時ファイルへ排他的に書いてから置き換え、`.agentcms` / `.agentcms/local` / 保存先のどれかがリンクなら書きません。
失敗の記録は成功の項目を持たないので、前回の成功と混ざりません。`--json` で機械可読の結果も出せます（`admissibility` に上の判定が入ります）。

## 5. 手順 B — 隔離した場所で差分を読む

前提: プロジェクトが Git 管理されていること（`agentcms create` は git init 済み）。作業ツリーはクリーンに。

1. **回復ポイント**（コードとデータの両方）
   ```bash
   git status                                   # クリーンであること
   git add -A && git commit -m "before kit update to <new>"
   git tag before-kit-update-<new>              # 名前で戻れるようにする（HEAD^ に頼らない）
   ```
   管理画面「バックアップ」または CLI で **DB + メディアのバックアップ**を取り、**復元前検証が通ること**を確認する（docs/MAINTENANCE.md）。
   バックアップ ZIP は `.agentcms/local/` の外、プロジェクトの外に置く。
2. **新しい CLI を、いまの CLI と別に**導入する（既存プロジェクトの `agentcms` を先に入れ替えない）
   ```bash
   npm install -g --prefix ~/.sitegrowkit/<new> ~/Downloads/agent-cms-kit-v<new>/agent-cms-kit-<new>.tgz
   ~/.sitegrowkit/<new>/bin/agentcms --version    # <new> と表示される
   ```
3. **参照プロジェクトを隔離して生成し、差分を読む**
   ```bash
   ~/.sitegrowkit/<new>/bin/agentcms create ../_update-reference-<new>
   git diff --no-index --stat core/ ../_update-reference-<new>/core/     # CORE MANAGED の差分
   git diff --no-index core/ ../_update-reference-<new>/core/ | less
   ```
   `core/` に**あなたの変更が混ざっている**なら、それは「改変された core」であり、更新前に切り分けが要ります。
   **DB migration が増えているかもここで見ます**（`update check` の契約番号一致は migration 不要の根拠になりません）:
   ```bash
   git diff --no-index --stat database/migrations/ ../_update-reference-<new>/database/migrations/
   git diff --no-index --stat core/database/ ../_update-reference-<new>/core/database/
   ```
   増えていれば、手順 C の `agentcms dev` で migrate が走ります。手順 B-1 のバックアップがその前提です。
4. **PROJECT OWNED に必要な変更を、リリースノートで読む**
   CHANGELOG / リリースノートに「あなたの `app/` `modules/` `content/schemas/` `resources/` に手を入れる必要がある」と書かれている箇所を**全部**書き出す。
   `update check` が「構造契約が変わります」と言った更新は、ここが空にはなりません。

## 6. 手順 C — 更新用 branch で適用し、verify を通す

```bash
git switch -c kit-update-<new>
rsync -a --delete --exclude='.gitkeep' ../_update-reference-<new>/core/ core/   # CORE MANAGED だけ。core/ 以外に向けない
git status                                   # 変わったのが core/ だけであることを確認する
```

`rsync --delete` は **`core/` に対してだけ**使います。宛先を間違えると PROJECT OWNED を消します。
不安なら `--dry-run` を先に付けて、出てくるファイル名が全部 `core/` で始まることを見てください。

続いて PROJECT OWNED の変更を、手順 B-4 で書き出した項目に沿って**あなたが**適用します（機械的上書きはしません）。

```bash
agentcms sync-agents          # GENERATED（CLAUDE.md / AGENTS.md）を再生成
agentcms dev                  # 依存更新・build・migrate（migrate が走るのはここ。B-1 のバックアップがある前提）
agentcms verify               # 全ゲート通過
```

`agentcms verify` が通り、管理画面と公開サイトを実際に開いて確認できたら:

```bash
# .agentcms/project.json の kit_version を <new> に書き換える（手で。verify が通った後だけ）
git add -A && git commit -m "kit update to <new>"
```

`kit_version` を先に書き換えないでください。次の `update check` が「同じ版」と言い、適用が済んでいない事実が見えなくなります。

## 7. 手順 D — 問題があったときの復元

**コードとデータベースは別々に戻します。** どちらか一方だけ戻すと、コードが知らない列や表ができます。

- **migrate の前に気づいた**（`agentcms dev` より前）:
  ```bash
  git switch -                         # 元の branch へ戻る（kit-update-<new> は残る。消さない）
  ```
  作業内容は branch に残るので、原因を調べてから続けられます。**`git reset --hard` は使いません** — 既存のコミットを失う道具です。
- **migrate の後に気づいた**:
  1. コンテナを止める: `agentcms stop`
  2. コードを戻す: `git switch -`（元の branch。B-1 の tag `before-kit-update-<new>` と同じ内容）
  3. **DB とメディアを B-1 のバックアップから復元する**（管理画面「復元」または docs/MAINTENANCE.md の手順。復元前検証が通ることを確認）
  4. `agentcms dev` → `agentcms verify` で、更新前の状態に戻ったことを確認する
  5. `kit-update-<new>` branch は残しておき、何が問題だったかを調べる
- 復元後は `update check` を再実行しても、判定は「新しい版があります」に戻ります（適用の記録は `kit_version` だけが持つため）。

## 8. 検証済みの経路と、手作業のまま残る箇所

**機械で検証されている**（kit のテストが毎回通す）:

- `update check` — 正しい署名 / 誤った鍵 / 改変された manifest / 改変された tgz / 署名欠落 / 不正な schema / サイズ上限（実読込で判定） / rc と stable の前後 / 構造契約の不一致 / プロジェクト未検出 / 導入可能性の 3 状態と exit code。CLI をプロセスとして起動し、`project.json` が変わらないこと・秘密鍵の中身が出力に出ないこと・記録がリンク先を書き換えないこと（symlink / hardlink / リンクされた親ディレクトリ・同時 8 プロセス）も見ている
- `scripts/sign-release.mjs`（発行側）— 秘密鍵を生成・表示・同梱しない、既存ファイルを上書きしない、発行日時を捏造しない、書いた manifest が検証側の schema を通る

**あなたが手で行う**（機械は代わりにやらない）:

- 信頼鍵の指紋を案内と見比べる（§1）
- `core/` の差分とリリースノートを読む（§5）
- PROJECT OWNED への変更を適用する（§6）
- バックアップの取得と復元、migrate 後の確認（§5-1 / §7）
- `kit_version` の書き換え（§6）

## 9. 将来

所有権ベースの conflict 検出つきの、より自動化された update は今後のバージョンで検討します。その場合もこのページの契約（所有権・可視性・サイレント上書き禁止・署名確認と適用の分離）は維持されます。

署名の対象は `signed-release.json` です。配布bundleの `release-manifest.json` は内容一覧用で形式が異なります。署名ファイルと混同しないでください。
