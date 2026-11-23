/**
 * Template Import Plan — Manifest から決定論的に生成する変換計画。
 * 各判断は reason + confidence（CONFIDENT / REVIEW / UNKNOWN）を必ず持つ（spec §17–18）。
 * 形式変更は docs/adr/0010 の改訂として扱うこと。
 */

import type { Warning } from "../inspect/manifest.js";
import type { TaxonomyChange } from "./plan-contract.js";

export type { TaxonomyChange };

export type PlanConfidence = "CONFIDENT" | "REVIEW" | "UNKNOWN";

export interface TemplatePlan {
  /**
   * Plan 形式の版。
   *
   * **v2 で `taxonomy_changes` が加わった。** v1 の Plan には「分類項目を作るかどうか」
   * という意思決定が存在しないので、v1 を v2 として読むと
   * 「提案が無い」のか「その概念が無い時代の Plan」なのかを区別できない。
   * apply は v1 を解釈せず、作り直しを求めて止まる。
   *
   * **v3 で `content_types` が加わり、`seeds` の形が変わった**（R-13 Stage 2）。
   * v2 の Plan には「型ごと持ち込む」という意思決定が無く、`seeds` も型名を
   * field 名に持っていた。v2 を v3 として読むと **seed が 1 件も無いように見える**ので、
   * 同じく解釈せず作り直しを求める。
   */
  version: 3;
  generated_by: string;
  source: string;
  /** inspect Manifest の fingerprint.total — apply 時の stale 検出に使う */
  manifest_fingerprint: string;
  /** home (/) として扱う source ページ（Manifest の entry。null なら home 未決定 = REVIEW） */
  entry: string | null;
  pages: PagePlan[];
  layout: LayoutPlan;
  components: ComponentPlan[];
  cms_integrations: CmsIntegration[];
  keep_static: KeepStatic[];
  javascript: JsPlan[];
  assets: AssetsPlan;
  schema_changes: SchemaChange[];
  /** 分類項目の追加提案（§O / §U.3）。select の選択肢とは別物 */
  taxonomy_changes: TaxonomyChange[];
  link_map: LinkMapEntry[];
  /** CSP 追加オリジン等、.env へ追記が必要な項目（ADR-0010 §5） */
  env_additions: Record<string, string>;
  seeds: SeedsPlan;
  /**
   * 新しく作る投稿タイプ（R-13 Stage 2）。
   *
   * 受け皿の型が在るときは**空**で、これまでどおり既存の型へ載せる
   * （どちらを選んだかは `pages[].reason` に出る）。
   *
   * **fields は持たない。** `preset` を PHP 側（`ContentTypeBuilderPresets`）が展開する。
   * TS が項目定義を複製すると、CTB と 2 つの正本ができる（F-18）。
   */
  content_types: ContentTypePlan[];
  uncertainties: Uncertainty[];
  warnings: Warning[];
}

export interface PagePlan {
  source: string;
  title: string | null;
  target: string;
  route: string | null;
  url: string | null;
  confidence: PlanConfidence;
  reason: string;
  /** このページに埋め込む CMS 統合（cms_integrations の id） */
  integrations: string[];
}

export interface LayoutPlan {
  target: string;
  header_selector: string | null;
  footer_selector: string | null;
  reason: string;
}

/** Component Registry future-proof な識別情報を持つ（spec §51） */
export interface ComponentPlan {
  component_id: string;
  kind: string;
  source_pattern: string;
  source_pages: string[];
  target_blade: string;
  props: string[];
  confidence: PlanConfidence;
  reason: string;
}

export interface CmsIntegration {
  id: string;
  schema: string;
  regions: { page: string; container: string; item: string | null }[];
  field_mapping: Record<string, string>;
  /**
   * HTML に存在しない必須 schema フィールドへの SERVER-SIDE constant mapping。
   * hidden input は使わない — UI へフィールドを追加せずサーバ側で固定値を保存する
   */
  server_constants: ServerConstant[];
  unmapped_html_fields: {
    name: string;
    handling: string;
    reason: string;
  }[];
  missing_schema_fields: { key: string; note: string }[];
  /**
   * 一覧から詳細へのリンク先の**名前付き route**（R-13 Stage 2）。
   *
   * `null` は「名前付き route が無い」= CMS が持つ投稿タイプで、
   * 公開面は `Route::fallback` が受ける（`/<type>/<slug>`）。
   * 生成器がここを見て、`route('news.show', …)` と素の URL を書き分ける。
   */
  detail_route?: string | null;
  confidence: PlanConfidence;
  reason: string;
}

export interface ServerConstant {
  key: string;
  value: string;
  note: string;
}

export interface KeepStatic {
  region: string;
  page: string;
  confidence: PlanConfidence;
  reason: string;
}

export interface JsPlan {
  file: string;
  action: "keep" | "keep-external";
  confidence: PlanConfidence;
  reason: string;
}

