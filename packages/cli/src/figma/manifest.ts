/**
 * Design Manifest v1（SPEC §20–23、ADR-0011 §5）。
 * Raw Figma JSON を AI が読まずに Web 実装を判断できる、決定論的で compact な表現。
 * - Raw の座標 / transform / bookkeeping は持ち込まない
 * - 同一 Figma version + node selection → バイト同一出力（snapshot test で担保）
 */

export type FigmaConfidence = "CONFIDENT" | "REVIEW" | "UNKNOWN";
export type CapabilityState = "PASS" | "OPTIONAL" | "UNAVAILABLE";

export interface DesignManifest {
  version: 1;
  generated_by: "agentcms figma inspect";
  source: ManifestSource;
  capabilities: Capability[];
  stats: ManifestStats;
  screens: ScreenEntry[];
  components: ComponentEntry[];
  tokens: TokensSection;
  assets: AssetEntry[];
  fonts: FontEntry[];
  cms_candidates: CmsCandidate[];
  warnings: FigmaWarning[];
}

export interface ManifestSource {
  file_key: string;
  file_name: string;
  figma_version: string;
  editor_type: string;
  /** 取得対象 node（sorted）。空 = file 全体 */
  selected_nodes: string[];
  /** figma_version + selection + 正規化済み内容の sha256（stale Plan 検出: SPEC §23） */
  fingerprint: string;
}

export interface Capability {
  name:
    | "file_metadata"
    | "file_content"
    | "variables"
    | "library"
    | "code_connect"
    | "mcp";
  state: CapabilityState;
  detail: string;
}

/** Manifest First の実測に使う（SPEC §79–80） */
export interface ManifestStats {
  raw_bytes: number;
  raw_nodes: number;
  skipped_hidden_nodes: number;
}

// ---- Screens ----

export type Breakpoint = "desktop" | "tablet" | "mobile";

export interface ScreenEntry {
  screen_id: string;
  name: string;
  variants: ScreenVariant[];
  /** Desktop/Mobile pairing の確度（SPEC §25） */
  pairing: FigmaConfidence;
  reason: string;
  /** 不足 breakpoint（例: ["mobile"] → MOBILE DESIGN: REVIEW） */
  missing: Breakpoint[];
  /**
   * 名前で pairing できなかった screen の Human-approved pairing 候補
   * （generic signal: viewport class / text 共有 / asset 共有。自動確定はしない — Phase 6.1 実測の一般化）
   */
  pairing_candidates?: PairingCandidate[];
}

export interface PairingCandidate {
  screen_id: string;
  /** 根拠 signal（例: "viewport-class" / "shared-text" / "shared-assets"） */
  signals: string[];
  /** テキスト内容の一致率 0..1（小数 2 桁へ丸め・決定論） */
  text_overlap: number;
}

export interface ScreenVariant {
  breakpoint: Breakpoint;
  node_id: string;
  frame_name: string;
  width: number;
  height: number;
  tree: ElementNode;
}

/**
 * 正規化済み要素ツリー。Raw node から Web 実装判断に必要な情報のみ残す。
 * - INSTANCE は component 参照へ畳む（children は text/image slot 抽出のみ）
 * - 非表示 node は含めない
 */
export interface ElementNode {
  id: string;
  role: "frame" | "text" | "image" | "vector" | "instance" | "group";
  name: string;
  /** Auto Layout の正規化（SPEC §27。機械的 1:1 変換はしない — candidate 表現） */
  layout?: "flex-row" | "flex-column" | "grid-candidate" | "absolute" | "stack";
  gap?: number;
  /** [top, right, bottom, left]（全 0 のときは省略） */
  padding?: [number, number, number, number];
  text?: string;
  text_token?: string;
  fill_token?: string;
  component?: string;
  variant?: Record<string, string>;
  text_slots?: string[];
  image_slots?: string[];
  asset?: string;
  /** TEXT に設定された hyperlink URL（実在する destination のみ <a> 化 — 発明しない） */
  link?: string;
  /** image fill の表示意図（Figma scaleMode 由来。object-fit 等へ安全に変換できる Design Intent） */
  fill_mode?: "cover" | "contain" | "crop" | "tile";
  /** scaleMode=CROP の正規化変換（2x3 行列を row-major で平坦化・小数 4 桁） */
  fill_transform?: number[];
  width?: number;
  height?: number;
  children?: ElementNode[];
}

// ---- Components ----

export type ComponentClassification =
  | "LAYOUT"
  | "UI_COMPONENT"
  | "CONTENT_COMPONENT"
  | "CMS_COMPONENT"
  | "STATIC_DECORATION"
  | "REVIEW";

export interface ComponentEntry {
  component_id: string;
  name: string;
  node_id: string;
  /** published component key（未 publish は null） */
  component_key: string | null;
  kind: "COMPONENT" | "COMPONENT_SET" | "CANDIDATE";
  classification: ComponentClassification;
  confidence: FigmaConfidence;
  reason: string;
  variants: ComponentVariant[];
  instance_count: number;
  tree: ElementNode;
}

export interface ComponentVariant {
  name: string;
  node_id: string;
  properties: Record<string, string>;
}

// ---- Tokens ----

export type TokenSource = "VARIABLE" | "STYLE" | "INFERRED";

export interface TokensSection {
  colors: TokenEntry[];
  typography: TypographyToken[];
  spacing: TokenEntry[];
  radius: TokenEntry[];
  shadows: TokenEntry[];
}

export interface TokenEntry {
  name: string;
  value: string;
  source: TokenSource;
  confidence: FigmaConfidence;
  usage_count: number;
}

export interface TypographyToken {
  name: string;
  family: string;
  weight: number;
  size: number;
  line_height: number | null;
  letter_spacing: number | null;
  source: TokenSource;
  confidence: FigmaConfidence;
  usage_count: number;
}

// ---- Assets ----

export type AssetClassification =
  "STATIC_DESIGN_ASSET" | "CONTENT_MEDIA" | "ICON" | "DECORATION" | "REVIEW";

export interface AssetEntry {
  asset_id: string;
  name: string;
  kind: "image" | "vector";
  classification: AssetClassification;
  confidence: FigmaConfidence;
  reason: string;
  /** 同一 asset が複数 node に現れても 1 entry（hash 重複排除: SPEC §52） */
  node_ids: string[];
  image_ref: string | null;
  width: number;
  height: number;
}

export interface FontEntry {
  family: string;
  weights: number[];
  usage_count: number;
}

// ---- CMS ----

export type CmsClassification =
  "STATIC" | "CMS_CANDIDATE" | "EXISTING_MODULE" | "REVIEW";

export interface CmsCandidate {
  candidate_id: string;
  screen_id: string | null;
  node_id: string;
  /** この繰り返しが現れる全 container（Desktop / Mobile / 一覧ページ）。apply の CMS ループ置換に使う */
  container_node_ids: string[];
  /** 既存 module 名（works / news / ...）または null */
  module_hint: string | null;
  classification: CmsClassification;
  confidence: FigmaConfidence;
  reason: string;
  item_count: number;
  fields: CmsField[];
}

export interface CmsField {
  name: string;
  kind: "text" | "image" | "date";
  /** Design Fixture としてのサンプル（redact 済み・最大 3 件・80 文字以内） */
  samples: string[];
}

export interface FigmaWarning {
  code: string;
  message: string;
  node_id?: string;
}
