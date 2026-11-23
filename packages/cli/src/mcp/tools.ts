import { z } from "zod";

/**
 * MCP へ公開するツール定義（Phase 9.1B / STEP 11）。
 *
 * 方針:
 * - **Content Type ごとにツールを増やさない**。`type` 引数を取る汎用ツールにする
 * - `data` の中身は schema を焼き込まず、`content_schema_get` で実行時に取得させる
 *   （Phase 9.2 で field type が増えても、このファイルは変わらない）
 * - Application 層の検証が **authoritative**。ここの schema は discovery と早期エラー用
 */

/** ツール名 → Application Service の operation ID（allowlist と 1:1） */
export const TOOL_OPERATIONS = {
  content_types_list: "content.types.list",
  content_schema_get: "content.schema.get",
  content_list: "content.list",
  content_get: "content.get",
  content_create: "content.create",
  content_duplicate: "content.duplicate",
  content_update: "content.update",
  content_delete: "content.delete",
  content_trash: "content.trash",
  content_reorder: "content.reorder",
  media_list: "media.list",
  media_get: "media.get",
  media_update: "media.update",
  media_replace: "media.replace",
  settings_get: "settings.get",
  settings_update: "settings.update",
  /*
   * **受信を読むツールは置かない**（B-43）。旧 `contact_list` / `contact_get` は
   * 9.12 より前に作られた口で、`ContactSubmission` の 14 列をそのまま返していた
   * （送信者の**生 IP**・本文・連絡先を含む）。9.12 が `form.submission.*` を
   * 「本文と連絡先そのもの」として出さないと決めているので、旧側をそれに揃える。
   * 受信は運用者が管理画面で読む。
   */
  page_list: "page.list",
  page_create: "page.create",
  page_duplicate: "page.duplicate",
  page_get: "page.get",
  page_document_get: "page.document.get",
  page_document_versions: "page.document.versions",
  page_document_patch: "page.document.patch",
  page_publish: "page.publish",
  page_unpublish: "page.unpublish",
  page_trash: "page.trash",
  // 分類（Phase 9.3B / SPEC §P.3）。**正確に 7 件**。
  // taxonomy 自体の作成・更新・削除は公開しない（§P.2 — Phase 9.4 へ延期）
  taxonomy_list: "taxonomy.list",
  taxonomy_get: "taxonomy.get",
  term_list: "term.list",
  term_create: "term.create",
  term_update: "term.update",
  term_reorder: "term.reorder",
  term_delete: "term.delete",
} as const;

export type ToolName = keyof typeof TOOL_OPERATIONS;

/**
 * 破壊的・外部影響のあるツール（annotation 用）。
 *
 * 実際に確認を要求するかは **サーバーの ConfirmationGate が決める**（SSoT）。
 * この一覧は AI クライアントへの申告であって、判断の根拠ではない。
 */
export const DESTRUCTIVE_TOOLS: readonly ToolName[] = [
  "content_delete",
  "content_trash",
  "page_trash",
  "page_publish",
  "page_unpublish",
  "term_delete",
];

const empty = z.object({});
/**
 * 楽観ロックの版（Phase 9.8 / STAGE 21C・OG11）。
 *
 * 省略できるのは移行期間のあいだだけで、**送れば必ず検証される**。
 * 読んだときの版を添えれば、その間に誰かが更新していた場合は上書きせずに止まる。
 */
const lockVersionArg = z
  .number()
  .int()
  .min(0)
  /*
   * 上限は **JavaScript が整数として正確に扱える範囲**。これを超えた数は
   * JSON の往復で値が変わり得るので、「送った版と比べたつもりが別の数だった」を作らない。
   * サーバー側も同じ上限で断る（片方だけの守りにしない）。
   */
  .max(Number.MAX_SAFE_INTEGER)
  .describe(
    "読み取り時の lock_version。指定すると、その間に他の人が更新していた場合は上書きせず競合を返す",
  );
const typeArg = z
  .string()
  .describe("Content Type の ID（content_types_list で取得できる）");
const dataArg = z
  .record(z.string(), z.unknown())
  .describe(
    "フィールド名 → 値。使えるフィールドは content_schema_get で取得すること",
  );