export interface AssetsPlan {
  /** public/ 配下の配置先プレフィックス */
  target_prefix: string;
  /**
   * Reference-Based Asset Allowlist（security rule）:
   * HTML/CSS から実際に参照され、かつ Web 配信用形式のものだけが copy 対象。
   * 未参照・制作/文書ファイル（psd/ai/docx/py/bak 等）は excluded に落ちる
   */
  static: { from: string; to: string }[];
  media: MediaAssetPlan[];
  /** copy しない asset とその理由（情報漏えい防止の監査証跡） */
  excluded: ExcludedAsset[];
}

export interface ExcludedAsset {
  path: string;
  kind:
    "unreferenced" | "blocked-extension" | "not-allowlisted" | "needs-approval";
  reason: string;
}

export interface MediaAssetPlan {
  from: string;
  name: string;
  alt: string;
}

export interface SchemaChange {
  schema: string;
  field: string;
  change: "add_choices" | "make_optional";
  /**
   * 適用先は常に project（生成プロジェクトの content/schemas/）。
   * Core（kit の template/）は変更しない — Core/Project Boundary
   */
  scope: "project";
  /** add_choices の追加値。make_optional では空配列 */
  values: string[];
  confidence: PlanConfidence;
  reason: string;
}

export interface LinkMapEntry {
  href: string;
  kind: "route" | "anchor" | "unmapped";
  route: string | null;
  url: string | null;
  fragment: string | null;
}

/**
 * 初期投入データ（R-13 Stage 2 で型名の決め打ちを外した）。
 *
 * 以前は `{ works: [...], news: [...] }` と **field 名が型名**だった。
 * plan の形に型名が焼き付いていると、**別名の型を持ち込めない**
 * （import が型ごと持ち込めるようになった以上、ここが最後の固定点になる）。
 *
 * `type` は投稿タイプ ID（値）。`shape` は**行の形**で、検出した構造そのものである。
 * 型名とは独立していて、同じ `cards` を別名の型へ入れることもできる。
 * モデル名は `type` から導けるので持たない。
 */
export interface SeedsPlan {
  /** head metadata から導出した Site Settings 初期値（未設定キーのみ投入） */
  site: Record<string, string>;
  collections: SeedCollection[];
}

export type SeedCollection =
  | ({ shape: "cards"; rows: CardSeed[] } & SeedTarget)
  | ({ shape: "dated"; rows: DatedSeed[] } & SeedTarget);

export interface SeedTarget {
  /** 投稿タイプ ID */
  type: string;
  /**
   * 投入先のモデル（FQCN）。
   *
   * **`type` から導けない。** CMS が作った型は `Modules\Works\Models\Works`
   * （`ContentTypeIdentifier::modelClass()` の規則 = Studly を 2 度使う）だが、
   * kit の例として同梱される module は `Modules\Works\Models\Work` である。
   * どちらかは **schema の `managedBy` を見ないと決まらず**、生成の段では
   * schema を持っていない（`generateFromPlan` は plan しか受け取らない）。
   *
   * plan に書いてあると、承認する人が**どのクラスへ入るのか**をそのまま読める。
   */
  model: string;
}

/** カード（画像・分類・年）の形。works.html のようなカード一覧から取る */
export interface CardSeed {
  title: string;
  slug: string;
  /** 原デザインに category 概念が無い場合は ""（架空値を補完しない） */
  category: string;
  /** 原デザインから年が取れない場合は null */
  completed_year: number | null;
  image: string | null;
  order: number;
  /** カードの説明テキスト（原文そのまま。本文を捏造しない） */
  excerpt?: string | null;
  /** 対応する詳細ページ（works show 統合の元。無ければ null） */
  source_page?: string | null;
}

/** 日付つき一覧の形。news.html のような日付リストから取る */
export interface DatedSeed {
  title: string;
  slug: string;
  date: string;
  order: number;
  /** 一覧のみで本文が存在しない場合は ""（本文を生成しない）。未定義なら従来動作 */
  body?: string;
}

export interface Uncertainty {
  subject: string;
  level: PlanConfidence;
  note: string;
}

/**
 * content/schemas/<id>.json の最小ビュー（override をマージした**実 contract**）。
 *
 * `taxonomies` は「この型がどの分類を使うか」の宣言。
 * これがあるかどうかで、同じ key の値でも選択肢になるか分類項目になるかが変わる。
 */
export interface ContentSchema {
  id: string;
  /**
   * 誰がこの型を管理しているか（`"cms"` = Content Type Builder が作った型）。
   * モデルのクラス名の規則がここで変わるので、seed の投入先の判断に要る。
   */
  managedBy?: string;
  fields: {
    key: string;
    type: string;
    required?: boolean;
    choices?: string[];
  }[];
  taxonomies?: {
    key: string;
    multiple?: boolean;
    required?: boolean;
  }[];
}

/** 新しく作る投稿タイプ 1 つ（R-13 Stage 2） */
export interface ContentTypePlan {
  /**
   * 投稿タイプ ID。**あとから変えられない**（URL とデータのキーになる）。
   * 取り込み先の一致条件そのものでもある。
   */
  id: string;
  /** 表示名。**あとから変えられる** */
  label: string;
  /** どの構成で作るか（`ContentTypeBuilderPresets` の id） */
  preset: string;
  confidence: PlanConfidence;
  /** なぜ作るのか。承認の材料なので、ID を変えられないことも書く */
  reason: string;
}
