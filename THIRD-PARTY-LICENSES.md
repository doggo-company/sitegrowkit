# Third-Party Licenses — 第三者ライセンス一覧

Agent CMS Kit（以下「本キット」）と、本キットが生成するプロジェクトが
利用する第三者ソフトウェア・アセットの棚卸しです。

- 本キット自体（`agentcms` CLI・テンプレート・CMS Core）は GNU General Public License v3.0 or later です（LICENSE・COPYRIGHT 参照）。
- **CLI 配布物（tgz）**には第三者ライブラリの実体は同梱されません。下記の依存は
  利用者環境での `composer install` / `npm install` / `pnpm install` / ビルド時に
  各パッケージの配布元から取得され、**各パッケージのライセンス全文はその取得物に
  同梱されています**。
- **GUI 配布物（launcher zip）**は例外です。起動に必要な CLI runtime 依存だけは
  `app/node_modules/` として同梱されます（利用者に Node インストールを求めないため）。
  同梱される各パッケージの LICENSE ファイルはパッケージ内にそのまま含まれます。
  同梱対象は「npm — CLI runtime」の直接依存とその推移的依存で、内訳は下記
  §GUI 配布物に同梱される npm 依存 を参照してください。
  （この記載は Phase 9.1B で実測に基づき訂正しました。以前の版は zip も
  「同梱しない」と記載しており不正確でした — **LEGAL RE-REVIEW REQUIRED**）
- 本書はパッケージメタデータ・LICENSE ファイル・上流リポジトリの記載に基づく
  **事実の記録**であり、法的判断・法的助言ではありません。
  法的確認が必要な項目には LEGAL REVIEW REQUIRED と明記しています。

## 分類

| 分類 | 意味 |
| --- | --- |
| A | 追加の notice 不要と判断できる材料のみ確認（第三者物でない等） |
| B | attribution / license notice が通常必要（本書と取得物内の全文が対応） |
| C | 再配布条件・ソース提供条件など、内容確認を要する条項を含む |
| D | 不明・要確認、または LEGAL REVIEW REQUIRED |

runtime の Composer / npm 依存ツリーに GPL / AGPL 単独ライセンスの
パッケージは検出されていません（lock メタデータ・実ファイル確認による。
選択式ライセンスは §特記事項 参照）。

---

## 特記事項（個別に記録が必要なもの）

### 1. ezyang/htmlpurifier — LGPL-2.1-or-later 【B: GPLv3 と両立】

| 項目 | 記録 |
| --- | --- |
| パッケージ | `ezyang/htmlpurifier` |
| バージョン | v4.19.0（`template/composer.lock`。制約 `^4.18`） |
| ライセンス | LGPL-2.1-or-later（composer メタデータおよび取得物内 LICENSE 本文で確認） |
| 依存経路 | `template/core/composer.json` の**直接依存**（first-party CMS Core が依存） |
| 改変 | なし（上流のまま利用） |
| 配布モデル | 本キットの配布 zip / tgz には**同梱されない**。利用者環境で Composer が Packagist から取得する |
| 統合形態 | `core/src/Html/Purifier.php` が API 呼び出しで利用（コードのコピー・改変なし） |
| 対応 | 本キットは GPL-3.0-or-later で公開する。GNU の一覧（https://www.gnu.org/licenses/license-list.en.html）は LGPL 2.1 を「GPLv2 と GPLv3 に互換」としている。上流のまま API 呼び出しで利用し、改変していない |

### 2. shadcn-vue 由来のコピーコード — MIT 【B: 本書が attribution を提供】

| 項目 | 記録 |
| --- | --- |
| 対象 | `template/resources/js/components/ui/` 配下 139 ファイル（22 コンポーネント）+ `template/resources/js/lib/utils.ts` の `cn()` |
| 取得元 | shadcn-vue（https://github.com/unovue/shadcn-vue）の CLI によるコード生成・コピー（`template/components.json`: style `new-york-v4` / iconLibrary `lucide`） |
| 取得バージョン | **UNKNOWN** — リポジトリに取得時の shadcn-vue バージョン・commit の記録がない（導入 commit `29a2e26` にも記録なし）。style 名から v4 世代と推測されるが、確定できないため UNKNOWN として記録する |
| 上流ライセンス | MIT（上流リポジトリ LICENSE: `Copyright (c) 2023 unovue`）。コピーされたファイル自体にはライセンスヘッダが含まれない形式で配布されている |
| 配布モデル | **本キットの配布物に実コードとして同梱される**（この点で他の依存と異なる） |
| 対応 | 本書の下記 notice が attribution を提供する。ランタイム基盤の `reka-ui` は通常の npm 依存（依存テーブル参照） |

上流 MIT ライセンス全文（コピーコードへの適用分）:

```text
MIT License

Copyright (c) 2023 unovue

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### 3. Instrument Sans（フォント）— SIL OFL-1.1 【C: 成果物に埋め込まれる】

| 項目 | 記録 |
| --- | --- |
| 対象 | Instrument Sans（weights 400 / 500 / 600） |
| ライセンス | SIL Open Font License 1.1（上流 https://github.com/Instrument/instrument-sans の OFL.txt で確認） |
| Copyright | `Copyright 2022 The Instrument Sans Project Authors (https://github.com/Instrument/instrument-sans)` |
| 配布モデル | 本キットの配布物には**同梱されない**。`template/vite.config.ts` の `bunny('Instrument Sans', …)` により、利用者環境の**ビルド時に Bunny Fonts から取得され、ビルド成果物（`public/build/assets/instrument-sans-*.woff{,2}`）に含まれる** |
| 特記 | npm / Composer の依存マニフェストに現れないため、自動棚卸しでは検出されない。本書で明示的に記録する |
| 実測（2026-08-10） | fresh production build の実体検証で、成果物 `public/build/assets/` に font バイナリ 6 ファイル（400/500/600 × woff/woff2）が **self-host 参照**（生成 CSS はローカル URL のみ・runtime の fonts.bunny.net 参照なし）で含まれることを確認 |
| 対応（TECHNICAL COMPLIANCE FIX: IMPLEMENTED — v0.8.0-rc7） | OFL-1.1 はフォントソフトウェア再配布時に copyright notice とライセンス全文の同梱を求めるため、上流公式 **OFL.txt を無改変（verbatim）**で `template/public/licenses/instrument-sans/OFL.txt` に同梱。生成 project → production deploy 成果物（`public/` 配下）に font と共に含まれる。**final legal clearance は D10 FINAL で確定**（本書の LEGAL REVIEW 体系は維持） |
| OFL.txt provenance | 上流 repository: https://github.com/Instrument/instrument-sans（default branch: master）/ source file: `OFL.txt` / 取得日: **2026-08-10** / 取得方法: raw.githubusercontent.com から verbatim copy（改変なし・copyright header `Copyright 2022 The Instrument Sans Project Authors` を含む）/ SHA-256: `9e27a72ed30eb49a08678f6a5d6ed98ec7ba5368f541637ee0683ec9134ef966`（取得時記録。**2026-08-20 に同梱物の全文 SHA と照合して一致を確認し、省略していた記録を全桁へ延長した**— 取得日・取得方法は変えていない。以後 `FontLicenseTest` がこの値を照合する） |

### 3.1 Node.js runtime（配布物に同梱）— MIT + 同梱第三者ライセンス 【C: BUNDLED RUNTIME DEPENDENCY】

| 項目 | 記録 |
| --- | --- |
| 対象 | **Node.js v22.23.2**（LTS "Jod" / release date 2026-07-28） |
| 同梱理由 | Phase 8.5-D。利用者に Node.js の導入を求めないため、GUI 配布物へ公式ビルドをそのまま同梱する（launcher が同梱 runtime で `agentcms` engine を起動する） |
| 配布モデル | **本キットの GUI 配布物に同梱される**（従来の第三者依存と異なり、利用者環境での取得ではない） |
| 対象 platform | macOS: `darwin-arm64` / Windows: `linux-x64`（WSL2 内で実行するため） |
| 取得元（official） | `https://nodejs.org/dist/v22.23.2/`（Node.js 公式配布） |
| 取得日 | **2026-08-11**（UTC 04:32:36 に SHASUMS256.txt を取得） |
| 公式 checksum | `SHASUMS256.txt`（https://nodejs.org/dist/v22.23.2/SHASUMS256.txt） |
| darwin-arm64 SHA-256 | `61130f394c1630d211dd50aecc4353d379480f36d3ac913cd85dbba1aed585c6`（公式値と取得物が一致） |
| linux-x64 SHA-256 | `d60acfe00a2932254bb0ad20e01b0d74397a0875595de719654b214f4b03f307`（公式値と取得物が一致） |
| ライセンス | Node.js は MIT。公式配布物の `LICENSE` は Node 自身の MIT に加え、**同梱される第三者コンポーネントのライセンス全文 43 セクション**（V8 / OpenSSL / libuv / zlib / c-ares / nghttp2 / undici / ICU / npm / corepack ほか）を含む単一ファイル |
| attribution 対応 | 公式配布物の **`LICENSE` を無改変（verbatim）で配布物へ同梱**する（`runtime/LICENSE-nodejs.txt`）。取得時 SHA-256: `c738ae413cf561f174e34f6961f8ca458aae2369a73640dda6234c629b98bcc4` |
| 改変 | **なし**（公式 tarball を展開してそのまま同梱。再ビルド・パッチ適用は行わない） |
| 単一情報源 | `packages/launcher/runtime/runtime-manifest.json`（version / URL / checksum を pin。floating latest は使わない） |
| Status | **TECHNICAL REDISTRIBUTION EVIDENCE COMPLETE / FINAL LEGAL CLEARANCE PENDING D10** — 本項は事実の記録であり、再配布可否の最終的な法的判断は D10 の外部レビューで確定する |

### 4. nette/schema, nette/utils — 選択式（New BSD / GPL-2.0 / GPL-3.0）【B】

- バージョン: `nette/schema` v1.3.5 / `nette/utils` v4.1.5（composer.lock）
- 取得物内 license.md: 「New BSD License または GPL version 2 / 3 のいずれかを選択可能」
- 本キットおよび生成プロジェクトは、この選択式ライセンスのうち
  **New BSD License を選択して利用する**（本書がその選択の記録）。
- 経路: Laravel Framework の推移依存。改変なし。

### 5. lightningcss（+ platform binaries）— MPL-2.0 【C】

- 対象: `lightningcss`（および `lightningcss-darwin-arm64` / `-linux-x64-gnu` /
  `-win32-x64-msvc` 等の platform variant）
- ライセンス: MPL-2.0（取得物内 LICENSE 本文で確認）
- 経路: Vite / Tailwind CSS v4 の CSS 変換（`template/package.json` の
  optionalDependencies にも明示）。**ビルド時のみ**使用。改変なし。
  ライブラリ自体は成果物に含まれない。
- 対応: MPL-2.0 の条件（ファイル単位の weak copyleft）が本利用形態に
  課す義務の有無は本書では断定せず、**D10 legal review で確認**する。

### 6. axe-core — MPL-2.0 【C: dev 専用・影響最小】

- バージョン: 4.13.0。`@lhci/cli`（Lighthouse CI）の推移依存。
- devDependencies 経由のみ。配布物にもビルド成果物にも含まれない。

### 7. GSAP 3 — 同梱の見本サイトからは外した（2026-11-23）【A: 配布物・同梱の見本は参照しない】

- 以前は同梱の見本サイトのトップ（`template/resources/views/public/home.blade.php`）が
  `https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js` を読み、見出しの登場の動きに使っていた。
  GPL-3.0-or-later で公開するにあたり、**CSS の animation（`public/imported/css/style.css` の
  `hero-rise`）に置き換え、GSAP の読み込みを外した**。`.env.example` の CSP の追加 origin も空にした。
- 理由（2026-11-23 に https://gsap.com/standard-license の本文を読んだ。Effective date: April 30, 2025 /
  Last modified date: May 30, 2025）: Standard "No Charge" GSAP License は自由なソフトウェアのライセンスではないと読んだ（本書の読み・法的な判断ではない）。
  知的財産権は Webflow の専有で、"Prohibited Uses"（visual animation の builder で Webflow と競合するもの）があり、
  条件に従わないときは Webflow が終了でき（V. TERMINATION）、Webflow は本文をいつでも改訂できる（Amendments）。`gsap@3` は版を固定しないので、将来の版の条件にも引きずられる。
  CMS を GPL で配る見本が、こうした条件の script を既定で読み込む形は避けた。
- **残っている参照**: `examples/html-template/`（取り込み機能の試験に使う、外のテンプレートの見本）は
  CDN の GSAP を参照したままにしてある。CLI の解析（`packages/cli/src/inspect/`）が CDN の外部依存を
  見分けられるかを試すための入力で、本キットは GSAP の実体を配らない（distribution artifact・npm 依存とも 0 件）。
  自分のテンプレートを取り込んだ利用者のサイトが GSAP を読むかどうか、その条件に従うかは、利用者の判断になる。

### 8. Noto Sans JP（CDN 参照）— SIL OFL-1.1 【B: 配布なし】

- 参照元: `template/resources/views/layouts/imported.blade.php`
  （Google Fonts の CSS API）。フォント実体は Google のサーバーから
  配信され、本キットは配布しない。

### 9. Fixture アセット（内製）【A / 一部 D】

- Green Leaf Design（`examples/html-template/`、配布物では
  `template/public/imported/` 以下の画像・CSS・JS）および
  Northwind Studio（Figma synthetic fixture）は、本キットの
  **内製ダミーブランド / 合成 fixture** であり第三者物ではない。
  `leaf-icons.woff2` は 18 バイトのダミーテキストで実フォントではない。
- fixture PNG 画像の生成元記録は残っておらず、第三者ストック素材で
  ないことのメタデータ上の証明はない（内製 fixture として扱う。D として記録）。
- `template/public/favicon.ico` / `favicon.svg` / `apple-touch-icon.png` は
  Laravel スケルトン由来（MIT）か自作かの記録がない（D として記録。リスク低）。

### 10. Apache-2.0 パッケージの NOTICE

Composer / npm 依存に Apache-2.0 のパッケージが含まれる
（例: `phpoption/phpoption`、npm ツリー約 50 件）。Apache-2.0 は
NOTICE ファイルが存在する場合にその転記を求める。該当パッケージの
NOTICE 有無の個別確認は **D10 legal review と合わせて実施**する。

---

### 11. IPADIC（題・サイト名の読みの辞書）— NAIST・ICOT の許諾 ／ kuromoji.js — Apache-2.0 【B: 本書と同梱ファイルが notice を提供】

- 何に使うか: ページの URL とフォルダ名の候補を日本語の題・サイト名から作るときの形態素解析（UX 一周 17〜19 周目）
  - 管理画面・MCP・API（サーバー・PHP）: mecab-ipadic 2.7.0 の全部を SQLite にまとめた辞書
    `template/core/resources/reading/ipadic.sqlite.gz`（約 11.8MB・展開すると約 31MB・`scripts/build-reading-dictionary.mjs` で作る）
    を、`core/src/Pages/Reading/IpadicTokenizer.php`（コスト最小の切り方・Viterbi）で読む。**生成物に同梱される**（公開サイトには出さない）
  - setup（CLI）: kuromoji 0.1.2（npm・Apache-2.0）。辞書は kuromoji のパッケージに入っていて、npm install で入る
  - 両方が同じ例で同じ候補になることを `template/tests/fixtures/reading-examples.json` で確かめる
- 条件: IPADIC は、元の形でも手を加えた形でも、写しに著作権表示と下の文（保証が無いこと）を含めること。
  同梱ファイル `ipadic.NOTICE` に原文を写した。kuromoji（Apache-2.0）の NOTICE は kuromoji のパッケージに入っている
- 2026-11-06 追加（0.9.4-rc22）・差し替え（0.9.4-rc25: 読みをサーバーへ。ブラウザへ辞書を送るのをやめた）。
- GPL-3.0-or-later との関係（2026-11-23 記録）: 許諾文は SPDX の `NAIST-2003`（OSI 承認なし）にあたり、
  Debian は main（自由なソフトウェアの区分）で mecab-ipadic を配っている。求めているのは著作権表示と
  無保証の文を写しに含めることで、GPLv3 §7(a)(b) が認める追加の条件（保証の否認・法的な表示の保存）の範囲と読める。
  辞書は本キットのコードとは別のデータファイルとして同梱し、上の許諾に従う（GPL を辞書へ及ぼさない）。
  FSF（GNU のライセンス一覧）はこの許諾を評価していないので、**法的な判断ではなく、本文を読んだ記録**。
  法的判断ではなく、ライセンス本文に基づく事実の記録

```
Copyright 2000, 2001, 2002, 2003 Nara Institute of Science
and Technology.  All Rights Reserved.

Use, reproduction, and distribution of this software is permitted.
Any copy of this software, whether in its original form or modified,
must include both the above copyright notice and the following
paragraphs.

Nara Institute of Science and Technology (NAIST),
the copyright holders, disclaims all warranties with regard to this
software, including all implied warranties of merchantability and
fitness, in no event shall NAIST be liable for
any special, indirect or consequential damages or any damages
whatsoever resulting from loss of use, data or profits, whether in an
action of contract, negligence or other tortuous action, arising out
of or in connection with the use or performance of this software.

A large portion of the dictionary entries
originate from ICOT Free Software.  The following conditions for ICOT
Free Software applies to the current dictionary as well.

Each User may also freely distribute the Program, whether in its
original form or modified, to any third party or parties, PROVIDED
that the provisions of Section 3 ("NO WARRANTY") will ALWAYS appear
on, or be attached to, the Program, which is distributed substantially
in the same form as set out herein and that such intended
distribution, if actually made, will neither violate or otherwise
contravene any of the laws and regulations of the countries having
jurisdiction over the User or the intended distribution itself.

NO WARRANTY

The program was produced on an experimental basis in the course of the
research and development conducted during the project and is provided
to users as so produced on an experimental basis.  Accordingly, the
program is provided without any warranty whatsoever, whether express,
implied, statutory or otherwise.  The term "warranty" used herein
includes, but is not limited to, any warranty of the quality,
performance, merchantability and fitness for a particular purpose of
the program and the nonexistence of any infringement or violation of
any right of any third party.

Each user of the program will agree and understand, and be deemed to
have agreed and understood, that there is no warranty whatsoever for
the program and, accordingly, the entire risk arising from or
otherwise connected with the program is assumed by the user.

Therefore, neither ICOT, the copyright holder, or any other
organization that participated in or was otherwise related to the
development of the program and their respective officials, directors,
officers and other employees shall be held liable for any and all
damages, including, without limitation, general, special, incidental
and consequential damages, arising out of or otherwise in connection
with the use or inability to use the program or any product, material
or result produced or otherwise obtained by using the program,
regardless of whether they have been advised of, or otherwise had
knowledge of, the possibility of such damages at any time during the
project or thereafter.  Each user will be deemed to have agreed to the
foregoing by his or her commencement of use of the program.  The term
"use" as used herein includes, but is not limited to, the use,
modification, copying and distribution of the program and the
production of secondary products from the program.

In the case where the program, whether in its original form or
modified, was distributed or delivered to or received by a user from
any person, organization or entity other than ICOT, unless it makes or
grants independently of ICOT any specific warranty to the user in
writing, such person, organization or entity, will also be exempted
from and not be held liable to the user for any such damages as noted
above as far as the program is concerned.
```

### 12. JMdict（カタカナ語の英語の表記）— EDRDG・CC BY-SA 4.0 【B: 本書と同梱ファイルが notice を提供】

- 何に使うか: ページの URL とフォルダ名の候補で、カタカナ語を英語の表記にそろえる（トリミング → trimming・ネイルサロン → nail-salon。UX 一周 20 周目 R2）。
  表に無いカタカナ語は音のローマ字
- 同梱するもの: `template/core/resources/reading/katakana-english.tsv.gz`（約 0.37MB・43,118 語）と記録 `katakana-english.json`。JMdict の英語版（`JMdict_e`）から、
  カタカナの見出しと、その語の元の英語（音が合うものだけ。翻訳はしない）を取り出した表。作り方は `scripts/build-katakana-english.py`。
  管理画面・MCP・API（PHP の `TitleReading`）と setup（CLI）が同じ表を読む。
  英語でない外来語（出所の言語が英語以外: パン・アルバイト）で英語にできない語は、英語を空にして載せ、音のローマ字で読む（0.9.4-rc27）
- 元にした版: JMdict_e（`JMdict created: 2026-11-05`）。http://ftp.edrdg.org/pub/Nihongo/JMdict_e.gz
- 条件（https://www.edrdg.org/edrdg/licence.html の本文から）:
  - 辞書ファイルは CC BY-SA 4.0。取り出した表も CC BY-SA 4.0 で提供する（同梱ファイル `katakana-english.NOTICE`）
  - ソフトウェアに使うときは、文書などで使ったことと出所を示すこと（本節と `katakana-english.NOTICE`）
  - **データを新しい版から定期的に作り直す手順を持つこと**（守らないと許諾違反になる）。手順:
    `python3 scripts/build-katakana-english.py --fetch` で最新の `JMdict_e.gz` から作り直す。元にした版の日付と sha256 は
    `katakana-english.json` に残る。**版を切るとき `scripts/package-release.mjs` が、版の日付から 31 日を超えていたら止める**
    （表が記録の sha256 と合わないときも止める・`scripts/jmdict-freshness.mjs`）。同じ入力からは同じ表（同じバイト列）ができる
    （`--source <JMdict_e.gz> --check` で確かめられる）
  - 商用利用の制限は無い（ソフトウェアに同梱して売ってよい）。収益が出たら EDRDG への寄付が勧められている（義務ではない）
- 2026-11-06 追加（0.9.4-rc26）。法的判断ではなく、ライセンス本文に基づく事実の記録

## 初期テンプレート A のアセット

`template/public/site-templates/fonts/shippori-mincho/`（Shippori Mincho 500）と
`fonts/cormorant/`（Cormorant Garamond Italic 400）は SIL OFL-1.1。
Google Fontsのunicode-range分割woff2を2026-11-05に取得した既存A基盤から、実体を無改変で移植しています。
各フォルダのOFL.txtを同梱し、CSSのURLだけをローカルに指定しています。外部CDNは使用しません。
上流: https://github.com/google/fonts/tree/main/ofl/shipporimincho と https://github.com/google/fonts/tree/main/ofl/cormorantgaramond 。

`template/resources/starter-photos/food-{hero,1,2}.jpg` は、2026-11-05にima2で生成した既存の内製見本3枚。
元の出典記録は template-a `abe80adc` のTHIRD-PARTY-LICENSES.md §13です。
実在する店舗や人物を撮影した写真ではありません。今回は新規生成・素材購入を行っていません。
生成画像の表記を見本ページとメディア説明に保持しています。
final legal clearance は既存のD10 FINALで確定します。

## 依存パッケージ一覧（自動生成）

以下は lock / manifest から生成される（`node scripts/generate-third-party-licenses.mjs`）。
release packaging はこのテーブルが依存セットと同期していることを検証する（`--check`）。

<!-- AUTO-GENERATED:DEPS START — edit via scripts/generate-third-party-licenses.mjs, not by hand -->

### Composer — runtime（template/composer.lock）

利用者の `composer install`（`agentcms dev` が実行）で取得される実行時依存。`agentcms/core` は first-party（本キット自体・GPL-3.0-or-later）のため除外。

| Package | Version | License |
| --- | --- | --- |
| `bacon/bacon-qr-code` | v3.1.1 | BSD-2-Clause |
| `brick/math` | 0.17.2 | MIT |
| `carbonphp/carbon-doctrine-types` | 3.2.1 | MIT |
| `dasprid/enum` | 1.0.7 | BSD-2-Clause |
| `dflydev/dot-access-data` | v3.0.3 | MIT |
| `doctrine/deprecations` | 1.1.6 | MIT |
| `doctrine/inflector` | 2.1.0 | MIT |
| `doctrine/lexer` | 3.0.3 | MIT |
| `dragonmantank/cron-expression` | v3.6.0 | MIT |
| `egulias/email-validator` | 4.0.4 | MIT |
| `ezyang/htmlpurifier` | v4.19.0 | LGPL-2.1-or-later |
| `fruitcake/php-cors` | v1.4.0 | MIT |
| `graham-campbell/result-type` | v1.2.0 | MIT |
| `guzzlehttp/guzzle` | 8.2.0 | MIT |
| `guzzlehttp/promises` | 3.0.2 | MIT |
| `guzzlehttp/psr7` | 3.1.0 | MIT |
| `guzzlehttp/uri-template` | v2.0.1 | MIT |
| `inertiajs/inertia-laravel` | v3.3.1 | MIT |
| `laravel/chisel` | v0.1.1 | MIT |
| `laravel/fortify` | v1.37.3 | MIT |
| `laravel/framework` | v13.40.0 | MIT |
| `laravel/passkeys` | v0.2.1 | MIT |
| `laravel/prompts` | v0.3.27 | MIT |
| `laravel/serializable-closure` | v2.1.0 | MIT |
| `laravel/tinker` | v3.0.2 | MIT |
| `laravel/wayfinder` | v0.1.22 | MIT |
| `league/commonmark` | 2.10.3 | BSD-3-Clause |
| `league/config` | v1.2.0 | BSD-3-Clause |
| `league/flysystem` | 3.36.0 | MIT |
| `league/flysystem-local` | 3.35.3 | MIT |
| `league/mime-type-detection` | 1.17.0 | MIT |
| `league/uri` | 7.8.1 | MIT |
| `league/uri-interfaces` | 7.8.1 | MIT |
| `minishlink/web-push` | v11.0.0 | MIT |
| `monolog/monolog` | 3.12.1 | MIT |
| `nesbot/carbon` | 3.14.2 | MIT |
| `nette/schema` | v1.3.6 | BSD-3-Clause / GPL-2.0-only / GPL-3.0-only |
| `nette/utils` | v4.1.5 | BSD-3-Clause / GPL-2.0-only / GPL-3.0-only |
| `nikic/php-parser` | v5.8.0 | BSD-3-Clause |
| `nunomaduro/termwind` | v2.4.0 | MIT |
| `paragonie/constant_time_encoding` | v3.1.3 | MIT |
| `php-http/discovery` | 1.20.0 | MIT |
| `php-http/httplug` | 2.4.1 | MIT |
| `php-http/promise` | 1.3.1 | MIT |
| `phpdocumentor/reflection-common` | 2.2.0 | MIT |
| `phpdocumentor/reflection-docblock` | 6.0.3 | MIT |
| `phpdocumentor/type-resolver` | 2.0.0 | MIT |
| `phpoption/phpoption` | 1.10.0 | Apache-2.0 |
| `phpstan/phpdoc-parser` | 2.3.3 | MIT |
| `pragmarx/google2fa` | v9.0.0 | MIT |
| `psr/clock` | 1.0.0 | MIT |
| `psr/container` | 2.0.2 | MIT |
| `psr/event-dispatcher` | 1.0.0 | MIT |
| `psr/http-client` | 1.0.3 | MIT |
| `psr/http-factory` | 1.1.0 | MIT |
| `psr/http-message` | 2.0 | MIT |
| `psr/log` | 3.0.2 | MIT |
| `psr/simple-cache` | 3.0.0 | MIT |
| `psy/psysh` | v0.12.24 | MIT |
| `ramsey/collection` | 2.1.1 | MIT |
| `ramsey/uuid` | 4.9.4 | MIT |
| `spomky-labs/base64url` | v2.0.4 | MIT |
| `spomky-labs/cbor-php` | 3.3.0 | MIT |
| `spomky-labs/pki-framework` | 1.6.0 | MIT |
| `symfony/clock` | v7.4.8 | MIT |
| `symfony/console` | v7.4.20 | MIT |
| `symfony/css-selector` | v7.4.18 | MIT |
| `symfony/deprecation-contracts` | v3.7.1 | MIT |
| `symfony/error-handler` | v7.4.20 | MIT |
| `symfony/event-dispatcher` | v7.4.17 | MIT |
| `symfony/event-dispatcher-contracts` | v3.7.1 | MIT |
| `symfony/finder` | v7.4.20 | MIT |
| `symfony/http-foundation` | v7.4.23 | MIT |
| `symfony/http-kernel` | v7.4.23 | MIT |
| `symfony/mailer` | v7.4.19 | MIT |
| `symfony/mime` | v7.4.22 | MIT |
| `symfony/polyfill-ctype` | v1.37.0 | MIT |
| `symfony/polyfill-intl-grapheme` | v1.43.0 | MIT |
| `symfony/polyfill-intl-idn` | v1.43.0 | MIT |
| `symfony/polyfill-intl-normalizer` | v1.43.0 | MIT |
| `symfony/polyfill-mbstring` | v1.43.0 | MIT |
| `symfony/polyfill-php80` | v1.43.0 | MIT |
| `symfony/polyfill-php82` | v1.43.0 | MIT |
| `symfony/polyfill-php83` | v1.43.0 | MIT |
| `symfony/polyfill-php84` | v1.43.0 | MIT |
| `symfony/polyfill-php85` | v1.43.0 | MIT |
| `symfony/polyfill-php86` | v1.43.0 | MIT |
| `symfony/polyfill-uuid` | v1.43.0 | MIT |
| `symfony/process` | v7.4.19 | MIT |
| `symfony/property-access` | v7.4.16 | MIT |
| `symfony/property-info` | v7.4.16 | MIT |
| `symfony/routing` | v7.4.23 | MIT |
| `symfony/serializer` | v7.4.23 | MIT |
| `symfony/service-contracts` | v3.7.3 | MIT |
| `symfony/string` | v7.4.19 | MIT |
| `symfony/translation` | v7.4.17 | MIT |
| `symfony/translation-contracts` | v3.7.1 | MIT |
| `symfony/type-info` | v7.4.9 | MIT |
| `symfony/uid` | v7.4.20 | MIT |
| `symfony/var-dumper` | v7.4.18 | MIT |
| `tijsverkoyen/css-to-inline-styles` | v2.4.0 | BSD-3-Clause |
| `vlucas/phpdotenv` | v5.7.0 | BSD-3-Clause |
| `voku/portable-ascii` | 2.1.1 | MIT |
| `web-auth/cose-lib` | 4.6.0 | MIT |
| `web-auth/webauthn-lib` | 5.3.5 | MIT |
| `web-token/jwt-library` | 4.1.7 | MIT |
| `webmozart/assert` | 2.4.1 | MIT |

### Composer — dev（template/composer.lock）

開発・検証時（`agentcms test` / `verify`）のみに使われる依存。配布物・本番成果物には含まれない。

| Package | Version | License |
| --- | --- | --- |
| `brianium/paratest` | v7.20.0 | MIT |
| `composer/pcre` | 3.4.0 | MIT |
| `composer/xdebug-handler` | 3.0.5 | MIT |
| `fakerphp/faker` | v1.24.1 | MIT |
| `fidry/cpu-core-counter` | 1.3.0 | MIT |
| `filp/whoops` | 2.18.4 | MIT |
| `hamcrest/hamcrest-php` | v2.1.1 | BSD-3-Clause |
| `iamcal/sql-parser` | v0.7 | MIT |
| `jean85/pretty-package-versions` | 2.1.1 | MIT |
| `larastan/larastan` | v3.10.0 | MIT |
| `laravel/agent-detector` | v2.0.2 | MIT |
| `laravel/pail` | v1.2.7 | MIT |
| `laravel/pao` | v1.1.3 | MIT |
| `laravel/pint` | v1.30.4 | MIT |
| `laravel/sail` | v1.65.0 | MIT |
| `mockery/mockery` | 1.6.12 | BSD-3-Clause |
| `myclabs/deep-copy` | 1.13.4 | MIT |
| `nunomaduro/collision` | v8.9.5 | MIT |
| `pestphp/pest` | v4.7.8 | MIT |
| `pestphp/pest-plugin` | v4.0.0 | MIT |
| `pestphp/pest-plugin-arch` | v4.0.2 | MIT |
| `pestphp/pest-plugin-laravel` | v4.1.0 | MIT |
| `pestphp/pest-plugin-mutate` | v4.0.1 | MIT |
| `pestphp/pest-plugin-profanity` | v4.2.1 | MIT |
| `phar-io/manifest` | 2.0.4 | BSD-3-Clause |
| `phar-io/version` | 3.2.1 | BSD-3-Clause |
| `phpstan/phpstan` | 2.2.8 | MIT |
| `phpunit/php-code-coverage` | 12.5.7 | BSD-3-Clause |
| `phpunit/php-file-iterator` | 6.0.1 | BSD-3-Clause |
| `phpunit/php-invoker` | 6.0.0 | BSD-3-Clause |
| `phpunit/php-text-template` | 5.0.0 | BSD-3-Clause |
| `phpunit/php-timer` | 8.0.0 | BSD-3-Clause |
| `phpunit/phpunit` | 12.5.33 | BSD-3-Clause |
| `sebastian/cli-parser` | 4.2.1 | BSD-3-Clause |
| `sebastian/comparator` | 7.1.8 | BSD-3-Clause |
| `sebastian/complexity` | 5.0.0 | BSD-3-Clause |
| `sebastian/diff` | 7.0.0 | BSD-3-Clause |
| `sebastian/environment` | 8.1.2 | BSD-3-Clause |
| `sebastian/exporter` | 7.0.3 | BSD-3-Clause |
| `sebastian/global-state` | 8.0.3 | BSD-3-Clause |
| `sebastian/lines-of-code` | 4.0.1 | BSD-3-Clause |
| `sebastian/object-enumerator` | 7.0.0 | BSD-3-Clause |
| `sebastian/object-reflector` | 5.0.0 | BSD-3-Clause |
| `sebastian/recursion-context` | 7.0.1 | BSD-3-Clause |
| `sebastian/type` | 6.0.4 | BSD-3-Clause |
| `sebastian/version` | 6.0.0 | BSD-3-Clause |
| `staabm/side-effects-detector` | 1.0.5 | MIT |
| `symfony/yaml` | v7.4.23 | MIT |
| `ta-tikoma/phpunit-architecture-test` | 0.8.7 | MIT |
| `theseer/tokenizer` | 2.0.1 | BSD-3-Clause |

### npm — CLI runtime（packages/cli/package.json）

`npm install -g` 時に取得される CLI の実行時依存。

| Package | Version | License |
| --- | --- | --- |
| `@modelcontextprotocol/server` | 2.0.0 | MIT |
| `commander` | ^14.0.0 | MIT |
| `kuromoji` | 0.1.2 | Apache-2.0 |
| `node-html-parser` | ^7.0.1 | MIT |
| `picocolors` | ^1.1.1 | ISC |
| `zod` | ^4.4.3 | MIT |

### npm — CLI dev（packages/cli/package.json）

Kit 開発時のみ。利用者環境にはインストールされない。

| Package | Version | License |
| --- | --- | --- |
| `@types/kuromoji` | ^0.1.3 | MIT |
| `@types/node` | ^24.0.0 | MIT |
| `eslint` | ^9.30.0 | MIT |
| `typescript` | ^5.9.0 | Apache-2.0 |
| `typescript-eslint` | ^8.35.0 | MIT |
| `vitest` | ^3.2.0 | MIT |

### npm — template runtime/build（template/package.json dependencies）

生成プロジェクトの `pnpm install` で取得され、ビルド成果物（管理画面 JS 等）に含まれ得る依存。

| Package | Version | License |
| --- | --- | --- |
| `@inertiajs/vite` | ^3.0.0 | MIT |
| `@inertiajs/vue3` | ^3.0.0 | MIT |
| `@laravel/passkeys` | ^0.2.0 | MIT |
| `@lucide/vue` | ^1.17.0 | ISC |
| `@tiptap/extension-image` | 3.31.3 | MIT |
| `@tiptap/pm` | 3.31.3 | MIT |
| `@tiptap/starter-kit` | 3.31.3 | MIT |
| `@tiptap/vue-3` | 3.31.3 | MIT |
| `@vueuse/core` | ^12.8.2 | MIT |
| `class-variance-authority` | ^0.7.1 | Apache-2.0 |
| `clsx` | ^2.1.1 | MIT |
| `laravel-vite-plugin` | ^3.0.0 | MIT |
| `reka-ui` | ^2.9.8 | MIT |
| `tailwind-merge` | ^3.2.0 | MIT |
| `tailwindcss` | ^4.1.1 | MIT |
| `tw-animate-css` | ^1.2.5 | MIT |
| `vue` | ^3.5.44 | MIT |
| `vue-input-otp` | ^0.3.2 | MIT |
| `vue-sonner` | ^2.0.0 | MIT |

### npm — template optional（platform binaries）

プラットフォーム別のビルド用バイナリ。ビルド時のみ使用され、成果物には含まれない。

| Package | Version | License |
| --- | --- | --- |
| `@rollup/rollup-linux-x64-gnu` | 4.9.5 | MIT |
| `@rollup/rollup-win32-x64-msvc` | 4.9.5 | MIT |
| `@tailwindcss/oxide-linux-x64-gnu` | ^4.0.1 | MIT |
| `@tailwindcss/oxide-win32-x64-msvc` | ^4.0.1 | MIT |
| `lightningcss-linux-x64-gnu` | ^1.29.1 | MPL-2.0 |
| `lightningcss-win32-x64-msvc` | ^1.29.1 | MPL-2.0 |

### npm — template dev（template/package.json devDependencies）

生成プロジェクトの開発・検証時のみ。ビルド成果物には含まれない。

| Package | Version | License |
| --- | --- | --- |
| `@axe-core/playwright` | ^4.12.1 | MPL-2.0 |
| `@eslint/js` | ^9.19.0 | MIT |
| `@laravel/vite-plugin-wayfinder` | ^0.1.3 | MIT |
| `@lhci/cli` | ^0.15.1 | Apache-2.0 |
| `@playwright/test` | ^1.62.1 | Apache-2.0 |
| `@stylistic/eslint-plugin` | ^5.10.0 | MIT |
| `@tailwindcss/vite` | ^4.1.11 | MIT |
| `@types/node` | ^22.13.5 | MIT |
| `@types/pixelmatch` | ^5.2.6 | MIT |
| `@types/pngjs` | ^6.0.5 | MIT |
| `@vitejs/plugin-vue` | ^6.0.0 | MIT |
| `@vue/eslint-config-typescript` | ^14.3.0 | MIT |
| `axe-core` | ^4.13.0 | MPL-2.0 |
| `concurrently` | ^9.0.1 | MIT |
| `eslint` | ^9.17.0 | MIT |
| `eslint-config-prettier` | ^10.0.1 | MIT |
| `eslint-import-resolver-typescript` | ^4.4.4 | ISC |
| `eslint-plugin-import` | ^2.32.0 | MIT |
| `eslint-plugin-vue` | ^9.32.0 | MIT |
| `pixelmatch` | ^7.2.0 | ISC |
| `pngjs` | ^7.0.0 | MIT |
| `prettier` | ^3.4.2 | MIT |
| `prettier-plugin-tailwindcss` | ^0.6.11 | MIT |
| `typescript` | ^5.2.2 | Apache-2.0 |
| `typescript-eslint` | ^8.23.0 | MIT |
| `vite` | ^8.3.5 | MIT |
| `vitest` | ^3.2.7 | MIT |
| `vue-tsc` | ^2.2.4 | MIT |

<!-- AUTO-GENERATED:DEPS END -->

---

## GUI 配布物に同梱される npm 依存（実測）

`scripts/package-gui.mjs` は CLI の production 依存を解決して
`app/node_modules/` へ同梱します。**上表の「npm — CLI runtime」直接依存に加えて、
その推移的依存も同梱されます**。実測した内訳は次のとおりです（Phase 9.1B 時点）。

| Package | Version | License | 経路 |
| --- | --- | --- | --- |
| `@modelcontextprotocol/server` | 2.0.0 | MIT | 直接 |
| `@modelcontextprotocol/core` | 2.0.0 | MIT | `@modelcontextprotocol/server` |
| `zod` | 4.4.3 | MIT | 直接 / `@modelcontextprotocol/server` |
| `commander` | 14.0.3 | MIT | 直接 |
| `picocolors` | 1.1.1 | ISC | 直接 |
| `node-html-parser` | 7.1.0 | MIT | 直接 |
| `he` | 1.2.0 | MIT | `node-html-parser` |
| `css-select` | 5.2.2 | BSD-2-Clause | `node-html-parser` |
| `css-what` | 6.2.2 | BSD-2-Clause | `css-select` |
| `domelementtype` | 2.3.0 | BSD-2-Clause | `css-select` |
| `domhandler` | 5.0.3 | BSD-2-Clause | `node-html-parser` |
| `domutils` | 3.2.2 | BSD-2-Clause | `css-select` |
| `dom-serializer` | 2.0.0 | MIT | `domutils` |
| `entities` | 4.5.0 | BSD-2-Clause | `dom-serializer` |
| `boolbase` | 1.0.0 | ISC | `nth-check` |
| `nth-check` | 2.1.1 | BSD-2-Clause | `css-select` |

いずれも許諾型（MIT / ISC / BSD-2-Clause）で、copyleft は含まれません。
各パッケージの LICENSE 全文は同梱物内の `app/node_modules/<name>/` にあります。

> この表は手動で棚卸ししたものです（自動生成ブロックは直接依存のみを扱います）。
> 依存を追加・変更した場合はここも更新してください。