/**
 * 分類の割り当て（Phase 9.3B / STAGE 3B）。
 *
 * - **省略可能**。省略した場合、既存の割り当ては変更されない
 * - 指定した分類キーだけが**全置換**される（差分の add / remove は無い）
 * - 1 件しか選べない分類でも**必ず配列**で渡す
 * - 値は項目の ID（推奨）またはスラッグ。**存在しない項目は自動作成されない**
 *
 * 検証は Application 層が authoritative。ここは discovery と早期エラー用。
 */
const taxonomiesArg = z
  .record(z.string(), z.array(z.string()))
  .describe(
    "分類キー → 項目 ID（またはスラッグ）の配列。省略すると分類は変更されない。指定した分類は全置換される",
  );

/** 分類キー。ULID ではなく人が読めるキーを使う（SPEC §J.3 / §Q.1） */
const taxonomyKeyArg = z
  .string()
  .describe("分類のキー（taxonomy_list で取得できる。ULID ではない）");
/** 項目の immutable な ID。名前やスラッグを変えても変わらない（§C.1） */
const termIdArg = z
  .string()
  .describe(
    "分類項目の ID（term_list で取得できる。名前を変えても変わらない）",
  );

export interface ToolDefinition {
  name: ToolName;
  title: string;
  description: string;
  inputSchema: z.ZodObject<z.ZodRawShape>;
  readOnly: boolean;
  destructive: boolean;
}

