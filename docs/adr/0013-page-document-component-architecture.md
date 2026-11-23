# ADR-0013: PageDocument / Component Architecture（方針決定・Gate は未確定）

- Status: **Accepted**（G17〜G25 は owner 承認済み / 2026-08-12。実装は Phase 9.1D）
- Date: 2026-08-12
- Phase: 9.1C

## Context

Phase 9.1B で「管理画面・CLI・API・AI が同じ Application Service を通る」土台ができた。
しかし実測（`docs/PHASE9_1C_SPEC.md` §1）で、**ページそのものはデータではなくコード**
であることが確認された。

- ページは `routes/public.php` の手書き route + `resources/views/public/*.blade.php`
- Navigation と Theme は Blade / CSS にハードコード
- Component Registry は Blade ファイルの目録で、`propsSchema` を持たず**実行時に何も制御していない**
- HTML import / Figma import の出力も **Blade ファイル生成**で終わる

このため、Primary Persona（Terminal が苦手な Web 制作者）は
「ページを 1 つ足す」「メニューを 1 つ増やす」「色を変える」ために
コード編集と deploy を要求される。Phase 9.1B が達成した Terminal 0 と矛盾が残っている。

将来の Visual Editor / AI Component Editing を後付けするとき、
**Content Schema と Pages を作り直さずに済む**境界を先に決める必要がある。

## Decision（本 ADR で確定させる方針）

### 1. Content Schema と PageDocument を正式に分離する

Content Schema =「何のデータを持つか」、PageDocument =「ページをどう構成するか」。
相互に埋め込まず、**参照は Document → Content の一方向**に限る。
Content Schema に presentation の責務（layout / columns / align）を持ち込まない。

### 2. PageDocument は semantic な木であり、DOM の写しではない

一級の node type は `page` / `section` / `container` / `component` の 4 種のみ。
`row` / `column` は `container.layout`、`slot` は Registry 定義で表現する。

### 3. 安定 ID は human-readable な semantic ID とする

`section-hero` `hero-heading` のような ID を primary identity にする。
並べ替え・テキスト変更・公開で変えない。複製と import でのみ新規採番する。
UUID / ULID は採用しない（AI が指しづらく、監査ログの可読性も落ちるため）。

### 4. Component Registry を実行時 contract に格上げする

`propsSchema` / `styleSchema` / `allowedChildren` / `renderer` / `capabilities` を持つ。
Registry は **allowlist** であり、`type` から任意のクラス・パスを解決しない。
Inspector（プロパティ編集 UI）は propsSchema から自動生成できる形にする。

### 5. Theme は Design Token の JSON、出力は CSS custom property

適用順序は Global Token → Theme → Page → Component override。
override できるのは `styleSchema` に列挙されたキーだけで、生 CSS は保存しない。
Figma import の `TokenExtraction`（colors / typography / spacing / radius / shadows）に形を合わせる。

### 6. Renderer は Blade / server-rendered first

公開側を Vue SPA 必須にしない。SEO / Lighthouse / accessibility / 共有ホスティング /
キャッシュのすべてで有利であり、既存 `layouts/public.blade.php` の中に収まる。

### 7. AI 操作は semantic command、内部で JSON Patch

AI に HTML 全文を書き直させない。外側は `component.update` のような semantic command、
内側は RFC 6902 JSON Patch。**逆 patch から undo を機械生成できる**利点を取る。

### 8. Phase 9.1B の枠をそのまま使う

`component.update` = WRITE、`component.delete` = DESTRUCTIVE（確認必須）、
`page.publish` = EXTERNAL_EFFECT（確認必須）。
`Result` / `ConfirmationGate` / `AuditRecorder` / `OperationCatalog` を再利用し、
新しい仕組みを作らない。監査には **変更キー名の要約のみ**を残し、HTML 全文や本文は入れない。

### 9. 既存 Blade ページと共存する

`Page.managed_by = "document" | "blade"` を持ち、既存 project を壊さない。
**全ページを一斉に Editor 管理へ移行させない。**

### 10. import は validation を bypass しない

AI 生成 / HTML import / Figma import の document も、人間が作った document と
同じ validator と sanitizer を通る。Figma node ID は provenance として保持するが、
**primary identity には使わない**。

## Owner Decisions（承認済み）

以下は owner により承認された。実装は Phase 9.1D（`docs/PHASE9_1D_REPORT.md`）。

| Gate | 内容 | 決定 |
| --- | --- | --- |
| G17 | Page / PageDocument storage model | Page + PageVersion + document JSON |
| G18 | node hierarchy | page / section / container / component の 4 種 |
| G19 | Component Registry contract | propsSchema を持つ実行時 contract |
| G20 | Theme / Design Token model | Token JSON → CSS custom property |
| G21 | responsive value model | breakpoint override map |
| G22 | AI Patch format | semantic command + 内部 JSON Patch |
| G23 | Draft / Publish / Version model | version 列 + `published_version_id` |
| G24 | escape hatch | Custom Registered Component 中心 |
| G25 | Blade-managed page との共存 | `Page.managed_by` |

### 安定 ID の上書き決定（owner override）

SPEC では human-readable な semantic ID を primary identity とする案を推奨したが、
owner 決定により **immutable な機械 ID（ULID）+ 任意の human-readable key** を採用する。

- `id` … ULID。並べ替え・内容変更・公開で不変。複製・import でも衝突しない。**これだけが identity**
- `key` … 任意。人と AI が読める。**rename 可能で identity ではない**

監査・MCP・Editor では可能な限り id と key を併記する。
ULID は Laravel 標準の `Str::ulid()` で生成し、**新しい依存は追加しない**。

### その他の owner 決定

- **PageDocument foundation は v1 に含める**（Visual Editor は POST-V1 のまま）
- `component.delete` / `page.trash` は ConfirmationGate 付きで MCP / API へ公開する。
  **ページの物理削除（purge）は公開しない**
- HTML Component は **Restricted HTML** として v1 に含める。JS の逃げ道にはしない
- Navigation は Page / Theme foundation の後に置く
- `/api/v1` の「write 1 本」制約は PageDocument 操作のために解除する。
  ただし public headless API 化しない / bearer token・Sanctum は追加しない
- Template 導入後のカスタマイズは **project 所有**。自動上書きしない

## Consequences

**良くなること**

- ページ・メニュー・テーマが **データ**になり、Terminal 0 のまま編集・AI 操作できる
- Visual Editor は独自の page model を持たない client に留まり、後から載せられる
- HTML / Figma import の成果物が「編集できるページ」になる
- AI は subtree だけを読めるので token 消費が抑えられる

**受け入れるコスト**

- Renderer / Registry / validation という新しい層が増える
- 既存 Blade ページと document ページの 2 系統を route 解決で扱う必要がある
- Registry に登録されていない component が document に残った場合の fallback 設計が要る

## References

- `docs/PHASE9_1C_SPEC.md` — 実測・比較・prototype contract・Owner Decision Gates
- `docs/PHASE9_1B_REPORT.md` / ADR-0012 — Application Service / MCP
- ADR-0010（HTML import）/ ADR-0011（Figma acquisition）— import 資産の互換要件