export const TOOLS: readonly ToolDefinition[] = [
  {
    name: "content_types_list",
    title: "コンテンツタイプ一覧",
    description:
      "この CMS で扱えるコンテンツタイプ（ニュース・実績など）の一覧を返します。ほかの content_* ツールを使う前にこれを呼びます。",
    inputSchema: empty,
    readOnly: true,
    destructive: false,
  },
  {
    name: "content_schema_get",
    title: "コンテンツタイプの定義を取得",
    description:
      "指定したコンテンツタイプのフィールド定義（キー・型・必須・選択肢）を返します。作成・更新の data はこの定義に従います。",
    inputSchema: z.object({ type: typeArg }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "content_list",
    title: "コンテンツ一覧",
    description: "指定したコンテンツタイプの一覧を返します。",
    inputSchema: z.object({
      type: typeArg,
      q: z.string().optional().describe("検索語（省略可）"),
      page: z.number().int().min(1).optional(),
      perPage: z.number().int().min(1).max(100).optional(),
    }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "content_get",
    title: "コンテンツを 1 件取得",
    description: "ID を指定して 1 件のコンテンツを返します。",
    inputSchema: z.object({ type: typeArg, id: z.number().int() }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "content_create",
    title: "コンテンツを作成",
    description:
      "コンテンツを 1 件作成します。管理画面と同じ検証を通ります。公開状態は data の status で指定します。",
    inputSchema: z.object({
      type: typeArg,
      data: dataArg,
      taxonomies: taxonomiesArg.optional(),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "content_duplicate",
    title: "コンテンツを下書きへ複製",
    description: "保存済みの内容と分類を新しい下書きへ複製します。元の記事・履歴・公開状態は変更しません。下書きのない型や無効な参照を含む場合は中断します。",
    inputSchema: z.object({ type: typeArg, id: z.number().int() }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "content_update",
    title: "コンテンツを更新",
    description:
      "コンテンツを 1 件更新します。data に含めたフィールドだけが変更されます。分類は taxonomies で指定した分類キーだけが置き換わります。" +
      "content_get で読んだ lock_version を添えると、その間に他の人が更新していた場合は上書きせず競合を返します。",
    inputSchema: z.object({
      type: typeArg,
      id: z.number().int(),
      data: dataArg,
      taxonomies: taxonomiesArg.optional(),
      lock_version: lockVersionArg.optional(),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "content_trash",
    title: "コンテンツをゴミ箱へ移動",
    description:
      "コンテンツを 1 件ゴミ箱へ移動します。**消えません** — 管理画面から元に戻せます。" +
      "公開ページからは表示されなくなります。**実行前に利用者への確認が必要**です。" +
      "確認できない環境では実行されません。完全に削除する操作は提供していません。",
    inputSchema: z.object({
      type: typeArg,
      id: z.number().int(),
      lock_version: lockVersionArg.optional(),
    }),
    readOnly: false,
    destructive: true,
  },
  {
    name: "content_delete",
    title: "コンテンツを削除（ゴミ箱へ移動）",
    description:
      "**互換のための名前です。物理削除ではありません** — content_trash と同じく、" +
      "コンテンツをゴミ箱へ移動します（元に戻せます）。新しく書くときは content_trash を使ってください。" +
      "**実行前に利用者への確認が必要**です。確認できない環境では実行されません。",
    inputSchema: z.object({
      type: typeArg,
      id: z.number().int(),
      lock_version: lockVersionArg.optional(),
    }),
    readOnly: false,
    destructive: true,
  },
  {
    name: "content_reorder",
    title: "コンテンツの掲載順を変える",
    description:
      "コンテンツ 1 件を、指定した記事の**直後**へ移動します（`after_id` を省略すると先頭へ）。" +
      "**内容は 1 文字も変わらず、また動かせます**。公開ページの並び順にそのまま反映されます。" +
      "並び全体を送る形ではないので、**一覧に出ていない記事を巻き込みません**。",
    inputSchema: z.object({
      type: typeArg,
      id: z.number().int(),
      afterId: z.number().int().optional(),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "media_list",
    title: "メディア一覧",
    description:
      "アップロード済み画像の一覧を返します。使用中かどうかも含まれます。",
    inputSchema: z.object({
      page: z.number().int().min(1).optional(),
      perPage: z.number().int().min(1).max(100).optional(),
    }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "media_get",
    title: "メディアを 1 件取得",
    description: "画像 1 件の情報と使用箇所を返します。",
    inputSchema: z.object({ id: z.number().int() }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "media_update",
    title: "メディア情報を更新",
    description: "画像の名前と代替テキスト（alt）を更新します。",
    inputSchema: z.object({
      id: z.number().int(),
      data: z.object({
        name: z.string().describe("表示名（必須）"),
        alt: z.string().nullable().optional().describe("代替テキスト"),
      }),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "media_replace",
    title: "画像を差し替え",
    description:
      "画像の中身を差し替えます。ID は変わらないため、参照しているコンテンツは壊れません。内容は base64 で渡します（サーバー上のファイルパスは受け付けません）。",
    inputSchema: z.object({
      id: z.number().int(),
      filename: z.string().describe("拡張子つきのファイル名（例 photo.png）"),
      contentBase64: z.string().describe("画像データの base64"),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "settings_get",
    title: "サイト設定を取得",
    description: "サイト設定の定義と現在値を返します。",
    inputSchema: empty,
    readOnly: true,
    destructive: false,
  },
  {
    name: "settings_update",
    title: "サイト設定を更新",
    description:
      "サイト設定を更新します。values に含めた項目だけが変更されます。項目名は settings_get で確認します。",
    inputSchema: z.object({
      values: z.record(z.string(), z.unknown()).describe("設定キー → 値"),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "page_list",
    title: "ページ一覧",
    description:
      "サイトのページ一覧を返します。ページの構成を編集する前にこれを呼びます。",
    inputSchema: z.object({
      status: z
        .string()
        .optional()
        .describe("draft / published / trashed で絞り込む（省略可）"),
    }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "page_create",
    title: "ページを作成",
    description:
      "新しいページを **下書きとして** 作成します。作成しただけでは公開されません。中身は page_document_patch で組み立て、公開は page_publish を使います。",
    // **strict**（Phase 9.3B / #17）。既定の z.object は未知キーを黙って strip するため、
    // `managedBy` 等を送っても「何も言われず無視された」ように見えてしまう。
    // ページの管理方式は入力 contract に存在しない（常に document）ので、
    // ここで明示的に拒否し、Application Service 側の判定と同じ結論にする
    inputSchema: z.strictObject({
      title: z.string().describe("ページのタイトル"),
      slug: z
        .string()
        .describe(
          "URL に使う英小文字・数字・ハイフン（例 company-profile）。空文字なら題の読みから付けます（例 焼き上がり時間 → yaki-agari-jikan。同じ URL があれば -2 を付けます）",
        ),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "page_get",
    title: "ページの設定を取得",
    description: "ページのタイトル・URL・公開状態・SEO を返します。",
    inputSchema: z.object({ id: z.number().int() }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "page_document_versions",
    title: "ページの版の一覧",
    description:
      "そのページがいつ・誰の操作で・どういう保存で変わったかを新しい順に返します。**版の中身は返しません**（大きく、本文がそのまま流れるため）。",
    inputSchema: z.object({
      pageId: z.number().int(),
      perPage: z
        .number()
        .int()
        .min(1)
        .max(50)
        .optional()
        .describe("返す件数。省略すると 50"),
      before: z
        .number()
        .int()
        .optional()
        .describe(
          "この版番号より古いものを返す。前の応答の nextBefore を渡すと続きが読める（件数では飛ばせない — 古い版は間引きで消えるため）",
        ),
    }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "page_document_get",
    title: "ページの構成を取得",
    description:
      "ページを構成するブロックの木を返します。from と depth を指定すると必要な部分だけ取得できます（大きなページを丸ごと読まないこと）。",
    inputSchema: z.object({
      pageId: z.number().int(),
      from: z
        .string()
        .optional()
        .describe("起点ブロックの id または key。省略するとページ全体"),
      depth: z
        .number()
        .int()
        .min(0)
        .max(10)
        .optional()
        .describe("取得する深さ。省略すると全部"),
      published: z
        .boolean()
        .optional()
        .describe("true なら公開中の内容、false（既定）なら編集中の内容"),
    }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "page_document_patch",
    title: "ページの構成を編集",
    description:
      "ブロックの追加・更新・移動・複製・削除を 1 件行います。HTML を書くのではなく、ブロックを操作してください。削除は利用者の確認が必要です。expectedVersion を付けると他の編集を上書きしません。",
    inputSchema: z.object({
      pageId: z.number().int(),
      command: z
        .object({
          operation: z.enum([
            "component.insert",
            "component.update",
            "component.move",
            "component.duplicate",
            "component.delete",
          ]),
          target: z
            .string()
            .optional()
            .describe("対象ブロックの id または key"),
          parent: z.string().optional().describe("追加・移動先の親ブロック"),
          after: z.string().optional().describe("この直後に置く"),
          expectedVersion: z
            .number()
            .int()
            .optional()
            .describe("page_document_get が返した version"),
          changes: z
            .record(z.string(), z.unknown())
            .optional()
            .describe("props / style / responsive / binding / key の変更"),
          component: z
            .record(z.string(), z.unknown())
            .optional()
            .describe("追加するブロック（type と props。id はサーバーが採番）"),
        })
        .describe("実行する操作を 1 つ"),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "page_duplicate",
    title: "ページを複製",
    description: "保存済みの本文とSEO設定を別の下書きへ複製します。新しいURLを割り当て、公開状態や予約日時は引き継ぎません。",
    inputSchema: z.object({ pageId: z.number().int() }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "page_publish",
    title: "ページを公開",
    description:
      "編集中の内容を公開します。publishedAt を渡すとその日時から公開（予約公開）になり、それまでは公開サイト・sitemap に出ません。公開サイトに反映されるため、利用者の確認が必要です。",
    inputSchema: z.object({
      pageId: z.number().int(),
      publishedAt: z
        .string()
        .optional()
        .describe(
          "公開日時。省略するとすぐ公開。「2026-09-10T09:00」（サイトの timezone）または offset 付き ISO 8601",
        ),
    }),
    readOnly: false,
    destructive: true,
  },
  {
    name: "page_unpublish",
    title: "ページを下書きへ戻す",
    description: "公開・公開予約を取り消します。本文と版の履歴は残ります。公開サイトから見えなくなるため、利用者の確認が必要です。",
    inputSchema: z.object({ pageId: z.number().int() }),
    readOnly: false,
    destructive: true,
  },
  {
    name: "page_trash",
    title: "ページをゴミ箱へ",
    description:
      "ページをゴミ箱へ移動します（復元できます）。利用者の確認が必要です。完全削除はこのツールではできません。",
    inputSchema: z.object({ pageId: z.number().int() }),
    readOnly: false,
    destructive: true,
  },
  /*
   * 分類（Phase 9.3B / SPEC §P.3）。
   *
   * - **分類そのものの追加・変更・削除ツールは作らない**（§P.2）。
   *   分類はコンテンツタイプの定義ファイルと対で意味を持つため
   * - archive / unarchive で ツールを増やさない（term_update の archived へ統合）
   * - 割り当て専用ツールも作らない（content_create / content_update の taxonomies へ統合）
   */
  {
    name: "taxonomy_list",
    title: "分類の一覧",
    description:
      "この CMS で使える分類（カテゴリなど）の一覧を返します。項目を操作する前にこれを呼び、分類キーを確認します。",
    inputSchema: empty,
    readOnly: true,
    destructive: false,
  },
  {
    name: "taxonomy_get",
    title: "分類を 1 件取得",
    description:
      "分類キーを指定して、その分類の設定（表示名・階層の有無・項目数）を返します。",
    inputSchema: z.object({ key: taxonomyKeyArg }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "term_list",
    title: "分類の項目一覧",
    description:
      "指定した分類の項目を並び順で返します。既定ではアーカイブ済みを含みません。階層をすべて再現したい場合は includeArchived を true にします。",
    inputSchema: z.object({
      taxonomyKey: taxonomyKeyArg,
      includeArchived: z
        .boolean()
        .optional()
        .describe("アーカイブ済みの項目も含めるか（既定 false）"),
    }),
    readOnly: true,
    destructive: false,
  },
  {
    name: "term_create",
    title: "分類の項目を追加",
    description:
      "分類へ項目を 1 つ追加します。スラッグを省略すると名前から自動生成されます（日本語名の場合は意味を持たない値になるため、必要なら後から変更できます）。",
    inputSchema: z.object({
      taxonomyKey: taxonomyKeyArg,
      name: z.string().describe("項目の表示名"),
      slug: z
        .string()
        .optional()
        .describe("URL 用のスラッグ（省略すると名前から自動生成）"),
      description: z.string().nullable().optional().describe("説明（省略可）"),
      parentId: termIdArg
        .nullable()
        .optional()
        .describe("親項目の ID。省略または null で最上位"),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "term_update",
    title: "分類の項目を更新",
    description:
      "項目の名前・スラッグ・説明・親を変更します。archived を true にするとアーカイブ、false に戻すとアーカイブ解除です。アーカイブしても既存の割り当ては残ります。",
    inputSchema: z.object({
      termId: termIdArg,
      name: z.string().optional().describe("項目の表示名"),
      slug: z.string().optional().describe("URL 用のスラッグ"),
      description: z.string().nullable().optional().describe("説明"),
      parentId: termIdArg
        .nullable()
        .optional()
        .describe("親項目の ID。null で最上位"),
      archived: z
        .boolean()
        .optional()
        .describe("true でアーカイブ、false でアーカイブ解除"),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "term_reorder",
    title: "分類の項目を並べ替え",
    description:
      "同じ親を持つ項目の並び順を入れ替えます。orderedTermIds にはその親の項目をすべて、新しい順序で渡します。",
    inputSchema: z.object({
      taxonomyKey: taxonomyKeyArg,
      parentId: termIdArg
        .nullable()
        .optional()
        .describe("並べ替える階層の親項目 ID。省略または null で最上位"),
      orderedTermIds: z
        .array(termIdArg)
        .describe("新しい並び順の項目 ID。その親に属する項目をすべて含めます"),
    }),
    readOnly: false,
    destructive: false,
  },
  {
    name: "term_delete",
    title: "分類の項目を削除",
    description:
      "項目を 1 つ削除します。**実行前に利用者への確認が必要**です。どこかで使われている項目は削除できません（その場合はアーカイブを使います）。",
    inputSchema: z.object({ termId: termIdArg }),
    readOnly: false,
    destructive: true,
  },
] as const;
